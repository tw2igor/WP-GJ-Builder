<?php

namespace WPGJBuilder\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Автоматизируемая часть чек-листа выпуска блока (раздел 6 спеки, план
 * Phase 4: "как lint-проверка"). Из 7 пунктов раздела 6 программно
 * проверяемы: 1 (нет зашитых hex/font-family мимо var()-фолбэка), 2
 * (семантика — data-slot-href только на <a>, alt у <img>), 5 (манифест
 * заполнен — переиспользует ManifestValidator, не дублирует правила).
 * Пункты 3 (3 контрастных темы), 4 (адаптивность), 7 (нейтральные
 * заглушки-изображения) — по природе визуальные/ручные, здесь НЕ
 * симулируются программной проверкой, которая создала бы ложное чувство
 * полноты; они закрываются отдельно (docs/adr/phase-4-verdict.md,
 * скриншот-прогон на 3 темах).
 */
class ChecklistLinter {

	/**
	 * @return array{pass: bool, issues: string[]}
	 */
	public static function lint_block( string $block_id ): array {
		$block = BlockLibrary::get( $block_id );
		if ( ! $block ) {
			return array( 'pass' => false, 'issues' => array( 'Блок не найден в каталоге.' ) );
		}

		$issues = array_merge(
			self::check_manifest_complete( $block['manifest'] ),
			self::check_no_hardcoded_colors( $block['style'] ),
			self::check_no_hardcoded_font_family( $block['style'] ),
			self::check_semantic_markup( $block['markup'] )
		);

		return array(
			'pass'   => empty( $issues ),
			'issues' => $issues,
		);
	}

	/**
	 * @return array<string, array{pass: bool, issues: string[]}>
	 */
	public static function lint_all(): array {
		$results = array();
		foreach ( array_keys( BlockLibrary::all() ) as $block_id ) {
			$results[ $block_id ] = self::lint_block( $block_id );
		}
		return $results;
	}

	private static function check_manifest_complete( array $manifest ): array {
		$issues = ManifestValidator::validate_manifest( $manifest );

		if ( empty( $manifest['purpose'] ) || mb_strlen( $manifest['purpose'] ) < 20 ) {
			$issues[] = 'purpose: описание назначения блока слишком короткое или отсутствует (нужно осмысленное предложение, не заглушка).';
		}
		if ( empty( $manifest['tags'] ) ) {
			$issues[] = 'tags: не заполнены (хотя бы одна метка для поиска/фильтрации в каталоге).';
		}

		return $issues;
	}

	/**
	 * Пункт 1 раздела 6: hex-цвет допустим ТОЛЬКО как фолбэк внутри
	 * var(--wp--preset--..., #hex) — не как самостоятельное значение.
	 */
	private static function check_no_hardcoded_colors( string $css ): array {
		$issues = array();

		if ( ! preg_match_all( '/#[0-9a-fA-F]{3,8}\b/', $css, $matches, PREG_OFFSET_CAPTURE ) ) {
			return $issues;
		}

		foreach ( $matches[0] as $match ) {
			list( $hex, $offset ) = $match;
			if ( ! self::is_inside_var_fallback( $css, $offset ) ) {
				$issues[] = "style.css: цвет {$hex} используется напрямую, не как var()-фолбэк темы.";
			}
		}

		return array_unique( $issues );
	}

	/**
	 * Проверяет, находится ли позиция $offset внутри незакрытого
	 * var( --что-то, ЗДЕСЬ ) — т.е. после запятой внутри вызова var(),
	 * а не как отдельное CSS-значение.
	 */
	private static function is_inside_var_fallback( string $css, int $offset ): bool {
		$before = substr( $css, max( 0, $offset - 120 ), min( $offset, 120 ) );

		$last_var_open = strrpos( $before, 'var(' );
		if ( false === $last_var_open ) {
			return false;
		}

		$between = substr( $before, $last_var_open );
		// Между "var(" и нашей позицией не должно быть закрывающей ")",
		// иначе это уже другой, завершившийся вызов var().
		if ( false !== strpos( $between, ')' ) ) {
			return false;
		}
		// И должна быть запятая (мы во ВТОРОМ аргументе var(), не в имени переменной).
		return false !== strpos( $between, ',' );
	}

	/**
	 * Пункт 1 раздела 6: font-family — либо через var(--wp--preset--font-family--*),
	 * либо не объявляется в блоке вовсе (наследуется от темы по умолчанию).
	 */
	private static function check_no_hardcoded_font_family( string $css ): array {
		$issues = array();

		if ( preg_match_all( '/font-family\s*:\s*([^;]+);/', $css, $matches ) ) {
			foreach ( $matches[1] as $value ) {
				if ( false === strpos( $value, 'var(--wp--preset--font-family' ) ) {
					$issues[] = "style.css: font-family: {$value} — зашитое имя шрифта, должно быть var(--wp--preset--font-family--*, ...) или не объявляться вовсе.";
				}
			}
		}

		return $issues;
	}

	/**
	 * Пункт 2 раздела 6: реальные button/a (не div+onclick), alt у изображений.
	 */
	private static function check_semantic_markup( string $markup ): array {
		$issues = array();

		if ( preg_match_all( '/<(\w+)[^>]*\bdata-slot-href=/', $markup, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				if ( 'a' !== strtolower( $tag ) ) {
					$issues[] = "markup.html: data-slot-href стоит на <{$tag}>, а не на <a> — ссылки должны быть настоящими <a href>.";
				}
			}
		}

		if ( preg_match_all( '/<img\b([^>]*)>/', $markup, $matches ) ) {
			foreach ( $matches[1] as $attrs ) {
				if ( ! preg_match( '/\balt\s*=/', $attrs ) ) {
					$issues[] = 'markup.html: <img> без атрибута alt.';
				}
			}
		}

		return $issues;
	}
}
