<?php

namespace WPGJBuilder\Render;

defined( 'ABSPATH' ) || exit;

class PageTemplates {

	const TEMPLATE_CANVAS = 'wpgjb-canvas.php';
	const TEMPLATE_FULL_WIDTH = 'wpgjb-full-width.php';
	const DEFAULT_TEMPLATE = self::TEMPLATE_FULL_WIDTH;

	public static function register_hooks() {
		add_filter( 'theme_page_templates', array( self::class, 'add_templates' ) );
		add_filter( 'template_include', array( self::class, 'maybe_override_template' ) );
	}

	public static function add_templates( $templates ) {
		$templates[ self::TEMPLATE_CANVAS ]     = __( 'Конструктор: Пустой холст', 'wp-gj-builder' );
		$templates[ self::TEMPLATE_FULL_WIDTH ] = __( 'Конструктор: Во всю ширину (с шапкой и подвалом темы)', 'wp-gj-builder' );
		return $templates;
	}

	public static function maybe_override_template( $template ) {
		if ( is_admin() || ! is_page() ) {
			return $template;
		}

		$selected = get_page_template_slug( get_queried_object_id() );

		if ( self::TEMPLATE_CANVAS === $selected ) {
			return WPGJB_PLUGIN_DIR . 'includes/Render/templates/wpgjb-canvas.php';
		}

		if ( self::TEMPLATE_FULL_WIDTH === $selected && ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			return WPGJB_PLUGIN_DIR . 'includes/Render/templates/wpgjb-full-width.php';
		}

		return $template;
	}

	public static function is_full_width_on_block_theme( int $post_id ): bool {
		return self::TEMPLATE_FULL_WIDTH === get_page_template_slug( $post_id )
			&& function_exists( 'wp_is_block_theme' )
			&& wp_is_block_theme();
	}
}
