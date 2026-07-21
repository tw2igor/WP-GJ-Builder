<?php

namespace WPGJBuilder\Blocks;

defined( 'ABSPATH' ) || exit;

/**
 * Раздел 12 спеки: "Регрессионные снимки. Релиз обновления библиотеки
 * блоков тестируется на сохранённых JSON-снимках предыдущих версий
 * каждого блока." Снимок — результат рендера блока (BlockRenderer, тот же
 * путь, что REST-вставка/AI) с КАНОНИЧНЫМИ синтетическими значениями слотов
 * (детерминированными по типу слота, не зависят от текста-плейсхолдера в
 * markup.html) + сигнатура слотов манифеста + версия схемы. Сравнение
 * "было/стало" ловит непреднамеренные структурные изменения при
 * обновлении библиотеки (переименование data-slot, поломанная
 * вложенность и т.п.), не просто визуальные правки стиля (style.css в
 * снимок не входит — раздел 6 уже даёт отдельный чек-лист на визуальное).
 */
class BlockSnapshotter {

	const SNAPSHOTS_DIR_RELATIVE = 'tests/php/Snapshots/blocks';

	public static function canonical_values( array $manifest ): array {
		$values = array();
		foreach ( $manifest['slots'] as $slot ) {
			$values[ $slot['key'] ] = self::canonical_value_for_slot( $slot );
		}
		return $values;
	}

	private static function canonical_value_for_slot( array $slot ) {
		switch ( $slot['type'] ) {
			case 'richtext':
				return '<p>Пример ' . $slot['key'] . '</p>';
			case 'link':
				return '#snapshot-link';
			case 'image':
				return '0';
			case 'raw_html':
				return '';
			case 'array':
				$min        = max( 1, (int) ( $slot['min_items'] ?? 1 ) );
				$item_slots = array();
				foreach ( $slot['item_schema'] as $item_key => $item_slot ) {
					$item_slot['key'] = $item_key;
					$item_slots[]     = $item_slot;
				}
				$item_values = array();
				foreach ( $item_slots as $item_slot ) {
					$item_values[ $item_slot['key'] ] = self::canonical_value_for_slot( $item_slot );
				}
				return array_fill( 0, $min, $item_values );
			default: // string, icon
				return 'Пример ' . $slot['key'];
		}
	}

	public static function generate_snapshot( string $block_id ): ?array {
		$block = BlockLibrary::get( $block_id );
		if ( ! $block ) {
			return null;
		}

		$values    = self::canonical_values( $block['manifest'] );
		$component = BlockRenderer::render_component( $block_id, $block['manifest'], $block['markup'], $values );

		return array(
			'block_id'        => $block_id,
			'schema_version'  => $block['manifest']['schema_version'] ?? null,
			'slots_signature' => array_map(
				function ( $slot ) {
					return array( 'key' => $slot['key'], 'type' => $slot['type'] );
				},
				$block['manifest']['slots']
			),
			'rendered'        => $component,
		);
	}

	private static function snapshot_path( string $block_id ): string {
		return WPGJB_PLUGIN_DIR . self::SNAPSHOTS_DIR_RELATIVE . "/{$block_id}.json";
	}

	public static function load_baseline( string $block_id ): ?array {
		$path = self::snapshot_path( $block_id );
		if ( ! file_exists( $path ) ) {
			return null;
		}
		$decoded = json_decode( file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return is_array( $decoded ) ? $decoded : null;
	}

	/** Перегенерировать baseline на диске — сознательное действие разработчика после проверенного изменения блока, не автоматика. */
	public static function write_baseline( string $block_id ): bool {
		$snapshot = self::generate_snapshot( $block_id );
		if ( null === $snapshot ) {
			return false;
		}
		$path = self::snapshot_path( $block_id );
		$dir  = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		return false !== file_put_contents( $path, wp_json_encode( $snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
	}

	/** @return array{status: string, diff: array|null} */
	public static function compare_snapshots( ?array $baseline, ?array $current ): array {
		if ( null === $baseline ) {
			return array( 'status' => 'no_baseline', 'diff' => null );
		}
		if ( $baseline === $current ) {
			return array( 'status' => 'match', 'diff' => null );
		}

		$diff = array();
		if ( ( $baseline['schema_version'] ?? null ) !== ( $current['schema_version'] ?? null ) ) {
			$diff['schema_version'] = array( 'before' => $baseline['schema_version'] ?? null, 'after' => $current['schema_version'] ?? null );
		}
		if ( wp_json_encode( $baseline['slots_signature'] ?? null ) !== wp_json_encode( $current['slots_signature'] ?? null ) ) {
			$diff['slots_signature'] = array( 'before' => $baseline['slots_signature'] ?? null, 'after' => $current['slots_signature'] ?? null );
		}
		if ( wp_json_encode( $baseline['rendered'] ?? null ) !== wp_json_encode( $current['rendered'] ?? null ) ) {
			$diff['rendered'] = array( 'before' => $baseline['rendered'] ?? null, 'after' => $current['rendered'] ?? null );
		}

		return array( 'status' => 'mismatch', 'diff' => $diff );
	}

	/** @return array<string, array{status: string, diff: array|null}> */
	public static function compare_all(): array {
		$report = array();
		foreach ( BlockLibrary::all() as $block_id => $block ) {
			$report[ $block_id ] = self::compare_snapshots( self::load_baseline( $block_id ), self::generate_snapshot( $block_id ) );
		}
		return $report;
	}
}
