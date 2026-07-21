<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\Blocks\ChecklistLinter;

defined( 'ABSPATH' ) || exit;

/**
 * Дев-only маршрут для ручного прогона чек-листа выпуска блока (раздел 6
 * спеки) по всей библиотеке. Регистрируется только при WP_DEBUG=true.
 * В отличие от других Dev*-контроллеров, этот может пережить Phase 7 как
 * реальный инструмент для будущих авторов блоков — либо получить
 * настоящий капабилити-гейт вместо WP_DEBUG на этом этапе; решение
 * отложено до Phase 6/тулинга для авторов блоков.
 */
class DevChecklistController {

	public static function register_routes() {
		register_rest_route(
			'wpgjb/v1',
			'/dev/checklist',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'run_checklist' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	public static function run_checklist() {
		$results  = ChecklistLinter::lint_all();
		$all_pass = ! in_array( false, array_column( $results, 'pass' ), true );

		return new \WP_REST_Response(
			array(
				'verdict' => $all_pass ? 'PASS' : 'FAIL',
				'blocks'  => $results,
			),
			200
		);
	}
}
