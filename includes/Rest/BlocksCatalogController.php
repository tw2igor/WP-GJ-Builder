<?php

namespace WPGJBuilder\Rest;

use WPGJBuilder\Blocks\BlockLibrary;
use WPGJBuilder\Blocks\BlockRenderer;
use WPGJBuilder\Blocks\ManifestValidator;
use WPGJBuilder\Blocks\TemplateLibrary;
use WPGJBuilder\Render\PageTemplates;
use WPGJBuilder\Sanitize\ProjectDataSanitizer;
use WPGJBuilder\Storage\DocumentRepository;
use WPGJBuilder\Storage\MigrationRunner;

defined( 'ABSPATH' ) || exit;

/**
 * REST-каталог библиотеки блоков (раздел 9 спеки, план Phase 4). Точка
 * приёмки №9 (раздел 15): "внешний скрипт, зная только REST API каталога,
 * может получить список блоков с манифестами и вставить блок с
 * заполненными слотами в черновик страницы" — НЕ зная о GrapesJS. Именно
 * поэтому insert_block() вызывает ту же валидацию/санитизацию
 * (ManifestValidator, ProjectDataSanitizer) и тот же слой хранения
 * (DocumentRepository), что и человеческий путь через редактор — единая
 * реализация, не два разных механизма (П7 спеки).
 *
 * "Собрать страницу из последовательности блоков" — явно вторая очередь
 * (план: "можно застабить как 501"), сознательно не реализуется здесь.
 */
class BlocksCatalogController {

	public static function register_routes() {
		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/blocks',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'list_blocks' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'section_type' => array( 'type' => 'string', 'required' => false ),
					'tag'          => array( 'type' => 'string', 'required' => false ),
					'search'       => array( 'type' => 'string', 'required' => false ),
				),
			)
		);

		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/blocks/(?P<id>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'get_block' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/documents/(?P<post_id>\d+)/(?P<type>[a-z]+)/insert-block',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'insert_block' ),
				'permission_callback' => array( DocumentsController::class, 'check_permission' ),
				'args'                => array(
					'post_id'     => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
					'type'        => array(
						'type'     => 'string',
						'required' => true,
						'enum'     => DocumentsController::ALLOWED_TYPES,
					),
					'block_id'    => array( 'type' => 'string', 'required' => true ),
					'slots'       => array( 'type' => 'object', 'required' => false ),
					'doc_version' => array( 'type' => 'integer', 'required' => false ),
				),
			)
		);

		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/templates',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'list_templates' ),
				'permission_callback' => array( self::class, 'check_permission' ),
			)
		);

		register_rest_route(
			DocumentsController::NAMESPACE_,
			'/pages/assemble',
			array(
				'methods'             => 'POST',
				'callback'            => array( self::class, 'assemble_page' ),
				'permission_callback' => array( self::class, 'check_permission' ),
				'args'                => array(
					'title'  => array( 'type' => 'string', 'required' => true ),
					'blocks' => array( 'type' => 'array', 'required' => true ),
				),
			)
		);
	}

	public static function check_permission() {
		return current_user_can( 'wpgjb_edit_pages' );
	}

	/**
	 * Раздел 10: блоки со слотом raw_html ("Вставка кода") недоступны по
	 * REST (ни чтение, ни вставка) без капабилити `wpgjb_insert_raw_code` —
	 * второй, независимый от санитизации рубеж.
	 */
	private static function is_forbidden_for_current_user( array $manifest ): bool {
		return BlockLibrary::requires_raw_code_capability( $manifest ) && ! current_user_can( 'wpgjb_insert_raw_code' );
	}

	public static function list_blocks( \WP_REST_Request $request ) {
		$section_type = $request->get_param( 'section_type' );
		$tag          = $request->get_param( 'tag' );
		$search       = $request->get_param( 'search' );

		$result = array();

		foreach ( BlockLibrary::all_visible_to_current_user() as $id => $block ) {
			$manifest = $block['manifest'];

			if ( $section_type && $manifest['section_type'] !== $section_type ) {
				continue;
			}
			if ( $tag && ! in_array( $tag, $manifest['tags'] ?? array(), true ) ) {
				continue;
			}
			if ( $search && false === stripos( $id . ' ' . $manifest['purpose'], $search ) ) {
				continue;
			}

			$result[] = array(
				'id'       => $id,
				'manifest' => $manifest,
			);
		}

		return new \WP_REST_Response( array( 'blocks' => $result ) );
	}

	public static function get_block( \WP_REST_Request $request ) {
		$id    = $request->get_param( 'id' );
		$block = BlockLibrary::get( $id );

		if ( ! $block || self::is_forbidden_for_current_user( $block['manifest'] ) ) {
			return new \WP_Error( 'wpgjb_block_not_found', __( 'Блок не найден в библиотеке.', 'wp-gj-builder' ), array( 'status' => 404 ) );
		}

		return new \WP_REST_Response(
			array(
				'id'       => $id,
				'manifest' => $block['manifest'],
				'markup'   => $block['markup'],
				'style'    => $block['style'],
			)
		);
	}

	public static function insert_block( \WP_REST_Request $request ) {
		$post_id              = (int) $request->get_param( 'post_id' );
		$type                 = $request->get_param( 'type' );
		$block_id             = $request->get_param( 'block_id' );
		$slots                = (array) $request->get_param( 'slots' );
		$expected_doc_version = $request->get_param( 'doc_version' );
		$expected_doc_version = null === $expected_doc_version ? null : (int) $expected_doc_version;

		$block = BlockLibrary::get( $block_id );
		if ( ! $block || self::is_forbidden_for_current_user( $block['manifest'] ) ) {
			return new \WP_Error( 'wpgjb_block_not_found', __( 'Блок не найден в библиотеке.', 'wp-gj-builder' ), array( 'status' => 404 ) );
		}

		$manifest = $block['manifest'];

		// Валидация ДО санитизации — тот же ManifestValidator, что и
		// человеческий путь (форма "Контента" в редакторе валидирует то же
		// самое перед сохранением).
		$errors = ManifestValidator::validate_values( $manifest, $slots );
		if ( ! empty( $errors ) ) {
			return new \WP_REST_Response(
				array(
					'status' => 'invalid',
					'errors' => $errors,
				),
				422
			);
		}

		$sanitized_values = ProjectDataSanitizer::sanitize_values( $manifest['slots'], $slots );
		$component         = BlockRenderer::render_component( $block_id, $manifest, $block['markup'], $sanitized_values );

		$repository = new DocumentRepository( new MigrationRunner() );
		$current    = $repository->get_for_edit( $post_id, $type );

		if ( DocumentRepository::STATUS_FROZEN === $current['status'] ) {
			return new \WP_REST_Response(
				array(
					'status' => 'frozen',
					'error'  => $current['error'],
				),
				200
			);
		}

		$project_data = self::append_component( $current['project_data'], $component );

		$result = $repository->save_draft(
			$post_id,
			$type,
			$project_data,
			$expected_doc_version ?? $current['doc_version'],
			get_current_user_id()
		);

		if ( DocumentRepository::STATUS_CONFLICT === $result['status'] ) {
			return new \WP_REST_Response(
				array(
					'status'      => 'conflict',
					'doc_version' => $result['doc_version'],
				),
				409
			);
		}

		return new \WP_REST_Response(
			array(
				'status'       => 'ok',
				'doc_version'  => $result['doc_version'],
				'project_data' => $project_data,
			),
			200
		);
	}

	private static function empty_project_data(): array {
		return array(
			'pages'  => array(
				array(
					'frames' => array(
						array( 'component' => array( 'type' => 'wrapper', 'components' => array() ) ),
					),
				),
			),
			'styles' => array(),
		);
	}

	private static function append_component( $project_data, array $component ): array {
		if ( empty( $project_data['pages'][0]['frames'][0]['component'] ) ) {
			$project_data = self::empty_project_data();
		}
		if ( ! isset( $project_data['pages'][0]['frames'][0]['component']['components'] ) || ! is_array( $project_data['pages'][0]['frames'][0]['component']['components'] ) ) {
			$project_data['pages'][0]['frames'][0]['component']['components'] = array();
		}
		$project_data['pages'][0]['frames'][0]['component']['components'][] = $component;
		return $project_data;
	}

	/**
	 * Раздел 9 спеки (вторая очередь, точка расширения заложена в MVP как
	 * 501-заглушка): "собрать страницу из последовательности блоков"
	 * [{block_id, slots}, ...] одним REST-вызовом — для AI/внешнего
	 * клиента, которому неудобно делать N отдельных insert-block на новую,
	 * ещё не существующую страницу. Валидация/санитизация/рендер —
	 * ТЕ ЖЕ методы (ManifestValidator/ProjectDataSanitizer/BlockRenderer),
	 * что insert_block() — не вторая реализация правил (раздел 9 П7).
	 *
	 * Все элементы последовательности валидируются ПЕРЕД созданием
	 * страницы — если хоть один невалиден, страница не создаётся вообще
	 * и клиент получает ошибки сразу по всем проблемным элементам одним
	 * ответом, не по одной ошибке за раунд-трип.
	 */
	/**
	 * Каталог готовых страниц ("Страницы", третий уровень вложенности) —
	 * помимо бутстрапа редактора (wpgjbEditorData.templates), отдельный
	 * REST-эндпоинт нужен для будущего AI-модуля, у которого нет доступа
	 * к wpgjbEditorData (тот доступен только экрану редактора).
	 */
	public static function list_templates() {
		return new \WP_REST_Response( TemplateLibrary::all_visible_to_current_user() );
	}

	public static function assemble_page( \WP_REST_Request $request ) {
		if ( ! current_user_can( 'publish_pages' ) ) {
			return new \WP_Error( 'wpgjb_forbidden', __( 'Недостаточно прав для создания страниц.', 'wp-gj-builder' ), array( 'status' => 403 ) );
		}

		$title  = (string) $request->get_param( 'title' );
		$blocks = (array) $request->get_param( 'blocks' );

		if ( empty( $blocks ) ) {
			return new \WP_Error( 'wpgjb_empty_sequence', __( 'Список блоков не может быть пустым.', 'wp-gj-builder' ), array( 'status' => 422 ) );
		}

		$components     = array();
		$sequence_errors = array();

		foreach ( $blocks as $index => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['block_id'] ) ) {
				$sequence_errors[] = array( 'index' => $index, 'errors' => array( 'Обязателен "block_id".' ) );
				continue;
			}

			$block_id = (string) $entry['block_id'];
			$slots    = isset( $entry['slots'] ) && is_array( $entry['slots'] ) ? $entry['slots'] : array();

			$block = BlockLibrary::get( $block_id );
			if ( ! $block || self::is_forbidden_for_current_user( $block['manifest'] ) ) {
				$sequence_errors[] = array( 'index' => $index, 'block_id' => $block_id, 'errors' => array( 'Блок не найден в библиотеке.' ) );
				continue;
			}

			$manifest = $block['manifest'];
			$errors   = ManifestValidator::validate_values( $manifest, $slots );
			if ( ! empty( $errors ) ) {
				$sequence_errors[] = array( 'index' => $index, 'block_id' => $block_id, 'errors' => $errors );
				continue;
			}

			$sanitized_values = ProjectDataSanitizer::sanitize_values( $manifest['slots'], $slots );
			$components[]      = BlockRenderer::render_component( $block_id, $manifest, $block['markup'], $sanitized_values );
		}

		if ( ! empty( $sequence_errors ) ) {
			return new \WP_REST_Response(
				array(
					'status' => 'invalid',
					'errors' => $sequence_errors,
				),
				422
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'draft',
				'post_title'  => $title,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error( 'wpgjb_page_create_failed', __( 'Не удалось создать страницу.', 'wp-gj-builder' ), array( 'status' => 500 ) );
		}

		update_post_meta( $post_id, '_wp_page_template', PageTemplates::DEFAULT_TEMPLATE );

		$project_data = self::empty_project_data();
		foreach ( $components as $component ) {
			$project_data = self::append_component( $project_data, $component );
		}

		$repository = new DocumentRepository( new MigrationRunner() );
		$result     = $repository->save_draft( $post_id, 'page', $project_data, null, get_current_user_id() );

		return new \WP_REST_Response(
			array(
				'status'       => 'ok',
				'post_id'      => $post_id,
				'type'         => 'page',
				'doc_version'  => $result['doc_version'],
				'editor_url'   => \WPGJBuilder\Admin\EditorPage::editor_url( $post_id, 'page' ),
				'project_data' => $project_data,
			),
			201
		);
	}
}
