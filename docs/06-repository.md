# 06. Структура монорепозитория

Ниже **целевая структура реализации**. Уже существуют исходники локального прототипа, Dockerfile и миграции; остальные элементы дерева реализуются по этапам. Актуальный перечень готовых частей находится в [статусе реализации](00-implementation-status.md).

```text
grim-hollow/
├── README.md
├── .gitignore
├── docs/
│   ├── 01-vision.md ... 12-decisions-and-sources.md
│   └── adr/                              # будущие дополнения к решениям
├── apps/
│   ├── server/
│   │   ├── artisan
│   │   ├── composer.json
│   │   ├── composer.lock
│   │   ├── app/
│   │   │   ├── Modules/
│   │   │   │   ├── Identity/
│   │   │   │   ├── Characters/
│   │   │   │   ├── World/
│   │   │   │   ├── Expeditions/
│   │   │   │   ├── Inventory/
│   │   │   │   ├── Economy/
│   │   │   │   ├── Social/
│   │   │   │   ├── Content/
│   │   │   │   └── Operations/
│   │   │   ├── Console/Commands/
│   │   │   │   ├── ServeWorld.php          # event loop игрового процесса
│   │   │   │   ├── PublishContent.php
│   │   │   │   └── RecoverExpedition.php
│   │   │   └── Providers/
│   │   ├── bootstrap/
│   │   ├── config/
│   │   ├── database/{migrations,factories,seeders}/
│   │   ├── resources/views/admin/
│   │   ├── routes/{api,web,console}.php
│   │   └── tests/{Unit,Feature,Integration}/
│   └── client/
│       ├── GrimHollow.lpr
│       ├── GrimHollow.lpi
│       ├── src/
│       │   ├── App/                       # жизненный цикл приложения
│       │   ├── Platform/                  # окно, ввод, часы, файловые пути
│       │   ├── Net/                       # HTTPS, WSS, reconnect
│       │   ├── State/                     # зеркало видимого мира
│       │   ├── Scenes/                    # вход, город, экспедиция
│       │   ├── Render/                    # тайлы, существа, эффекты, камера
│       │   ├── UI/                        # панели, чат, инвентарь
│       │   ├── Audio/
│       │   ├── Assets/                    # загрузчик и кэш ресурсов
│       │   └── Localization/
│       ├── tests/
│       └── packaging/{linux,windows}/
├── packages/
│   ├── game-core/
│   │   ├── composer.json
│   │   ├── src/
│   │   │   ├── Combat/{Actions,Effects,Damage,Targeting}/
│   │   │   ├── Dungeon/{Generator,Validation,Templates}/
│   │   │   ├── Simulation/{Clock,Commands,Events,Snapshot}/
│   │   │   ├── AI/{States,Navigation,Perception}/
│   │   │   ├── Loot/
│   │   │   └── Random/
│   │   └── tests/{Unit,Properties,Replay}/
│   └── protocol-pascal/
│       ├── src/{Messages,Serialization,Validation}/
│       └── tests/
├── contracts/
│   ├── http/openapi.yaml
│   ├── realtime/v1/{client,server}/
│   ├── content/v1/
│   └── fixtures/{valid,invalid}/
├── content/
│   ├── definitions/
│   │   ├── classes/
│   │   ├── skills/
│   │   ├── effects/
│   │   ├── items/
│   │   ├── affixes/
│   │   ├── enemies/
│   │   ├── biomes/
│   │   ├── rooms/
│   │   ├── loot-tables/
│   │   ├── quests/
│   │   └── recipes/
│   ├── localization/{ru,en}/
│   ├── source-assets/{sprites,tiles,ui,audio}/
│   └── manifests/
├── tools/
│   ├── content-editor/                    # Lazarus-редактор комнат и таблиц
│   ├── content-validator/                 # PHP CLI валидация ссылок и схем
│   ├── load-bot/                          # бот того же протокола
│   └── replay-inspector/                  # просмотр серверного повтора
├── infra/
│   ├── compose/{dev,staging,production}.yaml
│   ├── docker/{php-fpm,world,queue}/Dockerfile
│   ├── nginx/
│   ├── monitoring/{dashboards,alerts}/
│   ├── backup/
│   ├── runbooks/
│   └── env/.env.example
└── tests/
    ├── e2e/
    ├── load/
    ├── recovery/
    └── fixtures/
```

## Внутри серверного модуля

```text
Expeditions/
├── Domain/
│   ├── Expedition.php
│   ├── ExpeditionState.php
│   ├── Events/
│   └── Repositories/ExpeditionRepository.php
├── Application/
│   ├── StartExpedition.php
│   ├── SettleParticipant.php
│   ├── ReconnectParticipant.php
│   └── Ports/
├── Infrastructure/
│   ├── Persistence/MySqlExpeditionRepository.php
│   └── Messaging/OutboxPublisher.php
└── Interfaces/
    ├── Http/
    └── Console/
```

Правила боя находятся в `packages/game-core`, а не дублируются в контроллерах. Клиентская библиотека протокола не содержит формулы урона и право выдачи добычи. Общие контракты независимы от ORM и графического движка.

## Контент как данные

Каждое определение имеет стабильный строковый `id`, версию схемы, ключ локализации и тип. Пример будущего определения умения:

```json
{
  "schema_version": 1,
  "id": "guardian.shield_bash",
  "name_key": "skill.guardian.shield_bash",
  "target": "enemy",
  "range_cells": 1,
  "cast_ticks": 5,
  "cooldown_ticks": 40,
  "resource": {"type": "stamina", "cost": 15},
  "damage": {"type": "physical", "base": 8, "power_coefficient_bp": 6000},
  "effects": [{"id": "stunned", "duration_ticks": 5}]
}
```

Валидатор проверяет схему, диапазоны, ссылки, локализацию, циклы зависимостей и недостижимые цели комнат. Контент компилируется в неизменяемую серверную поставку и публичную клиентскую поставку. Секретные таблицы выпадения, seed и серверные триггеры не входят в публичную часть.

Редактор сохраняет исходные данные в репозиторий, затем изменения проходят review и проверку. Он не редактирует production-БД напрямую. Для сторонних графики, шрифтов, звука и библиотек ведётся реестр авторства и лицензий.
