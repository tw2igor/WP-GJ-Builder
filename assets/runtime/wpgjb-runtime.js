/**
 * Раздел 11 спеки: "общий рантайм конструктора — один маленький файл, и
 * только если на странице есть интерактивные блоки". Это НЕ часть
 * webpack-сборки редактора (assets/editor/) — простой, независимый файл,
 * который подключается на ФРОНТЕНДЕ через обычный wp_enqueue_script(),
 * только если манифест хотя бы одного блока на опубликованной странице
 * объявляет requirements.assets.js (см. includes/Render/PageAssets.php).
 * Блок-специфичные скрипты (напр. blocks-library/test-faq/assets/accordion.js)
 * объявляют его своей зависимостью — не дублируют делегирование событий сами.
 */
window.WPGJB = window.WPGJB || {};

window.WPGJB.onDelegate = function ( selector, event, handler ) {
	document.addEventListener( event, function ( e ) {
		var match = e.target.closest ? e.target.closest( selector ) : null;
		if ( match ) {
			handler( e, match );
		}
	} );
};
