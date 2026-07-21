import { __ } from '@wordpress/i18n';
import { BLOCK_TOOLBAR } from './blocks';
import { elementCategory, icon } from './elements';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

const THEME_ACCENT = 'var(--wp--preset--color--contrast, #2271b1)';
const THEME_ACCENT_TEXT = 'var(--wp--preset--color--base, #fff)';
const THEME_BORDER = 'var(--wp--preset--color--accent-4, #949494)';

const PIE_PALETTE = [
	'var(--wp--preset--color--contrast, #2271b1)',
	'var(--wp--preset--color--accent-1, #d63384)',
	'var(--wp--preset--color--accent-2, #fd7e14)',
	'var(--wp--preset--color--accent-3, #20c997)',
	'var(--wp--preset--color--accent-4, #6f42c1)',
	'var(--wp--preset--color--accent-5, #0dcaf0)',
];

function escapeHtml( s ) {
	return String( s == null ? '' : s )
		.replace( /&/g, '&amp;' )
		.replace( /"/g, '&quot;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' );
}

function defaultChartDataText() {
	return [
		[ t( 'Янв' ), 12 ],
		[ t( 'Фев' ), 19 ],
		[ t( 'Мар' ), 8 ],
		[ t( 'Апр' ), 15 ],
	]
		.map( ( row ) => row.join( ', ' ) )
		.join( '\n' );
}

function parseChartDataText( text ) {
	return String( text || '' )
		.split( '\n' )
		.map( ( line ) => line.trim() )
		.filter( Boolean )
		.map( ( line ) => {
			const parts = line.split( ',' );
			if ( parts.length < 2 ) {
				return null;
			}
			const label = parts[ 0 ].trim();
			const value = parseFloat( parts[ 1 ].trim() );
			return label && ! isNaN( value ) ? { label, value } : null;
		} )
		.filter( Boolean );
}

function chartDataTrait() {
	return {
		type: 'textarea',
		name: 'chartDataText',
		label: t( 'Данные (метка, значение — по строке)' ),
		changeProp: true,
	};
}

const REVEAL_STYLE = 'transition:opacity .5s ease-out,transform .5s ease-out';

function renderBarChartHtml( data, orientation ) {
	const rows = data.length
		? data
		: [ { label: t( 'Нет данных' ), value: 0 } ];
	const max = Math.max( 1, ...rows.map( ( d ) => d.value ) );
	const horizontal = 'horizontal' === orientation;
	const summary = rows
		.map( ( d ) => `${ d.label }: ${ d.value }` )
		.join( '; ' );
	const barBase = horizontal
		? `display:flex;align-items:center;gap:8px;background:${ THEME_ACCENT };border-radius:0 4px 4px 0;padding:6px 10px;color:${ THEME_ACCENT_TEXT };min-width:4%;transform:scale(1);transform-origin:left;transition:transform .7s cubic-bezier(.2,.8,.2,1)`
		: `display:flex;flex-direction:column;align-items:center;justify-content:flex-end;background:${ THEME_ACCENT };border-radius:4px 4px 0 0;padding:6px 4px;color:${ THEME_ACCENT_TEXT };flex:1;min-height:4%;transform:scale(1);transform-origin:bottom;transition:transform .7s cubic-bezier(.2,.8,.2,1)`;
	const bars = rows
		.map( ( d ) => {
			const pct = Math.max( 4, ( d.value / max ) * 100 );
			const sizeStyle = horizontal
				? `width:${ pct }%`
				: `height:${ pct }%`;
			return (
				`<div class="wpgjb-chart-bar" style="${ barBase };${ sizeStyle }" title="${ escapeHtml(
					d.label
				) }: ${ escapeHtml( String( d.value ) ) }">` +
				`<span style="font-size:12px;font-weight:600;line-height:1.3">${ escapeHtml(
					String( d.value )
				) }</span>` +
				`<span style="font-size:11px;opacity:.75;line-height:1.3">${ escapeHtml(
					d.label
				) }</span>` +
				`</div>`
			);
		} )
		.join( '' );
	const wrapStyle = horizontal
		? `${ REVEAL_STYLE };display:flex;flex-direction:column;gap:10px`
		: `${ REVEAL_STYLE };display:flex;align-items:flex-end;gap:10px;height:220px`;
	return `<div class="wpgjb-chart-reveal" role="img" aria-label="${ escapeHtml(
		summary
	) }" style="${ wrapStyle }">${ bars }</div>`;
}

function renderLineChartHtml( data ) {
	const rows = data.length
		? data
		: [ { label: t( 'Нет данных' ), value: 0 } ];
	const max = Math.max( 1, ...rows.map( ( d ) => d.value ) );
	const width = Math.max( 240, rows.length * 70 );
	const height = 160;
	const stepX = rows.length > 1 ? width / ( rows.length - 1 ) : 0;
	const points = rows.map( ( d, i ) => ( {
		x: rows.length > 1 ? i * stepX : width / 2,
		y: height - 16 - ( Math.max( 0, d.value ) / max ) * ( height - 32 ),
		d,
	} ) );
	const polylinePoints = points
		.map( ( p ) => `${ p.x },${ p.y }` )
		.join( ' ' );
	const circles = points
		.map(
			( p ) =>
				`<circle cx="${ p.x }" cy="${ p.y }" r="4" fill="${ THEME_ACCENT }"></circle>`
		)
		.join( '' );
	const labels = points
		.map(
			( p ) =>
				`<span style="position:absolute;left:${
					( p.x / width ) * 100
				}%;top:100%;transform:translateX(-50%);font-size:11px;opacity:.75;white-space:nowrap">${ escapeHtml(
					p.d.label
				) }</span>`
		)
		.join( '' );
	const summary = rows
		.map( ( d ) => `${ d.label }: ${ d.value }` )
		.join( '; ' );
	return (
		`<div class="wpgjb-chart-reveal" role="img" aria-label="${ escapeHtml(
			summary
		) }" style="${ REVEAL_STYLE };position:relative;width:100%;max-width:${ width }px;padding-bottom:26px">` +
		`<svg class="wpgjb-chart-line-svg" viewBox="0 0 ${ width } ${ height }" width="${ width }" height="${ height }" style="display:block;width:100%;height:auto;overflow:visible">` +
		`<polyline class="wpgjb-chart-line-path" points="${ polylinePoints }" fill="none" stroke="${ THEME_ACCENT }" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"></polyline>` +
		circles +
		'</svg>' +
		`<div style="position:relative">${ labels }</div>` +
		'</div>'
	);
}

function renderPieChartHtml( data ) {
	const rows = data.length
		? data
		: [ { label: t( 'Нет данных' ), value: 1 } ];
	const total =
		rows.reduce( ( sum, d ) => sum + Math.max( 0, d.value ), 0 ) || 1;
	let cursor = 0;
	const stops = rows
		.map( ( d, i ) => {
			const color = PIE_PALETTE[ i % PIE_PALETTE.length ];
			const start = ( cursor / total ) * 360;
			cursor += Math.max( 0, d.value );
			const end = ( cursor / total ) * 360;
			return `${ color } ${ start }deg ${ end }deg`;
		} )
		.join( ', ' );
	const legend = rows
		.map( ( d, i ) => {
			const pct = Math.round( ( Math.max( 0, d.value ) / total ) * 100 );
			return (
				'<li style="display:flex;align-items:center;gap:6px;font-size:13px">' +
				`<span style="width:12px;height:12px;border-radius:50%;background:${
					PIE_PALETTE[ i % PIE_PALETTE.length ]
				};flex:0 0 auto"></span>` +
				`<span>${ escapeHtml( d.label ) } — ${ pct }%</span>` +
				'</li>'
			);
		} )
		.join( '' );
	const summary = rows
		.map( ( d ) => `${ d.label }: ${ d.value }` )
		.join( '; ' );
	return (
		`<div class="wpgjb-chart-reveal" style="${ REVEAL_STYLE };display:flex;align-items:center;gap:20px;flex-wrap:wrap">` +
		`<div role="img" aria-label="${ escapeHtml(
			summary
		) }" style="width:160px;height:160px;border-radius:50%;flex:0 0 auto;background:conic-gradient(${ stops })"></div>` +
		`<ul style="list-style:none;margin:0;padding:0;display:flex;flex-direction:column;gap:6px">${ legend }</ul>` +
		'</div>'
	);
}

function registerChartBarType( editor ) {
	editor.Components.addType( 'wpgjb-chart-bar', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'bar' === el.getAttribute( 'data-wpgjb-chart' )
			) {
				return { type: 'wpgjb-chart-bar' };
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
				attributes: { 'data-wpgjb-chart': 'bar' },
				traits: [
					chartDataTrait(),
					{
						type: 'select',
						name: 'orientation',
						label: t( 'Ориентация' ),
						changeProp: true,
						options: [
							{ id: 'vertical', name: t( 'Вертикальная' ) },
							{ id: 'horizontal', name: t( 'Горизонтальная' ) },
						],
					},
				],
				chartDataText: defaultChartDataText(),
				orientation: 'vertical',
			},
			init() {
				this.on(
					'change:chartDataText change:orientation',
					this.onValueChange
				);
				this.onValueChange();
			},
			onValueChange() {
				const data = parseChartDataText( this.get( 'chartDataText' ) );
				this.components(
					renderBarChartHtml( data, this.get( 'orientation' ) )
				);
			},
		},
	} );
}

function registerChartLineType( editor ) {
	editor.Components.addType( 'wpgjb-chart-line', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'line' === el.getAttribute( 'data-wpgjb-chart' )
			) {
				return { type: 'wpgjb-chart-line' };
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
				attributes: { 'data-wpgjb-chart': 'line' },
				traits: [ chartDataTrait() ],
				chartDataText: defaultChartDataText(),
			},
			init() {
				this.on( 'change:chartDataText', this.onValueChange );
				this.onValueChange();
			},
			onValueChange() {
				this.components(
					renderLineChartHtml(
						parseChartDataText( this.get( 'chartDataText' ) )
					)
				);
			},
		},
	} );
}

function registerChartPieType( editor ) {
	editor.Components.addType( 'wpgjb-chart-pie', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'pie' === el.getAttribute( 'data-wpgjb-chart' )
			) {
				return { type: 'wpgjb-chart-pie' };
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
				attributes: { 'data-wpgjb-chart': 'pie' },
				traits: [ chartDataTrait() ],
				chartDataText: [
					[ t( 'Прямой' ), 45 ],
					[ t( 'Реклама' ), 30 ],
					[ t( 'Соцсети' ), 15 ],
					[ t( 'Другое' ), 10 ],
				]
					.map( ( row ) => row.join( ', ' ) )
					.join( '\n' ),
			},
			init() {
				this.on( 'change:chartDataText', this.onValueChange );
				this.onValueChange();
			},
			onValueChange() {
				this.components(
					renderPieChartHtml(
						parseChartDataText( this.get( 'chartDataText' ) )
					)
				);
			},
		},
	} );
}

const DEFAULT_TABLE_HEAD = [
	t( 'Колонка 1' ),
	t( 'Колонка 2' ),
	t( 'Колонка 3' ),
];
const DEFAULT_TABLE_ROWS = [
	[ t( 'Строка 1' ), '10', '20' ],
	[ t( 'Строка 2' ), '30', '5' ],
	[ t( 'Строка 3' ), '15', '40' ],
];

function tableRowStripe( index ) {
	return index % 2 === 1
		? { background: 'var(--wp--preset--color--base-2, #f5f5f5)' }
		: {};
}

function tableBodyRow( cells, index ) {
	return {
		tagName: 'tr',
		droppable: false,
		style: tableRowStripe( index ),
		components: cells.map( ( text ) => ( {
			tagName: 'td',
			type: 'text',
			style: { padding: '8px 12px' },
			components: text,
		} ) ),
	};
}

const TABLE_TOOLBAR = [
	...BLOCK_TOOLBAR,
	{
		attributes: {
			title: t( 'Добавить строку' ),
			'aria-label': t( 'Добавить строку' ),
		},
		label: '+R',
		command: 'wpgjb-table-add-row',
	},
	{
		attributes: {
			title: t( 'Удалить строку' ),
			'aria-label': t( 'Удалить строку' ),
		},
		label: '−R',
		command: 'wpgjb-table-remove-row',
	},
	{
		attributes: {
			title: t( 'Добавить столбец' ),
			'aria-label': t( 'Добавить столбец' ),
		},
		label: '+C',
		command: 'wpgjb-table-add-col',
	},
	{
		attributes: {
			title: t( 'Удалить столбец' ),
			'aria-label': t( 'Удалить столбец' ),
		},
		label: '−C',
		command: 'wpgjb-table-remove-col',
	},
];

function registerDataTableCommands( editor ) {
	editor.Commands.add( 'wpgjb-table-add-row', {
		run( ed ) {
			const table = ed.getSelected();
			const thead =
				table && table.find( '[data-wpgjb-datatable-thead]' )[ 0 ];
			const tbody =
				table && table.find( '[data-wpgjb-datatable-tbody]' )[ 0 ];
			if ( ! thead || ! tbody ) {
				return;
			}
			const headRow = thead.components().at( 0 );
			const colCount = headRow ? headRow.components().length : 1;
			const rowIndex = tbody.components().length;
			tbody.append( {
				tagName: 'tr',
				droppable: false,
				style: tableRowStripe( rowIndex ),
				components: Array.from( { length: colCount }, () => ( {
					tagName: 'td',
					type: 'text',
					style: { padding: '8px 12px' },
					components: t( 'Ячейка' ),
				} ) ),
			} );
		},
	} );

	editor.Commands.add( 'wpgjb-table-remove-row', {
		run( ed ) {
			const table = ed.getSelected();
			const tbody =
				table && table.find( '[data-wpgjb-datatable-tbody]' )[ 0 ];
			if ( ! tbody ) {
				return;
			}
			const rows = tbody.components();
			if ( rows.length > 1 ) {
				rows.at( rows.length - 1 ).remove();
			}
		},
	} );

	editor.Commands.add( 'wpgjb-table-add-col', {
		run( ed ) {
			const table = ed.getSelected();
			const thead =
				table && table.find( '[data-wpgjb-datatable-thead]' )[ 0 ];
			const tbody =
				table && table.find( '[data-wpgjb-datatable-tbody]' )[ 0 ];
			if ( ! thead || ! tbody ) {
				return;
			}
			const headRow = thead.components().at( 0 );
			if ( headRow ) {
				headRow.append( {
					tagName: 'th',
					type: 'text',
					attributes: { scope: 'col' },
					style: { padding: '8px 12px', 'text-align': 'left' },
					components: t( 'Колонка' ),
				} );
			}
			tbody.components().forEach( ( row ) => {
				row.append( {
					tagName: 'td',
					type: 'text',
					style: { padding: '8px 12px' },
					components: t( 'Ячейка' ),
				} );
			} );
		},
	} );

	editor.Commands.add( 'wpgjb-table-remove-col', {
		run( ed ) {
			const table = ed.getSelected();
			const thead =
				table && table.find( '[data-wpgjb-datatable-thead]' )[ 0 ];
			const tbody =
				table && table.find( '[data-wpgjb-datatable-tbody]' )[ 0 ];
			if ( ! thead || ! tbody ) {
				return;
			}
			const headRow = thead.components().at( 0 );
			if ( headRow && headRow.components().length > 1 ) {
				const headCells = headRow.components();
				headCells.at( headCells.length - 1 ).remove();
			}
			tbody.components().forEach( ( row ) => {
				const cells = row.components();
				if ( cells.length > 1 ) {
					cells.at( cells.length - 1 ).remove();
				}
			} );
		},
	} );
}

function registerDataTableType( editor ) {
	registerDataTableCommands( editor );

	editor.Components.addType( 'wpgjb-datatable', {
		isComponent( el ) {
			if (
				el.tagName === 'TABLE' &&
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-datatable' )
			) {
				return { type: 'wpgjb-datatable' };
			}
			return undefined;
		},
		model: {
			defaults: {
				tagName: 'table',
				draggable: true,
				droppable: false,
				removable: true,
				copyable: true,
				stylable: true,
				toolbar: TABLE_TOOLBAR,
				attributes: { 'data-wpgjb-datatable': 'true' },
				style: {
					width: '100%',
					'border-collapse': 'collapse',
					'font-size': '14px',
				},
				components: [
					{
						tagName: 'thead',
						attributes: { 'data-wpgjb-datatable-thead': 'true' },
						droppable: false,
						components: [
							{
								tagName: 'tr',
								droppable: false,
								components: DEFAULT_TABLE_HEAD.map(
									( label ) => ( {
										tagName: 'th',
										type: 'text',
										attributes: { scope: 'col' },
										style: {
											padding: '8px 12px',
											'text-align': 'left',
											cursor: 'pointer',
											'border-bottom': `2px solid ${ THEME_BORDER }`,
										},
										components: label,
									} )
								),
							},
						],
					},
					{
						tagName: 'tbody',
						attributes: { 'data-wpgjb-datatable-tbody': 'true' },
						droppable: false,
						components: DEFAULT_TABLE_ROWS.map( ( row, i ) =>
							tableBodyRow( row, i )
						),
					},
				],
			},
		},
	} );
}

export const INFOGRAPHIC_ELEMENT_DEFS = [
	{
		id: 'wpgjb-el-chart-bar',
		label: t( 'Столбчатая диаграмма' ),
		type: 'wpgjb-chart-bar',
		icon: 'chartBar',
		group: 'interactive',
	},
	{
		id: 'wpgjb-el-chart-line',
		label: t( 'Линейный график' ),
		type: 'wpgjb-chart-line',
		icon: 'chartLine',
		group: 'interactive',
	},
	{
		id: 'wpgjb-el-chart-pie',
		label: t( 'Круговая диаграмма' ),
		type: 'wpgjb-chart-pie',
		icon: 'chartPie',
		group: 'interactive',
	},
	{
		id: 'wpgjb-el-datatable',
		label: t( 'Таблица с сортировкой' ),
		type: 'wpgjb-datatable',
		icon: 'table',
		group: 'interactive',
	},
];

export const INFOGRAPHIC_ELEMENT_IDS = INFOGRAPHIC_ELEMENT_DEFS.map(
	( def ) => def.id
);

export function registerInfographicElementBlocks( editor ) {
	registerChartBarType( editor );
	registerChartLineType( editor );
	registerChartPieType( editor );
	registerDataTableType( editor );

	INFOGRAPHIC_ELEMENT_DEFS.forEach( ( def ) => {
		editor.BlockManager.add( def.id, {
			label: def.label,
			category: elementCategory( def.group ),
			media: icon( def.icon, 28 ),
			content: { type: def.type },
		} );
	} );
}
