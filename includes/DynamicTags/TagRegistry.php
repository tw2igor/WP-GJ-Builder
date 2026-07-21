<?php
/**
 * Спайк 2 (план Phase 0, раздел 8 спеки): whitelist-реестр динамических
 * тегов. Единственный источник правды о том, какие tag_id вообще
 * существуют — резолвер (см. BatchResolver) отказывается резолвить
 * всё, что здесь не зарегистрировано (правило 3 раздела 8: "только
 * белый список... никакого исполнения кода из контента").
 *
 * Каждый тег принадлежит ровно одной группе источника данных
 * (`GROUP_*`) — это то, по чему батч-резолвер группирует запросы,
 * а не по количеству тегов/вхождений. Резолвер тега — чистая функция
 * над уже загруженными данными (`$context`), сама она никогда не
 * обращается к БД напрямую: загрузка данных — обязанность резолвера,
 * не тега (иначе N тегов снова дали бы N запросов).
 */

namespace WPGJBuilder\DynamicTags;

defined( 'ABSPATH' ) || exit;

class TagRegistry {

	/** Тег читает поля уже загруженного $post — 0 доп. запросов. */
	const GROUP_POST = 'post';

	/** Тег требует данные автора — резолвер грузит их одним get_userdata(). */
	const GROUP_AUTHOR = 'author';

	/** Тег читает произвольное postmeta-поле — резолвер грузит все meta поста одним get_post_meta( $id ). */
	const GROUP_POSTMETA = 'postmeta';

	/** Тег читает настройки сайта (опции) — уже в alloptions-кеше, 0 доп. запросов. */
	const GROUP_SITE = 'site';

	/**
	 * @var array<string, array{group: string, resolve: callable}>|null
	 */
	private static $tags = null;

	/**
	 * @return array<string, array{group: string, resolve: callable}>
	 */
	public static function all() {
		if ( null === self::$tags ) {
			self::$tags = array();
			self::register_defaults();
		}
		return self::$tags;
	}

	/**
	 * @return array{group: string, resolve: callable}|null
	 */
	public static function get( $tag_id ) {
		$all = self::all();
		return isset( $all[ $tag_id ] ) ? $all[ $tag_id ] : null;
	}

	/**
	 * Точка расширения для будущих очередей (ACF-теги и т.п., см. план
	 * "Вторая очередь") — регистрация тега извне без хардкода в самом
	 * реестре.
	 *
	 * @param string   $tag_id  Стабильный идентификатор тега, напр. "post_title".
	 * @param string   $group   Одна из констант GROUP_*.
	 * @param callable $resolve function( array $context, array $params ): string
	 *                          Обязана быть чистой функцией — никаких обращений к БД внутри,
	 *                          все данные уже должны быть в $context.
	 */
	public static function register( $tag_id, $group, callable $resolve ) {
		self::all(); // гарантирует инициализацию self::$tags перед записью.
		self::$tags[ $tag_id ] = array(
			'group'   => $group,
			'resolve' => $resolve,
		);
	}

	/**
	 * Набор первой очереди раздела 8 спеки: сайт / страница-запись / автор /
	 * произвольное поле записи. Featured image (+ alt) сознательно НЕ
	 * включён здесь — он читает данные из ДРУГОЙ записи (вложения), а не
	 * из $post/автора/опций сайта, и потребовал бы пятой группы источника
	 * данных с собственной ценой запроса, не покрытой существующей моделью
	 * "site/post/author/postmeta" (см. вердикт Phase 2, docs/adr/) — это
	 * осознанно отложено, а не забыто.
	 */
	private static function register_defaults() {
		self::$tags['site_title'] = array(
			'group'   => self::GROUP_SITE,
			'resolve' => function ( $context, $params ) {
				return get_bloginfo( 'name' );
			},
		);

		self::$tags['site_description'] = array(
			'group'   => self::GROUP_SITE,
			'resolve' => function ( $context, $params ) {
				return get_bloginfo( 'description' );
			},
		);

		self::$tags['site_url'] = array(
			'group'   => self::GROUP_SITE,
			'resolve' => function ( $context, $params ) {
				return home_url( '/' );
			},
		);

		self::$tags['site_icon'] = array(
			'group'   => self::GROUP_SITE,
			'resolve' => function ( $context, $params ) {
				return (string) get_site_icon_url();
			},
		);

		self::$tags['post_title'] = array(
			'group'   => self::GROUP_POST,
			'resolve' => function ( $context, $params ) {
				return $context['post'] ? get_the_title( $context['post'] ) : '';
			},
		);

		self::$tags['post_url'] = array(
			'group'   => self::GROUP_POST,
			'resolve' => function ( $context, $params ) {
				return $context['post'] ? (string) get_permalink( $context['post'] ) : '';
			},
		);

		self::$tags['post_excerpt'] = array(
			'group'   => self::GROUP_POST,
			'resolve' => function ( $context, $params ) {
				return $context['post'] ? get_the_excerpt( $context['post'] ) : '';
			},
		);

		self::$tags['post_date'] = array(
			'group'   => self::GROUP_POST,
			'resolve' => function ( $context, $params ) {
				return $context['post'] ? get_the_date( '', $context['post'] ) : '';
			},
		);

		self::$tags['post_modified'] = array(
			'group'   => self::GROUP_POST,
			'resolve' => function ( $context, $params ) {
				return $context['post'] ? get_the_modified_date( '', $context['post'] ) : '';
			},
		);

		self::$tags['author_name'] = array(
			'group'   => self::GROUP_AUTHOR,
			'resolve' => function ( $context, $params ) {
				return $context['author'] ? $context['author']->display_name : '';
			},
		);

		self::$tags['author_url'] = array(
			'group'   => self::GROUP_AUTHOR,
			'resolve' => function ( $context, $params ) {
				// user_url — нативная колонка wp_users, уже в $author из get_userdata(),
				// доп. запроса не требует.
				return $context['author'] ? (string) $context['author']->user_url : '';
			},
		);

		self::$tags['author_avatar'] = array(
			'group'   => self::GROUP_AUTHOR,
			'resolve' => function ( $context, $params ) {
				return $context['author'] ? (string) get_avatar_url( $context['author']->ID ) : '';
			},
		);

		self::$tags['author_bio'] = array(
			'group'   => self::GROUP_AUTHOR,
			'resolve' => function ( $context, $params ) {
				return $context['author'] ? get_the_author_meta( 'description', $context['author']->ID ) : '';
			},
		);

		// "Произвольное поле записи" раздела 8 — параметр key=... в плейсхолдере,
		// напр. {{wpb:post_meta;key=subtitle}}.
		self::$tags['post_meta'] = array(
			'group'   => self::GROUP_POSTMETA,
			'resolve' => function ( $context, $params ) {
				$key = isset( $params['key'] ) ? $params['key'] : '';
				if ( '' === $key || ! isset( $context['post_meta'][ $key ][0] ) ) {
					return '';
				}
				return (string) $context['post_meta'][ $key ][0];
			},
		);

		// Раздел 8 (вторая очередь): ACF-теги. Зарегистрирован ТОЛЬКО если
		// плагин ACF реально активен — иначе tag_id вообще не существует в
		// реестре, и {{wpb:acf_field;...}} честно резолвится в пустую строку
		// тем же путём, что любой опечатанный tag_id (раздел 8 правило 3),
		// а не "тег есть, но всегда пуст" непонятного происхождения.
		//
		// ВАЖНО: резолвер НЕ вызывает get_field() ACF — это нарушило бы
		// инвариант "резолвер тега — чистая функция без обращений к БД"
		// (см. докблок BatchResolver): get_field() при необходимости
		// резолвит ОБЪЕКТ поля (схему), что может стоить доп. запрос(ов) НА
		// КАЖДОЕ РАЗНОЕ имя поля, а не один раз на группу — это сломало бы
		// гарантию "≤3 запроса вне зависимости от числа тегов". Вместо этого
		// читается СЫРОЕ значение из уже загруженного $context['post_meta']
		// (тот же батч, что post_meta, 0 доп. запросов) — корректно для
		// простых скалярных полей (text/textarea/number/url/email/одиночный
		// select), но НЕ для сложных типов (repeater/gallery/relationship/
		// true_false-форматирование) — ACF хранит их сериализованными,
		// поэтому такое значение сознательно отфильтровано (пустая строка),
		// а не выводится как сырой PHP serialize() в разметку.
		if ( function_exists( 'get_field' ) ) {
			self::$tags['acf_field'] = array(
				'group'   => self::GROUP_POSTMETA,
				'resolve' => function ( $context, $params ) {
					return self::resolve_acf_raw_field( $context, $params );
				},
			);
		}
	}

	/** Вынесено в отдельный именованный метод, чтобы логика была тестируема независимо от того, активен ли ACF в текущей среде. */
	public static function resolve_acf_raw_field( array $context, array $params ): string {
		$key = isset( $params['key'] ) ? $params['key'] : '';
		if ( '' === $key || ! isset( $context['post_meta'][ $key ][0] ) ) {
			return '';
		}

		$value = $context['post_meta'][ $key ][0];

		// Сериализованное значение (repeater/gallery/relationship и т.п.) —
		// честно не поддержано этим простым путём, не выводим PHP-мусор.
		if ( is_string( $value ) && 1 === preg_match( '/^[aOs]:\d+:/', $value ) ) {
			return '';
		}

		return (string) $value;
	}

	/**
	 * Только для тестов/спайков: сброс к состоянию по умолчанию, чтобы
	 * временные тег-пробники, зарегистрированные в тесте, не утекали
	 * между прогонами внутри одного запроса.
	 */
	public static function reset_for_tests() {
		self::$tags = null;
	}
}
