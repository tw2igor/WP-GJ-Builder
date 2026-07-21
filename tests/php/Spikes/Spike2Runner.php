<?php
/**
 * Спайк 2 (план Phase 0): доказать, что батч-резолвер динамических
 * тегов (раздел 8 спеки) удовлетворяет ВСЕ различные теги страницы за
 * 1-3 запроса к БД вне зависимости от числа вхождений тегов, и что
 * экранирование выбирается по позиции плейсхолдера в дереве HTML
 * (текст/атрибут/URL), а не по доверию к автору тега. Не является
 * автоматизированным юнит-тестом (в среде разработки нет PHPUnit) —
 * гоняется вручную через дев-only REST-маршрут DevSpike2Controller.
 * Удалить перед Phase 7 (приёмка MVP), см. план: "Критические файлы".
 */

namespace WPGJBuilder\Tests\Spikes;

use WPGJBuilder\DynamicTags\BatchResolver;
use WPGJBuilder\DynamicTags\TagRegistry;

defined( 'ABSPATH' ) || exit;

class Spike2Runner {

	public static function run() {
		$results = array();

		$results['basic_resolution']       = self::check_basic_resolution();
		$results['query_count_bound']      = self::check_query_count_bound();
		$results['all_groups_query_bound'] = self::check_all_groups_query_bound();
		$results['unknown_tag_whitelist']  = self::check_unknown_tag_whitelist();
		$results['context_aware_escaping'] = self::check_context_aware_escaping();

		return $results;
	}

	/**
	 * Проверяет, что каждый тег первой очереди резолвится в ожидаемое
	 * значение (site/post/author/postmeta) — базовая проверка
	 * механизма до профилирования запросов.
	 */
	private static function check_basic_resolution() {
		list( $post_id, $user_id ) = self::make_fixture( 'Spike2 Заголовок страницы' );
		$post = get_post( $post_id );

		update_post_meta( $post_id, 'subtitle', 'Подзаголовок из postmeta' );

		$html = '<h1>{{wpb:post_title}}</h1>'
			. '<p>{{wpb:site_title}} &mdash; {{wpb:author_name}}</p>'
			. '<p class="subtitle">{{wpb:post_meta;key=subtitle}}</p>'
			. '<a href="{{wpb:post_url}}">ссылка</a>';

		$resolved = BatchResolver::resolve( $html, $post );

		$ok = false !== strpos( $resolved, 'Spike2 Заголовок страницы' )
			&& false !== strpos( $resolved, get_bloginfo( 'name' ) )
			&& false !== strpos( $resolved, 'Spike2 Author' )
			&& false !== strpos( $resolved, 'Подзаголовок из postmeta' )
			&& false === strpos( $resolved, '{{wpb:' ); // ни один плейсхолдер не должен остаться нерезолвленным

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'все теги первой очереди (site/post/author/postmeta) резолвились корректно, плейсхолдеров не осталось'
				: 'резолвинг вернул неожиданный HTML',
			'result' => array( 'resolved_html' => $resolved ),
		);
	}

	/**
	 * Ключевая проверка спайка: 5 РАЗЛИЧНЫХ вхождений тегов, но только
	 * 2 различных источника данных (post + author) — запросов к БД
	 * должно быть <= 3, а не 5.
	 */
	private static function check_query_count_bound() {
		list( $post_id, $user_id ) = self::make_fixture( 'Query Bound Test Page' );
		$post = get_post( $post_id );

		// 5 вхождений: post_title x2, author_name x2, post_url x1 — 3 различных
		// tag_id, но только 2 группы источников (post, author).
		$html = '<h1>{{wpb:post_title}}</h1>'
			. '<p>Автор: {{wpb:author_name}}</p>'
			. '<a href="{{wpb:post_url}}" title="{{wpb:author_name}}">{{wpb:post_title}}</a>';

		$occurrences = substr_count( $html, '{{wpb:' );

		$query_count = self::count_queries_during(
			function () use ( $html, $post, &$resolved ) {
				$resolved = BatchResolver::resolve( $html, $post );
			}
		);

		$ok = 5 === $occurrences
			&& $query_count <= 3
			&& false === strpos( $resolved, '{{wpb:' );

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? sprintf( '%d вхождений тегов (2 источника данных: post+author) резолвились за %d запрос(ов) к БД (<= 3)', $occurrences, $query_count )
				: sprintf( 'ожидалось <= 3 запроса на %d вхождений, получено %d', $occurrences, $query_count ),
			'result' => array(
				'occurrences' => $occurrences,
				'query_count' => $query_count,
			),
		);
	}

	/**
	 * Более широкая проверка: одновременно используются ВСЕ 4 группы
	 * источников (site/post/author/postmeta), с несколькими вхождениями
	 * и несколькими различными ключами postmeta — всё равно должно
	 * укладываться в 1-3 запроса (site и post — 0 доп. запросов,
	 * author — максимум 1, postmeta — максимум 1 вне зависимости от
	 * числа различных ключей).
	 */
	private static function check_all_groups_query_bound() {
		list( $post_id, $user_id ) = self::make_fixture( 'All Groups Test Page' );
		$post = get_post( $post_id );

		update_post_meta( $post_id, 'subtitle', 'Sub' );
		update_post_meta( $post_id, 'cta_label', 'Click' );
		update_post_meta( $post_id, 'badge', 'New' );

		$html = '<h1>{{wpb:site_title}}</h1>'
			. '<h2>{{wpb:post_title}}</h2>'
			. '<p>{{wpb:author_name}} / {{wpb:author_name}}</p>'
			. '<span>{{wpb:post_meta;key=subtitle}}</span>'
			. '<span>{{wpb:post_meta;key=cta_label}}</span>'
			. '<span>{{wpb:post_meta;key=badge}}</span>'
			. '<a href="{{wpb:post_url}}">{{wpb:site_title}}</a>';

		$query_count = self::count_queries_during(
			function () use ( $html, $post, &$resolved ) {
				$resolved = BatchResolver::resolve( $html, $post );
			}
		);

		$ok = $query_count <= 3 && false === strpos( $resolved, '{{wpb:' );

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? sprintf( 'все 4 группы источников (site/post/author/postmeta, включая 3 разных ключа postmeta) резолвились за %d запрос(ов) (<= 3)', $query_count )
				: sprintf( 'ожидалось <= 3 запроса, получено %d', $query_count ),
			'result' => array( 'query_count' => $query_count ),
		);
	}

	/**
	 * Правило 3 раздела 8: только белый список. Тег, не зарегистрированный
	 * в TagRegistry, не должен резолвиться ни во что осмысленное — и уж
	 * тем более не должен вызывать никакой код.
	 */
	private static function check_unknown_tag_whitelist() {
		list( $post_id, $user_id ) = self::make_fixture( 'Whitelist Test Page' );
		$post = get_post( $post_id );

		$html = '<p>{{wpb:not_a_real_tag;key=whatever}}</p>';
		$resolved = BatchResolver::resolve( $html, $post );

		$ok = false === strpos( $resolved, '{{wpb:' ) // плейсхолдер заменён (не оставлен как есть)
			&& false === strpos( $resolved, 'whatever' ); // и не "угадан" никакой резолвер

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'незарегистрированный tag_id молча резолвится в пустую строку, TagRegistry не даёт исполнить произвольный тег'
				: 'незарегистрированный тег должен был резолвиться в пустоту',
			'result' => array( 'resolved_html' => $resolved ),
		);
	}

	/**
	 * Правило 4 раздела 8: экранирование по контексту (текст/атрибут/URL),
	 * определяемому положением плейсхолдера в дереве, а не по доверию к
	 * значению. Регистрирует два временных тег-пробника с заведомо
	 * "вредным" значением и проверяет, что вывод безопасен в каждом из
	 * трёх контекстов.
	 */
	private static function check_context_aware_escaping() {
		list( $post_id, $user_id ) = self::make_fixture( 'Escaping Test Page' );
		$post = get_post( $post_id );

		TagRegistry::register(
			'spike2_html_probe',
			TagRegistry::GROUP_POST,
			function () {
				return '"><script>alert(1)</script>';
			}
		);
		TagRegistry::register(
			'spike2_url_probe',
			TagRegistry::GROUP_POST,
			function () {
				return 'javascript:alert(1)';
			}
		);

		$html = '<div title="{{wpb:spike2_html_probe}}">{{wpb:spike2_html_probe}}</div>'
			. '<a href="{{wpb:spike2_url_probe}}">link</a>';

		$resolved = BatchResolver::resolve( $html, $post );

		$text_safe = false === strpos( $resolved, '<script>alert(1)</script>' );
		$attr_safe = false === strpos( $resolved, '"><script>' );
		$url_safe  = false === strpos( $resolved, 'javascript:alert(1)' );

		$ok = $text_safe && $attr_safe && $url_safe;

		self::cleanup_fixture( $post_id, $user_id );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'один и тот же "вредный" резолвер тега безопасен и в text-узле, и в обычном атрибуте (кодирует сам WP_HTML_Tag_Processor), и в URL-атрибуте (esc_url отсекает javascript:)'
				: sprintf( 'экранирование дало утечку: text_safe=%s attr_safe=%s url_safe=%s', var_export( $text_safe, true ), var_export( $attr_safe, true ), var_export( $url_safe, true ) ),
			'result' => array( 'resolved_html' => $resolved ),
		);
	}

	/**
	 * Считает число запросов к БД, реально выполненных во время $callback,
	 * через фильтр 'query' (срабатывает на каждый вызов wpdb::query(),
	 * не требует SAVEQUERIES).
	 */
	private static function count_queries_during( callable $callback ) {
		$count = 0;
		$counter = function ( $query ) use ( &$count ) {
			++$count;
			return $query;
		};

		add_filter( 'query', $counter );
		$callback();
		remove_filter( 'query', $counter );

		return $count;
	}

	/**
	 * @return array{0: int, 1: int} [post_id, user_id]
	 */
	private static function make_fixture( $post_title ) {
		$user_id = wp_insert_user(
			array(
				'user_login'   => 'wpgjb_spike2_' . uniqid(),
				'user_pass'    => wp_generate_password( 20 ),
				'display_name' => 'Spike2 Author',
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
		// wp_delete_user() живёт в wp-admin/includes/user.php, которое не
		// подключено на обычном фронтенд/REST-запросе.
		if ( ! function_exists( 'wp_delete_user' ) ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
		}

		wp_delete_post( $post_id, true );
		wp_delete_user( $user_id );
		TagRegistry::reset_for_tests();
	}
}
