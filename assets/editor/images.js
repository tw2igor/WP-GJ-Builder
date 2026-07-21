/**
 * Раздел 11 спеки: реальное разрешение image-слотов (ID вложения -> src/
 * srcset/sizes/alt) вместо голого data-slot-img-id. HTML публикуется
 * клиентом ОДИН раз в момент публикации (editor.getHtml()) — поэтому
 * разметка с реальными src/srcset должна быть уже в DOM canvas'а на
 * момент публикации, а не досчитываться сервером. Кэш работает синхронно
 * там, где данные уже есть (сразу после выбора в wp.media — Backbone-модель
 * приходит с полным набором sizes без REST round-trip), и асинхронно
 * догружает через REST wp/v2/media для уже сохранённых ID при открытии
 * документа.
 */

const attachmentCache = new Map();

function sizesFromWpMediaSizes( sizesObj ) {
	return Object.values( sizesObj || {} ).filter( ( s ) => s && s.url );
}

function buildSrcsetAndSizes( entries, widthKey = 'width', urlKey = 'url' ) {
	const withWidth = entries.filter( ( e ) => e[ widthKey ] );
	if ( ! withWidth.length ) {
		return { srcset: null, sizes: null };
	}
	const srcset = withWidth.map( ( e ) => `${ e[ urlKey ] } ${ e[ widthKey ] }w` ).join( ', ' );
	const maxWidth = Math.max( ...withWidth.map( ( e ) => e[ widthKey ] ) );
	return { srcset, sizes: `(max-width: ${ maxWidth }px) 100vw, ${ maxWidth }px` };
}

/** Кэширует уже загруженную Backbone-модель wp.media (после выбора в пикере) — без REST round-trip. */
export function cacheFromMediaModel( id, attributes ) {
	const entries = sizesFromWpMediaSizes( attributes.sizes ).map( ( s ) => ( { url: s.url, width: s.width } ) );
	const { srcset, sizes } = buildSrcsetAndSizes( entries );
	const resolved = {
		src: attributes.url,
		srcset,
		sizes,
		alt: attributes.alt || '',
		thumbnail: attributes.sizes?.thumbnail?.url || attributes.sizes?.medium?.url || attributes.url,
	};
	attachmentCache.set( String( id ), resolved );
	return resolved;
}

export function getCachedAttachment( id ) {
	return attachmentCache.get( String( id ) ) || null;
}

/** Резолв уже сохранённого ID (открытие документа, вставка через REST) — REST wp/v2/media, без прав редактирования attachments не требует ничего сверх обычного nonce редактора. */
export async function fetchAttachment( id, restRoot, nonce ) {
	const key = String( id );
	if ( attachmentCache.has( key ) ) {
		return attachmentCache.get( key );
	}
	if ( ! id || ! restRoot ) {
		return null;
	}

	const response = await fetch( `${ restRoot }wp/v2/media/${ id }`, {
		headers: { 'X-WP-Nonce': nonce },
	} );
	if ( ! response.ok ) {
		return null;
	}
	const data = await response.json();

	const entries = sizesFromWpMediaSizes( data.media_details?.sizes ).map( ( s ) => ( { url: s.source_url, width: s.width } ) );
	const { srcset, sizes } = buildSrcsetAndSizes( entries, 'width', 'url' );
	const resolved = {
		src: data.source_url,
		srcset,
		sizes,
		alt: data.alt_text || '',
		thumbnail: data.media_details?.sizes?.thumbnail?.source_url || data.source_url,
	};
	attachmentCache.set( key, resolved );
	return resolved;
}

/** Раздел 11: lazy по умолчанию, fetchpriority=high без lazy для слотов первого экрана (манифест: first_screen). */
export function applyResolvedImage( el, resolved, { firstScreen = false } = {} ) {
	if ( ! el || ! resolved || ! resolved.src ) {
		return;
	}
	el.setAttribute( 'src', resolved.src );
	if ( resolved.srcset ) {
		el.setAttribute( 'srcset', resolved.srcset );
		el.setAttribute( 'sizes', resolved.sizes );
	} else {
		el.removeAttribute( 'srcset' );
		el.removeAttribute( 'sizes' );
	}
	if ( resolved.alt ) {
		el.setAttribute( 'alt', resolved.alt );
	}
	if ( firstScreen ) {
		el.setAttribute( 'fetchpriority', 'high' );
		el.removeAttribute( 'loading' );
	} else {
		el.setAttribute( 'loading', 'lazy' );
		el.removeAttribute( 'fetchpriority' );
	}
}
