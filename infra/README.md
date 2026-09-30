# Инфраструктура прототипа

`compose/dev.yaml` запускает MySQL, миграции, API и world. Профиль test использует отдельную БД. Dockerfile сервера и клиента создают Linux-сборки. Секреты генерируются в исключённый из Git `.env`.

Production, TLS ingress, Redis, backup и мониторинг пока остаются проектом. Dev-порты доступны только на loopback.

[Практическая инструкция](../docs/13-running.md) · [Целевая инфраструктура](../docs/10-operations.md)
