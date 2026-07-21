/**
 * Манифест-управляемые компоненты GrapesJS (раздел 9 спеки: "форма
 * Контента редактора генерируется итерацией по slots[] — один источник
 * правды"). Архитектура рендера намеренно НЕ полагается на прямую
 * синхронизацию live-DOM canvas'а с моделью GrapesJS: при любом изменении
 * значения слота весь HTML блока пересобирается заново из markup-шаблона
 * + текущих значений и целиком передаётся в `component.components(html)`
 * — это официальный, документированный способ задать/заменить дочерние
 * элементы компонента через повторный разбор HTML (Component Recognition),
 * а не хак поверх внутреннего состояния GrapesJS.
 *
 * Источник правды для значений слотов — JSON в атрибуте
 * `data-wpb-values` самого корневого компонента блока (переживает
 * getProjectData()/loadProjectData() как обычный атрибут).
 */

import { __ } from '@wordpress/i18n';
import { getCachedAttachment, fetchAttachment, applyResolvedImage } from './images';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

/**
 * Разбирает markup-шаблон блока и достаёт значения слотов "как есть" —
 * используется как значения по умолчанию для только что вставленного
 * блока (шаблон уже содержит осмысленный текст-подсказку на русском).
 */
export function extractValuesFromTemplate( markup, manifest ) {
	const root = document.createElement( 'div' );
	root.innerHTML = markup.trim();
	const blockEl = root.firstElementChild;
	return readValues( blockEl, manifest.slots );
}

function readValues( scopeEl, slots ) {
	const values = {};

	for ( const slot of slots ) {
		if ( 'array' === slot.type ) {
			const repeatContainer = findOwnScope( scopeEl, `[data-slot-repeat="${ slot.key }"]` );
			if ( ! repeatContainer ) {
				values[ slot.key ] = [];
				continue;
			}
			// Раздел задачи (переработка блоков): markup.html теперь может
			// нести НЕСКОЛЬКО [data-slot-item] по умолчанию (3/6 карточек —
			// см. blocks-library) — читаем ВСЕ, не только первый, иначе
			// свежевставленный блок всегда схлопывался бы обратно к одному
			// элементу независимо от того, сколько их реально в шаблоне.
			const itemTemplates = findOwnScopeAll( repeatContainer, '[data-slot-item]' );
			values[ slot.key ] = itemTemplates.map( ( itemEl ) => readValues( itemEl, toSlotList( slot.item_schema ) ) );
			continue;
		}

		if ( slot.type === 'link' ) {
			// См. applyValues(): слот-ссылка живёт в data-slot-href="key",
			// не в data-slot="key" — тот же элемент может нести отдельный
			// текстовый слот через data-slot.
			const linkEl = findOwnScope( scopeEl, `[data-slot-href="${ slot.key }"]` );
			values[ slot.key ] = linkEl ? linkEl.getAttribute( 'href' ) || '' : '';
			continue;
		}

		const el = findOwnScope( scopeEl, `[data-slot="${ slot.key }"]` );
		if ( ! el ) {
			values[ slot.key ] = '';
			continue;
		}

		if ( slot.type === 'image' ) {
			values[ slot.key ] = el.getAttribute( 'data-slot-img-id' ) || '';
		} else if ( slot.type === 'richtext' || slot.type === 'raw_html' ) {
			// Симметрично applyValues(): эти типы ЗАПИСЫВАЮТСЯ через innerHTML,
			// поэтому и читаться должны через innerHTML — textContent потерял
			// бы теги/код из плейсхолдера шаблона при первой вставке блока.
			values[ slot.key ] = el.innerHTML || '';
		} else {
			values[ slot.key ] = el.textContent || '';
		}
	}

	return values;
}

function toSlotList( itemSchema ) {
	return Object.keys( itemSchema ).map( ( key ) => ( { key, ...itemSchema[ key ] } ) );
}

/**
 * Как querySelector, но не заходит внутрь вложенных [data-slot-repeat]
 * контейнеров (их слоты обрабатываются отдельно, per-item).
 */
function findOwnScope( scopeEl, selector ) {
	const all = scopeEl.querySelectorAll( selector );
	for ( const el of all ) {
		let node = el.parentElement;
		let insideNestedRepeat = false;
		while ( node && node !== scopeEl ) {
			if ( node.hasAttribute( 'data-slot-repeat' ) ) {
				insideNestedRepeat = true;
				break;
			}
			node = node.parentElement;
		}
		if ( ! insideNestedRepeat ) {
			return el;
		}
	}
	return null;
}

/** Как findOwnScope(), но собирает ВСЕ совпадения своей области видимости, а не только первое — нужно для чтения всех элементов repeat-массива. */
function findOwnScopeAll( scopeEl, selector ) {
	const all = scopeEl.querySelectorAll( selector );
	const results = [];
	for ( const el of all ) {
		let node = el.parentElement;
		let insideNestedRepeat = false;
		while ( node && node !== scopeEl ) {
			if ( node.hasAttribute( 'data-slot-repeat' ) ) {
				insideNestedRepeat = true;
				break;
			}
			node = node.parentElement;
		}
		if ( ! insideNestedRepeat ) {
			results.push( el );
		}
	}
	return results;
}

/**
 * Пересобирает блок из шаблона + текущих значений слотов на detached DOM
 * (не трогает live canvas). Возвращает сам элемент — вызывающий код сам
 * решает, нужен ли `.outerHTML` (вставка нового блока целиком) или
 * `.innerHTML` (замена ТОЛЬКО детей существующего компонента через
 * `component.components()`, см. setComponentValues).
 */
export function renderBlockElement( markup, manifest, values ) {
	const root = document.createElement( 'div' );
	root.innerHTML = markup.trim();
	const blockEl = root.firstElementChild;

	applyValues( blockEl, manifest.slots, values || {} );
	blockEl.setAttribute( 'data-wpb-values', JSON.stringify( values || {} ) );

	return blockEl;
}

/** Полный HTML целого блока (включая корневой тег) — для первой вставки. */
export function renderBlockHtml( markup, manifest, values ) {
	return renderBlockElement( markup, manifest, values ).outerHTML;
}

function applyValues( scopeEl, slots, values ) {
	for ( const slot of slots ) {
		if ( 'array' === slot.type ) {
			const repeatContainer = findOwnScope( scopeEl, `[data-slot-repeat="${ slot.key }"]` );
			if ( ! repeatContainer ) {
				continue;
			}
			const itemTemplate = repeatContainer.querySelector( '[data-slot-item]' );
			if ( ! itemTemplate ) {
				continue;
			}
			const items = Array.isArray( values[ slot.key ] ) ? values[ slot.key ] : [];
			const itemSlots = toSlotList( slot.item_schema );

			repeatContainer.innerHTML = '';
			items.forEach( ( itemValues ) => {
				const clone = itemTemplate.cloneNode( true );
				applyValues( clone, itemSlots, itemValues || {} );
				repeatContainer.appendChild( clone );
			} );
			continue;
		}

		if ( slot.type === 'link' ) {
			// Слот-ссылка биндится к href элемента, помеченного
			// data-slot-href="key" — этот элемент может быть тем же самым,
			// что несёт текстовый data-slot другого слота (напр. кнопка:
			// текст = cta_label, href = cta_link), поэтому ищем отдельным
			// селектором, а не переиспользуем data-slot.
			const linkEl = findOwnScope( scopeEl, `[data-slot-href="${ slot.key }"]` );
			if ( linkEl ) {
				linkEl.setAttribute( 'href', values[ slot.key ] || '#' );
			}
			continue;
		}

		const el = findOwnScope( scopeEl, `[data-slot="${ slot.key }"]` );
		if ( ! el ) {
			continue;
		}

		const value = values[ slot.key ] || '';

		if ( slot.type === 'image' ) {
			el.setAttribute( 'data-slot-img-id', value );
			// Синхронный путь: если вложение уже разрешалось в этой сессии
			// (сразу после выбора в wp.media — модель приходит с полным
			// набором sizes без REST round-trip), применяем немедленно.
			// Иначе — асинхронный догруз см. applyResolvedImagesToComponent(),
			// вызываемый ПОСЛЕ вставки в живое дерево GrapesJS (здесь el ещё
			// detached, менять его после того как innerHTML уже сериализован
			// в component.components() бессмысленно).
			const cached = value ? getCachedAttachment( value ) : null;
			if ( cached ) {
				applyResolvedImage( el, cached, { firstScreen: !! slot.first_screen } );
			} else if ( ! value ) {
				el.removeAttribute( 'src' );
				el.removeAttribute( 'srcset' );
				el.removeAttribute( 'sizes' );
			}
		} else if ( slot.type === 'richtext' || slot.type === 'raw_html' ) {
			// Rich-text/raw_html: допускаем разметку как есть — контур-1 на
			// сервере отфильтрует недопустимое при сохранении (раздел 10,
			// raw_html — только если у пользователя есть капабилити,
			// см. ProjectDataSanitizer::sanitize_raw_html_value), здесь
			// только отображение в canvas.
			el.innerHTML = value;
		} else {
			el.textContent = value;
		}
	}
}

/**
 * Регистрирует один манифест-блок как кастомный тип компонента GrapesJS.
 * `isComponent` — распознавание при парсинге вставляемого HTML (Component
 * Recognition); `stylable` — whitelist свойств из манифеста (П2/П3:
 * никакой свободной стилизации за пределами того, что разрешил дизайнер
 * блока).
 */
/**
 * Панель действий выделенного блока (раздел 5.2: "вверх/вниз/дублировать/
 * скрыть/удалить"). tlb-clone/tlb-delete — штатные команды GrapesJS;
 * move-up/move-down/hide — наши, регистрируются один раз в
 * registerAllBlocks(). Экспортируется — переход на Elementor-стиль
 * переиспользует тот же тулбар для "Элементов" (elements.js), без
 * дублирования разметки/команд.
 */
export const BLOCK_TOOLBAR = [
	{ attributes: { class: 'wpgjb-tlb-icon wpgjb-tlb-icon--up', title: t( 'Вверх' ), 'aria-label': t( 'Вверх' ) }, command: 'wpgjb-move-up' },
	{ attributes: { class: 'wpgjb-tlb-icon wpgjb-tlb-icon--down', title: t( 'Вниз' ), 'aria-label': t( 'Вниз' ) }, command: 'wpgjb-move-down' },
	{ attributes: { class: 'wpgjb-tlb-icon wpgjb-tlb-icon--clone', title: t( 'Дублировать' ), 'aria-label': t( 'Дублировать' ) }, command: 'tlb-clone' },
	{ attributes: { class: 'wpgjb-tlb-icon wpgjb-tlb-icon--hide', title: t( 'Скрыть' ), 'aria-label': t( 'Скрыть' ) }, command: 'wpgjb-toggle-hide' },
	{ attributes: { class: 'wpgjb-tlb-icon wpgjb-tlb-icon--delete', title: t( 'Удалить' ), 'aria-label': t( 'Удалить' ) }, command: 'tlb-delete' },
];

export function registerBlockType( editor, blockId, blockDef ) {
	const { manifest } = blockDef;

	editor.Components.addType( blockId, {
		isComponent( el ) {
			if ( el.getAttribute && el.getAttribute( 'data-wpb-block' ) === blockId ) {
				return { type: blockId };
			}
			return undefined;
		},
		model: {
			defaults: {
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: manifest.style_whitelist || [],
				toolbar: BLOCK_TOOLBAR,
				// Форма "Контент" по слотам манифеста рендерится инлайн в правой
				// панели (см. renderComponentSettingsPanel в index.js) вместо
				// нативных Trait'ов — у "Блоков" своих Trait'ов нет.
				traits: [],
			},
		},
	} );
}

function moveComponent( component, direction ) {
	const collection = component.collection;
	if ( ! collection ) {
		return;
	}
	const index = component.index();
	const newIndex = 'up' === direction ? index - 1 : index + 1;
	if ( newIndex < 0 || newIndex >= collection.length ) {
		return;
	}
	collection.remove( component, { silent: true } );
	collection.add( component, { at: newIndex } );
}

function registerSharedCommands( editor ) {
	if ( editor.Commands.has( 'wpgjb-move-up' ) ) {
		return; // Уже зарегистрированы (registerAllBlocks вызывается один раз за инициализацию).
	}

	editor.Commands.add( 'wpgjb-move-up', {
		run( ed, sender, opts = {} ) {
			moveComponent( opts.component || ed.getSelected(), 'up' );
		},
	} );

	editor.Commands.add( 'wpgjb-move-down', {
		run( ed, sender, opts = {} ) {
			moveComponent( opts.component || ed.getSelected(), 'down' );
		},
	} );

	editor.Commands.add( 'wpgjb-toggle-hide', {
		run( ed, sender, opts = {} ) {
			const component = opts.component || ed.getSelected();
			if ( ! component ) {
				return;
			}
			const hidden = component.getStyle().display === 'none';
			component.addStyle( { display: hidden ? '' : 'none' } );
		},
	} );
}

export function registerAllBlocks( editor, blocks ) {
	registerSharedCommands( editor );
	Object.keys( blocks ).forEach( ( blockId ) => registerBlockType( editor, blockId, blocks[ blockId ] ) );
}

/**
 * Значения слотов текущего выделенного блока — читает
 * `data-wpb-values`, а не пытается развернуть значения из live-DOM
 * (единственный источник правды, см. шапку файла).
 */
export function getComponentValues( component ) {
	const raw = component.getAttributes()[ 'data-wpb-values' ];
	if ( ! raw ) {
		return {};
	}
	try {
		return JSON.parse( raw );
	} catch ( e ) {
		return {};
	}
}

export function setComponentValues( component, blockDef, values ) {
	const rendered = renderBlockElement( blockDef.markup, blockDef.manifest, values );
	// Дети компонента заменяются целиком через официальный API GrapesJS
	// (повторный разбор HTML) — сам корневой компонент НЕ пересоздаётся,
	// только его содержимое; атрибут со значениями обновляется отдельно.
	component.components( rendered.innerHTML );
	component.addAttributes( { 'data-wpb-values': JSON.stringify( values ) } );
	applyResolvedImagesToComponent( component, blockDef.manifest );
}

/** first_screen ищется и среди верхнеуровневых слотов, и внутри item_schema повторяемых (array) слотов. */
function isFirstScreenSlot( manifest, slotKey ) {
	const direct = ( manifest.slots || [] ).find( ( s ) => s.key === slotKey );
	if ( direct ) {
		return !! direct.first_screen;
	}
	for ( const s of manifest.slots || [] ) {
		if ( 'array' === s.type && s.item_schema && s.item_schema[ slotKey ] ) {
			return !! s.item_schema[ slotKey ].first_screen;
		}
	}
	return false;
}

/**
 * Догружает реальные src/srcset/sizes для image-слотов уже ВСТАВЛЕННОГО в
 * живое дерево GrapesJS компонента — асинхронно, через кэш/REST (см.
 * images.js). Нужно вызывать после ЛЮБОЙ операции, которая могла добавить
 * в дерево узел с data-slot-img-id (setComponentValues, вставка нового
 * блока из пикера) — синхронный applyValues() применяет только то, что уже
 * есть в кэше на detached-элементе, который к этому моменту уже сериализован.
 */
export function applyResolvedImagesToComponent( component, manifest ) {
	const bootstrap = window.wpgjbEditorData || {};
	const imageComponents = component.find( '[data-slot-img-id]' );

	imageComponents.forEach( ( imgComponent ) => {
		const attrs = imgComponent.getAttributes();
		const id = attrs[ 'data-slot-img-id' ];
		const slotKey = attrs[ 'data-slot' ];
		if ( ! id ) {
			return;
		}
		const firstScreen = isFirstScreenSlot( manifest, slotKey );

		const cached = getCachedAttachment( id );
		if ( cached ) {
			setImageComponentAttributes( imgComponent, cached, firstScreen );
			return;
		}

		fetchAttachment( id, bootstrap.restRoot, bootstrap.restNonce ).then( ( resolved ) => {
			if ( resolved ) {
				setImageComponentAttributes( imgComponent, resolved, firstScreen );
			}
		} );
	} );
}

function setImageComponentAttributes( component, resolved, firstScreen ) {
	const attrs = { src: resolved.src };
	if ( resolved.srcset ) {
		attrs.srcset = resolved.srcset;
		attrs.sizes = resolved.sizes;
	}
	if ( resolved.alt ) {
		attrs.alt = resolved.alt;
	}
	if ( firstScreen ) {
		attrs.fetchpriority = 'high';
	} else {
		attrs.loading = 'lazy';
	}
	component.addAttributes( attrs );
}

export function getBlockIdForComponent( component ) {
	return component.getAttributes()[ 'data-wpb-block' ] || null;
}

/**
 * Клик по canvas выбирает САМЫЙ ВЛОЖЕННЫЙ компонент под курсором
 * (GrapesJS по умолчанию парсит каждый дочерний элемент markup-шаблона
 * блока — h1/p/a/div — как свой собственный, отдельно выбираемый
 * компонент, а не как "просто разметку" нашего единственного
 * зарегистрированного типа). Раздел 5.2: Контент/Настройки относятся к
 * БЛОКУ целиком, не к его внутренним элементам — поднимаемся по
 * родителям до компонента, который несёт `data-wpb-block`.
 *
 * Осознанно относится ТОЛЬКО к уровню "Блоков" (переход на Elementor-
 * стиль): для свободных "Элементов" (elements.js), у которых нет предка
 * с `data-wpb-block`, эта функция корректно возвращает `null`, и клик
 * выделяет ровно тот компонент, по которому кликнули — оборачивать это
 * отдельной веткой не нужно.
 */
export function resolveBlockRoot( component ) {
	let current = component;
	while ( current ) {
		if ( getBlockIdForComponent( current ) ) {
			return current;
		}
		current = current.parent();
	}
	return null;
}
