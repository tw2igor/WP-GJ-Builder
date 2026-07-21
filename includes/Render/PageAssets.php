<?php

namespace WPGJBuilder\Render;

use WPGJBuilder\Blocks\BlockLibrary;

defined( 'ABSPATH' ) || exit;

/**
 * Раздел 11 спеки: "манифест блока декларирует requirements.assets; при
 * публикации разница блоков на странице резолвится один раз в postmeta, не
 * пересчитывается на каждый запрос — wp_enqueue_scripts просто читает
 * список." Единое место сбора "какие блоки реально есть на странице" —
 * тем же обходом дерева project_data, что Publisher использует для сбора
 * CSS использованных блоков (не два разных обхода одного и того же дерева).
 */
class PageAssets {

	const META_JS = '_wpb_page_js_assets';
	const META_INTERACTIVE = '_wpb_page_interactive_elements';

	/**
	 * Маркер-атрибуты интерактивных "Элементов" (elements-interactive.js) —
	 * они НЕ участвуют в манифест/BlockLibrary-контракте (нет data-wpb-block),
	 * поэтому им нужен отдельный, параллельный collect_block_ids() обход
	 * дерева project_data, а не расширение resolve_js_assets().
	 */
	const INTERACTIVE_MARKER_ATTRIBUTES = array(
		'data-wpgjb-countdown',
		'data-wpgjb-counter',
		'data-wpgjb-gallery',
		'data-wpgjb-slider',
		'data-wpgjb-chart',
		'data-wpgjb-datatable',
		'data-wpgjb-tabs',
		'data-wpgjb-flipcard',
		'data-wpgjb-hotspot',
		'data-wpgjb-codeblock',
	);

	/** @return string[] уникальные data-wpb-block, реально присутствующие в project_data */
	public static function collect_block_ids( array $project_data ): array {
		$ids = array();
		if ( ! empty( $project_data['pages'] ) && is_array( $project_data['pages'] ) ) {
			foreach ( $project_data['pages'] as $page ) {
				if ( empty( $page['frames'] ) || ! is_array( $page['frames'] ) ) {
					continue;
				}
				foreach ( $page['frames'] as $frame ) {
					if ( ! empty( $frame['component'] ) ) {
						self::collect_block_ids_recursive( $frame['component'], $ids );
					}
				}
			}
		}
		return array_values( array_unique( $ids ) );
	}

	private static function collect_block_ids_recursive( $component, array &$ids ) {
		if ( ! is_array( $component ) ) {
			return;
		}
		if ( ! empty( $component['attributes']['data-wpb-block'] ) ) {
			$ids[] = $component['attributes']['data-wpb-block'];
		}
		if ( ! empty( $component['components'] ) && is_array( $component['components'] ) ) {
			foreach ( $component['components'] as $child ) {
				self::collect_block_ids_recursive( $child, $ids );
			}
		}
	}

	/**
	 * @return array<int, array{block_id: string, url: string}> только для блоков,
	 * реально присутствующих на странице и объявивших requirements.assets.js.
	 */
	public static function resolve_js_assets( array $project_data ): array {
		$assets = array();
		foreach ( self::collect_block_ids( $project_data ) as $block_id ) {
			$block = BlockLibrary::get( $block_id );
			if ( ! $block ) {
				continue;
			}
			$files = $block['manifest']['requirements']['assets']['js'] ?? array();
			foreach ( $files as $file ) {
				$assets[] = array(
					'block_id' => $block_id,
					'url'      => WPGJB_PLUGIN_URL . "blocks-library/{$block_id}/assets/{$file}",
				);
			}
		}
		return $assets;
	}

	/** @return bool на странице есть хотя бы один интерактивный "Элемент" (countdown/counter/gallery/slider). */
	public static function has_interactive_elements( array $project_data ): bool {
		if ( ! empty( $project_data['pages'] ) && is_array( $project_data['pages'] ) ) {
			foreach ( $project_data['pages'] as $page ) {
				if ( empty( $page['frames'] ) || ! is_array( $page['frames'] ) ) {
					continue;
				}
				foreach ( $page['frames'] as $frame ) {
					if ( ! empty( $frame['component'] ) && self::has_interactive_elements_recursive( $frame['component'] ) ) {
						return true;
					}
				}
			}
		}
		return false;
	}

	private static function has_interactive_elements_recursive( $component ): bool {
		if ( ! is_array( $component ) ) {
			return false;
		}
		foreach ( self::INTERACTIVE_MARKER_ATTRIBUTES as $attribute ) {
			if ( ! empty( $component['attributes'][ $attribute ] ) ) {
				return true;
			}
		}
		if ( ! empty( $component['components'] ) && is_array( $component['components'] ) ) {
			foreach ( $component['components'] as $child ) {
				if ( self::has_interactive_elements_recursive( $child ) ) {
					return true;
				}
			}
		}
		return false;
	}
}
