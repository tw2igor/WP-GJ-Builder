<?php
/**
 * Проверка Phase 2 (план: "Publish pipeline: статический HTML + динамические
 * теги"). Как и в Phase 0/1, гоняется вручную через дев-only REST-маршрут,
 * потому что в среде разработки нет PHPUnit. Удалить перед Phase 7.
 */

namespace WPGJBuilder\Tests\Phase2;

use WPGJBuilder\DynamicTags\TagRegistry;
use WPGJBuilder\Render\Publisher;

defined( 'ABSPATH' ) || exit;

class Phase2Runner {

	public static function run() {
		$results = array();

		$results['publish_sanitizes_and_writes_content'] = self::check_publish_sanitizes_and_writes_content();
		$results['frontend_resolves_tags']                = self::check_frontend_resolves_tags();
		$results['frontend_query_budget']                 = self::check_frontend_query_budget();
		$results['frontend_no_editor_assets']              = self::check_frontend_no_editor_assets();
		$results['publish_fires_cache_hooks']              = self::check_publish_fires_cache_hooks();

		return $results;
	}

	private static function make_fixture( $post_title ) {
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		$user_id = wp_insert_user(
			array(
				'user_login'   => 'wpgjb_phase2_' . uniqid(),
				'user_pass'    => wp_generate_password( 20 ),
				'display_name' => 'Phase2 Author',
			)
		);

		$post_id = wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $post_title,
				'post_author' => $user_id,
			)
		);

		return array( $post_id, $user_id );
	}

	private static function cleanup_fixture( $post_id, $user_id ) {
		wp_delete_post( $post_id, true );
		wp_delete_user( $user_id );
		TagRegistry::reset_for_tests();
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

	/**
	 * Публикует вручную собранный project_json + html (с 3 динамическими
	 * тегами и умышленно опасной разметкой) + css (с умышленно опасным
	 * свойством) через РЕАЛЬНЫЙ REST publish-эндпоинт. Проверяет: контур-2
	 * вычищает опасное, легитимное остаётся, post_content реально
	 * записан, postmeta-флаги выставлены.
	 */
	private static function check_publish_sanitizes_and_writes_content() {
		list( $post_id, $user_id ) = self::make_fixture( 'Phase2 Publish Test Page' );

		$html = '<section class="hero"><h1>{{wpb:post_title}}</h1>'
			. '<p>{{wpb:site_title}} &mdash; {{wpb:author_name}}</p>'
			. '<script>alert(1)</script>'
			. '<img src="x.png" onload="alert(2)"></section>';

		$css = '.hero { color: red; expression(alert(3)); }';

		$response = self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page/publish",
			array(
				'project_data' => array( 'sections' => array( array( 'type' => 'hero' ) ) ),
				'html'         => $html,
				'css'          => $css,
			)
		);

		$post_after = get_post( $post_id );
		$built_flag = get_post_meta( $post_id, Publisher::META_BUILT, true );
		$stored_css = get_post_meta( $post_id, Publisher::META_CSS, true );

		$ok = 200 === $response->get_status()
			&& false === strpos( $post_after->post_content, '<script>' )
			&& false === strpos( $post_after->post_content, 'onload' )
			&& false !== strpos( $post_after->post_content, '{{wpb:post_title}}' ) // плейсхолдеры резолвятся на фронтенде, не при публикации
			&& false !== strpos( $post_after->post_content, 'class="hero"' )
			&& '1' === $built_flag
			&& false !== strpos( $stored_css, 'color: red' )
			&& false === strpos( $stored_css, 'expression' );

		$result = array(
			'status'       => $response->get_status(),
			'post_content' => $post_after->post_content,
			'stored_css'   => $stored_css,
			'built_flag'   => $built_flag,
		);

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'публикация: <script>/onload/CSS-expression() вычищены контуром 2, легитимные класс/цвет и нерезолвленные плейсхолдеры сохранены в post_content'
				: 'публикация дала неожиданный post_content/postmeta',
			'result' => $result,
		);
	}

	/**
	 * Симулирует реальный визит посетителя: применяет зарегистрированный
	 * фильтр the_content (не вызывает BatchResolver напрямую) — проверяет
	 * саму проводку хука, не только механизм резолвинга (это уже доказано
	 * Spike 2).
	 */
	private static function check_frontend_resolves_tags() {
		list( $post_id, $user_id ) = self::make_fixture( 'Phase2 Frontend Test Page' );

		self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page/publish",
			array(
				'project_data' => array( 'sections' => array() ),
				'html'         => '<h1>{{wpb:post_title}}</h1><p>{{wpb:site_title}}</p>',
				'css'          => '.hero { color: blue; }',
			)
		);

		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );

		$rendered = apply_filters( 'the_content', $post->post_content );

		ob_start();
		do_action( 'wp_head' );
		$head_output = ob_get_clean();

		wp_reset_postdata();

		$ok = false !== strpos( $rendered, 'Phase2 Frontend Test Page' )
			&& false !== strpos( $rendered, get_bloginfo( 'name' ) )
			&& false === strpos( $rendered, '{{wpb:' )
			&& false !== strpos( $head_output, 'color: blue' );

		$result = array(
			'rendered_content' => $rendered,
			'head_contains_css' => false !== strpos( $head_output, 'wpb-page-css' ),
		);

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'реальный зарегистрированный фильтр the_content резолвит теги, wp_head реально выводит сохранённый page CSS'
				: 'фронтенд-рендер через зарегистрированные хуки дал неожиданный результат',
			'result' => $result,
		);
	}

	/**
	 * Тот же query-бюджет (<=3 запроса), что и в Spike 2, но теперь через
	 * реальный зарегистрированный хук the_content, а не прямой вызов
	 * BatchResolver — доказывает, что проводка (постметa-гейт + get_post())
	 * не добавляет своих запросов сверх уже доказанного бюджета резолвера.
	 */
	private static function check_frontend_query_budget() {
		list( $post_id, $user_id ) = self::make_fixture( 'Phase2 Query Budget Page' );

		self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page/publish",
			array(
				'project_data' => array( 'sections' => array() ),
				'html'         => '<h1>{{wpb:post_title}}</h1><p>Автор: {{wpb:author_name}}</p>'
					. '<a href="{{wpb:post_url}}" title="{{wpb:author_name}}">{{wpb:post_title}}</a>',
				'css'          => '',
			)
		);

		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );

		$count   = 0;
		$counter = function ( $query ) use ( &$count ) {
			++$count;
			return $query;
		};

		add_filter( 'query', $counter );
		$rendered = apply_filters( 'the_content', $post->post_content );
		remove_filter( 'query', $counter );

		wp_reset_postdata();

		$ok = $count <= 3 && false === strpos( $rendered, '{{wpb:' );

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? sprintf( 'резолвинг через реальный the_content-хук на реальной опубликованной странице уложился в %d запрос(ов) (<= 3)', $count )
				: sprintf( 'ожидалось <= 3 запроса через хук, получено %d', $count ),
			'result' => array( 'query_count' => $count ),
		);
	}

	/**
	 * Раздел 11: посетитель не должен грузить ни одного ассета редактора.
	 * В Phase 2 ничего не подключает editor.js на фронтенде вовсе —
	 * проверка фиксирует это как явный факт, а не полагается на "и так
	 * очевидно".
	 */
	private static function check_frontend_no_editor_assets() {
		list( $post_id, $user_id ) = self::make_fixture( 'Phase2 No Editor Assets Page' );

		self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page/publish",
			array(
				'project_data' => array( 'sections' => array() ),
				'html'         => '<h1>{{wpb:post_title}}</h1>',
				'css'          => '',
			)
		);

		global $post;
		$post = get_post( $post_id );
		setup_postdata( $post );

		$rendered = apply_filters( 'the_content', $post->post_content );

		ob_start();
		do_action( 'wp_enqueue_scripts' );
		do_action( 'wp_head' );
		$head_output = ob_get_clean();

		wp_reset_postdata();

		$ok = false === strpos( $rendered, 'assets/build/editor' )
			&& false === strpos( $head_output, 'assets/build/editor' )
			&& false === wp_script_is( 'wpgjb-editor', 'enqueued' );

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'ни рендер страницы, ни wp_head/wp_enqueue_scripts не подключают editor-бандл на фронтенде'
				: 'обнаружена ссылка на editor-бандл в выдаче посетителю',
			'result' => array(),
		);
	}

	/**
	 * Публикация пишет post_content через wp_update_post() (не сырой SQL),
	 * специально чтобы штатные хуки инвалидации кэша хостинга сработали
	 * автоматически (раздел 11/7 плана) — проверяем, что они РЕАЛЬНО
	 * срабатывают, а не просто полагаемся на то, что wp_update_post()
	 * "должен" их вызывать.
	 */
	private static function check_publish_fires_cache_hooks() {
		list( $post_id, $user_id ) = self::make_fixture( 'Phase2 Cache Hooks Page' );

		$fired_clean_cache = false;
		$fired_save_post    = false;

		$on_clean_cache = function ( $cleaned_post_id ) use ( $post_id, &$fired_clean_cache ) {
			if ( (int) $cleaned_post_id === $post_id ) {
				$fired_clean_cache = true;
			}
		};
		$on_save_post = function ( $saved_post_id ) use ( $post_id, &$fired_save_post ) {
			if ( (int) $saved_post_id === $post_id ) {
				$fired_save_post = true;
			}
		};

		add_action( 'clean_post_cache', $on_clean_cache );
		add_action( 'save_post', $on_save_post );

		self::dispatch(
			'POST',
			"/wpgjb/v1/documents/{$post_id}/page/publish",
			array(
				'project_data' => array( 'sections' => array() ),
				'html'         => '<p>ok</p>',
				'css'          => '',
			)
		);

		remove_action( 'clean_post_cache', $on_clean_cache );
		remove_action( 'save_post', $on_save_post );

		$ok = $fired_clean_cache && $fired_save_post;

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'wp_update_post() реально вызвал save_post и clean_post_cache для опубликованного поста — кэш-плагины хостинга получат штатный сигнал'
				: sprintf( 'ожидались оба хука; save_post=%s clean_post_cache=%s', var_export( $fired_save_post, true ), var_export( $fired_clean_cache, true ) ),
			'result' => array(),
		);
	}
}
