<?php
/**
 * Спайк 2 (план Phase 0, раздел 8 спеки): батч-резолвер плейсхолдеров
 * динамических тегов.
 *
 * Формат плейсхолдера (зафиксирован планом, не переизобретается здесь):
 *   {{wpb:tag_id;param=value;param2=value2}}
 * хранится буквально в post_content.
 *
 * Ключевой трюк, вытекающий из плана: запросы группируются по
 * ИСТОЧНИКУ ДАННЫХ, а не по тегу и не по вхождению.
 *   - $post уже загружен вызывающим кодом publish/serve-пути — 0 запросов.
 *   - get_userdata() вызывается РОВНО ОДИН РАЗ, если хотя бы один
 *     присутствующий на странице тег принадлежит группе "author" —
 *     не важно, сколько раз тег автора встречается на странице.
 *   - get_post_meta( $post->ID ) (без ключа — тянет все meta одним
 *     запросом) вызывается РОВНО ОДИН РАЗ, если хотя бы один
 *     присутствующий тег принадлежит группе "postmeta" — не важно,
 *     сколько разных ключей postmeta запрошено.
 *   - group "site" — get_bloginfo()/опции сайта уже в alloptions-кеше
 *     (autoload = yes для большинства core-опций) — 0 доп. запросов.
 * Итог: 1-3 запроса вне зависимости от числа тегов/вхождений на странице.
 *
 * Дерево обходится РОВНО ОДИН раз через WP_HTML_Tag_Processor::next_token()
 * (никакого нового HTML-парсера). Предварительный поиск tag_id по сырой
 * строке (scan_tag_ids) — это НЕ парсинг дерева, а дешёвый regex-скан
 * необходимый только чтобы понять, какие источники данных вообще нужно
 * загрузить, ДО того как начинается единственный проход по дереву.
 *
 * Экранирование определяется позицией плейсхолдера в дереве (текстовый
 * узел / атрибут / URL-атрибут), а не доверием к автору тега — правило 4
 * раздела 8.
 */

namespace WPGJBuilder\DynamicTags;

defined( 'ABSPATH' ) || exit;

class BatchResolver {

	const PATTERN = '/\{\{wpb:([a-zA-Z0-9_\-]+)((?:;[a-zA-Z0-9_\-]+=[^;{}]*)*)\}\}/';

	/**
	 * Атрибуты, значение которых трактуется как URL (esc_url), а не
	 * как обычный атрибут (esc_attr).
	 */
	const URL_ATTRIBUTES = array( 'href', 'src', 'action', 'formaction', 'poster', 'cite', 'data' );

	/**
	 * @param string   $html Статический HTML страницы (post_content), содержащий плейсхолдеры буквально.
	 * @param \WP_Post $post Уже загруженный объект поста текущего запроса.
	 * @return string HTML с резолвленными и экранированными по контексту значениями.
	 */
	public static function resolve( $html, \WP_Post $post ) {
		$tag_ids = self::scan_tag_ids( $html );

		if ( empty( $tag_ids ) ) {
			return $html; // Ни одного плейсхолдера — не парсим HTML впустую.
		}

		$context = self::load_context( $post, $tag_ids );

		return self::walk_and_replace( $html, $context );
	}

	/**
	 * Дешёвый regex-скан сырой строки (НЕ HTML-парсинг) — нужен только
	 * чтобы заранее узнать множество различных tag_id на странице и
	 * решить, какие источники данных грузить одним batch'ем.
	 *
	 * @return string[] Уникальные tag_id, встреченные на странице.
	 */
	private static function scan_tag_ids( $html ) {
		if ( ! preg_match_all( self::PATTERN, $html, $matches ) ) {
			return array();
		}
		return array_values( array_unique( $matches[1] ) );
	}

	/**
	 * Ровно здесь и только здесь резолвер обращается к БД — сгруппировано
	 * по источнику, не по тегу/вхождению.
	 *
	 * @return array{post: \WP_Post, author: \WP_User|null, post_meta: array|null}
	 */
	private static function load_context( \WP_Post $post, array $tag_ids ) {
		$registry = TagRegistry::all();

		$needs_author   = false;
		$needs_postmeta = false;

		foreach ( $tag_ids as $tag_id ) {
			if ( ! isset( $registry[ $tag_id ] ) ) {
				continue; // Не в белом списке — вообще не резолвится, см. resolve_and_escape().
			}
			if ( TagRegistry::GROUP_AUTHOR === $registry[ $tag_id ]['group'] ) {
				$needs_author = true;
			} elseif ( TagRegistry::GROUP_POSTMETA === $registry[ $tag_id ]['group'] ) {
				$needs_postmeta = true;
			}
		}

		$context = array(
			'post'      => $post,
			'author'    => null,
			'post_meta' => null,
		);

		if ( $needs_author ) {
			// Один вызов вне зависимости от того, сколько author-тегов/вхождений на странице.
			$context['author'] = get_userdata( (int) $post->post_author );
		}

		if ( $needs_postmeta ) {
			// Один вызов без ключа — тянет ВСЕ meta поста разом, вне зависимости
			// от того, сколько разных ключей postmeta запрошено на странице.
			$context['post_meta'] = get_post_meta( $post->ID );
		}

		return $context;
	}

	/**
	 * Единственный проход по дереву документа. next_token() отдаёт как
	 * теги (#tag), так и текстовые узлы (#text) в порядке следования —
	 * этого достаточно, чтобы обработать и текстовые плейсхолдеры, и
	 * плейсхолдеры в атрибутах за один обход, без повторного парсинга.
	 */
	private static function walk_and_replace( $html, array $context ) {
		$processor = new \WP_HTML_Tag_Processor( $html );

		while ( $processor->next_token() ) {
			$token_type = $processor->get_token_type();

			if ( '#text' === $token_type ) {
				$text = $processor->get_modifiable_text();
				if ( false !== strpos( $text, '{{wpb:' ) ) {
					$processor->set_modifiable_text( self::replace_in_string( $text, $context, 'text' ) );
				}
				continue;
			}

			if ( '#tag' !== $token_type ) {
				continue; // Комментарии, doctype и т.п. — плейсхолдеры там не резолвим.
			}

			$attribute_names = $processor->get_attribute_names_with_prefix( '' );
			if ( empty( $attribute_names ) ) {
				continue;
			}

			foreach ( $attribute_names as $attribute_name ) {
				$value = $processor->get_attribute( $attribute_name );
				if ( ! is_string( $value ) || false === strpos( $value, '{{wpb:' ) ) {
					continue;
				}

				$escape_context = self::attribute_escape_context( $attribute_name );
				$processor->set_attribute( $attribute_name, self::replace_in_string( $value, $context, $escape_context ) );
			}
		}

		return $processor->get_updated_html();
	}

	/**
	 * Позиция в дереве определяет контекст экранирования — не то, что
	 * "заявляет" сам тег.
	 */
	private static function attribute_escape_context( $attribute_name ) {
		return in_array( strtolower( $attribute_name ), self::URL_ATTRIBUTES, true ) ? 'url' : 'attr';
	}

	private static function replace_in_string( $subject, array $context, $escape_context ) {
		return preg_replace_callback(
			self::PATTERN,
			function ( $matches ) use ( $context, $escape_context ) {
				$params = self::parse_params( $matches[2] );
				return self::resolve_and_escape( $matches[1], $params, $context, $escape_context );
			},
			$subject
		);
	}

	/**
	 * @return array<string, string>
	 */
	private static function parse_params( $raw ) {
		$params = array();
		$raw    = ltrim( $raw, ';' );
		if ( '' === $raw ) {
			return $params;
		}
		foreach ( explode( ';', $raw ) as $pair ) {
			if ( false === strpos( $pair, '=' ) ) {
				continue;
			}
			list( $key, $value ) = explode( '=', $pair, 2 );
			$params[ $key ]      = $value;
		}
		return $params;
	}

	/**
	 * Whitelist-гейт (правило 3 раздела 8): тег, не зарегистрированный в
	 * TagRegistry, не резолвится и не выполняет никакого кода — просто
	 * заменяется на пустую строку.
	 *
	 * ВАЖНО (подтверждено эмпирически прогоном через WP Playground,
	 * см. отчёт спайка 2): WP_HTML_Tag_Processor::set_attribute() и
	 * set_modifiable_text() САМИ корректно HTML-кодируют значение,
	 * которое им передают (это их прямое назначение — безопасная запись
	 * текстовых узлов/атрибутов обратно в дерево). Если дополнительно
	 * прогонять значение через esc_html()/esc_attr() перед тем как
	 * отдать его set_modifiable_text()/set_attribute(), получается
	 * ДВОЙНОЕ экранирование (например, `"><script>` превращается не в
	 * `&quot;&gt;&lt;script&gt;`, а в `&amp;quot;&amp;gt;&amp;lt;script...`) —
	 * не уязвимость, но заметная порча отображаемого значения. Поэтому
	 * для контекстов text/attr сырое значение отдаётся процессору как
	 * есть, а esc_html()/esc_attr() не вызываются вовсе.
	 *
	 * Для URL-атрибутов Tag Processor не делает и не должен делать
	 * ничего похожего на esc_url() — у него нет понятия "опасная схема
	 * протокола" (javascript:, data: и т.п.), это чисто HTML-сериализация,
	 * не валидация URL. Поэтому esc_url() здесь обязателен именно для
	 * URL-контекста — это единственный контекст, где ручное экранирование
	 * до передачи в процессор реально нужно (и не дублирует его работу,
	 * что также подтверждено прогоном: esc_url_raw()-значение с `&` не
	 * было закодировано повторно процессором).
	 */
	private static function resolve_and_escape( $tag_id, array $params, array $context, $escape_context ) {
		$tag = TagRegistry::get( $tag_id );
		if ( null === $tag ) {
			return '';
		}

		$raw_value = (string) call_user_func( $tag['resolve'], $context, $params );

		if ( 'url' === $escape_context ) {
			return esc_url( $raw_value );
		}

		// 'text' и 'attr': значение отдаётся как есть — set_modifiable_text()/
		// set_attribute() сами кодируют его безопасно для своего контекста.
		return $raw_value;
	}
}
