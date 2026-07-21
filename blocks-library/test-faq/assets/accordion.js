/**
 * Раскрывающийся вопрос/ответ (раздел 11: JS конкретного блока подключается
 * только на страницах, где блок реально есть — см. PageAssets::resolve_js_assets()).
 * Работает через делегирование на document — не требует инициализации на
 * каждый инстанс блока и переживает несколько FAQ-блоков на одной странице.
 */
( function () {
	if ( ! window.WPGJB || ! window.WPGJB.onDelegate ) {
		return;
	}

	window.WPGJB.onDelegate( '.wpb-faq__question', 'click', function ( e, button ) {
		var item = button.closest( '.wpb-faq__item' );
		var answer = item ? item.querySelector( '.wpb-faq__answer' ) : null;
		if ( ! answer ) {
			return;
		}
		var expanded = 'true' === button.getAttribute( 'aria-expanded' );
		button.setAttribute( 'aria-expanded', expanded ? 'false' : 'true' );
		if ( expanded ) {
			answer.setAttribute( 'hidden', '' );
		} else {
			answer.removeAttribute( 'hidden' );
		}
	} );
} )();
