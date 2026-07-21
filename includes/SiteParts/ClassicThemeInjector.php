<?php

namespace WPGJBuilder\SiteParts;

defined( 'ABSPATH' ) || exit;

/**
 * Внедрение в классические темы (раздел 7 спеки): перехват get_header/
 * get_footer с ЧЕСТНОЙ индикацией уровня поддержки — ядро WP не даёт
 * механизма "заменить" вызов get_header() темы, только выполнить код
 * ДО подключения её header.php через экшн 'get_header'. Поэтому уровень
 * поддержки для классических тем — "вставка над/под" (одно из трёх
 * документированных состояний раздела 7: "полное замещение / вставка
 * над-под / тема несовместима"), а не полное замещение — это не
 * недоработка, а честный предел возможностей классических тем без
 * парсинга их шаблонов.
 */
class ClassicThemeInjector {

	public static function register_hooks() {
		add_action( 'get_header', array( self::class, 'inject_header' ) );
		add_action( 'get_footer', array( self::class, 'inject_footer' ) );
		// Раздел 7 (вторая очередь): 'get_sidebar' — тот же шаблонный тег,
		// что get_header()/get_footer(), срабатывает ТОЛЬКО когда тема
		// вызывает get_sidebar() БЕЗ имени (get_sidebar('shop') и т.п.
		// именованные сайдбары стреляют get_sidebar_{name} — этот случай
		// сознательно не покрыт первым проходом, тот же честный предел
		// "вставка", не "замена", что и у header/footer).
		add_action( 'get_sidebar', array( self::class, 'inject_sidebar' ) );
	}

	public static function inject_header() {
		self::inject( 'header' );
	}

	public static function inject_footer() {
		self::inject( 'footer' );
	}

	public static function inject_sidebar() {
		self::inject( 'sidebar' );
	}

	private static function inject( string $part_type ) {
		if ( wp_is_block_theme() ) {
			// Блочные темы обслуживает BlockThemeInjector (render_block_core/
			// template-part) — здесь ничего не делаем, иначе часть вставится дважды.
			return;
		}

		$post = DisplayConditions::resolve_for_type( $part_type, DisplayConditions::current_context() );
		if ( ! $post ) {
			return;
		}

		echo PartRenderer::render( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- уже прошло контур 2 при публикации части.
	}
}
