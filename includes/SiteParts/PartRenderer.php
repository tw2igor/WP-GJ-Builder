<?php

namespace WPGJBuilder\SiteParts;

use WPGJBuilder\DynamicTags\BatchResolver;
use WPGJBuilder\Render\Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * Общий рендер части сайта, переиспользуемый ClassicThemeInjector и
 * BlockThemeInjector — одна реализация, не дублируется по темам.
 * Динамические теги резолвятся относительно ТЕКУЩЕЙ отображаемой
 * страницы (не собственного поста части) — {{wpb:post_title}} в шапке
 * должен показывать заголовок реальной страницы, которую открыл
 * посетитель.
 */
class PartRenderer {

	public static function render( \WP_Post $part ): string {
		$current_post = get_post( get_queried_object_id() );
		$html         = $current_post instanceof \WP_Post
			? BatchResolver::resolve( $part->post_content, $current_post )
			: $part->post_content;

		$css = get_post_meta( $part->ID, Publisher::META_CSS, true );

		return $css ? '<style>' . $css . '</style>' . $html : $html;
	}
}
