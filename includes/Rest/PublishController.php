<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\Render\Publisher;

defined( 'ABSPATH' ) || exit;

/**
 * REST-эндпоинт публикации (план Phase 2). Отдельный контроллер от
 * DocumentsController (Phase 1, save/load без рендера) — публикация
 * содержательно другая операция (пишет post_content, гоняет контур-2).
 * Капабилити-гейт переиспользует DocumentsController::check_permission()
 * (тот же набор правил доступа к документу, не дублируется).
 */
class PublishController {

	public static function register_routes() {
		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/documents/(?P<post_id>\d+)/(?P<type>[a-z]+)/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'publish' ),
				'permission_callback' => array( DocumentsController::class, 'check_permission' ),
				'args'                => array(
					'post_id'      => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'type'         => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => DocumentsController::ALLOWED_TYPES,
					),
					'project_data' => array(
						'type'     => 'object',
						'required' => true,
					),
					'html'         => array(
						'type'     => 'string',
						'required' => true,
					),
					'css'          => array(
						'type'     => 'string',
						'required' => false,
						'default'  => '',
					),
				),
			)
		);
	}

	public static function publish( \WP_REST_Request $request ) {
		$post_id      = (int) $request->get_param( 'post_id' );
		$type         = $request->get_param( 'type' );
		$project_data = (array) $request->get_param( 'project_data' );
		$html         = (string) $request->get_param( 'html' );
		$css          = (string) $request->get_param( 'css' );

		$result = Publisher::publish( $post_id, $type, $project_data, $html, $css, get_current_user_id() );

		// CacheCascade слушает wpgjb_after_publish внутри Publisher::publish()
		// и синхронно (в рамках этого же запроса) заполняет $last_result —
		// раздел 7: публикация части сайта должна явно сообщить пользователю,
		// сколько страниц затронуто.
		$cascade = \WPGJBuilder\SiteParts\CacheCascade::$last_result;

		return new \WP_REST_Response(
			array(
				'status'      => 'ok',
				'html'        => $result['html'],
				'css'         => $result['css'],
				'cascade'     => $cascade,
				'quarantined' => $result['quarantined'],
			),
			200
		);
	}
}
