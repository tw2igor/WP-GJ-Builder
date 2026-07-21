<?php

namespace WPGJBuilder\SiteParts;

use WPGJBuilder\Core\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Движок условий отображения частей сайта (раздел 7 спеки, модель
 * Elementor Theme Builder). Правило: {scope, mode: include|exclude, target}.
 * Конфликт двух частей ОДНОГО типа (напр. две шапки) на одной странице
 * разрешается специфичностью (конкретная страница > рубрика/тип > весь
 * сайт); при равной специфичности — предупреждение в диагностический
 * журнал (см. Core\Diagnostics) и детерминированный выбор по дате
 * изменения, чтобы фронтенд никогда не оставался без ответа.
 */
class DisplayConditions {

	const SCOPE_ENTIRE_SITE  = 'entire_site';
	const SCOPE_FRONT_PAGE   = 'front_page';
	const SCOPE_ALL_PAGES    = 'all_pages';
	const SCOPE_ALL_POSTS    = 'all_posts';
	const SCOPE_POST_TYPE    = 'post_type';
	const SCOPE_SPECIFIC_PAGE = 'specific_page';
	const SCOPE_CATEGORY     = 'category';
	const SCOPE_ARCHIVE      = 'archive';
	const SCOPE_404          = '404';

	private static $specificity = array(
		self::SCOPE_SPECIFIC_PAGE => 100,
		self::SCOPE_FRONT_PAGE    => 75,
		self::SCOPE_404           => 75,
		self::SCOPE_CATEGORY      => 50,
		self::SCOPE_POST_TYPE     => 50,
		self::SCOPE_ARCHIVE       => 50,
		self::SCOPE_ALL_PAGES     => 25,
		self::SCOPE_ALL_POSTS     => 25,
		self::SCOPE_ENTIRE_SITE   => 0,
	);

	/**
	 * @param array $conditions [{scope, mode, target}, ...]
	 * @param array $context {post_id, post_type, is_front_page, is_404, category_ids}
	 * @return int|false Наивысшая специфичность совпавшего include-правила, или false если часть не применяется.
	 */
	public static function matches( array $conditions, array $context ) {
		if ( empty( $conditions ) ) {
			// Часть без явных условий — по умолчанию "весь сайт" (раздел 7:
			// это одна из опций диалога, разумный дефолт для новой части).
			return self::$specificity[ self::SCOPE_ENTIRE_SITE ];
		}

		$best_include = false;

		foreach ( $conditions as $rule ) {
			if ( 'exclude' === $rule['mode'] && self::rule_matches_context( $rule, $context ) ) {
				return false; // exclude побеждает include безусловно.
			}
		}

		foreach ( $conditions as $rule ) {
			if ( 'include' !== $rule['mode'] ) {
				continue;
			}
			if ( self::rule_matches_context( $rule, $context ) ) {
				$specificity = self::$specificity[ $rule['scope'] ] ?? 0;
				if ( false === $best_include || $specificity > $best_include ) {
					$best_include = $specificity;
				}
			}
		}

		return $best_include;
	}

	private static function rule_matches_context( array $rule, array $context ): bool {
		switch ( $rule['scope'] ) {
			case self::SCOPE_ENTIRE_SITE:
				return true;
			case self::SCOPE_FRONT_PAGE:
				return ! empty( $context['is_front_page'] );
			case self::SCOPE_404:
				return ! empty( $context['is_404'] );
			case self::SCOPE_ALL_PAGES:
				return 'page' === ( $context['post_type'] ?? '' );
			case self::SCOPE_ALL_POSTS:
				return 'post' === ( $context['post_type'] ?? '' );
			case self::SCOPE_POST_TYPE:
				return ( $context['post_type'] ?? '' ) === ( $rule['target'] ?? '' );
			case self::SCOPE_SPECIFIC_PAGE:
				return (int) ( $context['post_id'] ?? 0 ) === (int) ( $rule['target'] ?? 0 );
			case self::SCOPE_CATEGORY:
				return in_array( (int) ( $rule['target'] ?? 0 ), $context['category_ids'] ?? array(), true );
			case self::SCOPE_ARCHIVE:
				return ! empty( $context['is_archive'] );
			default:
				return false;
		}
	}

	/**
	 * Резолвинг для КОНКРЕТНОЙ части типа (header|footer|...) под данный
	 * контекст: среди всех ОПУБЛИКОВАННЫХ частей этого типа выбирает
	 * наиболее специфичную совпавшую; при равной специфичности —
	 * детерминированно (по дате изменения) + запись в диагностику.
	 *
	 * @return \WP_Post|null
	 */
	public static function resolve_for_type( string $part_type, array $context ) {
		$candidates = array();

		foreach ( PartsPostType::list_published( $part_type ) as $post ) {
			$conditions  = PartsPostType::get_conditions( $post->ID );
			$specificity = self::matches( $conditions, $context );
			if ( false !== $specificity ) {
				$candidates[] = array( 'post' => $post, 'specificity' => $specificity );
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			function ( $a, $b ) {
				if ( $a['specificity'] === $b['specificity'] ) {
					return strtotime( $b['post']->post_modified ) <=> strtotime( $a['post']->post_modified );
				}
				return $b['specificity'] <=> $a['specificity'];
			}
		);

		$top_specificity = $candidates[0]['specificity'];
		$tied            = array_filter( $candidates, fn( $c ) => $c['specificity'] === $top_specificity );

		if ( count( $tied ) > 1 ) {
			Diagnostics::log(
				'display-conditions-conflict',
				sprintf( 'Несколько частей типа "%s" совпали с одинаковой специфичностью (%d) — выбрана недавно изменённая.', $part_type, $top_specificity ),
				array(
					'context'          => $context,
					'candidate_ids'    => array_map( fn( $c ) => $c['post']->ID, $tied ),
				)
			);
		}

		return $candidates[0]['post'];
	}

	/**
	 * Текущий контекст запроса — единая точка, где эти данные читаются
	 * из глобального состояния WP (не дублируется в каждом вызывающем коде).
	 */
	public static function current_context(): array {
		$post_id = get_queried_object_id();
		return array(
			'post_id'       => $post_id,
			'post_type'     => get_post_type( $post_id ) ?: '',
			'is_front_page' => is_front_page(),
			'is_404'        => is_404(),
			'is_archive'    => is_archive(),
			'category_ids'  => $post_id ? wp_get_post_categories( $post_id ) : array(),
		);
	}
}
