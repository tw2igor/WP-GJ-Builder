import { __ } from '@wordpress/i18n';
import { BLOCK_TOOLBAR } from './blocks';
import { elementCategory, icon } from './elements';

function t( s ) {
	return __( s, 'wp-gj-builder' );
}

function escapeHtml( s ) {
	return String( s == null ? '' : s )
		.replace( /&/g, '&amp;' )
		.replace( /</g, '&lt;' )
		.replace( />/g, '&gt;' )
		.replace( /"/g, '&quot;' );
}

const LANG_KEYWORDS = {
	js: [
		'const',
		'let',
		'var',
		'function',
		'return',
		'if',
		'else',
		'for',
		'while',
		'class',
		'new',
		'import',
		'export',
		'from',
		'default',
		'async',
		'await',
		'try',
		'catch',
		'throw',
		'typeof',
		'null',
		'undefined',
		'true',
		'false',
	],
	php: [
		'function',
		'return',
		'if',
		'else',
		'elseif',
		'foreach',
		'as',
		'while',
		'for',
		'class',
		'public',
		'private',
		'protected',
		'static',
		'new',
		'use',
		'namespace',
		'echo',
		'true',
		'false',
		'null',
		'require',
		'require_once',
		'include',
	],
	html: [],
	css: [],
	json: [ 'true', 'false', 'null' ],
	bash: [
		'if',
		'then',
		'else',
		'fi',
		'for',
		'do',
		'done',
		'echo',
		'export',
		'function',
	],
	plain: [],
};

const LANG_OPTIONS = [
	{ id: 'js', name: 'JavaScript' },
	{ id: 'php', name: 'PHP' },
	{ id: 'html', name: 'HTML' },
	{ id: 'css', name: 'CSS' },
	{ id: 'json', name: 'JSON' },
	{ id: 'bash', name: 'Bash' },
	{ id: 'plain', name: t( 'Без подсветки' ) },
];

const DEFAULT_CODE_SAMPLE =
	"function greet( name ) {\n\treturn 'Hello, ' + name + '!';\n}";

function commentPatternFor( lang ) {
	if ( 'html' === lang ) {
		return '<!--[\\s\\S]*?-->';
	}
	if ( 'css' === lang ) {
		return '/\\*[\\s\\S]*?\\*/';
	}
	if ( 'bash' === lang ) {
		return '#.*';
	}
	if ( 'php' === lang ) {
		return '//.*|#.*|/\\*[\\s\\S]*?\\*/';
	}
	return '//.*|/\\*[\\s\\S]*?\\*/';
}

function highlightCode( code, lang ) {
	const source = String( code == null ? '' : code );
	const keywords = LANG_KEYWORDS[ lang ] || [];
	const groups = [
		`(${ commentPatternFor( lang ) })`,
		`("(?:[^"\\\\]|\\\\.)*"|'(?:[^'\\\\]|\\\\.)*'|` +
			'`(?:[^`\\\\]|\\\\.)*`)',
		'(\\b\\d+(?:\\.\\d+)?\\b)',
	];
	if ( keywords.length ) {
		groups.push( `(\\b(?:${ keywords.join( '|' ) })\\b)` );
	}
	const combined = new RegExp( groups.join( '|' ), 'gm' );

	let out = '';
	let last = 0;
	let match = combined.exec( source );
	while ( match ) {
		out += escapeHtml( source.slice( last, match.index ) );
		if ( match[ 1 ] ) {
			out += `<span class="wpgjb-tok-com">${ escapeHtml(
				match[ 1 ]
			) }</span>`;
		} else if ( match[ 2 ] ) {
			out += `<span class="wpgjb-tok-str">${ escapeHtml(
				match[ 2 ]
			) }</span>`;
		} else if ( match[ 3 ] ) {
			out += `<span class="wpgjb-tok-num">${ escapeHtml(
				match[ 3 ]
			) }</span>`;
		} else if ( match[ 4 ] ) {
			out += `<span class="wpgjb-tok-kw">${ escapeHtml(
				match[ 4 ]
			) }</span>`;
		}
		last = combined.lastIndex;
		match = combined.exec( source );
	}
	out += escapeHtml( source.slice( last ) );
	return out;
}

function renderCodeBlockHtml( code, lang ) {
	const highlighted = highlightCode( code, lang );
	return (
		`<button type="button" data-wpgjb-codeblock-copy="true" data-wpgjb-codeblock-copied-label="${ escapeHtml(
			t( 'Скопировано!' )
		) }" style="position:absolute;top:8px;right:8px;padding:4px 10px;font-size:12px;border:1px solid rgba(255,255,255,.3);border-radius:4px;background:rgba(255,255,255,.08);color:inherit;cursor:pointer">${ escapeHtml(
			t( 'Копировать' )
		) }</button>` +
		`<pre style="margin:0;padding:16px 100px 16px 16px;background:#1e1e1e;color:#d4d4d4;border-radius:8px;overflow:auto;font-family:Consolas,Monaco,monospace;font-size:13px;line-height:1.6"><code data-wpgjb-codeblock-code="true">${ highlighted }</code></pre>`
	);
}

function registerCodeBlockType( editor ) {
	editor.Components.addType( 'wpgjb-codeblock', {
		isComponent( el ) {
			if (
				el.getAttribute &&
				'true' === el.getAttribute( 'data-wpgjb-codeblock' )
			) {
				return { type: 'wpgjb-codeblock' };
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
				attributes: { 'data-wpgjb-codeblock': 'true' },
				style: { position: 'relative' },
				traits: [
					{
						type: 'select',
						name: 'lang',
						label: t( 'Язык' ),
						changeProp: true,
						options: LANG_OPTIONS,
					},
					{
						type: 'textarea',
						name: 'code',
						label: t( 'Код' ),
						changeProp: true,
					},
				],
				lang: 'js',
				code: DEFAULT_CODE_SAMPLE,
			},
			init() {
				this.on( 'change:lang change:code', this.onValueChange );
				this.onValueChange();
			},
			onValueChange() {
				const lang = this.get( 'lang' ) || 'plain';
				this.components(
					renderCodeBlockHtml( this.get( 'code' ), lang )
				);
			},
		},
	} );
}

export const CODEBLOCK_ELEMENT_DEFS = [
	{
		id: 'wpgjb-el-codeblock',
		label: t( 'Блок кода' ),
		type: 'wpgjb-codeblock',
		icon: 'code',
		group: 'interactive',
	},
];

export const CODEBLOCK_ELEMENT_IDS = CODEBLOCK_ELEMENT_DEFS.map(
	( def ) => def.id
);

export function registerCodeblockElementBlocks( editor ) {
	registerCodeBlockType( editor );

	CODEBLOCK_ELEMENT_DEFS.forEach( ( def ) => {
		editor.BlockManager.add( def.id, {
			label: def.label,
			category: elementCategory( def.group ),
			media: icon( def.icon, 28 ),
			content: { type: def.type },
		} );
	} );
}
