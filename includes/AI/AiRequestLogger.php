<?php

namespace WPGJBuilder\AI;

defined( 'ABSPATH' ) || exit;

/**
 * Файловый лог запросов/ответов AI-генерации страниц — по прямой просьбе
 * пользователя ("предусмотреть файл в папке плагина с логами, чтобы
 * отслеживать запросы и ответы"). Сознательно НЕ через `Diagnostics::log()`
 * (общий канал, кольцевой буфер на 200 записей в опции WP, делит место со
 * всей остальной диагностикой плагина) — это отдельный, читаемый файл
 * специально под отладку промптов/ответов модели, где записи могут быть
 * длинными и их может понадобиться читать целиком. Bearer-токен НИКОГДА
 * не попадает в этот файл (сам токен нигде не проходит через объекты,
 * которые здесь логируются, — только messages/ответ/результат сборки).
 */
class AiRequestLogger {

	private static function log_dir(): string {
		return WPGJB_PLUGIN_DIR . 'logs';
	}

	private static function log_file(): string {
		return self::log_dir() . '/ai-requests.log';
	}

	/**
	 * @param int   $attempt      Номер попытки (1-based).
	 * @param int   $max_attempts Максимум попыток за этот вызов генерации.
	 * @param array $brief        {niche, page_type, tone, details}.
	 * @param array $messages     Полный массив messages, отправляемый в TWC AI на этой попытке.
	 */
	public static function log_request( int $attempt, int $max_attempts, array $brief, array $messages ): void {
		$lines   = array();
		$lines[] = str_repeat( '=', 70 );
		$lines[] = sprintf( '[%s] user=%d attempt=%d/%d', gmdate( 'Y-m-d H:i:s' ), get_current_user_id(), $attempt, $max_attempts );
		$lines[] = sprintf(
			'BRIEF: niche=%s page_type=%s tone=%s details=%s',
			wp_json_encode( $brief['niche'] ?? '', JSON_UNESCAPED_UNICODE ),
			wp_json_encode( $brief['page_type'] ?? '', JSON_UNESCAPED_UNICODE ),
			wp_json_encode( $brief['tone'] ?? '', JSON_UNESCAPED_UNICODE ),
			wp_json_encode( $brief['details'] ?? '', JSON_UNESCAPED_UNICODE )
		);
		$lines[] = '--- REQUEST (messages) ---';
		foreach ( $messages as $message ) {
			$lines[] = sprintf( '[%s]', strtoupper( (string) ( $message['role'] ?? '?' ) ) );
			$lines[] = (string) ( $message['content'] ?? '' );
		}

		self::append( implode( "\n", $lines ) . "\n" );
	}

	/**
	 * @param string|\WP_Error $reply     Ответ `TwcAiClient::chat_completion()`.
	 * @param array|null       $assembled {status, data} результат внутреннего REST-диспетча на /pages/assemble, null если $reply — уже ошибка.
	 */
	public static function log_response( $reply, ?array $assembled ): void {
		$lines   = array();
		$lines[] = '--- RESPONSE ---';
		$lines[] = is_wp_error( $reply ) ? ( 'WP_Error: ' . $reply->get_error_message() ) : (string) $reply;

		if ( null !== $assembled ) {
			$lines[] = '--- RESULT ---';
			$lines[] = sprintf( 'http_status=%d data=%s', $assembled['status'], wp_json_encode( $assembled['data'], JSON_UNESCAPED_UNICODE ) );
		}

		self::append( implode( "\n", $lines ) . "\n\n" );
	}

	private static function append( string $text ): void {
		self::ensure_dir();
		// Без LOCK_EX: конкурентная запись здесь не критична (один
		// администратор запускает генерацию за раз), а на некоторых
		// файловых системах (в т.ч. эмпирически — виртуальная ФС WP
		// Playground при разработке) file_put_contents() с LOCK_EX молча
		// не пишет ничего вместо ошибки — обнаружено реальным прогоном,
		// не по документации.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( self::log_file(), $text, FILE_APPEND );
	}

	/**
	 * Плагин лежит внутри wp-content/plugins — сам файл лога теоретически
	 * достижим по прямому URL, если хостинг не гейтит доступ отдельно.
	 * `.htaccess` deny — стандартная защита на Apache (на nginx не
	 * сработает сама по себе, но и не мешает), `index.php` — против
	 * листинга директории на серверах без .htaccess вовсе.
	 */
	private static function ensure_dir(): void {
		if ( is_dir( self::log_dir() ) ) {
			return;
		}
		wp_mkdir_p( self::log_dir() );
		file_put_contents( self::log_dir() . '/.htaccess', "Deny from all\n" );
		file_put_contents( self::log_dir() . '/index.php', "<?php\n// Silence is golden.\n" );
	}
}
