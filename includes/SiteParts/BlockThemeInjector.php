<?php

namespace WPGJBuilder\SiteParts;

defined( 'ABSPATH' ) || exit;

/**
 * Внедрение в блочные (FSE) темы (раздел 7 спеки): полное замещение —
 * штатный фильтр `render_block_core/template-part` перехватывает вывод
 * template part с нужным slug ДО того, как он попадёт в финальный HTML,
 * заменяя его целиком нашей частью (в отличие от классических тем, где
 * возможна только вставка над/под — здесь ядро само даёт точку замены).
 */
class BlockThemeInjector {

	public static function register_hooks() {
		add_filter( 'render_block_core/template-part', array( self::class, 'maybe_replace' ), 10, 2 );
	}

	public static function maybe_replace( $block_content, $block ) {
		$slug = $block['attrs']['slug'] ?? '';
		// Раздел 7 (вторая очередь): "sidebar" добавлен наравне с header/footer —
		// сработает ТОЛЬКО если у активной темы вообще есть template part с
		// таким slug (в отличие от header/footer это не универсально для
		// блочных тем — многие FSE-темы вообще не имеют отдельного слота
		// "sidebar"). Если слота нет, фильтр для него просто никогда не
		// вызовется — честное ограничение, не молчаливый провал.
		if ( ! in_array( $slug, array( 'header', 'footer', 'sidebar' ), true ) ) {
			return $block_content;
		}

		$post = DisplayConditions::resolve_for_type( $slug, DisplayConditions::current_context() );
		if ( ! $post ) {
			// Нет совпавшей опубликованной части — честно оставляем то, что
			// уже отдаёт сама тема, не пытаемся подменить пустотой.
			return $block_content;
		}

		return PartRenderer::render( $post );
	}
}
