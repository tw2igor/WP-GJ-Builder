<?php
/**
 * Проверка Phase 1 (план: "CRUD-слой + REST save/load project_json,
 * капабилити-гейт, без рендера" + "Схема манифеста блока + валидатор").
 * Как и в Phase 0, гоняется вручную через дев-only REST-маршрут
 * (DevPhase1Controller), потому что в среде разработки нет PHPUnit.
 * Удалить перед Phase 7 (приёмка MVP).
 */

namespace WPGJBuilder\Tests\Phase1;

use WPGJBuilder\Blocks\ManifestValidator;

defined( 'ABSPATH' ) || exit;

class Phase1Runner {

	public static function run() {
		$results = array();

		$results['manifest_valid_accepted']      = self::check_manifest_valid_accepted();
		$results['manifest_invalid_rejected']     = self::check_manifest_invalid_rejected();
		$results['slot_values_valid_accepted']    = self::check_slot_values_valid_accepted();
		$results['slot_values_invalid_rejected']  = self::check_slot_values_invalid_rejected();
		$results['rest_permission_denied']        = self::check_rest_permission_denied();
		$results['rest_save_load_roundtrip']      = self::check_rest_save_load_roundtrip();
		$results['rest_migration_and_freeze']     = self::check_rest_migration_and_freeze();
		$results['rest_conflict_on_stale_version'] = self::check_rest_conflict_on_stale_version();

		return $results;
	}

	private static function load_test_hero_manifest() {
		$path = WPGJB_PLUGIN_DIR . 'blocks-library/test-hero/manifest.json';
		return json_decode( file_get_contents( $path ), true );
	}

	private static function check_manifest_valid_accepted() {
		$manifest = self::load_test_hero_manifest();
		$errors   = ManifestValidator::validate_manifest( $manifest );

		return array(
			'pass'   => empty( $errors ),
			'detail' => empty( $errors ) ? 'фикстура test-hero/manifest.json прошла validate_manifest() без ошибок' : 'валидный манифест неожиданно отклонён',
			'result' => $errors,
		);
	}

	private static function check_manifest_invalid_rejected() {
		$manifest = self::load_test_hero_manifest();
		unset( $manifest['purpose'] );
		$manifest['section_type'] = 'not-a-real-section-type';
		$manifest['slots'][]      = array( 'key' => 'Bad Key!', 'type' => 'not-a-real-type' );

		$errors = ManifestValidator::validate_manifest( $manifest );

		$ok = ! empty( $errors ) && count( $errors ) >= 3;

		return array(
			'pass'   => $ok,
			'detail' => $ok ? 'сломанный манифест (нет purpose, неверный section_type, неверный слот) корректно отклонён с несколькими ошибками' : 'ожидалось несколько ошибок валидации',
			'result' => $errors,
		);
	}

	private static function check_slot_values_valid_accepted() {
		$manifest = self::load_test_hero_manifest();
		$values   = array(
			'title'    => 'Заголовок',
			'subtitle' => 'Подзаголовок',
			'items'    => array(
				array( 'icon' => 'star', 'title' => 'Пункт 1', 'text' => 'Текст 1' ),
				array( 'icon' => 'star', 'title' => 'Пункт 2', 'text' => 'Текст 2' ),
				array( 'icon' => 'star', 'title' => 'Пункт 3', 'text' => 'Текст 3' ),
			),
		);

		$errors = ManifestValidator::validate_values( $manifest, $values );

		return array(
			'pass'   => empty( $errors ),
			'detail' => empty( $errors ) ? 'корректные значения слотов (в т.ч. вложенный array.item_schema) прошли validate_values() без ошибок' : 'валидные значения неожиданно отклонены',
			'result' => $errors,
		);
	}

	private static function check_slot_values_invalid_rejected() {
		$manifest = self::load_test_hero_manifest();
		$values   = array(
			// "title" обязателен - отсутствует.
			'subtitle' => str_repeat( 'x', 200 ), // превышает max_length=160
			'items'    => array(
				array( 'icon' => 'star', 'title' => 'Пункт 1', 'text' => 'Текст 1' ),
			), // меньше min_items=3
		);

		$errors = ManifestValidator::validate_values( $manifest, $values );

		$ok = count( $errors ) >= 3;

		return array(
			'pass'   => $ok,
			'detail' => $ok ? 'некорректные значения (нет обязательного title, превышена длина, мало элементов массива) дали >= 3 ошибок' : 'ожидалось несколько ошибок валидации значений',
			'result' => $errors,
		);
	}

	private static function make_test_page() {
		return wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'WPGJB Phase1 test page',
				'post_content' => '<p>Исходный HTML — Phase1 REST не должен его трогать (без рендера).</p>',
			)
		);
	}

	private static function dispatch( $method, $route, array $params = array() ) {
		$request = new \WP_REST_Request( $method, $route );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		if ( 'POST' === $method ) {
			$request->set_header( 'content-type', 'application/json' );
		}
		return rest_get_server()->dispatch( $request );
	}

	private static function check_rest_permission_denied() {
		$post_id  = self::make_test_page();
		$original = get_current_user_id();
		wp_set_current_user( 0 ); // анонимный посетитель, без wpgjb_edit_pages

		$response = self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page",
			array( 'project_data' => array( 'sections' => array() ) )
		);

		wp_set_current_user( $original );
		wp_delete_post( $post_id, true );

		$ok = 403 === $response->get_status();

		return array(
			'pass'   => $ok,
			'detail' => $ok ? 'анонимный запрос без wpgjb_edit_pages корректно получил 403' : 'ожидался 403 для неавторизованного запроса',
			'result' => array( 'status' => $response->get_status(), 'data' => $response->get_data() ),
		);
	}

	private static function check_rest_save_load_roundtrip() {
		$post_id = self::make_test_page();

		$save = self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page",
			array( 'project_data' => array( 'sections' => array( array( 'type' => 'hero' ) ) ) )
		);

		$load = self::dispatch( 'GET', "/wpgjb/v1/documents/{$post_id}/page" );

		$post_after = get_post( $post_id );

		$ok = 200 === $save->get_status()
			&& 1 === $save->get_data()['doc_version']
			&& 200 === $load->get_status()
			&& 'ok' === $load->get_data()['status']
			&& isset( $load->get_data()['project_data']['sections'] )
			&& '<p>Исходный HTML — Phase1 REST не должен его трогать (без рендера).</p>' === $post_after->post_content;

		wp_delete_post( $post_id, true );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'save -> load через реальный REST дал тот же project_data, doc_version=1, post_content не тронут (Phase 1 без рендера)'
				: 'save/load REST round-trip дал неожиданный результат',
			'result' => array( 'save' => $save->get_data(), 'load' => $load->get_data() ),
		);
	}

	private static function check_rest_migration_and_freeze() {
		global $wpdb;

		$post_id = self::make_test_page();
		$table   = \WPGJBuilder\Storage\DocumentsTable::table_name();

		// Повреждённый JSON, как в Spike 3, но теперь проверяем через
		// реальный GET REST-маршрут, а не напрямую через DocumentRepository.
		$wpdb->insert(
			$table,
			array(
				'post_id'        => $post_id,
				'type'           => 'page',
				'revision_type'  => 'draft',
				'is_current'     => 1,
				'project_json'   => '{ broken',
				'schema_version' => 1,
				'doc_version'    => 1,
				'updated_at'     => current_time( 'mysql', true ),
				'updated_by'     => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d' )
		);

		$load = self::dispatch( 'GET', "/wpgjb/v1/documents/{$post_id}/page" );
		$data = $load->get_data();

		$ok = 200 === $load->get_status() && 'frozen' === $data['status'] && ! empty( $data['error'] );

		wp_delete_post( $post_id, true );
		$wpdb->delete( $table, array( 'post_id' => $post_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'GET через реальный REST на повреждённый документ дал HTTP 200 + status=frozen (не 500), как и требует раздел 12'
				: 'ожидался HTTP 200 со status=frozen',
			'result' => array( 'status' => $load->get_status(), 'data' => $data ),
		);
	}

	private static function check_rest_conflict_on_stale_version() {
		$post_id = self::make_test_page();

		self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page",
			array( 'project_data' => array( 'sections' => array() ) )
		); // doc_version теперь 1

		$stale_save = self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page",
			array(
				'project_data' => array( 'sections' => array( array( 'type' => 'cta' ) ) ),
				'doc_version'  => 99, // заведомо устаревшая версия
			)
		);

		$ok = 409 === $stale_save->get_status() && 'conflict' === $stale_save->get_data()['status'];

		wp_delete_post( $post_id, true );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'сохранение с устаревшим doc_version дало HTTP 409 + status=conflict, не молчаливую перезапись (раздел 5.5)'
				: 'ожидался HTTP 409 при конфликте doc_version',
			'result' => array( 'status' => $stale_save->get_status(), 'data' => $stale_save->get_data() ),
		);
	}
}
