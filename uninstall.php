<?php
/**
 * Полная очистка выполняется только если пользователь явно включил опцию
 * "удалить данные при удалении" (раздел 12 спеки) — по умолчанию выключена,
 * управляется на экране "Настройки" (SecurityPage). ВАЖНО: когда в корне
 * плагина есть uninstall.php, WordPress НЕ загружает основной файл плагина
 * (wp-gj-builder.php) и его автолоадер — поэтому здесь регистрируется тот
 * же PSR-4-фоллбэк самостоятельно, а не через bootstrap.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

if ( ! get_option( 'wpgjb_delete_data_on_uninstall', false ) ) {
	return;
}

$wpgjb_uninstall_dir = __DIR__ . '/';

if ( file_exists( $wpgjb_uninstall_dir . 'vendor/autoload.php' ) ) {
	require_once $wpgjb_uninstall_dir . 'vendor/autoload.php';
}

spl_autoload_register(
	function ( $class ) use ( $wpgjb_uninstall_dir ) {
		$prefix = 'WPGJBuilder\\';
		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = $wpgjb_uninstall_dir . 'includes' . DIRECTORY_SEPARATOR
			. str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

\WPGJBuilder\Storage\Cleanup::full_cleanup();
