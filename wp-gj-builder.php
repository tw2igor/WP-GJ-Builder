<?php
/**
 * Plugin Name:       WP-Builder
 * Description:       Конструктор страниц на GrapesJS: готовые смысловые блоки, наследующие стиль активной темы, статический HTML для посетителей, машиночитаемые манифесты блоков. См. docs/wp-builder-dev-spec.md.
 * Version:           0.1.9-dev
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp-gj-builder
 */

defined( 'ABSPATH' ) || exit;

define( 'WPGJB_VERSION', '0.1.9-dev' );
define( 'WPGJB_PLUGIN_FILE', __FILE__ );
define( 'WPGJB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPGJB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WPGJB_DB_SCHEMA_VERSION', 1 );

/**
 * Автозагрузка: используем Composer-автолоадер, если он сгенерирован
 * (composer install), и в любом случае регистрируем собственный PSR-4
 * фоллбэк на пространство имён WPGJBuilder\ -> includes/, чтобы плагин
 * работал и без установленного локально Composer (см. Phase 0 плана —
 * в текущей среде разработки нет ни PHP CLI, ни Composer).
 */
if ( file_exists( WPGJB_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	require_once WPGJB_PLUGIN_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	function ( $class ) {
		$prefix = 'WPGJBuilder\\';
		if ( strncmp( $prefix, $class, strlen( $prefix ) ) !== 0 ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$path     = WPGJB_PLUGIN_DIR . 'includes' . DIRECTORY_SEPARATOR
			. str_replace( '\\', DIRECTORY_SEPARATOR, $relative ) . '.php';
		if ( file_exists( $path ) ) {
			require $path;
		}
	}
);

register_activation_hook( WPGJB_PLUGIN_FILE, array( 'WPGJBuilder\\Core\\Activation', 'activate' ) );
register_deactivation_hook( WPGJB_PLUGIN_FILE, array( 'WPGJBuilder\\Core\\Activation', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'WPGJBuilder\\Core\\Plugin', 'boot' ) );
