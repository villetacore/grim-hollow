# Сервер прототипа

Laravel HTTP API, Workerman world-процесс, MySQL и чистая PHP-библиотека правил. Зависимости закреплены в composer.lock. PHP 8.4+.

`routes/api.php` — маршруты; `app/Game/World.php` — транзакции экспедиций; `world.php` — WebSocket и такты; `database/migrations` — схема. Восстановление использует последний полный снимок в БД.

[Запуск и тесты](../../docs/13-running.md) · [Готовность и ограничения](../../docs/00-implementation-status.md)
