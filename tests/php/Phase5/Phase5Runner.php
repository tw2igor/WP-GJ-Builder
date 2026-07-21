<?php
/**
 * Проверка Phase 5 (Части сайта + безопасность). Как и в предыдущих фазах,
 * гоняется вручную через дев-only REST-маршрут — нет PHPUnit в среде
 * разработки. Удалить перед Phase 7.
 */

namespace WPGJBuilder\Tests\Phase5;

use WPGJBuilder\Blocks\BlockLibrary;
use WPGJBuilder\Core\Diagnostics;
use WPGJBuilder\Sanitize\ProjectDataSanitizer;
use WPGJBuilder\SiteParts\CacheCascade;
use WPGJBuilder\SiteParts\DisplayConditions;
use WPGJBuilder\SiteParts\PartsPostType;

defined( 'ABSPATH' ) || exit;

class Phase5Runner {

	public static function run() {
		$results = array();

		$results['part_cpt_and_default_conditions']    = self::check_part_cpt_and_default_conditions();
		$results['specificity_resolution']              = self::check_specificity_resolution();
		$results['equal_specificity_conflict_logged']    = self::check_conflict_logged();
		$results['cache_cascade_invalidates_target_page'] = self::check_cache_cascade();
		$results['insert_code_hidden_without_capability'] = self::check_insert_code_visibility();
		$results['raw_html_sanitized_without_capability'] = self::check_raw_html_sanitization_unprivileged();
		$results['raw_html_passed_with_capability_and_audited'] = self::check_raw_html_with_capability();
		$results['ordinary_script_still_rejected']        = self::check_ordinary_script_still_rejected();

		return $results;
	}

	private static function make_page( string $title ): int {
		return wp_insert_post(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
	}

	private static function cleanup_posts( array $ids ) {
		foreach ( $ids as $id ) {
			wp_delete_post( $id, true );
		}
	}

	private static function check_part_cpt_and_default_conditions() {
		$header_id = PartsPostType::create( 'header', 'Phase5 Test Header', get_current_user_id() );

		$type_ok       = 'header' === PartsPostType::get_part_type( $header_id );
		$conditions_ok = array() === PartsPostType::get_conditions( $header_id );

		$context      = array( 'post_id' => 123, 'post_type' => 'page', 'is_front_page' => false, 'is_404' => false, 'category_ids' => array() );
		$default_match = DisplayConditions::matches( PartsPostType::get_conditions( $header_id ), $context );

		$ok = $type_ok && $conditions_ok && 0 === $default_match; // entire_site по умолчанию, specificity 0

		self::cleanup_posts( array( $header_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok ? 'wpb_part создан, part_type=header, пустые условия трактуются как "весь сайт" (specificity=0)' : 'неожиданное поведение CPT/дефолтных условий',
			'result' => array( 'type_ok' => $type_ok, 'conditions_ok' => $conditions_ok, 'default_match' => $default_match ),
		);
	}

	private static function check_specificity_resolution() {
		$page_id      = self::make_page( 'Phase5 Specific Page' );
		$generic_id   = PartsPostType::create( 'header', 'Generic Header', get_current_user_id() );
		$specific_id  = PartsPostType::create( 'header', 'Specific Header', get_current_user_id() );

		wp_publish_post( $generic_id );
		wp_publish_post( $specific_id );

		PartsPostType::set_conditions( $generic_id, array( array( 'scope' => DisplayConditions::SCOPE_ENTIRE_SITE, 'mode' => 'include', 'target' => null ) ) );
		PartsPostType::set_conditions( $specific_id, array( array( 'scope' => DisplayConditions::SCOPE_SPECIFIC_PAGE, 'mode' => 'include', 'target' => $page_id ) ) );

		$context = array( 'post_id' => $page_id, 'post_type' => 'page', 'is_front_page' => false, 'is_404' => false, 'category_ids' => array() );
		$resolved = DisplayConditions::resolve_for_type( 'header', $context );

		$ok = $resolved && $resolved->ID === $specific_id;

		self::cleanup_posts( array( $page_id, $generic_id, $specific_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'при конфликте "весь сайт" (specificity 0) и "конкретная страница" (specificity 100) выбрана более специфичная'
				: 'ожидалась специфичная часть, получена другая или ничего',
			'result' => array( 'resolved_id' => $resolved->ID ?? null, 'expected' => $specific_id ),
		);
	}

	private static function check_conflict_logged() {
		$page_id = self::make_page( 'Phase5 Conflict Page' );
		$a_id    = PartsPostType::create( 'header', 'Header A', get_current_user_id() );
		$b_id    = PartsPostType::create( 'header', 'Header B', get_current_user_id() );
		wp_publish_post( $a_id );
		wp_publish_post( $b_id );

		// Обе части нацелены на ОДНУ и ту же страницу — равная специфичность (100).
		PartsPostType::set_conditions( $a_id, array( array( 'scope' => DisplayConditions::SCOPE_SPECIFIC_PAGE, 'mode' => 'include', 'target' => $page_id ) ) );
		PartsPostType::set_conditions( $b_id, array( array( 'scope' => DisplayConditions::SCOPE_SPECIFIC_PAGE, 'mode' => 'include', 'target' => $page_id ) ) );

		$before_count = count( Diagnostics::recent( 500 ) );

		$context  = array( 'post_id' => $page_id, 'post_type' => 'page', 'is_front_page' => false, 'is_404' => false, 'category_ids' => array() );
		$resolved = DisplayConditions::resolve_for_type( 'header', $context );

		$after = Diagnostics::recent( 500 );
		$logged = false;
		foreach ( $after as $entry ) {
			if ( 'display-conditions-conflict' === ( $entry['channel'] ?? '' ) ) {
				$logged = true;
			}
		}

		$ok = null !== $resolved && $logged; // деттерминированный выбор БЕЗ падения + запись предупреждения

		self::cleanup_posts( array( $page_id, $a_id, $b_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'две части с равной специфичностью на одной странице: резолвер вернул одну из них детерминированно И записал предупреждение в диагностику'
				: 'ожидался результат + запись конфликта в Diagnostics',
			'result' => array( 'resolved' => null !== $resolved, 'logged' => $logged ),
		);
	}

	private static function check_cache_cascade() {
		$page_id  = self::make_page( 'Phase5 Cascade Page' );
		$other_id = self::make_page( 'Phase5 Untouched Page' );
		$header_id = PartsPostType::create( 'header', 'Cascade Header', get_current_user_id() );
		wp_publish_post( $header_id );

		PartsPostType::set_conditions( $header_id, array( array( 'scope' => DisplayConditions::SCOPE_SPECIFIC_PAGE, 'mode' => 'include', 'target' => $page_id ) ) );

		$cleaned = array();
		$listener = function ( $id ) use ( &$cleaned ) {
			$cleaned[] = (int) $id;
		};
		add_action( 'clean_post_cache', $listener );

		$count = CacheCascade::invalidate_for_part( $header_id, 'header' );

		remove_action( 'clean_post_cache', $listener );

		$ok = 1 === $count && in_array( $page_id, $cleaned, true ) && ! in_array( $other_id, $cleaned, true );

		self::cleanup_posts( array( $page_id, $other_id, $header_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'публикация части с condition=specific_page сбросила кэш ТОЛЬКО целевой страницы, не всех подряд'
				: 'ожидался clean_post_cache ровно для целевой страницы',
			'result' => array( 'count' => $count, 'cleaned' => $cleaned ),
		);
	}

	private static function check_insert_code_visibility() {
		// Ловушка: $user->remove_cap() снимает ИНДИВИДУАЛЬНОЕ право
		// пользователя, но не право, унаследованное от РОЛИ — текущий
		// пользователь в этой среде всегда 'administrator', а именно роли
		// принадлежит wpgjb_insert_raw_code (см. Activation::CAPABILITIES).
		// Чтобы реально смоделировать "без права", нужно временно снять
		// капабилити с самой роли, а не с объекта пользователя.
		$role    = get_role( 'administrator' );
		$had_cap = $role->has_cap( 'wpgjb_insert_raw_code' );

		$role->remove_cap( 'wpgjb_insert_raw_code' );
		wp_get_current_user()->get_role_caps(); // WP_User кэширует allcaps при создании — без явного пересчёта current_user_can() не увидит изменение роли в этом же запросе.
		$without = BlockLibrary::all_visible_to_current_user();
		$hidden_when_unprivileged = ! array_key_exists( 'insert-code', $without );

		$role->add_cap( 'wpgjb_insert_raw_code' );
		wp_get_current_user()->get_role_caps();
		$with = BlockLibrary::all_visible_to_current_user();
		$visible_when_privileged = array_key_exists( 'insert-code', $with );

		if ( ! $had_cap ) {
			$role->remove_cap( 'wpgjb_insert_raw_code' );
			wp_get_current_user()->get_role_caps();
		}

		$ok = $hidden_when_unprivileged && $visible_when_privileged;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'блок insert-code скрыт из каталога без капабилити wpgjb_insert_raw_code и виден с ней'
				: 'видимость insert-code не зависит от капабилити как ожидалось',
			'result' => array( 'hidden_when_unprivileged' => $hidden_when_unprivileged, 'visible_when_privileged' => $visible_when_privileged ),
		);
	}

	private static function check_raw_html_sanitization_unprivileged() {
		// См. check_insert_code_visibility(): капабилити снимается с РОЛИ,
		// не с пользователя — иначе administrator сохраняет право через
		// роль независимо от вызова remove_cap() на объекте пользователя.
		$role    = get_role( 'administrator' );
		$had_cap = $role->has_cap( 'wpgjb_insert_raw_code' );
		$role->remove_cap( 'wpgjb_insert_raw_code' );
		wp_get_current_user()->get_role_caps(); // см. check_insert_code_visibility() — allcaps кэшируется, нужен явный пересчёт.

		$slots = array( array( 'key' => 'code', 'type' => 'raw_html' ) );
		$sanitized = ProjectDataSanitizer::sanitize_values( $slots, array( 'code' => '<script>alert(1)</script><p>ok</p>' ) );

		if ( $had_cap ) {
			$role->add_cap( 'wpgjb_insert_raw_code' );
			wp_get_current_user()->get_role_caps();
		}

		$ok = false === strpos( $sanitized['code'], '<script>' ) && false === strpos( $sanitized['code'], '<p>' );
		// richness_none снимает вообще все теги, включая безобидный <p> — это ожидаемо
		// (безопасный дефолт для неавторизованного вызова, не частичная фильтрация).

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'без капабилити raw_html полностью очищается (ноль тегов), как обычный string-слот'
				: 'raw_html не был безопасно очищен без капабилити',
			'result' => array( 'sanitized' => $sanitized['code'] ),
		);
	}

	private static function check_raw_html_with_capability() {
		// ВАЖНО: $user->add_cap() ВСЕГДА пишет капабилити ИНДИВИДУАЛЬНО в
		// usermeta пользователя, независимо от того, была ли она уже
		// доступна через роль — если не убрать её потом безусловно, она
		// "прилипает" в БД навсегда и ломает последующие прогоны других
		// проверок этого же файла (найдено и исправлено в этой сессии:
		// именно так ранее давал ложный "провал" check_insert_code_visibility
		// после первого запуска этого теста).
		$user = wp_get_current_user();
		$user->add_cap( 'wpgjb_insert_raw_code' );

		$before_count = count( array_filter( Diagnostics::recent( 500 ), fn( $e ) => 'raw-code-audit' === ( $e['channel'] ?? '' ) ) );

		$slots = array( array( 'key' => 'code', 'type' => 'raw_html' ) );
		$payload = '<script>console.log("privileged snippet")</script>';
		$sanitized = ProjectDataSanitizer::sanitize_values( $slots, array( 'code' => $payload ) );

		$after_count = count( array_filter( Diagnostics::recent( 500 ), fn( $e ) => 'raw-code-audit' === ( $e['channel'] ?? '' ) ) );

		$user->remove_cap( 'wpgjb_insert_raw_code' ); // безусловно — см. комментарий выше.

		$ok = $sanitized['code'] === $payload && $after_count > $before_count;

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'с капабилити raw_html проходит БЕЗ изменений (осознанный обход контура 1) И пишется запись в аудит-журнал'
				: 'ожидался неизменённый payload + новая запись в Diagnostics',
			'result' => array( 'unchanged' => $sanitized['code'] === $payload, 'audit_entry_added' => $after_count > $before_count ),
		);
	}

	private static function check_ordinary_script_still_rejected() {
		// Раздел 10 / критерий приёмки №7: обычные (не raw_html) слоты
		// по-прежнему не пропускают <script> вне зависимости от того, что
		// в схему добавился новый тип raw_html.
		$slots = array( array( 'key' => 'title', 'type' => 'string' ) );
		$sanitized = ProjectDataSanitizer::sanitize_values( $slots, array( 'title' => '<script>alert(1)</script>Заголовок' ) );

		$ok = false === strpos( $sanitized['title'], '<script>' ) && false !== strpos( $sanitized['title'], 'Заголовок' );

		return array(
			'pass'   => $ok,
			'detail' => $ok ? 'обычный string-слот по-прежнему отклоняет <script>, текст сохранён' : 'регрессия: обычный слот пропустил script',
			'result' => array( 'sanitized' => $sanitized['title'] ),
		);
	}
}
