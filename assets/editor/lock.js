/**
 * Блокировка одновременного редактирования (раздел 5.5 спеки): "через
 * штатный механизм WordPress (Heartbeat)". Ядро WP уже привязывает
 * `wp_refresh_post_lock()` к фильтру `heartbeat_received` безусловно
 * (wp-includes/default-filters.php) — нам не нужен свой PHP-обработчик,
 * достаточно на клиенте отправлять/читать данные в том же формате, что
 * использует стандартный экран редактирования поста (post.php).
 */

const { postId } = window.wpgjbEditorData;

/**
 * @param {{onTakeover: (lockedBy: {text:string}) => void}} handlers
 */
export function initLock( handlers ) {
	if ( ! window.jQuery || ! window.wp || ! window.wp.heartbeat ) {
		// Скрипт 'heartbeat' не подключился (напр. отключён другим плагином) —
		// без него нет способа держать штатный лок живым; работаем без
		// детекции конфликтов, а не падаем.
		return;
	}

	const $ = window.jQuery;

	$( document ).on( 'heartbeat-send', ( event, data ) => {
		data[ 'wp-refresh-post-lock' ] = { post_id: postId };
	} );

	$( document ).on( 'heartbeat-tick', ( event, data ) => {
		const lockData = data[ 'wp-refresh-post-lock' ];
		if ( lockData && lockData.lock_error ) {
			handlers.onTakeover( lockData.lock_error );
		}
	} );
}
