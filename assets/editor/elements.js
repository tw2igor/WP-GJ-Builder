/**
 * "Элементы" — переход редактора на Elementor-стиль: свободно компонуемые
 * атомарные компоненты (заголовок/текст/кнопка/картинка/…), в отличие от
 * составных манифест-управляемых "Блоков" (blocks.js). Большинство не
 * требуют своего `Components.addType()` — GrapesJS-ядро уже даёт нужные
 * типы (`text`/`link`/`video`/`image`/generic `default`), проверено
 * эмпирически (см. docs/adr/elementor-rework-spike-verdict.md): `<h2>`/
 * `<p>` распознаются как `text` c `editable:true`, `<img>` — как `image`.
 *
 * У Элементов НЕТ манифеста/слотов и НЕТ ограничения `resolveBlockRoot`
 * (у них нет предка с `data-wpb-block`) — полная нативная стилизация и
 * inline RTE по двойному клику, в отличие от "безопасного" уровня Блоков.
 */

import { __ } from '@wordpress/i18n';
import { resolveBlockRoot, BLOCK_TOOLBAR } from './blocks';
import { cacheFromMediaModel } from './images';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

/**
 * "Элементы" сгруппированы по типу — то же самое, что уже есть у "Блоков"
 * (там группировка по `section_type`, см. index.js: SECTION_TYPE_LABELS/
 * categoryLabel). Единая точка правды и для elements.js, и для
 * elements-interactive.js (там просто импортируют ELEMENT_GROUP_LABELS/
 * elementCategoryId), чтобы категории обоих файлов встали в один и тот же
 * список категорий BlockManager, а не задвоились.
 */
export const ELEMENT_GROUP_LABELS = {
	text: t( 'Текст' ),
	'buttons-links': t( 'Кнопки и ссылки' ),
	media: t( 'Медиа' ),
	forms: t( 'Формы' ),
	layout: t( 'Макет' ),
	interactive: t( 'Интерактив' ),
};

export function elementCategoryId( group ) {
	return `wpgjb-el-cat-${ group }`;
}

export function elementCategory( group ) {
	return { id: elementCategoryId( group ), label: ELEMENT_GROUP_LABELS[ group ] || group, open: false };
}

/**
 * Единый набор path-данных (24x24 viewBox, формат Material Design Icons) —
 * источник ОДНОВРЕМЕННО для превью-иконок BlockManager (media) и для
 * выбираемого набора Trait'а "Иконка" компонента `wpgjb-icon` — не
 * дублируем path дважды.
 */
const ICON_PATHS = {
	heading: 'M2,4V7H7V19H10V7H15V4H2M21,9H12V12H15V19H18V12H21V9Z',
	text: 'M3,3H21V5H3V3M3,7H21V9H3V7M3,11H15V13H3V11M3,15H21V17H3V15M3,19H15V21H3V19Z',
	button: 'M4,6A2,2 0 0,0 2,8V16A2,2 0 0,0 4,18H20A2,2 0 0,0 22,16V8A2,2 0 0,0 20,6H4M4,8H20V16H4V8Z',
	divider: 'M4,11H20V13H4V11Z',
	video: 'M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M10,16.5V7.5L16,12L10,16.5Z',
	container: 'M4,4H20V20H4V4M6,6V18H18V6H6Z',
	columns: 'M4,4H10V20H4V4M14,4H20V20H14V4Z',
	spacer: 'M12,3L8,7H11V17H8L12,21L16,17H13V7H16L12,3Z',
	image: 'M8.5,13.5L11,16.5L14.5,12L19,18H5M21,19V5C21,3.89 20.1,3 19,3H5C3.89,3 3,3.89 3,5V19C3,20.1 3.89,21 5,21H19C20.1,21 21,20.1 21,19Z',
	inputText: 'M4,6H20V8H4V6M4,11H20V13H4V11M4,16H14V18H4V16Z',
	checkbox: 'M19,3H5C3.89,3 3,3.89 3,5V19A2,2 0 0,0 5,21H19A2,2 0 0,0 21,19V5C21,3.89 20.1,3 19,3M19,5V19H5V5H19M17.99,9L16.58,7.58L9.99,14.17L7.41,11.6L6,13L9.99,17L17.99,9Z',
	radio: 'M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20M12,2A10,10 0 0,0 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,7A5,5 0 0,0 7,12A5,5 0 0,0 12,17A5,5 0 0,0 17,12A5,5 0 0,0 12,7Z',
	select: 'M7,10L12,15L17,10H7Z',
	accordion: 'M3,4H21V8H3V4M3,10H21V14H3V10M3,16H21V20H3V16Z',
	check: 'M21,7L9,19L3.5,13.5L4.91,12.09L9,16.17L19.59,5.59L21,7Z',
	close: 'M19,6.41L17.59,5L12,10.59L6.41,5L5,6.41L10.59,12L5,17.59L6.41,19L12,13.41L17.59,19L19,17.59L13.41,12L19,6.41Z',
	heart: 'M12,21.35L10.55,20.03C5.4,15.36 2,12.27 2,8.5C2,5.41 4.42,3 7.5,3C9.24,3 10.91,3.81 12,5.08C13.09,3.81 14.76,3 16.5,3C19.58,3 22,5.41 22,8.5C22,12.27 18.6,15.36 13.45,20.03L12,21.35Z',
	star: 'M12,17.27L18.18,21L16.54,13.97L22,9.24L14.81,8.62L12,2L9.19,8.62L2,9.24L7.45,13.97L5.82,21L12,17.27Z',
	arrowRight: 'M4,11V13H16L10.5,18.5L11.92,19.92L19.84,12L11.92,4.08L10.5,5.5L16,11H4Z',
	phone: 'M6.62,10.79C8.06,13.62 10.38,15.94 13.21,17.38L15.41,15.18C15.69,14.9 16.08,14.82 16.43,14.93C17.55,15.3 18.75,15.5 20,15.5A1,1 0 0,1 21,16.5V20A1,1 0 0,1 20,21A17,17 0 0,1 3,4A1,1 0 0,1 4,3H7.5A1,1 0 0,1 8.5,4C8.5,5.25 8.7,6.45 9.07,7.57C9.18,7.92 9.1,8.31 8.82,8.59L6.62,10.79Z',
	email: 'M20,4H4C2.89,4 2,4.89 2,6V18A2,2 0 0,0 4,20H20A2,2 0 0,0 22,18V6C22,4.89 21.1,4 20,4M20,8L12,13L4,8V6L12,11L20,6V8Z',
	home: 'M10,20V14H14V20H19V12H22L12,3L2,12H5V20H10Z',
	search: 'M9.5,3A6.5,6.5 0 0,1 16,9.5C16,11.11 15.41,12.59 14.44,13.73L14.71,14H15.5L20.5,19L19,20.5L14,15.5V14.71L13.73,14.44C12.59,15.41 11.11,16 9.5,16A6.5,6.5 0 0,1 3,9.5A6.5,6.5 0 0,1 9.5,3M9.5,5C7,5 5,7 5,9.5C5,12 7,14 9.5,14C12,14 14,12 14,9.5C14,7 12,5 9.5,5Z',
	user: 'M12,4A4,4 0 0,1 16,8A4,4 0 0,1 12,12A4,4 0 0,1 8,8A4,4 0 0,1 12,4M12,14C16.42,14 20,15.79 20,18V20H4V18C4,15.79 7.58,14 12,14Z',
	gear: 'M12,15.5A3.5,3.5 0 0,1 8.5,12A3.5,3.5 0 0,1 12,8.5A3.5,3.5 0 0,1 15.5,12A3.5,3.5 0 0,1 12,15.5M19.43,12.97C19.47,12.65 19.5,12.33 19.5,12C19.5,11.67 19.47,11.34 19.43,11L21.54,9.37C21.73,9.22 21.78,8.95 21.66,8.73L19.66,5.27C19.54,5.05 19.27,4.96 19.05,5.05L16.56,6.05C16.04,5.66 15.5,5.32 14.87,5.07L14.5,2.42C14.46,2.18 14.25,2 14,2H10C9.75,2 9.54,2.18 9.5,2.42L9.13,5.07C8.5,5.32 7.96,5.66 7.44,6.05L4.95,5.05C4.73,4.96 4.46,5.05 4.34,5.27L2.34,8.73C2.22,8.95 2.27,9.22 2.46,9.37L4.57,11C4.53,11.34 4.5,11.67 4.5,12C4.5,12.33 4.53,12.65 4.57,12.97L2.46,14.63C2.27,14.78 2.22,15.05 2.34,15.27L4.34,18.73C4.46,18.95 4.73,19.03 4.95,18.95L7.44,17.94C7.96,18.34 8.5,18.68 9.13,18.93L9.5,21.58C9.54,21.82 9.75,22 10,22H14C14.25,22 14.46,21.82 14.5,21.58L14.87,18.93C15.5,18.67 16.04,18.34 16.56,17.94L19.05,18.95C19.27,19.03 19.54,18.95 19.66,18.73L21.66,15.27C21.78,15.05 21.73,14.78 21.54,14.63L19.43,12.97Z',
	menu: 'M3,6H21V8H3V6M3,11H21V13H3V11M3,16H21V18H3V16Z',
	play: 'M8,5.14V19.14L19,12.14L8,5.14Z',
	share: 'M18,16.08C17.24,16.08 16.56,16.38 16.04,16.85L8.91,12.7C8.96,12.47 9,12.24 9,12C9,11.76 8.96,11.53 8.91,11.3L15.96,7.19C16.5,7.69 17.21,8 18,8A3,3 0 0,0 21,5A3,3 0 0,0 18,2A3,3 0 0,0 15,5C15,5.24 15.04,5.47 15.09,5.7L8.04,9.81C7.5,9.31 6.79,9 6,9A3,3 0 0,0 3,12A3,3 0 0,0 6,15C6.79,15 7.5,14.69 8.04,14.19L15.16,18.34C15.11,18.55 15.08,18.77 15.08,19C15.08,20.61 16.39,21.92 18,21.92C19.61,21.92 20.92,20.61 20.92,19A2.92,2.92 0 0,0 18,16.08Z',
	location: 'M12,11.5A2.5,2.5 0 0,1 9.5,9A2.5,2.5 0 0,1 12,6.5A2.5,2.5 0 0,1 14.5,9A2.5,2.5 0 0,1 12,11.5M12,2A7,7 0 0,0 5,9C5,14.25 12,22 12,22C12,22 19,14.25 19,9A7,7 0 0,0 12,2Z',
	countdown: 'M15,1H9V3H15V1M11,14H13V8H11M12,20A7,7 0 0,1 5,13A7,7 0 0,1 12,6A7,7 0 0,1 19,13A7,7 0 0,1 12,20M19.03,7.39L20.45,5.97C20,5.46 19.55,5 19.04,4.56L17.62,6C16.07,4.74 14.12,4 12,4A9,9 0 0,0 3,13A9,9 0 0,0 12,22C17,22 21,17.97 21,13C21,10.88 20.26,8.93 19.03,7.39Z',
	counter: 'M4,17V9H2V7H6V17H4M22,15C22,16.11 21.1,17 20,17H16V15H20V13H17V11H20V9H16V7H20A2,2 0 0,1 22,9V10.5A1.5,1.5 0 0,1 20.5,12A1.5,1.5 0 0,1 22,13.5V15M14,15V17H8V13C8,11.89 8.9,11 10,11H12V9H8V7H12A2,2 0 0,1 14,9V11C14,12.11 13.1,13 12,13H10V15H14Z',
	gallery: 'M22,16V4A2,2 0 0,0 20,2H8A2,2 0 0,0 6,4V16A2,2 0 0,0 8,18H20A2,2 0 0,0 22,16M11,12L13.03,14.71L16,11L20,16H8M2,6V20A2,2 0 0,0 4,22H18V20H4V6H2Z',
	slider: 'M4,4H2V8H4V4M4,16H2V20H4V16M4,10H2V14H4V10M22,4H8A2,2 0 0,0 6,6V18A2,2 0 0,0 8,20H22A2,2 0 0,0 24,18V6A2,2 0 0,0 22,4M22,18H8V6H22V18Z',
	chartBar: 'M22,21H2V3H4V19H6V10H10V19H12V6H16V19H18V14H22V21Z',
	chartLine: 'M16,11.78L20.24,4.45L21.97,5.45L16.74,14.5L10.23,10.75L5.46,19H22V21H2V3H4V17.54L9.5,8L16,11.78Z',
	chartPie: 'M11,2V22C5.9,21.5 2,17.2 2,12C2,6.8 5.9,2.5 11,2M13,2V11H22C21.5,6.2 17.8,2.5 13,2M13,13V22C17.7,21.5 21.5,17.8 22,13H13Z',
	table: 'M5,4H19A2,2 0 0,1 21,6V19A2,2 0 0,1 19,21H5A2,2 0 0,1 3,19V6A2,2 0 0,1 5,4M5,8V12H11V8H5M13,8V12H19V8H13M5,14V18H11V14H5M13,14V18H19V14H13Z',
	tabs: 'M20,3H4A2,2 0 0,0 2,5V19A2,2 0 0,0 4,21H20A2,2 0 0,0 22,19V5A2,2 0 0,0 20,3M11,19H4V12H11V19M11,10H4V5H11V10M20,19H13V12H20V19M20,10H13V5H20V10Z',
	code: 'M14.6,16.6L19.2,12L14.6,7.4L16,6L22,12L16,18L14.6,16.6M9.4,16.6L4.8,12L9.4,7.4L8,6L2,12L8,18L9.4,16.6Z',
	flipcard: 'M15,3L9,9V3H15M9,21V15L15,21H9M20,3H17V5H20V8H22V5A2,2 0 0,0 20,3M22,19V16H20V19H17V21H20A2,2 0 0,0 22,19M2,5V8H4V5H7V3H4A2,2 0 0,0 2,5M4,16H2V19A2,2 0 0,0 4,21H7V19H4V16Z',
	hotspot: 'M12,2C8.13,2 5,5.13 5,9C5,14.25 12,22 12,22C12,22 19,14.25 19,9C19,5.13 15.87,2 12,2M12,11.5A2.5,2.5 0 0,1 9.5,9A2.5,2.5 0 0,1 12,6.5A2.5,2.5 0 0,1 14.5,9A2.5,2.5 0 0,1 12,11.5Z',
	quote: 'M14,17H17L19,13V7H13V13H16M6,17H9L11,13V7H5V13H8L6,17Z',
	// Простые геометрические маркеры (квадраты/треугольники), а не
	// заимствованные из чужой иконочной библиотеки glyph-данные — для
	// списков достаточно узнаваемого различия "маркер/номер", а не точного
	// повторения чужого дизайна.
	listBullet: 'M2,3.5H5V6.5H2Z M8,4H22V6H8Z M2,10.5H5V13.5H2Z M8,11H22V13H8Z M2,17.5H5V20.5H2Z M8,18H22V20H8Z',
	listNumber: 'M2,4L5,5.5L2,7Z M8,4H22V6H8Z M2,11L5,12.5L2,14Z M8,11H22V13H8Z M2,18L5,19.5L2,21Z M8,18H22V20H8Z',
};

export function icon( name, size = 28 ) {
	const path = ICON_PATHS[ name ] || ICON_PATHS.star;
	return `<svg viewBox="0 0 24 24" width="${ size }" height="${ size }"><path fill="currentColor" d="${ path }"></path></svg>`;
}

const ICONS = Object.keys( ICON_PATHS ).reduce( ( acc, name ) => {
	acc[ name ] = icon( name, 28 );
	return acc;
}, {} );

const ICON_ELEMENT_LABELS = {
	check: t( 'Галочка' ),
	close: t( 'Крестик' ),
	heart: t( 'Сердце' ),
	star: t( 'Звезда' ),
	arrowRight: t( 'Стрелка вправо' ),
	phone: t( 'Телефон' ),
	email: t( 'Письмо' ),
	home: t( 'Дом' ),
	search: t( 'Поиск' ),
	user: t( 'Пользователь' ),
	gear: t( 'Настройки' ),
	menu: t( 'Меню' ),
	play: t( 'Воспроизведение' ),
	share: t( 'Поделиться' ),
	location: t( 'Метка на карте' ),
};

/**
 * Раздел задачи: "редактор с учётом стиля темы... применялись стили
 * темы" — как и весь blocks-library (см. INDEX.md/сурвей блоков), любой
 * новый цвет здесь обёрнут в `var( --wp--preset--color--X, #fallback )`,
 * никогда голый hex: если у активной темы есть такой пресет, канвас и
 * фронтенд сразу совпадают с её фирменным цветом; иначе используется тот
 * же fallback, что и раньше — визуальной регрессии нет ни в одном случае.
 */
const THEME_ACCENT = 'var( --wp--preset--color--contrast, #2271b1 )';
const THEME_ACCENT_TEXT = 'var( --wp--preset--color--base, #fff )';
const THEME_BORDER = 'var( --wp--preset--color--accent-4, #949494 )';

const BUTTON_VARIANT_STYLES = {
	primary: { display: 'inline-block', padding: '10px 24px', border: `1px solid ${ THEME_ACCENT }`, 'border-radius': '4px', 'text-decoration': 'none', background: THEME_ACCENT, color: THEME_ACCENT_TEXT },
	outline: { display: 'inline-block', padding: '10px 24px', border: '1px solid currentColor', 'border-radius': '4px', 'text-decoration': 'none', background: 'transparent', color: 'inherit' },
	text: { display: 'inline-block', padding: '4px 0', border: 'none', 'border-radius': '0', 'text-decoration': 'underline', background: 'transparent', color: 'inherit' },
};

/**
 * Стили заданы через `style` в defaults модели (а не через отдельный
 * class-based CSS-файл): GrapesJS сериализует это как обычное per-
 * instance правило в editor.getCss() при публикации — тот же путь, что и
 * любая стилизация пользователя (раздел 6: "маленький per-page файл
 * только с инстанс-переопределениями"). Не требует новой сборки/enqueue
 * для фронтенда.
 */
function elementDefs() {
	return [
		{
			id: 'wpgjb-el-heading',
			group: 'text',
			label: t( 'Заголовок' ),
			media: ICONS.heading,
			content: {
				type: 'text',
				tagName: 'h2',
				components: t( 'Заголовок' ),
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
			},
		},
		{
			id: 'wpgjb-el-text',
			group: 'text',
			label: t( 'Текст' ),
			media: ICONS.text,
			content: {
				type: 'text',
				tagName: 'p',
				components: t( 'Текст абзаца. Дважды кликните, чтобы отредактировать.' ),
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
			},
		},
		{
			id: 'wpgjb-el-button',
			group: 'buttons-links',
			label: t( 'Кнопка' ),
			media: ICONS.button,
			content: { type: 'wpgjb-button' },
		},
		{
			id: 'wpgjb-el-divider',
			group: 'layout',
			label: t( 'Разделитель' ),
			media: ICONS.divider,
			content: {
				tagName: 'hr',
				stylable: true,
				removable: true,
				draggable: true,
				droppable: false,
				toolbar: BLOCK_TOOLBAR,
			},
		},
		{
			id: 'wpgjb-el-video',
			group: 'media',
			label: t( 'Видео' ),
			media: ICONS.video,
			content: {
				type: 'video',
				tagName: 'video',
				attributes: { controls: true },
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { width: '100%' },
			},
		},
		{
			id: 'wpgjb-el-container',
			group: 'layout',
			label: t( 'Контейнер' ),
			media: ICONS.container,
			content: {
				tagName: 'div',
				stylable: true,
				droppable: true,
				draggable: true,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { 'min-height': '80px', padding: '20px' },
			},
		},
		{
			id: 'wpgjb-el-columns',
			group: 'layout',
			label: t( 'Колонки' ),
			media: ICONS.columns,
			content: {
				tagName: 'div',
				stylable: true,
				droppable: false,
				draggable: true,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { display: 'flex', gap: '20px' },
				components: [
					{ tagName: 'div', droppable: true, stylable: true, style: { flex: '1', 'min-height': '60px' } },
					{ tagName: 'div', droppable: true, stylable: true, style: { flex: '1', 'min-height': '60px' } },
				],
			},
		},
		{
			id: 'wpgjb-el-spacer',
			group: 'layout',
			label: t( 'Отступ' ),
			media: ICONS.spacer,
			content: { type: 'spacer' },
		},
		{
			id: 'wpgjb-el-image',
			group: 'media',
			label: t( 'Изображение' ),
			media: ICONS.image,
			content: {
				type: 'image',
				tagName: 'img',
				attributes: { alt: '' },
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { 'max-width': '100%' },
			},
		},
		{
			id: 'wpgjb-el-input-text',
			group: 'forms',
			label: t( 'Поле: текст' ),
			media: ICONS.inputText,
			content: {
				tagName: 'input',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: { type: 'text', placeholder: t( 'Введите текст' ), name: 'field' },
				style: { display: 'block', width: '100%', padding: '8px 10px', border: `1px solid ${ THEME_BORDER }`, 'border-radius': '4px' },
			},
		},
		{
			id: 'wpgjb-el-input-email',
			group: 'forms',
			label: t( 'Поле: email' ),
			media: ICONS.email,
			content: {
				tagName: 'input',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: { type: 'email', placeholder: 'name@example.com', name: 'email' },
				style: { display: 'block', width: '100%', padding: '8px 10px', border: `1px solid ${ THEME_BORDER }`, 'border-radius': '4px' },
			},
		},
		{
			id: 'wpgjb-el-input-textarea',
			group: 'forms',
			label: t( 'Поле: многострочный текст' ),
			media: ICONS.inputText,
			content: {
				tagName: 'textarea',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: { placeholder: t( 'Введите сообщение' ), name: 'message', rows: 4 },
				style: { display: 'block', width: '100%', padding: '8px 10px', border: `1px solid ${ THEME_BORDER }`, 'border-radius': '4px' },
			},
		},
		{
			id: 'wpgjb-el-input-checkbox',
			group: 'forms',
			label: t( 'Чекбокс' ),
			media: ICONS.checkbox,
			content: {
				tagName: 'label',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { display: 'flex', 'align-items': 'center', gap: '8px' },
				components: [
					{ tagName: 'input', attributes: { type: 'checkbox', name: 'agree' } },
					{ type: 'text', tagName: 'span', components: t( 'Согласен с условиями' ) },
				],
			},
		},
		{
			id: 'wpgjb-el-input-radio',
			group: 'forms',
			label: t( 'Переключатель (radio)' ),
			media: ICONS.radio,
			content: {
				tagName: 'label',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { display: 'flex', 'align-items': 'center', gap: '8px' },
				components: [
					{ tagName: 'input', attributes: { type: 'radio', name: 'option' } },
					{ type: 'text', tagName: 'span', components: t( 'Вариант ответа' ) },
				],
			},
		},
		{
			id: 'wpgjb-el-input-select',
			group: 'forms',
			label: t( 'Выпадающий список' ),
			media: ICONS.select,
			content: {
				tagName: 'select',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { display: 'block', width: '100%', padding: '8px 10px', border: `1px solid ${ THEME_BORDER }`, 'border-radius': '4px' },
				components: [
					{ tagName: 'option', attributes: { value: '1' }, components: t( 'Вариант 1' ) },
					{ tagName: 'option', attributes: { value: '2' }, components: t( 'Вариант 2' ) },
				],
			},
		},
		{
			id: 'wpgjb-el-accordion',
			group: 'interactive',
			label: t( 'Аккордеон' ),
			media: ICONS.accordion,
			content: {
				tagName: 'details',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				attributes: { open: true },
				style: { border: `1px solid ${ THEME_BORDER }`, 'border-radius': '4px', padding: '12px 16px', 'margin-bottom': '8px' },
				components: [
					{ tagName: 'summary', type: 'text', stylable: true, style: { cursor: 'pointer', 'font-weight': '600' }, components: t( 'Вопрос или заголовок' ) },
					{ tagName: 'div', type: 'text', stylable: true, style: { 'margin-top': '8px' }, components: t( 'Ответ или содержимое аккордеона.' ) },
				],
			},
		},
		{
			id: 'wpgjb-el-icon',
			group: 'media',
			label: t( 'Иконка' ),
			media: ICONS.star,
			content: { type: 'wpgjb-icon' },
		},
		{
			id: 'wpgjb-el-link-email',
			group: 'buttons-links',
			label: t( 'Email-ссылка' ),
			media: ICONS.email,
			content: {
				type: 'link',
				tagName: 'a',
				attributes: { href: 'mailto:info@example.com' },
				components: 'info@example.com',
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
			},
		},
		{
			id: 'wpgjb-el-link-phone',
			group: 'buttons-links',
			label: t( 'Телефон-ссылка' ),
			media: ICONS.phone,
			content: {
				type: 'link',
				tagName: 'a',
				attributes: { href: 'tel:+70000000000' },
				components: '+7 (000) 000-00-00',
				stylable: true,
				toolbar: BLOCK_TOOLBAR,
			},
		},
		{
			id: 'wpgjb-el-list-bullet',
			group: 'text',
			label: t( 'Список (маркированный)' ),
			media: ICONS.listBullet,
			content: {
				tagName: 'ul',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { 'padding-left': '20px', margin: '0' },
				components: [ 1, 2, 3 ].map( ( n ) => listTextItem( 'li', n ) ),
			},
		},
		{
			id: 'wpgjb-el-list-number',
			group: 'text',
			label: t( 'Список (нумерованный)' ),
			media: ICONS.listNumber,
			content: {
				tagName: 'ol',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { 'padding-left': '20px', margin: '0' },
				components: [ 1, 2, 3 ].map( ( n ) => listTextItem( 'li', n ) ),
			},
		},
		{
			id: 'wpgjb-el-list-check',
			group: 'text',
			label: t( 'Список с чекбоксами' ),
			media: ICONS.checkbox,
			content: {
				tagName: 'ul',
				stylable: true,
				draggable: true,
				droppable: false,
				removable: true,
				toolbar: BLOCK_TOOLBAR,
				style: { 'list-style': 'none', padding: '0', margin: '0' },
				components: [ 1, 2, 3 ].map( ( n ) => iconListItem( 'check', n ) ),
			},
		},
		{
			id: 'wpgjb-el-list-icon',
			group: 'text',
			label: t( 'Список с иконками' ),
			media: ICONS.star,
			content: { type: 'wpgjb-icon-list' },
		},
	];
}

/** Пункт `<li>` маркированного/нумерованного списка — редактируемый текст (двойной клик), нумерация/маркеры — заслуга самого `<ul>`/`<ol>`, не отдельных стилей. */
function listTextItem( tagName, n ) {
	return {
		tagName,
		type: 'text',
		style: { 'margin-bottom': '6px' },
		components: `${ t( 'Пункт списка' ) } ${ n }`,
	};
}

/**
 * Пункт списка с иконкой слева (чекбокс-список — фиксированная иконка
 * `check`; список с иконками — трейт `iconName` у `wpgjb-icon-list` меняет
 * ТОЛЬКО первый дочерний `<span>` (саму иконку) у каждого `<li>`, текстовый
 * `<span>` не трогается — иначе смена иконки стирала бы уже отредактированный
 * пользователем текст пункта.
 */
function iconListItem( iconName, n ) {
	return {
		tagName: 'li',
		style: { display: 'flex', 'align-items': 'center', gap: '8px', 'margin-bottom': '8px' },
		components: [
			{ tagName: 'span', style: { display: 'flex', flex: '0 0 auto', color: THEME_ACCENT }, components: icon( iconName, 18 ) },
			{ type: 'text', tagName: 'span', components: `${ t( 'Пункт списка' ) } ${ n }` },
		],
	};
}

export const ELEMENT_BLOCK_IDS = elementDefs().map( ( def ) => def.id );

function registerSpacerType( editor ) {
	editor.Components.addType( 'spacer', {
		isComponent( el ) {
			if ( el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-spacer' ) ) {
				return { type: 'spacer' };
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
				stylable: false,
				attributes: { 'data-wpgjb-spacer': 'true' },
				style: { height: '40px' },
				toolbar: BLOCK_TOOLBAR,
				traits: [
					{
						type: 'number',
						name: 'height',
						label: t( 'Высота (px)' ),
						changeProp: true,
					},
				],
				height: 40,
			},
			init() {
				this.on( 'change:height', this.onHeightChange );
			},
			onHeightChange() {
				this.addStyle( { height: `${ this.get( 'height' ) }px` } );
			},
		},
	} );
}

/**
 * Кнопка с переключаемым визуальным вариантом (раздел задачи: "разные...
 * типы ссылок... и др." — Elementor-стиль набор пресетов кнопки вместо
 * одного жёстко заданного вида). Стиль варианта задаётся ЦЕЛИКОМ (все
 * ключи одного набора свойств во всех вариантах) — `addStyle()` мёржит
 * поверх текущего стиля компонента, а не заменяет его целиком, поэтому
 * при переключении варианта важно перезаписывать одинаковый набор
 * свойств, иначе значения предыдущего варианта останутся "залипшими".
 */
function registerButtonType( editor ) {
	editor.Components.addType( 'wpgjb-button', {
		isComponent( el ) {
			if ( el.tagName === 'A' && el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-button' ) ) {
				return { type: 'wpgjb-button' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'a',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				editable: true,
				attributes: { href: '#', 'data-wpgjb-button': 'true' },
				toolbar: BLOCK_TOOLBAR,
				components: t( 'Кнопка' ),
				traits: [
					{
						type: 'select',
						name: 'variant',
						label: t( 'Вариант' ),
						changeProp: true,
						options: [
							{ id: 'primary', name: t( 'Основная' ) },
							{ id: 'outline', name: t( 'Контурная' ) },
							{ id: 'text', name: t( 'Текстовая ссылка' ) },
						],
					},
					{
						type: 'text',
						name: 'href',
						label: t( 'Ссылка (URL)' ),
					},
				],
				variant: 'primary',
			},
			init() {
				this.on( 'change:variant', this.onVariantChange );
				this.onVariantChange();
			},
			onVariantChange() {
				const variant = this.get( 'variant' ) || 'primary';
				this.addStyle( BUTTON_VARIANT_STYLES[ variant ] || BUTTON_VARIANT_STYLES.primary );
			},
		},
	} );
}

/**
 * Trait "Иконка" — changeProp-свойство `iconName` (не HTML-атрибут):
 * персистентность обеспечивает обычная сериализация модели в
 * project_data (getProjectData()/loadProjectData()), как и `height` у
 * spacer — HTML-атрибут-маркер (`data-wpgjb-icon-el`) нужен только для
 * распознавания при разборе произвольного HTML, не для хранения состояния.
 */
function registerIconType( editor ) {
	editor.Components.addType( 'wpgjb-icon', {
		isComponent( el ) {
			if ( el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-icon-el' ) ) {
				return { type: 'wpgjb-icon' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'span',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				attributes: { 'data-wpgjb-icon-el': 'true' },
				toolbar: BLOCK_TOOLBAR,
				traits: [
					{
						type: 'select',
						name: 'iconName',
						label: t( 'Иконка' ),
						changeProp: true,
						options: Object.keys( ICON_ELEMENT_LABELS ).map( ( key ) => ( { id: key, name: ICON_ELEMENT_LABELS[ key ] } ) ),
					},
					{
						type: 'number',
						name: 'iconSize',
						label: t( 'Размер (px)' ),
						changeProp: true,
					},
				],
				iconName: 'star',
				iconSize: 32,
				components: icon( 'star', 32 ),
			},
			init() {
				this.on( 'change:iconName change:iconSize', this.onIconChange );
			},
			onIconChange() {
				const name = this.get( 'iconName' ) || 'star';
				const size = this.get( 'iconSize' ) || 32;
				this.components( icon( name, size ) );
			},
		},
	} );
}

/**
 * Изображения внутри уже существующих "Блоков" редактируются ТОЛЬКО через
 * форму "Контент" (владеет синхронизацией `data-slot-img-id`/
 * `data-wpb-values`, см. blocks.js) — двойной клик по ним не должен
 * открывать wp.media напрямую, иначе src разойдётся с формой при
 * следующем renderContentForm/setComponentValues. Свободный "Элемент"
 * Изображение (без предка `data-wpb-block`) — двойной клик открывает
 * wp.media, как и положено для свободно компонуемого уровня.
 */
function registerImageCommandOverride( editor ) {
	const restBootstrap = window.wpgjbEditorData || {};

	editor.Commands.add( 'open-assets', {
		run( ed, sender, opts = {} ) {
			const target = opts.target || ed.getSelected();
			if ( ! target ) {
				return;
			}
			if ( resolveBlockRoot( target ) ) {
				return; // Внутри Блока — путь редактирования только через форму Контента.
			}
			if ( ! window.wp || ! window.wp.media ) {
				return;
			}
			const frame = window.wp.media( {
				title: t( 'Выбрать изображение' ),
				button: { text: t( 'Использовать' ) },
				multiple: false,
			} );
			frame.on( 'select', () => {
				const attachment = frame.state().get( 'selection' ).first().toJSON();
				const resolved = cacheFromMediaModel( attachment.id, attachment );
				const attrs = { src: resolved.src, alt: resolved.alt || '' };
				if ( resolved.srcset ) {
					attrs.srcset = resolved.srcset;
					attrs.sizes = resolved.sizes;
				}
				target.addAttributes( attrs );
			} );
			frame.open();
		},
	} );

	// restBootstrap зарезервирован для будущего REST-фолбэка разрешения
	// уже сохранённого src (не требуется сейчас — wp.media отдаёт готовый URL).
	void restBootstrap;
}

/**
 * "Список с иконками" — как wpgjb-icon, но трейт `iconName` применяется
 * СРАЗУ ко всем пунктам (per-item пикер иконок — отдельный репитер-UI,
 * не оправданный на этом уровне возможностей). `onIconChange` трогает
 * только первый дочерний `<span>` (иконку) каждого `<li>`, не второй
 * (текст) — см. iconListItem().
 */
function registerIconListType( editor ) {
	editor.Components.addType( 'wpgjb-icon-list', {
		isComponent( el ) {
			if ( el.getAttribute && 'true' === el.getAttribute( 'data-wpgjb-icon-list' ) ) {
				return { type: 'wpgjb-icon-list' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'ul',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				attributes: { 'data-wpgjb-icon-list': 'true' },
				toolbar: BLOCK_TOOLBAR,
				style: { 'list-style': 'none', padding: '0', margin: '0' },
				traits: [
					{
						type: 'select',
						name: 'iconName',
						label: t( 'Иконка' ),
						changeProp: true,
						options: Object.keys( ICON_ELEMENT_LABELS ).map( ( key ) => ( { id: key, name: ICON_ELEMENT_LABELS[ key ] } ) ),
					},
				],
				iconName: 'star',
				components: [ 1, 2, 3 ].map( ( n ) => iconListItem( 'star', n ) ),
			},
			init() {
				this.on( 'change:iconName', this.onIconChange );
			},
			onIconChange() {
				const name = this.get( 'iconName' ) || 'star';
				this.components().forEach( ( li ) => {
					const iconSpan = li.components().at( 0 );
					if ( iconSpan ) {
						iconSpan.components( icon( name, 18 ) );
					}
				} );
			},
		},
	} );
}

export function registerElementBlocks( editor ) {
	registerSpacerType( editor );
	registerButtonType( editor );
	registerIconType( editor );
	registerIconListType( editor );
	registerImageCommandOverride( editor );

	elementDefs().forEach( ( def ) => {
		editor.BlockManager.add( def.id, {
			label: def.label,
			category: elementCategory( def.group ),
			media: def.media,
			content: def.content,
		} );
	} );
}
