/**
 * "Страницы" — третий уровень вложенности (Элементы → Блоки → Страницы),
 * по прямому требованию пользователя, с прицелом на будущую AI-сборку
 * страниц ("чтобы в будущем страницы могла собирать нейронка"). Каждый
 * шаблон (`templates-library/{id}/manifest.json`, зеркало формы
 * `POST /wpgjb/v1/pages/assemble`) регистрируется как ОДИН draggable
 * BlockManager-элемент, чей `content` — конкатенация HTML каждого блока
 * последовательности, собранная теми же `extractValuesFromTemplate`/
 * `renderBlockHtml`, что уже используют "Блоки" (index.js). Собираем на
 * КЛИЕНТЕ, а не через сервер — чтобы вставленные блоки остались
 * настоящими, узнаваемыми `data-wpb-block`-компонентами (редактируемыми
 * формой "Контент", инспектируемыми будущим AI-модулем), а не запечённым
 * мёртвым HTML без структуры.
 */

import { __ } from '@wordpress/i18n';
import { extractValuesFromTemplate, renderBlockHtml } from './blocks';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

function escapeHtml( s ) {
	const div = document.createElement( 'div' );
	div.textContent = String( s == null ? '' : s );
	return div.innerHTML;
}

const TEMPLATES_CATEGORY_ID = 'wpgjb-cat-templates';

export const TEMPLATE_ID_PREFIX = 'wpgjb-tpl-';

export function registerTemplateBlocks( editor, blocks, templates ) {
	Object.keys( templates ).forEach( ( templateId ) => {
		const tpl = templates[ templateId ];
		const html = ( tpl.blocks || [] )
			.map( ( entry ) => {
				const blockDef = blocks[ entry.block_id ];
				if ( ! blockDef ) {
					return '';
				}
				const defaults = extractValuesFromTemplate( blockDef.markup, blockDef.manifest );
				const values = { ...defaults, ...( entry.slots || {} ) };
				return renderBlockHtml( blockDef.markup, blockDef.manifest, values );
			} )
			.join( '' );

		if ( ! html ) {
			return;
		}

		editor.BlockManager.add( `${ TEMPLATE_ID_PREFIX }${ templateId }`, {
			label: `<strong>${ escapeHtml( tpl.title ) }</strong>`,
			category: { id: TEMPLATES_CATEGORY_ID, label: t( 'Страницы' ), open: false },
			content: html,
		} );
	} );
}
