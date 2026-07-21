# Spike 1 (Phase 0): наследование стиля темы в canvas GrapesJS — вердикт

Дата: 18 июля 2026. Статус: **PASS**. Прототип: `assets/editor-spike/` (отдельный `package.json`, не влияет на корневой билд плагина).

## Что проверялось

1. Canvas GrapesJS (`canvas.styles`) может загрузить **реальный, не переизобретённый** CSS активной темы WordPress — и для блочной (FSE) темы, и для классической.
2. Токены `theme.json` (`--wp--preset--*`) можно превратить в пикабельные свойства Style Manager через `grapesjs-css-variables`.
3. Визуальное сравнение: рендер в canvas того же самого блока против реального фронтенда того же сайта.

## Методология (что реально было сделано, не просто прочитано в доке)

Поднят `npx @wp-playground/cli@latest server --auto-mount --port=9400` (WP core, PHP 8.3, активная тема **Twenty Twenty-Five** — блочная/FSE). Залогинен как `admin`/`password` (дефолт Playground), получен REST-нонс со страницы `post-new.php`, через `POST /wp-json/wp/v2/pages` реально опубликована страница `spike-1-hero` с блочной разметкой (`wp:group`/`wp:heading`/`wp:paragraph`/`wp:buttons`), использующей реальные слаги палитры темы (`accent-4`, `contrast`, `base` — взяты из `wp-content/themes/twentytwentyfive/theme.json`, не придуманы). Реальный фронтенд-рендер этой страницы (`http://127.0.0.1:9400/spike-1-hero/`) заскриншочен headless Chrome — это и есть контрольный образец ("что видит посетитель").

Дальше та же самая опубликованная HTML-разметка (взята дословно из view-source фронтенда, не перепечатана вручную) и три реальных CSS-источника этого же сайта загружены в независимый GrapesJS-прототип:
- `wp-includes/css/dist/block-library/style.min.css` (core, реальный статический файл),
- `wp-includes/css/dist/block-library/theme.min.css` (core, реальный статический файл),
- `wp-content/themes/twentytwentyfive/style.css` (сама тема, реальный статический файл),
- содержимое `<style id="global-styles-inline-css">` (это и есть `wp_get_global_stylesheet()` — WordPress отдаёт его инлайново в `<head>`, а не отдельным файлом) — извлечено `curl`+regex и сохранено байт-в-байт как `theme-fixtures/twentytwentyfive-global-styles-inline.css`.

Для классической темы: **в этом Playground-инстансе не было установлено ни одной классической темы** (WP по умолчанию поставляет только последние блочные темы — twentytwentyfive/twentytwentyfour/twentytwentythree). Реальный `style.css` **Twenty Twenty-One** получен напрямую с `raw.githubusercontent.com/WordPress/twentytwentyone` — тоже байт-в-байт, не написан руками. Полного цикла "опубликовать страницу → заскриншотить реальный фронтенд" для классической темы не было (нет живого сайта на этой теме в сессии); визуальная проверка — статическая (та же реальная CSS, тот же принцип разметки блоков, без REST-публикации). Это единственное отклонение от методологии блочной темы, и оно не подрывает вывод: цель спайка — доказать, что canvas способен загрузить и применить реальный CSS темы, а не воспроизвести полный publish-пайплайн (это Phase 2/3).

## Результат 1: визуальный паритет (блочная тема) — подтверждён

Скриншоты (в `assets/editor-spike/screenshots/`):
- `wp-real-frontend-twentytwentyfive.png` — реальный фронтенд WordPress (Twenty Twenty-Five): чёрный фон `contrast`, белый текст `base`, серая пилюля-кнопка `accent-4`, тема-шрифт Manrope.
- `grapesjs-canvas-twentytwentyfive-basic.png` — тот же самый блок в GrapesJS canvas, той же самой версткой и теми же CSS-файлами: **визуально неотличимо** — тот же чёрный фон, тот же белый текст, та же серая пилюля, тот же шрифт.
- `grapesjs-canvas-twentytwentyone-classic.png` — Twenty Twenty-One (классическая тема): чёрный фон, реальная кнопка темы (закруглённый прямоугольник, тёмно-серый), другой шрифт — тоже реальный CSS, тоже корректно применяется в canvas.

Вывод: canvas.styles с массивом реальных URL — это не приближение, а тот же самый CSS-каскад, что видит браузер посетителя. П5 спеки ("по умолчанию — стиль темы") и критерий приёмки №2 раздела 15 ("страница в редакторе и опубликованная страница визуально идентичны") — технически достижимы этим механизмом уже сейчас, без доработок ядра.

## Результат 2: theme.json-токены в Style Manager — подтверждён, но НЕ автоматически

Ключевая находка, прямо предсказанная `builder-analysis.md` §8.2 ("Style Manager не знает про theme.json... но это решаемая задача, а не автоматика"): **`grapesjs-css-variables` не сканирует загруженные в canvas стили сам**. Он управляет только переменными, явно зарегистрированными в его собственном API (`presets` опция / `css-var:set` команда), которые пишутся во **внутреннее** `:root`-правило самого GrapesJS (`editor.Css.setRule(':root', ...)`), а не читаются из реального `--wp--preset--*` в iframe.

Понадобился мост (`assets/editor-spike/theme-tokens.js`): чистый парсер текста CSS, извлекающий `--wp--preset--color--*`/`--font-size--*`/`--font-family--*`/`--spacing--*` из реального сгенерированного `wp_get_global_stylesheet()` и классифицирующий их в `{name, value, type: 'color'|'size'|'font-family'}`. Результат на Twenty Twenty-Five: **34 токена** (20 цветов, 12 размеров [5 font-size + 7 spacing], 2 семейства шрифтов) — все они появились как реальные пикабельные свойства Style Manager (скриншот `grapesjs-canvas-twentytwentyfive-with-tokens.png`: маленькие иконки-карандаши рядом с Font family/Font size/Color/Background color открывают дропдаун с именами токенов темы).

### Подводный камень №1 (важно для Phase 3): `presets` тихо не применяется без Storage Manager

Опция `pluginsOpts['@silexlabs/grapesjs-css-variables'].presets` документирована как "Pre-defined variables for first load", но код (`src/variables.js`, `applyPresets()`) реально вызывается только на событии `'storage:end:load'` — части жизненного цикла Storage Manager. Если `storageManager: false` (как в этом спайке — нет бэкенда для сохранения) или автозагрузка выключена, **это событие никогда не срабатывает, и presets молча игнорируются** — ни ошибки, ни предупреждения. Обошли вручную: сразу после `grapesjs.init()` сами сделали то же самое, что делает `applyPresets()` — `editor.Css.setRule(':root', {...})` + `editor.getModel().set('cssVarOrder', [...])` (см. `editor.js`). **Phase 3 должен либо держать Storage Manager включённым с реальной начальной загрузкой, либо явно повторить этот обходной путь** — иначе токены в UI просто не появятся, без единого сообщения об ошибке в консоли.

### Подводный камень №2: пакет в npm называется не так, как в плане/спеке

И план (`development-plan.md` п.6), и спека (раздел 13), и корневой `package.json` называют пакет `grapesjs-css-variables`. **Такого пакета не существует на npm** (`npm error 404`). Реальное имя — **`@silexlabs/grapesjs-css-variables`** (последняя версия `0.1.0`, помечена deprecated в пользу будущего Silex-монорепозитория, но устанавливается и работает). **Корневой `package.json` плагина нужно поправить перед Phase 3** — сейчас там указана несуществующая зависимость.

### Классическая тема: ожидаемый (не провальный) результат

Twenty Twenty-One не имеет `theme.json` → ноль `--wp--preset--*` переменных → `theme-tokens.js` возвращает пустой массив (`grapesjs-canvas-twentytwentyone-classic.png`: баннер "0 theme.json tokens found (expected...)"). При этом у темы **есть** собственная палитра (`get_theme_support('editor-color-palette')`: black/blue/dark-gray/gray/green/orange/purple/red/white/yellow), но она реализована как **захардкоженные CSS-классы с литеральными hex** (`.has-black-color{color:#000}`), а не CSS custom properties — значит её принципиально нельзя достать тем же текстовым парсером CSS. Это ровно то расхождение, которое план (раздел 6) предвидел: "Токены — через `wp_get_global_settings()` (блочные темы) с фолбэком на `get_theme_support(...)` для классических". Для классических тем Phase 3 понадобится **второй, отдельный источник токенов** — не парсинг CSS-текста, а чтение PHP-массива `get_theme_support('editor-color-palette'/'editor-font-sizes')` через REST-эндпоинт (текстовый CSS-парсер здесь бесполезен в принципе, а не просто недоработан).

## Конфиг для повторного использования в Phase 3

### `canvas.styles`

```js
canvas: {
  styles: [
    // Порядок важен: core → theme → global-styles (переменные должны быть
    // объявлены раньше, чем на них ссылаются через var()).
    includesUrl('css/dist/block-library/style.min.css'),
    includesUrl('css/dist/block-library/theme.min.css'),
    getStylesheetDirectoryUri() + '/style.css',
    // wp_get_global_stylesheet() не публикуется как статический файл —
    // нужен небольшой REST-эндпоинт, оборачивающий его с кэш-заголовками:
    restUrl('wpb/v1/theme-global-styles.css'),
  ],
}
```

### `grapesjs-css-variables`

```js
import cssVariablesPlugin from '@silexlabs/grapesjs-css-variables'; // ВАЖНО: scoped-имя, не "grapesjs-css-variables"

const editor = grapesjs.init({
  // ...
  plugins: [cssVariablesPlugin],
  pluginsOpts: {
    [cssVariablesPlugin]: {
      enableColors: true,
      enableSizes: true,
      enableTypography: true,
      presets: themeTokens, // {name, value, type}[] — см. theme-tokens.js
    },
  },
});

// ОБЯЗАТЕЛЬНО, если Storage Manager не гарантирует 'storage:end:load' на
// старте (см. "Подводный камень №1") — иначе presets не применятся:
const rootStyle = {};
for (const t of themeTokens) rootStyle[`--${t.name}`] = t.value;
editor.Css.setRule(':root', rootStyle);
editor.getModel().set('cssVarOrder', themeTokens.map(t => ({ name: t.name, type: t.type })));
```

### Парсер токенов

`assets/editor-spike/theme-tokens.js` — `extractWpPresetTokens(cssText)`, переносится в Phase 3 как есть (чистая функция, без зависимостей от DOM/GrapesJS). Классификация: `color`→`color`, `font-size`/`spacing`→`size`, `font-family`→`font-family`; `gradient`/`aspect-ratio` пропускаются — под них у этой версии плагина нет секции Style Manager.

## Что дальше (Phase 3)

- Использовать конфиг выше как отправную точку `assets/editor/` — прототип это не выброшенный код, а рабочий чертёж интеграции.
- Поправить корневой `package.json`: `grapesjs-css-variables` → `@silexlabs/grapesjs-css-variables`.
- Реализовать REST-эндпоинт `wpb/v1/theme-global-styles.css`, оборачивающий `wp_get_global_stylesheet()` (с ETag/кэшем — вызывается на каждой загрузке редактора).
- Реализовать второй источник токенов для классических тем: REST-эндпоинт над `get_theme_support('editor-color-palette'/'editor-font-sizes')`, отдельный от `theme-tokens.js`.
- Не забыть про "Подводный камень №1" (presets + Storage Manager lifecycle) при реальной интеграции с `includes/Storage/`.
- `assets/editor-spike/` — не удалять раньше Phase 7 (как и другие дев-спайки), но это уже не блокирующий тестовый код, а справочный прототип.
