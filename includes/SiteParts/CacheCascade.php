<?php

namespace WPGJBuilder\SiteParts;

defined( 'ABSPATH' ) || exit;

/**
 * "Изменение опубликованной шапки/подвала = сброс HTML-кэша всех
 * затронутых страниц; операция явная, с сообщением «Обновление
 * применится ко всем страницам сайта»" (раздел 7 спеки). Слушает
 * `wpgjb_after_publish` — уже существующую точку расширения Publisher'а
 * (Render/Publisher.php намеренно не знает о частях сайта, см. его
 * собственный комментарий "точка расширения для Phase 5"), не трогая
 * общий publish-пайплайн ради этой специфичной для частей логики.
 */
class CacheCascade {

	/** @var array{type:string, affected_pages:int}|null Результат последнего вызова — читается PublishController сразу после Publisher::publish() в том же запросе. */
	public static $last_result = null;

	public static function register_hooks() {
		add_action( 'wpgjb_after_publish', array( self::class, 'on_after_publish' ), 10, 2 );
	}

	public static function on_after_publish( $post_id, $type ) {
		self::$last_result = null;

		// Раздел 7 (вторая очередь): "sidebar" — часть сайта наравне с
		// header/footer, каскад инвалидации не должен быть специфичен для
		// конкретных двух типов.
		if ( ! in_array( $type, PartsPostType::PART_TYPES, true ) ) {
			return;
		}

		$count = self::invalidate_for_part( (int) $post_id, $type );

		self::$last_result = array(
			'type'           => $type,
			'affected_pages' => $count,
		);
	}

	/** @return int Число страниц, для которых был сброшен кэш. */
	public static function invalidate_for_part( int $part_id, string $part_type ): int {
		$conditions = PartsPostType::get_conditions( $part_id );
		$affected   = self::find_affected_pages( $conditions );

		foreach ( $affected as $page_id ) {
			clean_post_cache( $page_id );
		}

		return count( $affected );
	}

	/**
	 * Полный перебор опубликованных страниц — простая, но корректная
	 * реализация для MVP (оптимизация "резолвится один раз, не на каждый
	 * запрос" — раздел 11, задача Phase 6, здесь важна корректность
	 * каскада, а не его цена при большом числе страниц).
	 *
	 * @return int[]
	 */
	private static function find_affected_pages( array $conditions ): array {
		$front_page_id = (int) get_option( 'page_on_front' );
		$page_ids      = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'numberposts'    => -1,
				'fields'         => 'ids',
			)
		);

		$affected = array();
		foreach ( $page_ids as $page_id ) {
			$context = array(
				'post_id'       => $page_id,
				'post_type'     => 'page',
				'is_front_page' => $front_page_id === (int) $page_id,
				'is_404'        => false,
				'is_archive'    => false,
				'category_ids'  => array(),
			);
			if ( false !== DisplayConditions::matches( $conditions, $context ) ) {
				$affected[] = (int) $page_id;
			}
		}

		return $affected;
	}
}
