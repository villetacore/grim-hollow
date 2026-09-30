# 07. Модель данных

Это логическая схема для будущих Laravel migrations. SQL и миграции пока не реализованы. Основное хранилище — MySQL InnoDB, кодировка `utf8mb4`, время UTC с точностью до миллисекунд. Идентификаторы сущностей — ULID в `CHAR(26)` с ASCII binary collation; в API всегда строки. Короткие справочные ключи контента — строки. Последовательности тактов и событий — `BIGINT UNSIGNED`; в JSON передаются десятичными строками, чтобы избежать ограничений числовых типов клиентов.

## Аккаунты и персонажи

| Таблица | Основные поля | Ограничения и индексы |
| --- | --- | --- |
| accounts | id, email_normalized, password_hash, status, created_at | UNIQUE email_normalized |
| device_sessions | id, account_id, access_hash, refresh_hash, expires_at, revoked_at | UNIQUE хэшей токенов, INDEX account_id |
| account_restrictions | id, account_id, kind, reason, expires_at, actor_id | INDEX account_id/kind/expires_at |
| regions | id, code, status | UNIQUE code |
| characters | id, account_id, region_id, name, name_normalized, class_id, level, xp, state, version | UNIQUE region_id/name_normalized, INDEX account_id |
| character_talents | character_id, talent_id, rank | PRIMARY character_id/talent_id |
| character_reputations | character_id, faction_id, value | PRIMARY character_id/faction_id |
| character_recipes | character_id, recipe_id, learned_at | PRIMARY character_id/recipe_id |
| quest_progress | character_id, quest_instance_id, quest_id, objectives_json, state | UNIQUE character_id/quest_instance_id |

Нормализация имени — Unicode NFC + согласованный case folding; оригинальное написание сохраняется отдельно. Сервер проверяет длину, допустимые символы, зарезервированные имена и визуально смешанные алфавиты по явному продуктовому правилу. Удаление героя — отложенная доменная операция после закрытия активных экспедиций, лотов и доставок; каскадное удаление финансового аудита запрещено.

## Инвентарь и экономика

| Таблица | Основные поля | Ограничения и индексы |
| --- | --- | --- |
| containers | id, owner_character_id, kind, capacity | INDEX owner_character_id/kind |
| item_instances | id, definition_id, content_version, container_id, slot_no, quantity, durability, binding_owner_id, attributes_json, version | UNIQUE container_id/slot_no; quantity > 0 |
| wallets | id, character_id, currency, balance, version | UNIQUE character_id/currency; balance >= 0 |
| ledger_transactions | id, reason, source_id, idempotency_key, created_at | UNIQUE idempotency_key, INDEX source_id |
| ledger_entries | id, transaction_id, wallet_id, delta | INDEX transaction_id, INDEX wallet_id/id |
| market_listings | id, region_id, seller_id, item_id, price, state, expires_at, version | INDEX region_id/state/definition filter via projection; UNIQUE active item ownership enforced by escrow |
| deliveries | id, character_id, container_id, reason, source_id, claimed_at | UNIQUE reason/source_id/character_id |
| crafting_operations | id, character_id, recipe_id, content_version, result_json, idempotency_key | UNIQUE character_id/idempotency_key |

Предмет находится ровно в одном контейнере: экипировка, рюкзак, банк, эскроу рынка, почта или добыча экспедиции. В последнем случае предмет доступен только в рамках забега и не является торговым активом до финализации. У контейнеров мира и системы может не быть `owner_character_id`; это не даёт права клиенту обращаться к ним.

Поля фильтров рынка (тип, уровень, редкость) материализуются при публикации лота, а не извлекаются JSON-поиском на каждом запросе. Все поддерживаемые сортировки получают составной индекс с `id` последним полем для курсорной пагинации.

`ledger_entries` — двойная запись с системными кошельками источника и стока: сумма delta внутри транзакции равна нулю. Баланс игрока не бывает отрицательным; системный источник допускает отрицательное сальдо и не доступен обычным операциям. Проверку суммы выполняет доменный сервис в той же транзакции и отдельная сверка. Таблицы аудита не заменяют транзакционную блокировку кошельков.

## Мир и экспедиции

| Таблица | Основные поля | Ограничения и индексы |
| --- | --- | --- |
| zone_instances | id, region_id, kind, state, capacity, owner_id, owner_epoch, lease_until | INDEX state/region_id; INDEX owner_id |
| character_presence | character_id, instance_id, connection_generation, state | PRIMARY character_id; INDEX instance_id |
| expeditions | id, instance_id, dungeon_id, difficulty, seed, generator_version, content_version, state, started_at, finished_at | UNIQUE instance_id; INDEX state/started_at |
| expedition_members | expedition_id, character_id, state, loadout_json, reserved_supplies_json, last_command_seq | PRIMARY expedition_id/character_id |
| instance_events | instance_id, event_seq, tick, owner_epoch, event_type, payload_json | PRIMARY instance_id/event_seq; INDEX instance_id/tick |
| instance_snapshots | instance_id, last_event_seq, tick, checksum, schema_version, payload_blob, created_at | PRIMARY instance_id/last_event_seq |
| expedition_settlements | id, expedition_id, character_id, outcome, rewards_json, ledger_transaction_id | UNIQUE expedition_id/character_id |
| command_receipts | instance_id, character_id, command_id, command_seq, payload_hash, result_json | UNIQUE instance_id/character_id/command_id; UNIQUE instance_id/character_id/command_seq |
| zone_transfers | id, character_id, source_id, destination_id, state, ticket_hash, expires_at | UNIQUE ticket_hash; INDEX character_id/state |

Снимки сжимаются и имеют checksum. Для прототипа хранятся в БД; перенос в объектное хранилище требует атомарного указателя на уже проверенный объект. Seed доступен только внутренним сервисам и не выводится в обычный лог API.

## Социальные и служебные данные

| Таблица | Назначение |
| --- | --- |
| parties, party_members, party_invites | Группа, лидер, готовность, приглашения; UNIQUE character_id в активном составе |
| guilds, guild_members | Гильдия региона, роли; UNIQUE character_id в составе гильдии |
| friendships, account_blocks | Направленные запросы/блокировки; уникальная пара участников |
| chat_messages, reports, moderation_actions | Ограниченное хранение чата, жалобы и решения |
| content_releases | Версии, контрольные суммы, статус публикации |
| outbox_events | event_id, type, payload, attempts, available_at, processed_at |
| idempotency_records | principal_id, scope, key, request_hash, status, response, expires_at |
| admin_audit_log | actor_id, operation, target, reason, before/after, request_id |

Все внешние ключи вводятся миграциями. Cascade допускается для технических дочерних данных, но запрещён для наград, проводок и административного аудита. JSON применяем для снимков и версионированных параметров; владельцы, состояния, деньги и поля поиска — типизированные столбцы.

## Обязательные транзакционные сценарии

1. **Старт:** блокировка персонажей по отсортированным ID → проверка отсутствия активного забега → резервирование расходников → участники и размещение → outbox. Ошибка откатывает весь старт группы.
2. **Эвакуация:** проверка подтверждённого события эвакуации → блокировка участника, персонажа и кошелька → уникальный settlement → перемещение добычи → проводки и опыт → outbox.
3. **Покупка:** лот → кошельки по ID → предмет/контейнер → проводки → доставка → закрытие лота. Конкурирующая покупка видит закрытый лот.
4. **Ремесло:** блокировка ингредиентов по ID → проверка количества и рецепта → списание → создание результата и receipt. Повтор не расходует материалы снова.
5. **Перемещение предмета:** проверка владельца обоих контейнеров, привязки и ревизии → блокировка предмета и целевого слота → атомарное изменение.

Операции повторяются после deadlock ограниченное число раз с тем же idempotency key. UNIQUE-ограничения остаются окончательной защитой. До появления миграций эти правила являются требованиями к реализации.

## Redis

Префикс включает среду и регион. Примеры будущих ключей: `prod:eu1:presence:{accountId}` (TTL 60 с), `prod:eu1:ticket:{ticketHash}` (TTL 30 с, атомарное потребление), `prod:eu1:route:{instanceId}`, `prod:eu1:rate:{principal}:{action}`. Билеты и токены не сохраняются открытым текстом в логах. Для очередей и cache используются разные Redis-процессы в production: eviction кэша не должен удалять job. Durable outbox позволяет повторно поставить потерянную job.
