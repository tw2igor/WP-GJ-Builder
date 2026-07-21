/**
 * "Элементы" — интерактивный/анимационный подраздел (обратный отсчёт,
 * увеличивающийся счётчик, галерея, слайдер). В отличие от статичных
 * Элементов (elements.js), эти четыре несут отметку `data-wpgjb-*` в
 * корневом DOM-узле — тот же признак, что PageAssets::has_interactive_elements()
 * (PHP, публикация) ищет в project_data, чтобы решить, нужно ли подключать
 * общий фронтенд-рантайм `assets/runtime/wpgjb-elements-runtime.js`
 * (см. includes/Render/PageAssets.php, includes/Render/Publisher.php,
 * includes/Render/FrontendRenderer.php) — параллельный, не-манифестный
 * путь к уже существующему per-block asset-пайплайну (Блоки резолвятся
 * через data-wpb-block/BlockLibrary, у свободных Элементов такого
 * контракта нет).
 *
 * В canvas'е эти компоненты рендерятся статичным превью (без тикающего
 * таймера/анимации) — реальное поведение появляется только на
 * опубликованном фронтенде через рантайм-скрипт, тот же принцип "рендер
 * HTML один раз при публикации", что и у остального проекта.
 */

import { __ } from '@wordpress/i18n';
import { BLOCK_TOOLBAR } from './blocks';
import { cacheFromMediaModel } from './images';
import { elementCategory, icon } from './elements';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

function escapeAttr( s ) {
	return String( s == null ? '' : s )
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}

const COUNTDOWN_UNITS = [
	{ key: 'days', label: t( 'Дней' ) },
	{ key: 'hours', label: t( 'Часов' ) },
	{ key: 'minutes', label: t( 'Минут' ) },
	{ key: 'seconds', label: t( 'Секунд' ) },
];

function defaultCountdownTarget() {
	const target = new Date();
	target.setDate( target.getDate() + 7 );
	return target.toISOString().slice( 0, 16 );
}

/**
 * Кастомный Trait-тип: нативный `<input type="datetime-local">` вместо
 * ручного ввода ISO-строки — стандартный документированный паттерн
 * GrapesJS TraitManager.addType() (createInput/onEvent/onUpdate).
 */
function registerDatetimeTraitType( editor ) {
	editor.TraitManager.addType( 'datetime', {
		createInput() {
			const el = document.createElement( 'input' );
			el.type = 'datetime-local';
			return el;
		},
		onEvent( { elInput, component, trait } ) {
			component.addAttributes( { [ trait.get( 'name' ) ]: elInput.value } );
		},
		onUpdate( { elInput, component, trait } ) {
			elInput.value = component.getAttributes()[ trait.get( 'name' ) ] || '';
		},
	} );
}

function registerCountdownType( editor ) {
	editor.Components.addType( 'wpgjb-countdown', {
		isComponent( el ) {
			if ( el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-countdown' ) ) {
				return { type: 'wpgjb-countdown' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'div',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: {
					'data-wpgjb-countdown': 'true',
					'data-wpgjb-countdown-target': defaultCountdownTarget(),
				},
				style: { display: 'flex', gap: '16px' },
				traits: [
					{
						type: 'datetime',
						name: 'data-wpgjb-countdown-target',
						label: t( 'Дата и время окончания' ),
					},
				],
				components: COUNTDOWN_UNITS.map( ( unit ) => ( {
					tagName: 'div',
					style: { display: 'flex', 'flex-direction': 'column', 'align-items': 'center', 'min-width': '56px' },
					components: [
						{
							tagName: 'span',
							attributes: { 'data-wpgjb-countdown-unit': unit.key },
							style: { 'font-size': '28px', 'font-weight': '700', 'line-height': '1' },
							components: '00',
						},
						{
							tagName: 'span',
							style: { 'font-size': '12px', 'text-transform': 'uppercase', opacity: '0.7' },
							components: unit.label,
						},
					],
				} ) ),
			},
		},
	} );
}

function registerCounterType( editor ) {
	editor.Components.addType( 'wpgjb-counter', {
		isComponent( el ) {
			if ( el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-counter' ) ) {
				return { type: 'wpgjb-counter' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'div',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: { 'data-wpgjb-counter': 'true' },
				style: { 'font-size': '40px', 'font-weight': '700' },
				traits: [
					{ type: 'number', name: 'targetValue', label: t( 'Конечное число' ), changeProp: true },
					{ type: 'number', name: 'duration', label: t( 'Длительность анимации (мс)' ), changeProp: true },
					{ type: 'text', name: 'suffix', label: t( 'Суффикс (например: %, +)' ), changeProp: true },
				],
				targetValue: 500,
				duration: 2000,
				suffix: '',
			},
			init() {
				this.on( 'change:targetValue change:duration change:suffix', this.onValueChange );
				this.onValueChange();
			},
			onValueChange() {
				const target = this.get( 'targetValue' ) || 0;
				const duration = this.get( 'duration' ) || 2000;
				const suffix = this.get( 'suffix' ) || '';
				this.addAttributes( {
					'data-wpgjb-counter-target': String( target ),
					'data-wpgjb-counter-duration': String( duration ),
				} );
				this.components(
					`<span data-wpgjb-counter-value="true">${ target }</span><span>${ escapeAttr( suffix ) }</span>`
				);
			},
		},
	} );
}

function pickMultipleImages( onSelect ) {
	if ( ! window.wp || ! window.wp.media ) {
		return;
	}
	const frame = window.wp.media( {
		title: t( 'Выбрать изображения' ),
		button: { text: t( 'Использовать' ) },
		multiple: true,
	} );
	frame.on( 'select', () => {
		const selection = frame.state().get( 'selection' ).models;
		const resolved = selection.map( ( model ) => {
			const attachment = model.toJSON();
			return cacheFromMediaModel( attachment.id, attachment );
		} );
		onSelect( resolved );
	} );
	frame.open();
}

function registerGalleryType( editor ) {
	editor.Commands.add( 'wpgjb-pick-gallery-images', {
		run( ed ) {
			const component = ed.getSelected();
			if ( ! component ) {
				return;
			}
			pickMultipleImages( ( images ) => {
				const html = images
					.map(
						( img ) =>
							`<img src="${ img.src }" alt="${ escapeAttr( img.alt ) }" loading="lazy" data-wpgjb-gallery-item="true" style="width:100%;height:160px;object-fit:cover;border-radius:4px;cursor:pointer;display:block">`
					)
					.join( '' );
				component.components( html );
			} );
		},
	} );

	editor.Components.addType( 'wpgjb-gallery', {
		isComponent( el ) {
			if ( el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-gallery' ) ) {
				return { type: 'wpgjb-gallery' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'div',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: { 'data-wpgjb-gallery': 'true' },
				style: { display: 'grid', 'grid-template-columns': 'repeat(auto-fill, minmax(160px, 1fr))', gap: '12px' },
				traits: [
					{ type: 'button', text: t( 'Выбрать изображения…' ), full: true, command: 'wpgjb-pick-gallery-images' },
				],
			},
		},
	} );
}

function registerSliderType( editor ) {
	editor.Commands.add( 'wpgjb-pick-slider-images', {
		run( ed ) {
			const component = ed.getSelected();
			if ( ! component ) {
				return;
			}
			const track = component.find( '[data-wpgjb-slider-track]' )[ 0 ];
			const dots = component.find( '[data-wpgjb-slider-dots]' )[ 0 ];
			if ( ! track || ! dots ) {
				return;
			}
			pickMultipleImages( ( images ) => {
				const slidesHtml = images
					.map(
						( img ) =>
							`<div data-wpgjb-slide="true" style="flex:0 0 100%"><img src="${ img.src }" alt="${ escapeAttr( img.alt ) }" loading="lazy" style="width:100%;height:360px;object-fit:cover;display:block"></div>`
					)
					.join( '' );
				const dotsHtml = images
					.map(
						( img, i ) =>
							`<button type="button" data-wpgjb-slider-dot="true" data-index="${ i }" aria-label="${ t( 'Слайд' ) } ${ i + 1 }" style="width:8px;height:8px;border-radius:50%;border:none;background:rgba(255,255,255,0.6);cursor:pointer;padding:0"></button>`
					)
					.join( '' );
				track.components( slidesHtml );
				dots.components( dotsHtml );
			} );
		},
	} );

	editor.Components.addType( 'wpgjb-slider', {
		isComponent( el ) {
			if ( el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-slider' ) && ! el.getAttribute( 'data-wpgjb-slider-variant' ) ) {
				return { type: 'wpgjb-slider' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'div',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: { 'data-wpgjb-slider': 'true', 'data-wpgjb-slider-index': '0' },
				style: { position: 'relative', overflow: 'hidden', 'min-height': '200px', background: '#eee' },
				traits: [
					{ type: 'button', text: t( 'Выбрать изображения…' ), full: true, command: 'wpgjb-pick-slider-images' },
				],
				components: [
					{
						tagName: 'div',
						attributes: { 'data-wpgjb-slider-track': 'true' },
						droppable: false,
						style: { display: 'flex', transition: 'transform 0.4s ease' },
					},
					{
						tagName: 'button',
						attributes: { 'data-wpgjb-slider-prev': 'true', type: 'button', 'aria-label': t( 'Предыдущий слайд' ) },
						style: {
							position: 'absolute',
							top: '50%',
							left: '8px',
							transform: 'translateY(-50%)',
							'z-index': '2',
							border: 'none',
							background: 'rgba(0,0,0,0.5)',
							color: '#fff',
							'border-radius': '50%',
							width: '36px',
							height: '36px',
							cursor: 'pointer',
						},
						components: '‹',
					},
					{
						tagName: 'button',
						attributes: { 'data-wpgjb-slider-next': 'true', type: 'button', 'aria-label': t( 'Следующий слайд' ) },
						style: {
							position: 'absolute',
							top: '50%',
							right: '8px',
							transform: 'translateY(-50%)',
							'z-index': '2',
							border: 'none',
							background: 'rgba(0,0,0,0.5)',
							color: '#fff',
							'border-radius': '50%',
							width: '36px',
							height: '36px',
							cursor: 'pointer',
						},
						components: '›',
					},
					{
						tagName: 'div',
						attributes: { 'data-wpgjb-slider-dots': 'true' },
						droppable: false,
						style: {
							position: 'absolute',
							bottom: '8px',
							left: '0',
							right: '0',
							display: 'flex',
							'justify-content': 'center',
							gap: '6px',
							'z-index': '2',
						},
					},
				],
			},
		},
	} );
}

export const INTERACTIVE_ELEMENT_DEFS = [
	{ id: 'wpgjb-el-countdown', label: t( 'Обратный отсчёт' ), type: 'wpgjb-countdown', icon: 'countdown', group: 'interactive' },
	{ id: 'wpgjb-el-counter', label: t( 'Счётчик (анимированный)' ), type: 'wpgjb-counter', icon: 'counter', group: 'interactive' },
	{ id: 'wpgjb-el-gallery', label: t( 'Галерея изображений' ), type: 'wpgjb-gallery', icon: 'gallery', group: 'media' },
	{ id: 'wpgjb-el-slider', label: t( 'Слайдер изображений' ), type: 'wpgjb-slider', icon: 'slider', group: 'media' },
];

export const INTERACTIVE_ELEMENT_IDS = INTERACTIVE_ELEMENT_DEFS.map( ( def ) => def.id );

export function registerInteractiveElementBlocks( editor ) {
	registerDatetimeTraitType( editor );
	registerCountdownType( editor );
	registerCounterType( editor );
	registerGalleryType( editor );
	registerSliderType( editor );

	INTERACTIVE_ELEMENT_DEFS.forEach( ( def ) => {
		editor.BlockManager.add( def.id, {
			label: def.label,
			category: elementCategory( def.group ),
			media: icon( def.icon, 28 ),
			content: { type: def.type },
		} );
	} );
}
