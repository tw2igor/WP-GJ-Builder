# Spike 3 (Phase 0): dual storage + миграции + freeze-on-failure — вердикт

Дата: 18 июля 2026. Статус: **PASS**, все 3 проверки пройдены на реальном WordPress (WP Playground, PHP 8.3, WordPress latest).

## Что проверялось

1. **Миграционная цепочка** — `MigrationRunner` корректно применяет ступенчатую миграцию `schema_version` 1→2 (переименование поля `blocks` → `sections` как демонстрационный пример реальной будущей миграции).
2. **Контролируемый отказ миграции** — некорректный вход (документ v1 без ожидаемого поля) даёт `ok=false` с сообщением об ошибке, не бросая исключение наружу.
3. **Freeze-on-failure** — умышленно повреждённый (невалидный JSON) `project_json` даёт `status=frozen` от `DocumentRepository::get_for_edit()`, а `post_content` "опубликованной" тестовой страницы остаётся нетронутым.

## Как проверено

Активирован реальный плагин в WP Playground (`npx @wp-playground/cli@latest server --auto-mount --define-bool WP_DEBUG true`), таблица `wp_wpb_documents` создана через `dbDelta` при активации, дев-only REST-маршрут `GET /wp-json/wpgjb/v1/dev/spike3` (гейт `WP_DEBUG` + `manage_options`) прогнал `Spike3Runner::run()` внутри реального WP-процесса. Дополнительно проверен полный цикл деактивации/активации плагина через админку — чисто, без фатальных ошибок.

## Ключевой вывод: freeze-on-failure — структурная гарантия, не отдельный код

Опубликованная страница читает `post_content` напрямую и никогда не обращается к `project_json` — поэтому сбой миграции физически не может сломать то, что видит посетитель. "Заморозка" — это просто то, что `get_for_edit()` возвращает `status=frozen` вместо валидных данных редактирования; сама безопасность живого сайта обеспечивается архитектурой дуального хранения (раздел 3 спеки), а не try/catch в этом конкретном месте. Это подтверждено, а не просто задекларировано.

## Что дальше (Phase 1)

- Схема `wpb_documents`, `MigrationRunner`, `DocumentRepository` из этого спайка становятся реальным кодом Phase 1 без переписывания — это не выброшенный прототип.
- `FakeMigration_1_to_2` в `tests/php/Spikes/Spike3Runner.php` — демонстрационная, будет удалена; реальные миграции появятся в `includes/Storage/Migrations/` по мере эволюции схемы манифеста блока.
- Дев-only REST-маршрут (`includes/Rest/DevSpikeController.php`, гейт `WP_DEBUG`) и весь `tests/php/Spikes/` — удалить перед Phase 7 (приёмка MVP), см. план.
