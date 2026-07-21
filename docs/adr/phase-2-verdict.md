# Phase 2: Publish pipeline — статический HTML + динамические теги — вердикт

Дата: 18 июля 2026. Статус: **PASS**, проверено дважды — 5 автоматизированных проверок через дев-only REST (`GET /wpgjb/v1/dev/phase2`) и отдельно вручную полным HTTP-циклом как настоящий анонимный посетитель (без авторизации), оба раза на реальном WordPress (WP Playground, PHP 8.3).

## Что реализовано

- `includes/Render/Publisher.php` — publish-оркестрация: контур-2 санитизация (`Contour2PrePublish`, whitelist `Sanitizer::richness_page()`/`CSS_PROPERTIES_PAGE` — новые, более широкие пресеты для целой страницы, добавлены аддитивно, не меняя уже проверенную в Spike 4 логику) → новая ревизия `project_json` (`DocumentRepository::publish()`) → запись `post_content` через `wp_update_post()` → postmeta `_wpb_built`/`_wpb_page_css` → `do_action('wpgjb_after_publish', ...)`.
- `includes/Render/FrontendRenderer.php` — `the_content` (резолвинг тегов через `BatchResolver`, гейт `_wpb_built`) и `wp_head` (вывод сохранённого CSS страницы).
- `includes/Rest/PublishController.php` — `POST /wpgjb/v1/documents/{post_id}/{type}/publish`, капабилити-гейт переиспользует `DocumentsController::check_permission()` (не дублируется).
- `TagRegistry` расширен с 5 до 13 тегов первой очереди раздела 8 (site: title/description/url/icon; post: title/url/excerpt/date/modified; author: name/url/avatar/bio; + post_meta) — все в рамках уже проверенной 4-группной query-модели, без новых источников данных.

## Осознанно отложено (не забыто)

Featured image (+alt) — не реализован в Phase 2: требует данные ВЛОЖЕНИЯ (другой записи), а не `$post`/автора/опций сайта — потребовал бы пятой группы источника данных с собственной ценой запроса вне уже проверенной модели "0/0/≤1/≤1". Отмечено в `TagRegistry` как явный дизайн-выбор, не пробел по недосмотру.

## Проверено

**Автоматически (5/5, `GET /wpgjb/v1/dev/phase2`):**
1. Публикация: `<script>`, `onload`, CSS `expression()` вычищены контуром 2; легитимные класс/цвет и нерезолвленные плейсхолдеры (резолвятся на фронтенде, не при публикации) сохранены в `post_content`.
2. Реальный зарегистрированный фильтр `the_content` резолвит теги; `wp_head` реально выводит сохранённый CSS.
3. Query-бюджет через реальный хук (не прямой вызов резолвера) — 1 запрос на post+author теги.
4. Ни рендер страницы, ни `wp_head`/`wp_enqueue_scripts` не подключают editor-бандл на фронтенде.
5. `wp_update_post()` реально вызывает `save_post` и `clean_post_cache` — кэш-плагины хостинга получат штатный сигнал.

**Вручную, полный HTTP-цикл как настоящий посетитель** (создание страницы через `wp/v2/pages`, публикация через реальный `POST .../publish`, затем `curl` БЕЗ авторизации на реальный permalink):
```
<section class="hero">
<h1>Real Visitor Test</h1>       ← {{wpb:post_title}} резолвлен
<p>Site: My WordPress Website</p> ← {{wpb:site_title}} резолвлен
<p>alert(1)</section>             ← <script>-теги вычищены, осталась безвредная текстовая строка
```
`<style id="wpb-page-css">.hero { color: navy; }</style>` — в `<head>`. Ни одной ссылки на `assets/build/editor` в выдаче.

## Что дальше (Phase 3)

- Полноэкранный редактор GrapesJS (канвас + тема из Spike 1, Контент/Настройки, автосохранение через Phase 1 REST, публикация через Phase 2 REST) — впервые реальный человек сможет собрать страницу, а не вручную собранный JSON/HTML через curl.
- Дев-only `DevPhase2Controller`/`tests/php/Phase2/` — оставить до Phase 7.
