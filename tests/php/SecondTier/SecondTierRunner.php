<?php

namespace WPGJBuilder\Tests\SecondTier;

use WPGJBuilder\DynamicTags\TagRegistry;
use WPGJBuilder\SiteParts\CacheCascade;
use WPGJBuilder\SiteParts\DisplayConditions;
use WPGJBuilder\SiteParts\PartsPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only проверка второй очереди (после MVP, восьмифазный план закрыт
 * Phase 7): сайдбары как третий тип части сайта наравне с header/footer,
 * ACF-теги в реестре динамических тегов, сборка страницы из
 * последовательности блоков (/pages/assemble).
 */
class SecondTierRunner {

	public static function run() {
		return array(
			'sidebar_is_valid_part_type'               => self::check_sidebar_is_valid_part_type(),
			'sidebar_resolves_via_display_conditions'   => self::check_sidebar_resolves(),
			'cache_cascade_handles_sidebar_type'        => self::check_cascade_handles_sidebar(),
			'conditions_rest_route_param_matches_check' => self::check_conditions_route_param(),
			'acf_tag_absent_when_acf_not_active'        => self::check_acf_tag_absent_without_acf(),
			'assemble_rejects_empty_sequence'           => self::check_assemble_rejects_empty_sequence(),
			'assemble_creates_page_with_valid_sequence' => self::check_assemble_creates_page(),
			'assemble_is_atomic_on_invalid_entry'       => self::check_assemble_atomic_on_invalid_entry(),
			'acf_raw_resolver_reads_simple_value'       => self::check_acf_resolver_simple_value(),
			'acf_raw_resolver_filters_serialized_value' => self::check_acf_resolver_filters_serialized(),
		);
	}

	private static function make_page( string $title ): int {
		return wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	private static function cleanup_posts( array $ids ) {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	private static function check_sidebar_is_valid_part_type() {
		$ok = in_array( 'sidebar', PartsPostType::PART_TYPES, true );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? '"sidebar" присутствует в PartsPostType::PART_TYPES наравне с header/footer'
				: 'ожидался "sidebar" в списке допустимых типов частей сайта',
			'result' => array( 'part_types' => PartsPostType::PART_TYPES ),
		);
	}

	private static function check_sidebar_resolves() {
		$page_id    = self::make_page( 'SecondTier Sidebar Target' );
		$sidebar_id = PartsPostType::create( 'sidebar', 'SecondTier Test Sidebar', get_current_user_id() );
		wp_publish_post( $sidebar_id );
		PartsPostType::set_conditions( $sidebar_id, array( array( 'scope' => DisplayConditions::SCOPE_SPECIFIC_PAGE, 'mode' => 'include', 'target' => $page_id ) ) );

		$resolved = DisplayConditions::resolve_for_type( 'sidebar', array( 'post_id' => $page_id, 'post_type' => 'page' ) );

		$ok = $resolved && (int) $resolved->ID === $sidebar_id;

		self::cleanup_posts( array( $page_id, $sidebar_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'DisplayConditions::resolve_for_type("sidebar", ...) находит опубликованный сайдбар с condition=specific_page — движок уже был обобщён в Phase 5, третий тип добавлен без переработки'
				: 'ожидалось разрешение сайдбара тем же движком specificity, что header/footer',
			'result' => array( 'resolved_id' => $resolved ? $resolved->ID : null, 'expected' => $sidebar_id ),
		);
	}

	private static function check_cascade_handles_sidebar() {
		$page_id    = self::make_page( 'SecondTier Sidebar Cascade Page' );
		$other_id   = self::make_page( 'SecondTier Sidebar Cascade Untouched' );
		$sidebar_id = PartsPostType::create( 'sidebar', 'SecondTier Cascade Sidebar', get_current_user_id() );
		wp_publish_post( $sidebar_id );
		PartsPostType::set_conditions( $sidebar_id, array( array( 'scope' => DisplayConditions::SCOPE_SPECIFIC_PAGE, 'mode' => 'include', 'target' => $page_id ) ) );

		$cleaned  = array();
		$listener = function ( $id ) use ( &$cleaned ) {
			$cleaned[] = (int) $id;
		};
		add_action( 'clean_post_cache', $listener );

		CacheCascade::on_after_publish( $sidebar_id, 'sidebar' );

		remove_action( 'clean_post_cache', $listener );

		$ok = null !== CacheCascade::$last_result
			&& 'sidebar' === CacheCascade::$last_result['type']
			&& in_array( $page_id, $cleaned, true )
			&& ! in_array( $other_id, $cleaned, true );

		self::cleanup_posts( array( $page_id, $other_id, $sidebar_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'публикация типа "sidebar" запускает CacheCascade (раньше был захардкожен список только header/footer — публикация сайдбара молча не сбрасывала кэш ни для одной страницы)'
				: 'ожидалось, что CacheCascade::on_after_publish обработает type=sidebar так же, как header/footer',
			'result' => array( 'last_result' => CacheCascade::$last_result, 'cleaned' => $cleaned ),
		);
	}

	private static function check_conditions_route_param() {
		// Раздел 7: /parts/{post_id}/conditions переиспользует
		// DocumentsController::check_permission(), который читает
		// get_param('post_id') — маршрут ДО фикса объявлял плейсхолдер как
		// {id}, из-за чего permission_callback ВСЕГДА возвращал 404 (баг
		// существовал и для header/footer, не только для нового sidebar —
		// найден при добавлении sidebar, исправлен для всех типов разом).
		// Проверяется настоящим REST-диспетчем, не интроспекцией внутренней
		// структуры маршрутов.
		$header_id = PartsPostType::create( 'header', 'SecondTier Route Param Check', get_current_user_id() );

		$request = new \WP_REST_Request( 'GET', '/wpgjb/v1/parts/' . $header_id . '/conditions' );
		$response = rest_do_request( $request );
		$status   = $response->get_status();

		self::cleanup_posts( array( $header_id ) );

		$ok = 200 === $status;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'GET /parts/{post_id}/conditions возвращает 200 (маршрут использует параметр post_id, совпадающий с тем, что читает check_permission)'
				: sprintf( 'ожидался HTTP 200, получен %d — вероятно рассинхронизация имени параметра маршрута с check_permission', $status ),
			'result' => array( 'status' => $status ),
		);
	}

	private static function check_acf_tag_absent_without_acf() {
		// В этой среде ACF реально не установлен — резолвер обязан честно
		// отсутствовать в реестре, а не существовать и всегда резолвиться в
		// пустую строку по непонятной причине.
		$acf_active = function_exists( 'get_field' );
		$tag        = TagRegistry::get( 'acf_field' );

		$ok = $acf_active ? ( null !== $tag ) : ( null === $tag );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? sprintf( 'acf_field %s в реестре, что соответствует %s ACF в этой среде', $tag ? 'присутствует' : 'отсутствует', $acf_active ? 'активному' : 'неактивному' )
				: 'регистрация тега acf_field должна точно соответствовать function_exists("get_field")',
			'result' => array( 'acf_active' => $acf_active, 'tag_registered' => null !== $tag ),
		);
	}

	private static function check_acf_resolver_simple_value() {
		$context = array( 'post_meta' => array( 'my_text_field' => array( 'Hello ACF' ) ) );
		$value   = TagRegistry::resolve_acf_raw_field( $context, array( 'key' => 'my_text_field' ) );

		$ok = 'Hello ACF' === $value;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'простое скалярное значение (text/textarea/number/url/email) читается из уже загруженного $context[post_meta] без доп. запросов'
				: 'ожидалось значение простого поля как есть',
			'result' => array( 'value' => $value ),
		);
	}

	private static function check_acf_resolver_filters_serialized() {
		// Типичный вид сериализованного repeater/gallery-значения ACF.
		$serialized = serialize( array( array( 'sub_field' => 'a' ), array( 'sub_field' => 'b' ) ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
		$context    = array( 'post_meta' => array( 'my_repeater' => array( $serialized ) ) );
		$value      = TagRegistry::resolve_acf_raw_field( $context, array( 'key' => 'my_repeater' ) );

		$ok = '' === $value;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'сериализованное значение сложного типа (repeater/gallery/relationship) честно резолвится в пустую строку, а не выводит PHP serialize() в разметку'
				: 'ожидалась пустая строка для сериализованного значения',
			'result' => array( 'value_length' => strlen( $value ) ),
		);
	}

	private static function dispatch_assemble( array $body ) {
		$request = new \WP_REST_Request( 'POST', '/wpgjb/v1/pages/assemble' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( $body ) );
		return rest_do_request( $request );
	}

	private static function count_pages(): int {
		return (int) wp_count_posts( 'page' )->draft + (int) wp_count_posts( 'page' )->publish;
	}

	private static function check_assemble_rejects_empty_sequence() {
		$response = self::dispatch_assemble( array( 'title' => 'SecondTier Empty', 'blocks' => array() ) );

		$ok = 422 === $response->get_status();

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'POST /pages/assemble с пустым blocks отклонён 422'
				: sprintf( 'ожидался HTTP 422 для пустой последовательности, получен %d', $response->get_status() ),
			'result' => array( 'status' => $response->get_status() ),
		);
	}

	private static function check_assemble_creates_page() {
		$response = self::dispatch_assemble(
			array(
				'title'  => 'SecondTier Assembled Page',
				'blocks' => array(
					array( 'block_id' => 'test-cta', 'slots' => array( 'title' => 'Assembled', 'button_label' => 'Go', 'button_link' => '#' ) ),
				),
			)
		);

		$data     = $response->get_data();
		$post_id  = $data['post_id'] ?? null;
		$ok       = 201 === $response->get_status() && $post_id && 1 === count( $data['project_data']['pages'][0]['frames'][0]['component']['components'] ?? array() );

		if ( $post_id ) {
			wp_delete_post( $post_id, true );
		}

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'POST /pages/assemble с одним валидным блоком создаёт реальную страницу (status=201) с этим блоком в project_data'
				: 'ожидался HTTP 201 с post_id и одним компонентом в project_data',
			'result' => array( 'status' => $response->get_status(), 'post_id' => $post_id ),
		);
	}

	private static function check_assemble_atomic_on_invalid_entry() {
		$before = self::count_pages();

		$response = self::dispatch_assemble(
			array(
				'title'  => 'SecondTier Should Not Exist',
				'blocks' => array(
					array( 'block_id' => 'test-cta', 'slots' => array( 'title' => 'Valid', 'button_label' => 'Go', 'button_link' => '#' ) ),
					array( 'block_id' => 'does-not-exist', 'slots' => array() ),
				),
			)
		);

		$after = self::count_pages();

		$ok = 422 === $response->get_status() && $before === $after;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'последовательность с одним невалидным элементом отклонена 422 целиком, ни одна страница не создана (проверено счётчиком постов до/после)'
				: sprintf( 'ожидался HTTP 422 и неизменное число страниц (%d), получено status=%d, страниц=%d', $before, $response->get_status(), $after ),
			'result' => array( 'status' => $response->get_status(), 'pages_before' => $before, 'pages_after' => $after ),
		);
	}
}
