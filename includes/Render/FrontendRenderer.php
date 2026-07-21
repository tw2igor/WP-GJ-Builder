<?php

namespace WPGJBuilder\Render;

use WPGJBuilder\DynamicTags\BatchResolver;
use WPGJBuilder\Rest\ThemeStylesController;

defined( 'ABSPATH' ) || exit;

/**
 * Отдаёт опубликованную конструктором страницу посетителю: статический
 * HTML уже лежит в post_content (раздел 11 — "ничего не собирается на
 * лету, кроме батч-резолва динамических тегов"), CSS страницы — из
 * postmeta. Гейт `_wpb_built` — чтобы резолвер вообще не трогал обычные
 * посты/страницы сайта, где `{{...}}` в тексте — просто текст.
 */
class FrontendRenderer {

	public static function register_hooks() {
		add_filter( 'the_content', array( self::class, 'resolve_dynamic_tags' ), 20 );
		add_action( 'wp_head', array( self::class, 'output_page_css' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_page_assets' ) );
	}

	public static function resolve_dynamic_tags( $content ) {
		$post = get_post();
		if ( ! self::is_wpb_built( $post ) ) {
			return $content;
		}
		return BatchResolver::resolve( $content, $post );
	}

	public static function output_page_css() {
		$post = get_post();
		if ( is_admin() || ! self::is_wpb_built( $post ) ) {
			return;
		}

		$css = get_post_meta( $post->ID, Publisher::META_CSS, true );
		if ( '' === $css ) {
			return;
		}

		// Уже прошёл Contour2PrePublish::sanitize_document() при публикации —
		// повторная санитизация здесь не нужна и не выполняется.
		echo '<style id="wpb-page-css">' . $css . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Раздел 11: (1) общий, долгокэшируемый файл платформенных блоков — одна
	 * и та же версионированная ссылка на каждой странице сайта, браузер
	 * скачивает её один раз; (2) JS конкретных блоков — резолвится один раз
	 * при публикации (Publisher::publish() -> PageAssets), здесь только
	 * читается из postmeta и подключается ТОЛЬКО если на странице реально
	 * есть блок(и), объявившие requirements.assets.js — на остальных
	 * страницах сайта ни общий рантайм, ни block-специфичный JS не грузятся.
	 * Параллельно: `wpgjb-elements-runtime` (countdown/counter/gallery/
	 * slider — свободные "Элементы", вне манифест-контракта Блоков) грузится
	 * по отдельному флагу `PageAssets::META_INTERACTIVE`, независимо от
	 * наличия block-специфичного JS.
	 */
	public static function enqueue_page_assets() {
		$post = get_post();
		if ( is_admin() || ! self::is_wpb_built( $post ) ) {
			return;
		}

		wp_enqueue_style(
			'wpgjb-platform-blocks',
			rest_url( ThemeStylesController::ROUTE_BLOCKS_STYLE ),
			array(),
			ThemeStylesController::blocks_style_version()
		);

		$assets             = json_decode( get_post_meta( $post->ID, PageAssets::META_JS, true ), true );
		$has_interactive_el = '1' === get_post_meta( $post->ID, PageAssets::META_INTERACTIVE, true );
		$has_block_assets   = ! empty( $assets ) && is_array( $assets );

		if ( ! $has_block_assets && ! $has_interactive_el ) {
			return;
		}

		wp_register_script( 'wpgjb-runtime', WPGJB_PLUGIN_URL . 'assets/runtime/wpgjb-runtime.js', array(), WPGJB_VERSION, true );

		if ( $has_block_assets ) {
			foreach ( $assets as $asset ) {
				if ( empty( $asset['block_id'] ) || empty( $asset['url'] ) ) {
					continue;
				}
				wp_enqueue_script( 'wpgjb-block-' . $asset['block_id'], $asset['url'], array( 'wpgjb-runtime' ), WPGJB_VERSION, true );
			}
		}

		if ( $has_interactive_el ) {
			wp_enqueue_script(
				'wpgjb-elements-runtime',
				WPGJB_PLUGIN_URL . 'assets/runtime/wpgjb-elements-runtime.js',
				array( 'wpgjb-runtime' ),
				WPGJB_VERSION,
				true
			);
		}
	}

	private static function is_wpb_built( $post ) {
		return $post instanceof \WP_Post && '1' === get_post_meta( $post->ID, Publisher::META_BUILT, true );
	}
}
