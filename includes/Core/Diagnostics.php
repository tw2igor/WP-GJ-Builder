<?php

namespace WPGJBuilder\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Единый формат журнала ошибок рендера/миграций (раздел 12 спеки).
 * На Phase 0 — минимальная реализация (error_log + опция-кольцевой буфер
 * для последних записей, читаемых будущим экраном "Диагностика" из
 * Phase 6); формат записи стабилен с самого начала, чтобы не потребовалась
 * миграция логов позже.
 */
class Diagnostics {

	const OPTION_LOG = 'wpgjb_diagnostics_log';
	const MAX_ENTRIES = 200;

	/**
	 * @param string $channel  например 'migration', 'render', 'save-conflict'
	 * @param string $message
	 * @param array  $context  произвольные доп. данные (post_id, document id, версии...)
	 */
	public static function log( $channel, $message, array $context = array() ) {
		$entry = array(
			'time'    => gmdate( 'c' ),
			'channel' => $channel,
			'message' => $message,
			'context' => $context,
		);

		error_log( sprintf( '[wp-gj-builder][%s] %s %s', $channel, $message, wp_json_encode( $context ) ) );

		$log   = get_option( self::OPTION_LOG, array() );
		$log[] = $entry;
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, -1 * self::MAX_ENTRIES );
		}
		update_option( self::OPTION_LOG, $log, false );
	}

	public static function recent( $limit = 50 ) {
		$log = get_option( self::OPTION_LOG, array() );
		return array_slice( $log, -1 * $limit );
	}
}
