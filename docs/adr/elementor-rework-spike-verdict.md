# Спайк: эмпирическая проверка допущений перехода на Elementor-стиль — вердикт

Дата: 19 июля 2026. Статус: **PASS**. Проверено в реальном headless Chrome (Puppeteer) на установленной версии `grapesjs@0.22.16` (не смоделировано, не по документации/памяти).

## Методика

Отдельная статическая HTML-страница, подключающая `node_modules/grapesjs/dist/grapes.min.js` напрямую (без WordPress — эти три допущения касаются только ядра GrapesJS, WP не участвует), `editor.setComponents()` с тестовой разметкой, чтение полученных моделей компонентов и `editor.StyleManager.getSectors()`/`getProperty()`.

## Проверено

1. **`data-gjs-droppable="false"` реально блокирует дроп**: тестовые `<div data-slot-repeat="items" data-gjs-droppable="false">` и `<div data-slot-item data-gjs-droppable="false">` — после парсинга `component.get('droppable') === false` на обоих (атрибут потреблён парсером и вырезан из итогового HTML). Подтверждает, что фикс "Обязательный фикс" из плана рабочий.
2. **Дефолтные сектора Style Manager при отсутствии кастомного `sectors`**: `['general', 'flex', 'dimension', 'typography', 'decorations', 'extra']` — стандартные id GrapesJS. `editor.StyleManager.getProperty('decorations', 'background-color')` и `getProperty('typography', 'color')` — оба найдены (не `undefined`). Подтверждает, что удаление кастомного 3-секторного конфига действительно откроет стандартные id, на которые жёстко завязан `@silexlabs/grapesjs-css-variables`.
3. **`<h2>`/`<p>` распознаются нативным типом `text`**: `component.get('type') === 'text'`, `component.get('editable') === true` на обоих. Подтверждает, что "Заголовок"/"Текст"-элементы не требуют своего `Components.addType()` — только регистрация в BlockManager.

## Вывод

Все три допущения плана перехода на Elementor-стиль подтверждены эмпирически до начала основной реализации — можно приступать к `elements.js`/`index.js`/`editor.css` без риска, что фундаментальные механизмы GrapesJS не сработают так, как предполагалось.
