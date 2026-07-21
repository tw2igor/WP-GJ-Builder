<?php
/**
 * Контур 2 (раздел 10 спеки): срабатывает НЕПОСРЕДСТВЕННО ПЕРЕД публикацией
 * (Phase 2, перед записью финального HTML/CSS в post_content), НЕЗАВИСИМО
 * от того, прошёл ли документ через контур 1 — то есть обязателен даже
 * если запрос обошёл обычный UI редактора (прямой forged REST-вызов на
 * publish-эндпоинт). Это ТОНКАЯ обёртка — никакой собственной логики
 * санитизации здесь нет, только вызов Sanitizer, буквально та же функция,
 * что и в Contour1EditorSave. Различается только точка вызова, не правила.
 */

namespace WPGJBuilder\Sanitize;

defined( 'ABSPATH' ) || exit;

class Contour2PrePublish {

	/**
	 * @param string $html                Финальный собранный HTML перед записью в post_content.
	 * @param string $css                 Финальный собранный CSS перед публикацией.
	 * @param array  $allowed_tags        Whitelist тегов/атрибутов (формат wp_kses()).
	 * @param array  $allowed_css_properties Whitelist CSS-свойств.
	 * @return array{html: string, css: string}
	 */
	public static function sanitize_document( string $html, string $css, array $allowed_tags, array $allowed_css_properties ): array {
		return array(
			'html' => Sanitizer::sanitize_html( $html, $allowed_tags ),
			'css'  => Sanitizer::sanitize_css( $css, $allowed_css_properties ),
		);
	}
}
