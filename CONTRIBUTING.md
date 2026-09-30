# Работа с проектом

1. Прочитайте README и docs/00-implementation-status.md: проектные документы не всегда описывают уже реализованные функции.
2. Для сервера используйте PHP 8.4, Composer 2 и MySQL 8.4. Для клиента — Lazarus/FPC; CI фиксирует Windows Lazarus 4.4/FPC 3.2.2, Linux Debian bookworm Lazarus 2.2.6/FPC 3.2.2. Локально также проверен Lazarus 4.8.
3. Запуск Docker: `tools/setup.ps1` или `sh tools/setup.sh`. Сборка Windows: `tools/build-client.ps1`; Linux — Dockerfile в infra/docker/client.
4. Серверные тесты: `tools/test-mysql.ps1` (только отдельная тестовая база). PHP-правила можно проверить через `php vendor/bin/phpunit` из apps/server после Composer install.
5. Проверка поставки: `python tools/release.py check`, `python -m unittest discover -s tests/packaging`. В CI дополнительно выполняются Linux GUI и сетевые сценарии.

Меняйте игровые правила в packages/game-core, транзакции — в apps/server/app/Game, отображение — в apps/client/src. Новые действия должны проверяться сервером. Экономические операции требуют уникального operation_id и проверки повтора. Не меняйте исторические миграции работающей базы — добавляйте новые.

Не коммитьте `.env`, backup, базы, токены, vendor, .tools, dist и файлы IDE. Не вставляйте секреты в Issue, логи и скриншоты. Для изменения несовместимого игрового формата обновите content_version и опишите миграцию/ограничения.

Перед PR опишите изменение поведения и выполненные проверки. Перед релизом обновите VERSION, CHANGELOG, ограничения и руководство. Ветка main и теги v* должны быть защищены в настройках репозитория. Работайте через review; не меняйте уже опубликованный тег.

Лицензия проекта сейчас не является open source: см. LICENSE. Вклад не должен добавлять чужие игровые ресурсы без разрешения и обязательных notices.
