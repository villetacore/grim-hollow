# Реализованный контракт прототипа 0.1

Целевой `docs/08-protocol.md` шире реализации. Этот документ фиксирует текущий путь, общий для GUI и сетевых тестов. Все HTTP-маршруты начинаются с `/api/v1`, тела и ответы JSON, авторизация `Authorization: Bearer <access_token>`.

| Метод | Путь | Тело / параметры | Основной результат |
| --- | --- | --- | --- |
| POST | /auth/register | email, password (12–128 символов) | 201: access_token, expires_in |
| POST | /auth/login | email, password | access_token, expires_in |
| POST | /auth/logout | — | 204, отзыв сессии |
| GET | /characters | — | items: id, name, class_id, gold, xp, active_expedition |
| POST | /characters | name (3–24 символа) | 201: id, name |
| POST | /expeditions | character_id, необязательный join_code | id, join_code, status |
| POST | /expeditions/{id}/start | character_id | status: active |
| POST | /expeditions/{id}/leave | character_id | status: left |
| GET | /expeditions/{id} | query character_id | snapshot |
| POST | /expeditions/{id}/commands | character_id, command_id (UUID), payload | command_result |
| POST | /world/tickets | character_id, expedition_id | ticket, expires_in: 30 |

HTTP-ошибки: `message` и при валидации `errors`; это пока стандартный формат Laravel, а не целевой message_key. Экономические команды имеют persisted receipts по UUID. Создание лобби повторно возвращает активную экспедицию героя. Создание героя не поддерживает общий Idempotency-Key.

## Payload команд

- `{"action":"move","direction":"north"}`: north/south/east/west.
- `{"action":"attack","target_id":"e0"}`: соседний противник.
- `{"action":"bash","target_id":"e0"}`: удар с оглушением.
- `{"action":"guard"}`, `{"action":"potion"}`, `{"action":"extract"}`, `{"action":"descend"}`.

Результат: `v:1`, `type:command_result`, `command_id`, строковый `tick`, `status:executed|rejected`; при отклонении `reason`. Повтор UUID с другим payload возвращает 409. Порядок ключей JSON не значим.

## WebSocket

Соединение `ws://127.0.0.1:8081` требует первое сообщение `{"v":1,"type":"hello","ticket":"..."}` в течение 5 секунд. Билет одноразовый. Ответ `welcome`, затем `snapshot`. Для активности отправляется `{"v":1,"type":"ping"}` не реже раза в 10 секунд; ответ `pong`.

Команда: `{"v":1,"type":"command","command_id":"UUID","payload":{"action":"guard"}}`. Персонаж определяется билетом, а не полем сообщения. Входящие сообщения ограничены 4 KiB, 20/с на соединение. До появления распределённого gateway поддерживается только один world-процесс.

Snapshot: `v`, `type`, `instance_id`, строковая `revision`, `lobby`, `join_code`, `host_id`, `world`. В `world`: строковый tick, floor, status, map из 32 строк по 32 символа, exit либо null, self, players, enemies, log. Пробел — неизвестная клетка, `#` — стена, `.` — пол. Серверный seed/RNG не передаётся. `self.outcome` — null, extracted, defeated или abandoned.

Клиент всегда целиком заменяет текущий вид мира. Он не вычисляет урон, награды или допустимость перемещения. Дельты, command_seq, refresh, TLS, общая JSON Schema и строгая типизация всех сообщений остаются открытыми задачами.
