<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\AI\PageGenerator;

defined( 'ABSPATH' ) || exit;

/**
 * `POST /wpgjb/v1/ai/generate-page` — тонкий REST-контроллер, вся
 * оркестрация в `PageGenerator` (раздел 9 спеки, AI-фаза). Права — те же,
 * что уже требует `assemble_page()` (`wpgjb_edit_pages` + `publish_pages`) —
 * новую капабилити не заводим (см. план фазы).
 */
class AiController {

	const NAMESPACE_ = 'wpgjb/v1';

	/** Простой троттлинг на пользователя — защита от повторной отправки формы/двойного клика, не полноценные квоты (сознательно вне рамок v1). */
	const THROTTLE_SECONDS = 20;

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/ai/generate-page',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'generate_page' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'niche'     => array( 'type' => 'string', 'required' => false ),
					'page_type' => array( 'type' => 'string', 'required' => false ),
					'tone'      => array( 'type' => 'string', 'required' => false ),
					'details'   => array( 'type' => 'string', 'required' => false ),
				),
			)
		);
	}

	public static function check_permission() {
		return current_user_can( 'wpgjb_edit_pages' ) && current_user_can( 'publish_pages' );
	}

	public static function generate_page( \WP_REST_Request $request ) {
		$throttle_key = 'wpgjb_ai_throttle_' . get_current_user_id();
		if ( get_transient( $throttle_key ) ) {
			return new \WP_Error(
				'wpgjb_ai_throttled',
				__( 'Подождите немного перед повторной генерацией страницы.', 'wp-gj-builder' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $throttle_key, 1, self::THROTTLE_SECONDS );

		$brief = array(
			'niche'     => (string) $request->get_param( 'niche' ),
			'page_type' => (string) $request->get_param( 'page_type' ),
			'tone'      => (string) $request->get_param( 'tone' ),
			'details'   => (string) $request->get_param( 'details' ),
		);

		$result = PageGenerator::generate( $brief );

		if ( 'ok' === $result['status'] ) {
			return new \WP_REST_Response(
				array(
					'status'     => 'ok',
					'post_id'    => $result['post_id'],
					'editor_url' => $result['editor_url'],
				),
				201
			);
		}

		return new \WP_REST_Response(
			array(
				'status' => 'error',
				'errors' => $result['errors'],
			),
			422
		);
	}
}
