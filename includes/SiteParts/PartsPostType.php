<?php

namespace WPGJBuilder\SiteParts;

defined( 'ABSPATH' ) || exit;

/**
 * Части сайта (раздел 7 спеки) хранятся как обычные WP-посты скрытого
 * типа `wpb_part` — переиспользуем УЖЕ существующий post_id-keyed слой
 * хранения (DocumentRepository/DocumentsController/PublishController/
 * EditorPage уже умеют работать с type=header|footer|sidebar|template,
 * см. Phase 1-3), не изобретаем параллельный механизм хранения ради
 * шапки/подвала. `capability_type => 'page'` + `map_meta_cap` — чтобы
 * права редактирования проверялись штатным `current_user_can('edit_post', ...)`,
 * без отдельной капабилити-модели для частей сайта.
 */
class PartsPostType {

	const POST_TYPE = 'wpb_part';

	const META_PART_TYPE  = '_wpb_part_type';
	const META_CONDITIONS = '_wpb_display_conditions';

	/**
	 * Раздел 7 (вторая очередь): "sidebar" добавлен наравне с header/footer —
	 * движок display conditions и это хранилище с самого начала (Phase 5)
	 * построены обобщённо над "типом части", не хардкодят только шапку/
	 * подвал, поэтому добавление третьего типа — единственная точка
	 * изменения, не переработка модели.
	 */
	const PART_TYPES = array( 'header', 'footer', 'sidebar' );

	public static function register() {
		register_post_type(
			self::POST_TYPE,
			array(
				'label'           => __( 'Части сайта', 'wp-gj-builder' ),
				'public'          => false,
				'show_ui'         => false, // своя админ-страница (SitePartsPage), не стандартный список CPT.
				'show_in_rest'    => false, // свой REST-контроллер (SitePartsController), не core wp/v2.
				'supports'        => array( 'title' ),
				'capability_type' => 'page',
				'map_meta_cap'    => true,
			)
		);
	}

	public static function create( string $part_type, string $title, int $user_id ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_status' => 'draft',
				'post_title'  => $title,
				'post_author' => $user_id,
			)
		);
		update_post_meta( $post_id, self::META_PART_TYPE, $part_type );
		return (int) $post_id;
	}

	public static function get_part_type( int $post_id ) {
		$type = get_post_meta( $post_id, self::META_PART_TYPE, true );
		return $type ? $type : null;
	}

	/**
	 * @return array<int, array{scope:string, mode:string, target:mixed}>
	 */
	public static function get_conditions( int $post_id ): array {
		$raw     = get_post_meta( $post_id, self::META_CONDITIONS, true );
		$decoded = $raw ? json_decode( $raw, true ) : null;
		return is_array( $decoded ) ? $decoded : array();
	}

	public static function set_conditions( int $post_id, array $conditions ) {
		update_post_meta( $post_id, self::META_CONDITIONS, wp_json_encode( array_values( $conditions ) ) );
	}

	/**
	 * @return \WP_Post[]
	 */
	public static function list_by_type( string $part_type ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'meta_key'       => self::META_PART_TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $part_type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => -1,
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);
		return $query->posts;
	}

	/**
	 * Все опубликованные части (для резолвинга на фронтенде — раздел 7).
	 *
	 * @return \WP_Post[]
	 */
	public static function list_published( string $part_type ): array {
		$query = new \WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'meta_key'       => self::META_PART_TYPE, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => $part_type, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'post_status'    => 'publish',
				'posts_per_page' => -1,
			)
		);
		return $query->posts;
	}
}
