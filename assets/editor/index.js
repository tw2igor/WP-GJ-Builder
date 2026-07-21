import grapesjs from 'grapesjs';
import grapesjsRuLocale from 'grapesjs/locale/ru';
import { __ } from '@wordpress/i18n';
import gjsPresetWebpage from 'grapesjs-preset-webpage';
import cssVariablesPlugin from '@silexlabs/grapesjs-css-variables';
import 'grapesjs/dist/css/grapes.min.css';
import './editor.css';

import * as api from './api';
import {
	registerAllBlocks,
	extractValuesFromTemplate,
	renderBlockHtml,
	getBlockIdForComponent,
	resolveBlockRoot,
	applyResolvedImagesToComponent,
} from './blocks';
import { registerElementBlocks, ELEMENT_BLOCK_IDS } from './elements';
import { registerInteractiveElementBlocks, INTERACTIVE_ELEMENT_IDS } from './elements-interactive';
import { registerInfographicElementBlocks, INFOGRAPHIC_ELEMENT_IDS } from './elements-infographic';
import { registerContentElementBlocks, CONTENT_ELEMENT_IDS } from './elements-content';
import { registerCodeblockElementBlocks, CODEBLOCK_ELEMENT_IDS } from './elements-codeblock';
import { registerTemplateBlocks, TEMPLATE_ID_PREFIX } from './templates';
import { renderContentForm } from './content-form';
import { openPageSettingsModal } from './page-settings';
import { initLock } from './lock';

// GrapesJS зависит от backbone@1.4.1; Backbone's UMD-обёртка БЕЗУСЛОВНО
// пишет себя в window.Backbone при выполнении модуля (см. backbone.js —
// это не webpack-специфика, так устроен сам исходник), поэтому наш бандл
// молча подменяет собой window.Backbone ядра WP (обычно свежее — 1.6.x),
// на который опирается wp.media() (Медиатека): в результате модалка
// открывается, но её content-регион (AttachmentsBrowser) не рендерится —
// найдено реальным прогоном (Puppeteer), не по коду. GrapesJS использует
// СВОЮ импортированную ссылку на Backbone внутри бандла и НЕ читает
// window.Backbone повторно после старта — поэтому откат глобала сразу
// после загрузки модуля безопасен и не затрагивает сам редактор.
if ( window.Backbone && typeof window.Backbone.noConflict === 'function' ) {
	window.Backbone.noConflict();
}

const bootstrap = window.wpgjbEditorData;
const blocks = bootstrap.blocks || {};
const templates = bootstrap.templates || {};

const root = document.getElementById( 'wpgjb-editor-root' );
root.innerHTML = `
	<div class="wpgjb-shell">
		<div class="wpgjb-status-strip">
			<a class="wpgjb-status-strip__back" href="${ bootstrap.adminUrl }">&larr; ${ t( 'Назад' ) }</a>
			<span class="wpgjb-status-strip__title">${ escapeHtml( bootstrap.postTitle ) }</span>
			<span class="wpgjb-save-indicator" id="wpgjb-save-indicator" role="status" aria-live="polite">${ t( 'Сохранено' ) }</span>
			${ bootstrap.previewUrl ? `<a class="wpgjb-status-strip__preview" href="${ bootstrap.previewUrl }" target="_blank" rel="noreferrer">${ t( 'Просмотр' ) }</a>` : '' }
			<button type="button" class="wpgjb-lang-switch" id="wpgjb-lang-switch" title="${ t( 'Язык интерфейса редактора' ) }">${ ( bootstrap.uiLang || 'ru' ).toUpperCase() }</button>
		</div>
		<div class="wpgjb-lock-banner" id="wpgjb-lock-banner" role="alert" hidden></div>
		<div class="wpgjb-workspace">
			<aside class="wpgjb-left-panel" aria-label="${ t( 'Элементы, блоки и страницы' ) }">
				<div class="wpgjb-panel-tabs" id="wpgjb-left-tabs" role="tablist">
					<button type="button" class="active" data-tab="elements" role="tab" aria-selected="true">${ t( 'Элементы' ) }</button>
					<button type="button" data-tab="blocks" role="tab" aria-selected="false">${ t( 'Блоки' ) }</button>
					<button type="button" data-tab="pages" role="tab" aria-selected="false">${ t( 'Страницы' ) }</button>
				</div>
				<div class="wpgjb-panel-body" id="wpgjb-blocks-panel"></div>
			</aside>
			<div id="wpgjb-gjs-root"></div>
			<aside class="wpgjb-right-panel" aria-label="${ t( 'Свойства и настройки' ) }">
				<div class="wpgjb-panel-tabs" id="wpgjb-right-tabs" role="tablist">
					<button type="button" class="active" data-tab="style" role="tab" aria-selected="true">${ t( 'Свойства' ) }</button>
					<button type="button" data-tab="traits" role="tab" aria-selected="false">${ t( 'Настройки компонента' ) }</button>
					<button type="button" data-tab="layers" role="tab" aria-selected="false">${ t( 'Слои' ) }</button>
				</div>
				<div class="wpgjb-panel-body" id="wpgjb-style-panel"></div>
				<div class="wpgjb-panel-body" id="wpgjb-traits-panel" hidden>
					<div id="wpgjb-traits-native"></div>
					<div id="wpgjb-content-form" hidden></div>
				</div>
				<div class="wpgjb-panel-body" id="wpgjb-layers-panel" hidden></div>
			</aside>
		</div>
		<input type="file" id="wpgjb-import-file" accept="application/json" hidden>
	</div>
`;

/**
 * Раздел 13 спеки: "язык интерфейса — русский и английский с самого
 * начала (i18n-готовность всех строк)". `@wordpress/i18n` — тот же
 * механизм, что и остальное ядро WP, подключается как внешняя
 * зависимость (см. webpack.config.js/dependency-extraction) и требует
 * со стороны PHP `wp_set_script_translations()` (см.
 * EditorPage::maybe_enqueue()).
 */
function t( s ) {
	return __( s, 'wp-gj-builder' );
}

function escapeHtml( s ) {
	const div = document.createElement( 'div' );
	div.textContent = String( s == null ? '' : s );
	return div.innerHTML;
}

/**
 * Переключатель языка интерфейса редактора — короткий query-параметр
 * `wpgjb_lang`, который EditorPage::resolve_ui_lang() читает на сервере
 * (переключает и PHP-, и JS-переводы через switch_to_locale() ДО
 * wp_set_script_translations()/render_page(), см. includes/Admin/
 * EditorPage.php). Клиент не хранит выбор языка сам — источник правды
 * всегда URL, перезагрузка страницы обязательна (переводы — не то, что
 * можно перезагрузить точечно без полного цикла enqueue).
 */
function setupLangSwitch() {
	const btn = document.getElementById( 'wpgjb-lang-switch' );
	if ( ! btn ) {
		return;
	}
	btn.addEventListener( 'click', () => {
		const url = new URL( window.location.href );
		const next = 'en' === ( bootstrap.uiLang || 'ru' ) ? 'ru' : 'en';
		url.searchParams.set( 'wpgjb_lang', next );
		window.location.href = url.toString();
	} );
}
setupLangSwitch();

/** Раздел 6 спеки: библиотека блоков организована по смыслу, не по типам разметки. */
const SECTION_TYPE_LABELS = {
	hero: t( 'Обложка' ),
	about: t( 'О компании' ),
	features: t( 'Преимущества' ),
	services: t( 'Услуги/Товары' ),
	pricing: t( 'Цены' ),
	testimonials: t( 'Отзывы' ),
	team: t( 'Команда' ),
	gallery: t( 'Галерея' ),
	faq: t( 'FAQ' ),
	steps: t( 'Этапы работы' ),
	cta: t( 'CTA' ),
	contacts: t( 'Контакты' ),
	header: t( 'Шапка' ),
	footer: t( 'Подвал' ),
	stats: t( 'Цифры и факты' ),
	logos: t( 'Клиенты и партнёры' ),
	video: t( 'Видео' ),
	misc: t( 'Разное' ),
};

/** Раздел 6: "библиотека организована по смыслу" — метка категории BlockManager. */
function categoryLabel( sectionType ) {
	return SECTION_TYPE_LABELS[ sectionType ] || sectionType;
}

const editor = grapesjs.init( {
	container: '#wpgjb-gjs-root',
	height: '100%',
	fromElement: false,
	storageManager: false,
	i18n: {
		// GrapesJS's own strings (block/style/layer manager chrome) follow
		// the same `uiLang` the server resolved (`?wpgjb_lang=`/site locale,
		// see EditorPage::resolve_ui_lang()) — GrapesJS ships an English
		// catalogue built-in, only 'ru' needs an imported locale file.
		locale: bootstrap.uiLang || 'ru',
		localeFallback: 'en',
		detectLocale: false,
		messages: { ru: grapesjsRuLocale },
	},
	canvas: {
		styles: bootstrap.canvasStyles || [],
	},
	deviceManager: {
		devices: [
			{ name: 'desktop', width: '' },
			{ name: 'tablet', width: '768px', widthMedia: '992px' },
			{ name: 'mobile', width: '375px', widthMedia: '575px' },
		],
	},
	blockManager: { appendTo: '#wpgjb-blocks-panel' },
	styleManager: { appendTo: '#wpgjb-style-panel' },
	traitManager: { appendTo: '#wpgjb-traits-native' },
	layerManager: { appendTo: '#wpgjb-layers-panel' },
	plugins: [ gjsPresetWebpage, cssVariablesPlugin ],
	pluginsOpts: {
		[ gjsPresetWebpage ]: {
			blocks: [],
			useCustomTheme: false,
			modalImportTitle: t( 'Импорт HTML' ),
			modalImportButton: t( 'Импортировать' ),
			modalImportLabel: t( 'Вставьте HTML-код ниже и нажмите «Импортировать»' ),
		},
		[ cssVariablesPlugin ]: {
			enableColors: true,
			enableSizes: true,
			enableTypography: true,
		},
	},
} );

editor.Panels.removePanel( 'views' );

/**
 * Раздел задачи: "добавь в текстовый редактор списки для элемента 'текст'".
 * GrapesJS's RichTextEditor toolbar (появляется при двойном клике на любом
 * `type: 'text'` компоненте — Заголовок/Текст среди "Элементов", см.
 * elements.js) по умолчанию не содержит команд списка — только
 * bold/italic/underline/strike/link. `rte.exec()` — официальный тонкий
 * враппер над `document.execCommand()`, тот же путь, что и штатные кнопки.
 * Действует на ЛЮБОЙ RTE-редактируемый текст, но `rte:enable`-guard выше
 * (resolveBlockRoot) уже гарантирует, что текст ВНУТРИ "Блоков" никогда не
 * получает нативный RTE вообще — эти кнопки реально всплывают только у
 * свободных "Элементов" Заголовок/Текст, как и просили.
 */
editor.RichTextEditor.add( 'wrapUl', {
	icon: '<b>&#8226;</b>',
	attributes: { title: t( 'Маркированный список' ) },
	result: ( rte ) => rte.exec( 'insertUnorderedList' ),
} );
editor.RichTextEditor.add( 'wrapOl', {
	icon: '<b>1.</b>',
	attributes: { title: t( 'Нумерованный список' ) },
	result: ( rte ) => rte.exec( 'insertOrderedList' ),
} );

function setupLeftPanelTabs( ed ) {
	const TAB_PREDICATES = {
		elements: ( id ) =>
			ELEMENT_BLOCK_IDS.includes( id ) ||
			INTERACTIVE_ELEMENT_IDS.includes( id ) ||
			INFOGRAPHIC_ELEMENT_IDS.includes( id ) ||
			CONTENT_ELEMENT_IDS.includes( id ) ||
			CODEBLOCK_ELEMENT_IDS.includes( id ),
		pages: ( id ) => id.startsWith( TEMPLATE_ID_PREFIX ),
		blocks: ( id ) =>
			! ELEMENT_BLOCK_IDS.includes( id ) &&
			! INTERACTIVE_ELEMENT_IDS.includes( id ) &&
			! INFOGRAPHIC_ELEMENT_IDS.includes( id ) &&
			! CONTENT_ELEMENT_IDS.includes( id ) &&
			! CODEBLOCK_ELEMENT_IDS.includes( id ) &&
			! id.startsWith( TEMPLATE_ID_PREFIX ),
	};
	const tabsEl = document.getElementById( 'wpgjb-left-tabs' );
	const panelEl = document.getElementById( 'wpgjb-blocks-panel' );
	let activeTab = 'elements';

	function applyFilter() {
		const predicate = TAB_PREDICATES[ activeTab ];
		const filtered = ed.BlockManager.getAll().filter( ( block ) => predicate( block.get( 'id' ) ) );
		ed.BlockManager.render( filtered );
		panelEl.classList.toggle( 'wpgjb-blocks-panel--grid', 'elements' === activeTab );
	}

	tabsEl.addEventListener( 'click', ( event ) => {
		const btn = event.target.closest( '[data-tab]' );
		if ( ! btn ) {
			return;
		}
		activeTab = btn.dataset.tab;
		tabsEl.querySelectorAll( 'button' ).forEach( ( b ) => {
			const isActive = b === btn;
			b.classList.toggle( 'active', isActive );
			b.setAttribute( 'aria-selected', String( isActive ) );
		} );
		applyFilter();
	} );

	applyFilter();
}

function setupRightPanelTabs() {
	const CONTAINERS = {
		style: 'wpgjb-style-panel',
		traits: 'wpgjb-traits-panel',
		layers: 'wpgjb-layers-panel',
	};
	const tabsEl = document.getElementById( 'wpgjb-right-tabs' );

	tabsEl.addEventListener( 'click', ( event ) => {
		const btn = event.target.closest( '[data-tab]' );
		if ( ! btn ) {
			return;
		}
		const activeTab = btn.dataset.tab;
		tabsEl.querySelectorAll( 'button' ).forEach( ( b ) => {
			const isActive = b === btn;
			b.classList.toggle( 'active', isActive );
			b.setAttribute( 'aria-selected', String( isActive ) );
		} );
		Object.keys( CONTAINERS ).forEach( ( key ) => {
			document.getElementById( CONTAINERS[ key ] ).hidden = key !== activeTab;
		} );
	} );
}

registerAllBlocks( editor, blocks );
registerElementBlocks( editor );
registerInteractiveElementBlocks( editor );
registerInfographicElementBlocks( editor );
registerContentElementBlocks( editor );
registerCodeblockElementBlocks( editor );
registerBlocksInBlockManager( editor, blocks );
registerTemplateBlocks( editor, blocks, templates );

setupLeftPanelTabs( editor );
setupRightPanelTabs();

/**
 * Регистрирует существующие 26 составных "Блоков" как реальные записи
 * BlockManager (drag-and-drop) — `content` строится один раз через уже
 * существующие `extractValuesFromTemplate`/`renderBlockHtml`, категории —
 * по `SECTION_TYPE_LABELS`. Коэкзистирует без конфликтов с 3 стоковыми
 * блоками пресета (BlockManager-категории аддитивны).
 */
function blockDisplayName( blockId ) {
	return blockId
		.replace( /^test-/, '' )
		.split( '-' )
		.map( ( word ) => word.charAt( 0 ).toUpperCase() + word.slice( 1 ) )
		.join( ' ' );
}

function registerBlocksInBlockManager( ed, allBlocks ) {
	Object.keys( allBlocks ).forEach( ( blockId ) => {
		const blockDef = allBlocks[ blockId ];
		const manifest = blockDef.manifest;
		const defaultValues = extractValuesFromTemplate( blockDef.markup, manifest );
		const html = renderBlockHtml( blockDef.markup, manifest, defaultValues );
		ed.BlockManager.add( blockId, {
			label: `<strong>${ escapeHtml( blockDisplayName( blockId ) ) }</strong><span>${ escapeHtml( manifest.purpose || '' ) }</span>`,
			category: { id: `wpgjb-cat-${ manifest.section_type }`, label: categoryLabel( manifest.section_type ), open: false },
			content: html,
		} );
	} );
}

// Вердикт спайка 1: presets опция cssVariablesPlugin молча не применяется
// без реального цикла Storage Manager (мы его не используем — своё REST
// хранилище). Обходной путь — тот же, что и в спайке: применить токены
// напрямую через editor.Css + cssVarOrder, вне зависимости от presets.
api.fetchThemeTokens().then( ( tokens ) => {
	if ( ! tokens.length ) {
		return;
	}
	const rootStyle = {};
	tokens.forEach( ( token ) => {
		rootStyle[ `--${ token.name }` ] = token.value;
	} );
	editor.Css.setRule( ':root', rootStyle );
	editor.getModel().set(
		'cssVarOrder',
		tokens.map( ( token ) => ( { name: token.name, type: token.type } ) )
	);
} );

editor.on( 'load', () => {
	api.fetchThemeChrome().then( ( chrome ) => {
		if ( ! chrome.isBlockTheme || ( ! chrome.header && ! chrome.footer ) ) {
			return;
		}
		const iframe = document.querySelector( '#wpgjb-gjs-root iframe' );
		if ( ! iframe || ! iframe.contentDocument ) {
			return;
		}
		const doc = iframe.contentDocument;
		const wrapperEl = editor.getWrapper().getEl();

		const chromeStyle = doc.createElement( 'style' );
		chromeStyle.textContent = '.wpgjb-theme-chrome { pointer-events: none; }';
		doc.head.appendChild( chromeStyle );

		if ( chrome.header ) {
			const headerEl = doc.createElement( 'div' );
			headerEl.className = 'wpgjb-theme-chrome wpgjb-theme-chrome--header';
			headerEl.innerHTML = chrome.header;
			doc.body.insertBefore( headerEl, wrapperEl );
		}
		if ( chrome.footer ) {
			const footerEl = doc.createElement( 'div' );
			footerEl.className = 'wpgjb-theme-chrome wpgjb-theme-chrome--footer';
			footerEl.innerHTML = chrome.footer;
			doc.body.appendChild( footerEl );
		}
	} );
} );

/**
 * Раздел задачи: "сразу видно, как будут выглядеть элементы со стилями
 * темы, включая фон" — многие классические темы задают фон/типографику
 * через правила, завязанные на классы `body_class()` (`.home`, `.page`,
 * `.page-template-X`), не через голый `body{...}`; без этих классов на
 * canvas'овом `<body>` такие правила темы просто ни на что не совпадают.
 * Прямое присваивание className — никакой перестройки DOM, независимо
 * от chrome-инжекции выше (специально держим отдельно от неё).
 */
editor.on( 'load', () => {
	if ( ! bootstrap.bodyClass ) {
		return;
	}
	const iframe = document.querySelector( '#wpgjb-gjs-root iframe' );
	if ( ! iframe || ! iframe.contentDocument ) {
		return;
	}
	iframe.contentDocument.body.className = `${ iframe.contentDocument.body.className } ${ bootstrap.bodyClass }`.trim();
} );

// ---- Загрузка документа ----
if ( 'frozen' === bootstrap.document.status ) {
	showFrozenNotice( bootstrap.document.error );
} else if ( bootstrap.document.projectData ) {
	editor.loadProjectData( bootstrap.document.projectData );
}

let currentDocVersion = bootstrap.document.docVersion;

function showFrozenNotice( error ) {
	const banner = document.getElementById( 'wpgjb-lock-banner' );
	banner.hidden = false;
	banner.textContent = `${ t( 'Документ повреждён и не может быть открыт для редактирования' ) }: ${ error }`;
	banner.classList.add( 'wpgjb-lock-banner--error' );
}

/**
 * Клик по canvas выбирает САМЫЙ ВЛОЖЕННЫЙ компонент под курсором —
 * поднимаемся по родителям до компонента, который несёт `data-wpb-block`,
 * чтобы toolbar/Trait Manager относились к БЛОКУ целиком, не к его
 * внутренним элементам. Для свободных "Элементов" (нет предка с
 * data-wpb-block) resolveBlockRoot корректно возвращает null — эта
 * ветка тогда не срабатывает, выделяется ровно то, по чему кликнули.
 */
editor.on( 'component:selected', ( component ) => {
	const rootComponent = resolveBlockRoot( component );
	if ( rootComponent && rootComponent !== component ) {
		editor.select( rootComponent );
		return;
	}
	renderComponentSettingsPanel( component );
} );

editor.on( 'component:deselected', () => renderComponentSettingsPanel( null ) );

/**
 * Правая панель "Настройки компонента" показывает ОДНО из двух: для
 * "Блоков" (есть data-wpb-block) — форму "Контент" по слотам манифеста
 * (renderContentForm), инлайн вместо прежней Modal-попапа; для свободных
 * "Элементов" и во всех остальных случаях — нативный Trait Manager
 * (смонтирован в #wpgjb-traits-native при инициализации, appendTo не
 * переустанавливается повторно — переключение только через hidden/скрытие
 * соответствующего контейнера-соседа).
 */
function renderComponentSettingsPanel( component ) {
	const nativeEl = document.getElementById( 'wpgjb-traits-native' );
	const contentEl = document.getElementById( 'wpgjb-content-form' );
	const blockId = component && getBlockIdForComponent( component );
	const blockDef = blockId && blocks[ blockId ];

	if ( blockDef ) {
		nativeEl.hidden = true;
		contentEl.hidden = false;
		contentEl.innerHTML = '';
		renderContentForm( component, blockDef, contentEl, onContentChanged );
	} else {
		contentEl.hidden = true;
		contentEl.innerHTML = '';
		nativeEl.hidden = false;
	}
}

/**
 * Найдено эмпирически (реальный прогон в WP Playground, не по документации):
 * GrapesJS активирует inline RTE по dblclick НА ЛЮБОМ компоненте типа
 * `text` НЕЗАВИСИМО от `component:selected`/resolveBlockRoot — заголовки/
 * абзацы ВНУТРИ существующих "Блоков" (`<h2 data-slot="title">` и т.п.)
 * парсятся тем же нативным `text`-типом, что и свободные "Элементы", и
 * без этого guard'а получили бы редактируемый contenteditable в обход
 * формы "Контент" (рассинхронизация с `data-wpb-values`).
 *
 * `view.disableEditing()`, вызванный СИНХРОННО внутри обработчика
 * `rte:enable`, не побеждает — GrapesJS выставляет `contentEditable`
 * ПОСЛЕ события, в рамках того же синхронного потока (подтверждено
 * прогоном: `contentEditable` сразу после синхронного вызова всё ещё
 * `"true"`). Откладывание на следующий тик (`setTimeout(…, 0)`) даёт
 * `disableEditing()` выполниться уже ПОСЛЕ того, как GrapesJS закончил
 * штатное включение — единственный рабочий вариант, подтверждено прогоном.
 */
editor.on( 'rte:enable', ( view ) => {
	const component = view && view.model;
	if ( ! component ) {
		return;
	}
	const rootComponent = resolveBlockRoot( component );
	if ( rootComponent && rootComponent !== component ) {
		setTimeout( () => {
			if ( 'function' === typeof view.disableEditing ) {
				view.disableEditing();
			}
		}, 0 );
	}
} );

// ---- Автосохранение (раздел 5.5: троттлинг 15-30с, по изменениям) ----
let saveTimer = null;
let saving = false;
let locked = false;

function setSaveIndicator( state ) {
	const el = document.getElementById( 'wpgjb-save-indicator' );
	const labels = {
		unsaved: t( 'Есть несохранённые изменения…' ),
		saving: t( 'Сохранение…' ),
		saved: t( 'Сохранено' ),
		error: t( 'Ошибка — повторим через 20 сек' ),
		publishing: t( 'Публикация…' ),
	};
	el.textContent = labels[ state ] || '';
	el.className = `wpgjb-save-indicator wpgjb-save-indicator--${ state }`;
}

function onContentChanged() {
	if ( locked ) {
		return;
	}
	setSaveIndicator( 'unsaved' );
	scheduleAutosave();
}

editor.on( 'component:styleUpdate', () => onContentChanged() );
editor.on( 'component:add', ( component ) => {
	// Догруз реальных src/srcset для image-слотов нужен для ЛЮБОГО пути
	// добавления компонента с data-wpb-block (drag, paste, "Страницы",
	// undo/redo) — не только при вставке одного блока.
	const blockId = getBlockIdForComponent( component );
	if ( blockId && blocks[ blockId ] ) {
		applyResolvedImagesToComponent( component, blocks[ blockId ].manifest );
	}
	onContentChanged();
} );
editor.on( 'component:remove', () => onContentChanged() );

function scheduleAutosave() {
	if ( saveTimer ) {
		return;
	}
	saveTimer = setTimeout( () => {
		saveTimer = null;
		doSave();
	}, 20000 );
}

function doSave() {
	if ( locked ) {
		return;
	}
	if ( saving ) {
		scheduleAutosave();
		return;
	}
	saving = true;
	setSaveIndicator( 'saving' );

	api.saveDraft( editor.getProjectData(), currentDocVersion ).then( ( { status, data } ) => {
		saving = false;

		if ( 409 === status ) {
			showConflictPrompt( data );
			return;
		}

		if ( 200 === status && data && 'ok' === data.status ) {
			currentDocVersion = data.doc_version;
			setSaveIndicator( 'saved' );
			return;
		}

		setSaveIndicator( 'error' );
		scheduleAutosave();
	} );
}

function showConflictPrompt( data ) {
	const banner = document.getElementById( 'wpgjb-lock-banner' );
	banner.hidden = false;
	banner.classList.add( 'wpgjb-lock-banner--conflict' );
	banner.innerHTML = '';

	const text = document.createElement( 'span' );
	text.textContent = t( 'Кто-то ещё сохранил эту страницу, пока вы редактировали. Ваши изменения ещё не сохранены.' );
	banner.appendChild( text );

	const loadTheirs = document.createElement( 'button' );
	loadTheirs.type = 'button';
	loadTheirs.className = 'wpgjb-button wpgjb-button--ghost';
	loadTheirs.textContent = t( 'Загрузить их версию (потерять мои правки)' );
	loadTheirs.addEventListener( 'click', () => {
		if ( data.server_project_data ) {
			editor.loadProjectData( data.server_project_data );
		}
		currentDocVersion = data.doc_version;
		banner.hidden = true;
		banner.classList.remove( 'wpgjb-lock-banner--conflict' );
		setSaveIndicator( 'saved' );
	} );

	const keepMine = document.createElement( 'button' );
	keepMine.type = 'button';
	keepMine.className = 'wpgjb-button wpgjb-button--primary';
	keepMine.textContent = t( 'Сохранить мою версию поверх' );
	keepMine.addEventListener( 'click', () => {
		currentDocVersion = data.doc_version; // принять их версию как базовую и перезаписать
		banner.hidden = true;
		banner.classList.remove( 'wpgjb-lock-banner--conflict' );
		doSave();
	} );

	banner.appendChild( loadTheirs );
	banner.appendChild( keepMine );
}

// ---- Публикация, экспорт/импорт JSON — кнопки в панели "options" пресета ----
editor.Commands.add( 'wpgjb-publish', {
	run() {
		if ( locked ) {
			return;
		}
		setSaveIndicator( 'publishing' );

		const projectData = editor.getProjectData();
		const html = editor.getHtml();
		const css = editor.getCss();

		api.publish( projectData, html, css ).then( ( { status, data } ) => {
			if ( 200 === status ) {
				setSaveIndicator( 'saved' );
				// Раздел 7: публикация части сайта — явное сообщение о том, что
				// изменение затрагивает другие страницы, не тихое обновление.
				if ( data && data.cascade ) {
					showCascadeNotice( data.cascade );
				}
			} else {
				setSaveIndicator( 'error' );
			}
		} );
	},
} );

/**
 * Экспорт/импорт страницы в файл — project_data уже чистый сериализуемый
 * JSON по замыслу. Отдельно от стокового "Import" пресета (тот вставляет
 * произвольный HTML в canvas через `setComponents()`, без какой-либо
 * санитизации внутри самого пресета — наш контур 1/2 при save/publish
 * всё равно прогоняет результат независимо от источника, см.
 * includes/Sanitize/). Наш экспорт/импорт — полный раунд-трип
 * project_data (JSON), другая задача (бэкап/перенос страницы), не
 * вставка HTML-фрагмента.
 */
editor.Commands.add( 'wpgjb-export-json', {
	run() {
		const data = editor.getProjectData();
		const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		const datestamp = new Date().toISOString().slice( 0, 10 );
		link.href = url;
		link.download = `wpb-${ bootstrap.type }-${ bootstrap.postId }-${ datestamp }.json`;
		document.body.appendChild( link );
		link.click();
		link.remove();
		URL.revokeObjectURL( url );
	},
} );

editor.Commands.add( 'wpgjb-import-json', {
	run() {
		document.getElementById( 'wpgjb-import-file' ).click();
	},
} );

editor.Commands.add( 'wpgjb-open-page-settings', {
	run( ed ) {
		openPageSettingsModal( ed, bootstrap, ( { title, slug, status, featuredMedia, featuredMediaUrl, pageTemplate } ) => {
			bootstrap.postTitle = title;
			bootstrap.postSlug = slug;
			bootstrap.postStatus = status;
			bootstrap.featuredMedia = featuredMedia;
			bootstrap.featuredMediaUrl = featuredMediaUrl;
			document.querySelector( '.wpgjb-status-strip__title' ).textContent = title;
			if ( bootstrap.pageTemplates ) {
				bootstrap.pageTemplates.current = pageTemplate;
			}
		} );
	},
} );

document.getElementById( 'wpgjb-import-file' ).addEventListener( 'change', ( event ) => {
	const file = event.target.files[ 0 ];
	event.target.value = ''; // сброс — повторный выбор того же файла должен снова вызвать change.
	if ( ! file ) {
		return;
	}

	const reader = new FileReader();
	reader.onload = () => {
		let parsed;
		try {
			parsed = JSON.parse( reader.result );
		} catch ( e ) {
			window.alert( t( 'Файл повреждён или не является корректным JSON.' ) );
			return;
		}
		if ( ! parsed || ! Array.isArray( parsed.pages ) ) {
			window.alert( t( 'Файл не похож на экспортированную страницу конструктора (нет ожидаемой структуры pages).' ) );
			return;
		}
		if ( ! window.confirm( t( 'Заменить текущее содержимое страницы данными из файла? Текущие несохранённые изменения будут потеряны.' ) ) ) {
			return;
		}
		editor.loadProjectData( parsed );
		onContentChanged();
	};
	reader.readAsText( file );
} );

editor.Panels.addButton( 'options', {
	id: 'wpgjb-publish',
	className: 'fa fa-cloud-upload',
	command: 'wpgjb-publish',
	attributes: { title: t( 'Опубликовать' ) },
} );
editor.Panels.addButton( 'options', {
	id: 'wpgjb-export-json',
	className: 'fa fa-download',
	command: 'wpgjb-export-json',
	attributes: { title: t( 'Экспорт страницы в файл (.json)' ) },
} );
editor.Panels.addButton( 'options', {
	id: 'wpgjb-import-json',
	className: 'fa fa-upload',
	command: 'wpgjb-import-json',
	attributes: { title: t( 'Импорт страницы из файла (.json)' ) },
} );

if ( 'page' === bootstrap.type ) {
	editor.Panels.addButton( 'options', {
		id: 'wpgjb-page-settings',
		className: 'fa fa-cog',
		command: 'wpgjb-open-page-settings',
		attributes: { title: t( 'Настройки страницы' ) },
	} );
}

function showCascadeNotice( cascade ) {
	const banner = document.getElementById( 'wpgjb-lock-banner' );
	banner.hidden = false;
	banner.classList.remove( 'wpgjb-lock-banner--conflict', 'wpgjb-lock-banner--takeover', 'wpgjb-lock-banner--error' );
	banner.classList.add( 'wpgjb-lock-banner--info' );
	banner.textContent =
		cascade.affected_pages > 0
			? `${ t( 'Обновление применится ко всем затронутым страницам сайта' ) } (${ cascade.affected_pages }).`
			: t( 'Часть опубликована. Пока ни одна страница не подпадает под её условия показа.' );
}

// ---- Блокировка одновременного редактирования (Heartbeat) ----
function showTakeoverBanner( label ) {
	locked = true;
	const banner = document.getElementById( 'wpgjb-lock-banner' );
	banner.hidden = false;
	banner.classList.add( 'wpgjb-lock-banner--takeover' );
	banner.textContent = `${ t( 'Страницу сейчас редактирует' ) } ${ label || t( 'другой пользователь' ) }. ${ t( 'Ваши изменения не будут сохранены.' ) }`;
}

initLock( {
	onTakeover( lockError ) {
		showTakeoverBanner( lockError && lockError.text );
	},
} );

// Проверка на момент открытия редактора (bootstrap.lock.lockedBy) —
// Heartbeat детектит только захват лока ПОСЛЕ того как мы уже открыли
// страницу, этот случай нужен для "уже редактируется кем-то другим
// прямо сейчас".
if ( bootstrap.lock && bootstrap.lock.lockedBy ) {
	showTakeoverBanner( bootstrap.lock.lockedBy.name );
}

// Только для отладки/E2E-проверки (Playground + headless-браузер) —
// не используется никаким продакшен-кодом.
window.__wpgjbEditor = editor;
