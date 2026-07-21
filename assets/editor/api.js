/**
 * Тонкий REST-клиент поверх эндпоинтов Phase 1 (save/load) и Phase 2
 * (publish) — единственное место, где редактор знает URL/nonce. Ничего
 * не кеширует и не хранит состояние — вызывающий код (index.js) сам
 * решает, когда сохранять/публиковать.
 */

const { restRoot, restNonce, namespace, postId, type } = window.wpgjbEditorData;

function request( path, options = {} ) {
	return fetch( `${ restRoot }${ namespace }/${ path }`, {
		...options,
		headers: {
			'Content-Type': 'application/json',
			'X-WP-Nonce': restNonce,
			...( options.headers || {} ),
		},
		credentials: 'same-origin',
	} ).then( async ( res ) => {
		let data = null;
		try {
			data = await res.json();
		} catch ( e ) {
			data = null;
		}
		return { status: res.status, data };
	} );
}

export function loadDocument() {
	return request( `documents/${ postId }/${ type }` );
}

export function saveDraft( projectData, docVersion ) {
	const body = { project_data: projectData };
	// doc_version отсутствует у совсем нового документа (null) — REST-схема
	// объявляет его как integer, явный JSON null не проходит валидацию типа
	// (обнаружено реальным прогоном: 400 Bad Request на первом автосохранении
	// новой страницы). Ключ должен ОТСУТСТВОВАТЬ, а не быть null.
	if ( null !== docVersion && undefined !== docVersion ) {
		body.doc_version = docVersion;
	}
	return request( `documents/${ postId }/${ type }`, {
		method: 'POST',
		body: JSON.stringify( body ),
	} );
}

export function publish( projectData, html, css ) {
	return request( `documents/${ postId }/${ type }/publish`, {
		method: 'POST',
		body: JSON.stringify( { project_data: projectData, html, css } ),
	} );
}

export function fetchThemeTokens() {
	return request( 'theme/tokens' ).then( ( r ) => ( r.data && r.data.tokens ) || [] );
}

export function fetchThemeChrome() {
	return request( 'theme/chrome' ).then( ( r ) => r.data || { header: '', footer: '', isBlockTheme: false } );
}

export function updatePageSettings( { title, slug, status, featuredMedia, pageTemplate } ) {
	return request( `documents/${ postId }/${ type }/page-settings`, {
		method: 'POST',
		body: JSON.stringify( {
			title,
			slug,
			status,
			featured_media: featuredMedia,
			page_template: pageTemplate,
		} ),
	} );
}
