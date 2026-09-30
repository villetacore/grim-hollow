# Публикация репозитория, CI/CD и поставка

## Что подготовлено

`VERSION` — версия поставки. Она должна совпадать с тегом `vVERSION`. `CHANGELOG.md` содержит описание выпуска. `tools/release.py` создаёт архивы по разрешённому списку файлов; Python 3.10+ достаточен, сторонние модули не нужны.

| Файл релиза | Назначение |
| --- | --- |
| GrimHollow-VERSION-windows-x64.zip | Клиент Windows, руководство и notices |
| GrimHollow-VERSION-linux-x64.tar.gz | Клиент Linux GTK2, руководство и notices; пока только локальный HTTP |
| GrimHollow-VERSION-source.zip | Чистый исходный проект, готовый к импорту в Git |
| GrimHollow-VERSION-server.tar.gz | Compose, Nginx/Caddy и шаблон настроек для опубликованного образа |
| SHA256SUMS.txt | Хэши всех архивов |
| server-image.txt | Адрес server image с digest, создаётся при публикации в GHCR |

Серверный образ Linux amd64 публикуется как `ghcr.io/owner/repository:VERSION` и `:sha-COMMIT`. ARM64 и macOS-клиент не собираются. Подписи EXE/архивов и автообновление не настроены. CI ничего не разворачивает на ваш сервер.

## Первый импорт на GitHub

Самый простой путь — распаковать `*-source.zip` в новую папку и создать репозиторий из неё: в архив не входят `.tools`, `.env`, базы, backup, vendor и результаты сборки. Если используете рабочую папку, перед первым commit обязательно просмотрите список добавленных файлов.

```sh
git init -b main
git add .
git diff --cached --stat
python tools/release.py check
git commit -m "Prepare Grim Hollow 0.3.0 release"
git remote add origin https://github.com/OWNER/REPOSITORY.git
git push -u origin main
```

Замените OWNER/REPOSITORY своим адресом. Репозиторий и удалённый origin эти скрипты сами не создают. Тестовые аккаунты/пароли из локальной базы не публикуются. Лицензию можно изменить отдельно решением правообладателя; сейчас действует LICENSE с сохранением всех прав.

В Settings → Actions разрешите GitHub Actions. Workflow запрашивает `contents: write` и `packages: write` **только** для публикации релиза. Обычно встроенного `GITHUB_TOKEN` достаточно; PAT и SSH-ключ сервера не нужны. Политики организации могут ограничивать эти права. Для GHCR-пакета, который уже существует, проверьте доступ данного репозитория в Manage Actions access. Для публичного скачивания образа отдельно проверьте видимость пакета GHCR: она не обязательно совпадает с видимостью репозитория.

Включите защиту main с обязательной проверкой Verify, защиту тегов v*, Dependabot alerts и Private vulnerability reporting. Обновления зависимостей приходят PR через `.github/dependabot.yml`.

## Как работает CI/CD

- `.github/workflows/verify.yml`: push ветки, pull request и ручной запуск. Права только на чтение.
- `.github/workflows/build.yml`: общий pipeline — проверка состава исходников и упаковщика; PHP/MySQL; production FPM image; Windows Lazarus 4.4/FPC 3.2.2; Linux-клиент в Debian bookworm, настоящий GUI smoke, HTTP/WS, игровой бот и restart; архивы как Actions artifacts.
- `.github/workflows/release.yml`: push тега v*. Повторяет полный pipeline **для commit тега**. Только после успеха публикует образ GHCR и GitHub Release. Тег с суффиксом вроде `v0.3.1-rc.1` становится prerelease.

Повторный запуск того же Release workflow переотправляет файлы с `--clobber`. Не перемещайте опубликованные теги: исправления получают новую версию. Workflow использует фиксированные major-версии Actions; Dependabot предлагает обновления. Для более строгой политики supply chain замените ссылки на Actions на проверенные commit SHA.

Использованы документированные интерфейсы [GitHub artifacts](https://docs.github.com/en/actions/tutorials/store-and-share-data), [публикации контейнеров](https://docs.github.com/en/actions/tutorials/publish-packages/publish-docker-images) и [setup-lazarus](https://github.com/gcarreno/setup-lazarus). Windows toolchain в CI зафиксирован на поддерживаемой этим Action версии 4.4; локально текущая игра также проверена на Lazarus 4.8.

## Выпуск

1. Обновите VERSION и CHANGELOG, ограничения в документации. Если меняется игровая совместимость, отдельно обновите `Game::CONTENT_VERSION` и опишите обновление существующих походов. Версия пакета и версия игрового формата — разные значения.
2. Выполните локальные проверки, загрузите изменения и дождитесь зелёного Verify.
3. Поставьте тег, совпадающий с VERSION:

```sh
git tag -a v0.3.0 -m "Grim Hollow 0.3.0"
git push origin v0.3.0
```

4. Дождитесь Release. Проверьте скачивание файлов и GHCR, затем раздавайте ссылку на выпуск. Архив Windows можно распаковать без установки IDE/PHP.

На момент подготовки pipeline локальные сборки и проверки выполнены; реальный GitHub Actions run возможен только после загрузки в ваш репозиторий. Успешный удалённый CI заранее не заявляется.

## Локальная упаковка

```powershell
# Lazarus/FPC и Python должны быть установлены
./tools/package-client.ps1
python tools/release.py source
python tools/release.py server
python tools/release.py checksums
```

При нестандартном Python: `./tools/package-client.ps1 -Python C:\path\python.exe`. Linux собирается Dockerfile клиента; бинарник нужно извлечь из `/game/build/client/linux/grim-hollow`, затем вызвать `python tools/release.py client --platform linux-x64 --binary PATH`. Готовые файлы — `dist/releases`. Список исходников можно проверить распаковкой `*-source.zip`; упаковщик не использует копирование всей рабочей папки.

## Самостоятельное размещение сервера

Нужны Linux amd64 с Docker Engine/Compose v2, домен с DNS на сервер и входящие TCP 80/443 (UDP 443 необязателен). Caddy запрашивает сертификат автоматически. Ни MySQL, ни API/world не должны публиковаться отдельными портами.

Распакуйте `*-server.tar.gz` и перейдите в его каталог. Подготовьте настройки:

```sh
cp infra/compose/.env.production.example infra/compose/.env.production
chmod 600 infra/compose/.env.production
openssl rand -base64 32
openssl rand -hex 32
openssl rand -hex 32
```

Впишите три полученных значения: APP_KEY с префиксом `base64:`, отдельные DB_PASSWORD и DB_ROOT_PASSWORD. Укажите GAME_DOMAIN без схемы и GAME_IMAGE из GHCR; для воспроизводимого развёртывания используйте `ghcr.io/owner/repository@sha256:...` из server-image.txt. Не оставляйте REPLACE/OWNER из примера. Не меняйте APP_KEY и пароли существующей базы при обычном обновлении.

```sh
docker compose --env-file infra/compose/.env.production -f infra/compose/production.yaml pull
docker compose --env-file infra/compose/.env.production -f infra/compose/production.yaml up -d --no-build
docker compose --env-file infra/compose/.env.production -f infra/compose/production.yaml ps
curl --fail https://YOUR_DOMAIN/up
```

`--no-build` обязателен для серверного bundle: он использует готовый образ и не содержит исходники для Docker build. Для частного GHCR предварительно выполните docker login с учётными данными, имеющими право чтения пакета. Не храните этот токен в `.env` игры.

Миграции выполняет одноразовый сервис migrate до старта API/world. Production FPM запускается от www-data. Город/аккаунты и world используют одну MySQL; это конфигурация **одного world-процесса**, не масштабируйте world replicas произвольно.

В Windows-клиенте игрок указывает `https://YOUR_DOMAIN`: HTTP API и WSS `/ws` идут через Caddy. Создайте обычный аккаунт оператора, затем выдайте роль:

```sh
docker compose --env-file infra/compose/.env.production -f infra/compose/production.yaml exec api php artisan game:admin operator@example.com
```

Экран модерации — `https://YOUR_DOMAIN/admin`; для отзыва роли добавьте `--revoke`. Не используйте пример адреса вместо своего аккаунта.

## Обновления и резервные копии

Перед обновлением сохраните `.env.production`, содержимое MySQL и тома Caddy отдельно от публичного репозитория. Для SQL-копии без вывода пароля:

```sh
docker compose --env-file infra/compose/.env.production -f infra/compose/production.yaml exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" mysqldump -uroot --single-transaction --no-tablespaces --set-gtid-purged=OFF grim_hollow' > backup.sql
```

Ограничьте доступ к backup.sql: он содержит аккаунты. Периодически проверяйте восстановление на отдельном стенде. Локальный `tools/backup-verify.ps1` относится к dev Compose, не переключайте его на production не проверив имена БД.

Для согласованного обновления остановите edge/API/world, сделайте backup, измените GAME_IMAGE, выполните pull, `run --rm migrate`, затем `up -d --no-build --force-recreate`. Планируйте перерыв и завершение активных походов при смене content_version. Откат образа не откатывает схему БД: обратимость определяется конкретной миграцией; восстановление backup теряет записи после его создания.

`docker compose down` оставляет volumes, `down -v` уничтожает данные. Копии и ключи никогда не включаются в релиз. Перед публичным запуском нужны ваша проверка сертификата/WSS, сетевой доступности, резервирования и нагрузки: готовые конфигурации не являются подтверждением выполненной эксплуатации.
