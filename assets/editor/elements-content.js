import { __ } from '@wordpress/i18n';
import { BLOCK_TOOLBAR } from './blocks';
import { elementCategory, icon } from './elements';
import { cacheFromMediaModel } from './images';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

const THEME_ACCENT = 'var(--wp--preset--color--contrast, #2271b1)';
const THEME_ACCENT_TEXT = 'var(--wp--preset--color--base, #fff)';
const THEME_BORDER = 'var(--wp--preset--color--accent-4, #949494)';

function tabNavButton( index, active ) {
	return {
		tagName: 'button',
		type: 'text',
		attributes: {
			type: 'button',
			'data-wpgjb-tab-nav': 'true',
			'data-index': String( index ),
			role: 'tab',
			'aria-selected': active ? 'true' : 'false',
		},
		style: {
			padding: '10px 18px',
			border: 'none',
			'border-bottom': `2px solid ${
				active ? THEME_ACCENT : 'transparent'
			}`,
			background: 'transparent',
			'font-weight': active ? '600' : '400',
			opacity: active ? '1' : '.7',
			cursor: 'pointer',
		},
		components: `${ t( 'Вкладка' ) } ${ index + 1 }`,
	};
}

function tabPanel( index, active ) {
	const attributes = {
		'data-wpgjb-tab-panel': 'true',
		'data-index': String( index ),
		role: 'tabpanel',
	};
	if ( ! active ) {
		attributes.hidden = true;
	}
	return {
		tagName: 'div',
		droppable: true,
		attributes,
		style: { padding: '16px 0' },
		components: [
			{
				tagName: 'p',
				type: 'text',
				components: `${ t( 'Содержимое вкладки' ) } ${ index + 1 }`,
			},
		],
	};
}

const TABS_TOOLBAR = [
	...BLOCK_TOOLBAR,
	{
		attributes: {
			title: t( 'Добавить вкладку' ),
			'aria-label': t( 'Добавить вкладку' ),
		},
		label: '+',
		command: 'wpgjb-tabs-add',
	},
	{
		attributes: {
			title: t( 'Удалить вкладку' ),
			'aria-label': t( 'Удалить вкладку' ),
		},
		label: '−',
		command: 'wpgjb-tabs-remove',
	},
];

function registerTabsType( editor ) {
	editor.Commands.add( 'wpgjb-tabs-add', {
		run( ed ) {
			const tabs = ed.getSelected();
			const nav = tabs && tabs.find( '[data-wpgjb-tabs-nav]' )[ 0 ];
			const panels = tabs && tabs.find( '[data-wpgjb-tabs-panels]' )[ 0 ];
			if ( ! nav || ! panels ) {
				return;
			}
			const index = nav.components().length;
			nav.append( tabNavButton( index, false ) );
			panels.append( tabPanel( index, false ) );
		},
	} );

	editor.Commands.add( 'wpgjb-tabs-remove', {
		run( ed ) {
			const tabs = ed.getSelected();
			const nav = tabs && tabs.find( '[data-wpgjb-tabs-nav]' )[ 0 ];
			const panels = tabs && tabs.find( '[data-wpgjb-tabs-panels]' )[ 0 ];
			if ( ! nav || ! panels || nav.components().length <= 1 ) {
				return;
			}
			nav.components()
				.at( nav.components().length - 1 )
				.remove();
			panels
				.components()
				.at( panels.components().length - 1 )
				.remove();
		},
	} );

	editor.Components.addType( 'wpgjb-tabs', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-tabs' )
			) {
				return { type: 'wpgjb-tabs' };
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
				toolbar: TABS_TOOLBAR,
				attributes: { 'data-wpgjb-tabs': 'true' },
				components: [
					{
						tagName: 'div',
						attributes: {
							'data-wpgjb-tabs-nav': 'true',
							role: 'tablist',
						},
						droppable: false,
						style: {
							display: 'flex',
							gap: '4px',
							'border-bottom': `1px solid ${ THEME_BORDER }`,
						},
						components: [ 0, 1, 2 ].map( ( i ) =>
							tabNavButton( i, 0 === i )
						),
					},
					{
						tagName: 'div',
						attributes: { 'data-wpgjb-tabs-panels': 'true' },
						droppable: false,
						components: [ 0, 1, 2 ].map( ( i ) =>
							tabPanel( i, 0 === i )
						),
					},
				],
			},
		},
	} );
}

function registerFlipCardType( editor ) {
	editor.Components.addType( 'wpgjb-flipcard', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-flipcard' )
			) {
				return { type: 'wpgjb-flipcard' };
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
				attributes: { 'data-wpgjb-flipcard': 'true' },
				style: {
					position: 'relative',
					height: '260px',
					'min-width': '220px',
				},
				components: [
					{
						tagName: 'div',
						attributes: { 'data-wpgjb-flipcard-inner': 'true' },
						droppable: false,
						style: {
							position: 'relative',
							width: '100%',
							height: '100%',
							transition: 'transform .6s',
							'transform-style': 'preserve-3d',
						},
						components: [
							{
								tagName: 'div',
								droppable: true,
								style: {
									position: 'absolute',
									inset: '0',
									'backface-visibility': 'hidden',
									display: 'flex',
									'flex-direction': 'column',
									'align-items': 'center',
									'justify-content': 'center',
									gap: '10px',
									padding: '20px',
									'text-align': 'center',
									border: `1px solid ${ THEME_BORDER }`,
									'border-radius': '8px',
									background:
										'var(--wp--preset--color--base, #fff)',
								},
								components: [
									{
										tagName: 'span',
										style: { color: THEME_ACCENT },
										components: icon( 'star', 40 ),
									},
									{
										tagName: 'h3',
										type: 'text',
										style: {
											margin: '0',
											'font-size': '18px',
										},
										components: t( 'Заголовок' ),
									},
								],
							},
							{
								tagName: 'div',
								droppable: true,
								style: {
									position: 'absolute',
									inset: '0',
									'backface-visibility': 'hidden',
									transform: 'rotateY(180deg)',
									display: 'flex',
									'flex-direction': 'column',
									'align-items': 'center',
									'justify-content': 'center',
									gap: '10px',
									padding: '20px',
									'text-align': 'center',
									border: `1px solid ${ THEME_BORDER }`,
									'border-radius': '8px',
									background: THEME_ACCENT,
									color: THEME_ACCENT_TEXT,
								},
								components: [
									{
										tagName: 'p',
										type: 'text',
										style: { margin: '0' },
										components: t(
											'Текст на обратной стороне карточки.'
										),
									},
								],
							},
						],
					},
				],
			},
		},
	} );
}

function registerHotspotDotType( editor ) {
	editor.Components.addType( 'wpgjb-hotspot-dot', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-hotspot-dot' )
			) {
				return { type: 'wpgjb-hotspot-dot' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'button',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				attributes: {
					type: 'button',
					'data-wpgjb-hotspot-dot': 'true',
					'aria-expanded': 'false',
				},
				toolbar: BLOCK_TOOLBAR,
				traits: [
					{
						type: 'number',
						name: 'topPct',
						label: t( 'Сверху (%)' ),
						changeProp: true,
					},
					{
						type: 'number',
						name: 'leftPct',
						label: t( 'Слева (%)' ),
						changeProp: true,
					},
				],
				topPct: 50,
				leftPct: 50,
				style: {
					position: 'absolute',
					top: '50%',
					left: '50%',
					transform: 'translate(-50%, -50%)',
					width: '22px',
					height: '22px',
					'border-radius': '50%',
					border: `2px solid ${ THEME_ACCENT_TEXT }`,
					background: THEME_ACCENT,
					cursor: 'pointer',
					padding: '0',
				},
				components: [
					{
						tagName: 'span',
						type: 'text',
						attributes: { 'data-wpgjb-hotspot-tip': 'true' },
						style: {
							display: 'none',
							position: 'absolute',
							bottom: '130%',
							left: '50%',
							transform: 'translateX(-50%)',
							background: 'rgba(0,0,0,.85)',
							color: '#fff',
							padding: '6px 10px',
							'border-radius': '4px',
							'font-size': '12px',
							'white-space': 'nowrap',
							'text-align': 'left',
						},
						components: t( 'Текст подсказки' ),
					},
				],
			},
			init() {
				this.on( 'change:topPct change:leftPct', this.onPosChange );
			},
			onPosChange() {
				this.addStyle( {
					top: `${ this.get( 'topPct' ) }%`,
					left: `${ this.get( 'leftPct' ) }%`,
				} );
			},
		},
	} );
}

const HOTSPOT_TOOLBAR = [
	...BLOCK_TOOLBAR,
	{
		attributes: {
			title: t( 'Добавить точку' ),
			'aria-label': t( 'Добавить точку' ),
		},
		label: '+●',
		command: 'wpgjb-hotspot-add-dot',
	},
];

function pickSingleImage( onSelect ) {
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
		onSelect( cacheFromMediaModel( attachment.id, attachment ) );
	} );
	frame.open();
}

function registerHotspotType( editor ) {
	registerHotspotDotType( editor );

	editor.Commands.add( 'wpgjb-hotspot-add-dot', {
		run( ed ) {
			const hotspot = ed.getSelected();
			if ( hotspot ) {
				hotspot.append( { type: 'wpgjb-hotspot-dot' } );
			}
		},
	} );

	editor.Commands.add( 'wpgjb-hotspot-pick-image', {
		run( ed ) {
			const hotspot = ed.getSelected();
			const placeholder =
				hotspot && hotspot.find( '[data-wpgjb-hotspot-bg]' )[ 0 ];
			if ( ! placeholder ) {
				return;
			}
			pickSingleImage( ( resolved ) => {
				const attrs = {
					src: resolved.src,
					alt: resolved.alt || '',
					'data-wpgjb-hotspot-bg': 'true',
				};
				if ( resolved.srcset ) {
					attrs.srcset = resolved.srcset;
					attrs.sizes = resolved.sizes;
				}
				const index = placeholder.index();
				const parent = placeholder.parent();
				parent.append(
					{
						tagName: 'img',
						droppable: false,
						attributes: attrs,
						style: { display: 'block', width: '100%' },
					},
					{ at: index }
				);
				placeholder.remove();
			} );
		},
	} );

	editor.Components.addType( 'wpgjb-hotspot', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-hotspot' )
			) {
				return { type: 'wpgjb-hotspot' };
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
				toolbar: HOTSPOT_TOOLBAR,
				attributes: { 'data-wpgjb-hotspot': 'true' },
				style: {
					position: 'relative',
					display: 'inline-block',
					'max-width': '100%',
				},
				traits: [
					{
						type: 'button',
						text: t( 'Выбрать изображение…' ),
						full: true,
						command: 'wpgjb-hotspot-pick-image',
					},
				],
				components: [
					{
						tagName: 'div',
						droppable: false,
						attributes: { 'data-wpgjb-hotspot-bg': 'true' },
						style: {
							display: 'block',
							width: '100%',
							'min-height': '240px',
							background: THEME_BORDER,
						},
					},
					{ type: 'wpgjb-hotspot-dot', topPct: 50, leftPct: 50 },
				],
			},
		},
	} );
}

const TESTIMONIAL_SAMPLES = [
	{
		name: t( 'Анна Иванова' ),
		role: t( 'Клиент' ),
		quote: t( 'Отличный сервис, всё понравилось!' ),
	},
	{
		name: t( 'Пётр Смирнов' ),
		role: t( 'Партнёр' ),
		quote: t( 'Работаем уже второй год, рекомендую.' ),
	},
	{
		name: t( 'Мария Кузнецова' ),
		role: t( 'Клиент' ),
		quote: t( 'Быстро, качественно, по делу.' ),
	},
];

function starsRow() {
	return {
		tagName: 'div',
		style: { display: 'flex', gap: '2px', color: THEME_ACCENT },
		components: Array.from( { length: 5 }, () => icon( 'star', 16 ) ).join(
			''
		),
	};
}

function testimonialSlide( sample ) {
	return {
		tagName: 'div',
		attributes: { 'data-wpgjb-slide': 'true' },
		style: {
			flex: '0 0 100%',
			padding: '24px',
			display: 'flex',
			'flex-direction': 'column',
			'align-items': 'center',
			'text-align': 'center',
			gap: '10px',
		},
		toolbar: [
			...BLOCK_TOOLBAR,
			{
				attributes: {
					title: t( 'Сменить фото' ),
					'aria-label': t( 'Сменить фото' ),
				},
				label: icon( 'image', 14 ),
				command: 'wpgjb-testimonial-set-avatar',
			},
		],
		components: [
			{
				tagName: 'div',
				droppable: false,
				attributes: { 'data-wpgjb-testimonial-avatar': 'true' },
				style: {
					width: '64px',
					height: '64px',
					'border-radius': '50%',
					background: THEME_BORDER,
				},
			},
			starsRow(),
			{
				tagName: 'p',
				type: 'text',
				style: {
					'font-style': 'italic',
					margin: '0',
					'max-width': '480px',
				},
				components: sample.quote,
			},
			{ tagName: 'strong', type: 'text', components: sample.name },
			{
				tagName: 'span',
				type: 'text',
				style: { opacity: '.7', 'font-size': '13px' },
				components: sample.role,
			},
		],
	};
}

function sliderDotDef( index ) {
	return {
		tagName: 'button',
		attributes: {
			type: 'button',
			'data-wpgjb-slider-dot': 'true',
			'data-index': String( index ),
			'aria-label': `${ t( 'Слайд' ) } ${ index + 1 }`,
		},
		style: {
			width: '8px',
			height: '8px',
			'border-radius': '50%',
			border: 'none',
			background: 'rgba(0,0,0,0.25)',
			cursor: 'pointer',
			padding: '0',
		},
	};
}

function sliderNavButtonDef( direction ) {
	return {
		tagName: 'button',
		attributes: {
			type: 'button',
			[ 'data-wpgjb-slider-' + direction ]: 'true',
			'aria-label':
				'prev' === direction
					? t( 'Предыдущий отзыв' )
					: t( 'Следующий отзыв' ),
		},
		style: {
			position: 'absolute',
			top: '50%',
			[ 'prev' === direction ? 'left' : 'right' ]: '8px',
			transform: 'translateY(-50%)',
			'z-index': '2',
			border: 'none',
			background: 'rgba(0,0,0,0.15)',
			color: 'inherit',
			'border-radius': '50%',
			width: '32px',
			height: '32px',
			cursor: 'pointer',
		},
		components: 'prev' === direction ? '‹' : '›',
	};
}

const TESTIMONIAL_TOOLBAR = [
	...BLOCK_TOOLBAR,
	{
		attributes: {
			title: t( 'Добавить отзыв' ),
			'aria-label': t( 'Добавить отзыв' ),
		},
		label: '+',
		command: 'wpgjb-testimonial-add',
	},
];

function registerTestimonialSliderType( editor ) {
	editor.Commands.add( 'wpgjb-testimonial-set-avatar', {
		run( ed ) {
			const slide = ed.getSelected();
			const placeholder =
				slide && slide.find( '[data-wpgjb-testimonial-avatar]' )[ 0 ];
			if ( ! placeholder ) {
				return;
			}
			pickSingleImage( ( resolved ) => {
				const attrs = {
					src: resolved.src,
					alt: resolved.alt || '',
					'data-wpgjb-testimonial-avatar': 'true',
				};
				const index = placeholder.index();
				const parent = placeholder.parent();
				parent.append(
					{
						tagName: 'img',
						droppable: false,
						attributes: attrs,
						style: {
							width: '64px',
							height: '64px',
							'border-radius': '50%',
							'object-fit': 'cover',
						},
					},
					{ at: index }
				);
				placeholder.remove();
			} );
		},
	} );

	editor.Commands.add( 'wpgjb-testimonial-add', {
		run( ed ) {
			const slider = ed.getSelected();
			const track =
				slider && slider.find( '[data-wpgjb-slider-track]' )[ 0 ];
			const dots =
				slider && slider.find( '[data-wpgjb-slider-dots]' )[ 0 ];
			if ( ! track || ! dots ) {
				return;
			}
			const index = track.components().length;
			track.append(
				testimonialSlide(
					TESTIMONIAL_SAMPLES[ index % TESTIMONIAL_SAMPLES.length ]
				)
			);
			dots.append( sliderDotDef( index ) );
		},
	} );

	editor.Components.addType( 'wpgjb-testimonial-slider', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'testimonial' === el.getAttribute( 'data-wpgjb-slider-variant' )
			) {
				return { type: 'wpgjb-testimonial-slider' };
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
				toolbar: TESTIMONIAL_TOOLBAR,
				attributes: {
					'data-wpgjb-slider': 'true',
					'data-wpgjb-slider-variant': 'testimonial',
					'data-wpgjb-slider-index': '0',
				},
				style: { position: 'relative', overflow: 'hidden' },
				components: [
					{
						tagName: 'div',
						attributes: { 'data-wpgjb-slider-track': 'true' },
						droppable: false,
						style: {
							display: 'flex',
							transition: 'transform 0.4s ease',
						},
						components: TESTIMONIAL_SAMPLES.map( testimonialSlide ),
					},
					sliderNavButtonDef( 'prev' ),
					sliderNavButtonDef( 'next' ),
					{
						tagName: 'div',
						attributes: { 'data-wpgjb-slider-dots': 'true' },
						droppable: false,
						style: {
							display: 'flex',
							'justify-content': 'center',
							gap: '6px',
							'margin-top': '12px',
						},
						components: TESTIMONIAL_SAMPLES.map( ( _, i ) =>
							sliderDotDef( i )
						),
					},
				],
			},
		},
	} );
}

function registerIconBoxType( editor ) {
	editor.Components.addType( 'wpgjb-iconbox', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-iconbox' )
			) {
				return { type: 'wpgjb-iconbox' };
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
				attributes: { 'data-wpgjb-iconbox': 'true' },
				traits: [
					{
						type: 'select',
						name: 'direction',
						label: t( 'Направление' ),
						changeProp: true,
						options: [
							{ id: 'column', name: t( 'Вертикально' ) },
							{ id: 'row', name: t( 'Горизонтально' ) },
						],
					},
				],
				direction: 'column',
				style: {
					display: 'flex',
					'flex-direction': 'column',
					'align-items': 'center',
					'text-align': 'center',
					gap: '10px',
					padding: '20px',
				},
				components: [
					{
						tagName: 'span',
						style: { color: THEME_ACCENT, display: 'flex' },
						components: icon( 'star', 40 ),
					},
					{
						tagName: 'h3',
						type: 'text',
						style: { margin: '0', 'font-size': '18px' },
						components: t( 'Заголовок' ),
					},
					{
						tagName: 'p',
						type: 'text',
						style: { margin: '0', opacity: '.8' },
						components: t(
							'Краткое описание преимущества или услуги.'
						),
					},
				],
			},
			init() {
				this.on( 'change:direction', this.onDirectionChange );
			},
			onDirectionChange() {
				const dir = this.get( 'direction' ) || 'column';
				this.addStyle( {
					'flex-direction': dir,
					'text-align': 'row' === dir ? 'left' : 'center',
					'align-items': 'row' === dir ? 'flex-start' : 'center',
				} );
			},
		},
	} );
}

function registerImageBoxType( editor ) {
	editor.Commands.add( 'wpgjb-imagebox-pick-image', {
		run( ed ) {
			const box = ed.getSelected();
			const placeholder =
				box && box.find( '[data-wpgjb-imagebox-img]' )[ 0 ];
			if ( ! placeholder ) {
				return;
			}
			pickSingleImage( ( resolved ) => {
				const attrs = {
					src: resolved.src,
					alt: resolved.alt || '',
					'data-wpgjb-imagebox-img': 'true',
				};
				if ( resolved.srcset ) {
					attrs.srcset = resolved.srcset;
					attrs.sizes = resolved.sizes;
				}
				const index = placeholder.index();
				const parent = placeholder.parent();
				parent.append(
					{
						tagName: 'img',
						droppable: false,
						attributes: attrs,
						style: {
							display: 'block',
							width: '100%',
							'border-radius': '8px',
						},
					},
					{ at: index }
				);
				placeholder.remove();
			} );
		},
	} );

	editor.Components.addType( 'wpgjb-imagebox', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-imagebox' )
			) {
				return { type: 'wpgjb-imagebox' };
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
				attributes: { 'data-wpgjb-imagebox': 'true' },
				traits: [
					{
						type: 'button',
						text: t( 'Выбрать изображение…' ),
						full: true,
						command: 'wpgjb-imagebox-pick-image',
					},
					{
						type: 'select',
						name: 'direction',
						label: t( 'Направление' ),
						changeProp: true,
						options: [
							{ id: 'column', name: t( 'Изображение сверху' ) },
							{ id: 'row', name: t( 'Изображение слева' ) },
						],
					},
				],
				direction: 'column',
				style: {
					display: 'flex',
					'flex-direction': 'column',
					gap: '14px',
				},
				components: [
					{
						tagName: 'div',
						droppable: false,
						attributes: { 'data-wpgjb-imagebox-img': 'true' },
						style: {
							width: '100%',
							'min-height': '180px',
							background: THEME_BORDER,
							'border-radius': '8px',
						},
					},
					{
						tagName: 'div',
						style: {
							display: 'flex',
							'flex-direction': 'column',
							gap: '6px',
							flex: '1',
						},
						components: [
							{
								tagName: 'h3',
								type: 'text',
								style: { margin: '0', 'font-size': '18px' },
								components: t( 'Заголовок' ),
							},
							{
								tagName: 'p',
								type: 'text',
								style: { margin: '0', opacity: '.8' },
								components: t(
									'Описание изображения или услуги.'
								),
							},
						],
					},
				],
			},
			init() {
				this.on( 'change:direction', this.onDirectionChange );
			},
			onDirectionChange() {
				const dir = this.get( 'direction' ) || 'column';
				this.addStyle( {
					'flex-direction': dir,
					'align-items': 'row' === dir ? 'flex-start' : 'stretch',
				} );
				const img = this.find( '[data-wpgjb-imagebox-img]' )[ 0 ];
				if ( img ) {
					img.addStyle( { width: 'row' === dir ? '40%' : '100%' } );
				}
			},
		},
	} );
}

const QUOTE_CONTENT = {
	tagName: 'blockquote',
	draggable: true,
	droppable: false,
	removable: true,
	copyable: true,
	stylable: true,
	toolbar: BLOCK_TOOLBAR,
	style: {
		margin: '0',
		padding: '20px 24px',
		'border-left': `4px solid ${ THEME_ACCENT }`,
		background: 'var(--wp--preset--color--base-2, #f5f5f5)',
		'border-radius': '0 8px 8px 0',
	},
	components: [
		{
			tagName: 'span',
			style: {
				color: THEME_ACCENT,
				opacity: '.5',
				display: 'block',
				'margin-bottom': '8px',
			},
			components: icon( 'quote', 28 ),
		},
		{
			tagName: 'p',
			type: 'text',
			style: { margin: '0', 'font-size': '18px', 'font-style': 'italic' },
			components: t(
				'Здесь располагается вдохновляющая цитата или отзыв клиента.'
			),
		},
		{
			tagName: 'cite',
			type: 'text',
			style: {
				display: 'block',
				'margin-top': '12px',
				'font-size': '14px',
				opacity: '.7',
			},
			components: `— ${ t( 'Автор цитаты' ) }`,
		},
	],
};

export const CONTENT_ELEMENT_DEFS = [
	{
		id: 'wpgjb-el-tabs',
		label: t( 'Вкладки' ),
		type: 'wpgjb-tabs',
		icon: 'tabs',
		group: 'interactive',
	},
	{
		id: 'wpgjb-el-flipcard',
		label: t( 'Флип-карточка' ),
		type: 'wpgjb-flipcard',
		icon: 'flipcard',
		group: 'interactive',
	},
	{
		id: 'wpgjb-el-hotspot',
		label: t( 'Hotspot на изображении' ),
		type: 'wpgjb-hotspot',
		icon: 'hotspot',
		group: 'interactive',
	},
	{
		id: 'wpgjb-el-testimonial-slider',
		label: t( 'Слайдер отзывов' ),
		type: 'wpgjb-testimonial-slider',
		icon: 'slider',
		group: 'media',
	},
	{
		id: 'wpgjb-el-iconbox',
		label: t( 'Блок с иконкой' ),
		type: 'wpgjb-iconbox',
		icon: 'star',
		group: 'media',
	},
	{
		id: 'wpgjb-el-imagebox',
		label: t( 'Блок с картинкой' ),
		type: 'wpgjb-imagebox',
		icon: 'image',
		group: 'media',
	},
	{
		id: 'wpgjb-el-quote',
		label: t( 'Цитата' ),
		content: QUOTE_CONTENT,
		icon: 'quote',
		group: 'text',
	},
];

export const CONTENT_ELEMENT_IDS = CONTENT_ELEMENT_DEFS.map(
	( def ) => def.id
);

export function registerContentElementBlocks( editor ) {
	registerTabsType( editor );
	registerFlipCardType( editor );
	registerHotspotType( editor );
	registerTestimonialSliderType( editor );
	registerIconBoxType( editor );
	registerImageBoxType( editor );

	CONTENT_ELEMENT_DEFS.forEach( ( def ) => {
		editor.BlockManager.add( def.id, {
			label: def.label,
			category: elementCategory( def.group ),
			media: icon( def.icon, 28 ),
			content: def.content || { type: def.type },
		} );
	} );
}
