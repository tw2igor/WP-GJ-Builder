<?php

namespace WPGJBuilder\Blocks;

use WPGJBuilder\Core\Diagnostics;

defined( 'ABSPATH' ) || exit;

/**
 * Раздел 12 спеки: "Отказоустойчивый рендер. Один повреждённый/неизвестный
 * блок не роняет страницу: пропускается с записью в журнал диагностики,
 * остальная страница публикуется." Это НЕ про целый документ (тот случай
 * уже покрыт DocumentRepository::STATUS_FROZEN на уровне невалидного JSON/
 * неудачной миграции) — а про ОДИН компонент внутри иначе валидного
 * project_data: структурно испорченный узел (не массив там, где ожидался)
 * или ссылка на `data-wpb-block`, которого больше нет в библиотеке (блок
 * удалили/переименовали при обновлении платформы). Вызывается и при выдаче
 * документа редактору (DocumentRepository::get_for_edit()), и перед
 * публикацией (Publisher::publish()) — в обоих случаях лучше тихо заменить
 * проблемный узел безопасной заглушкой, чем уронить всю страницу/сессию
 * редактирования из-за одного узла.
 */
class BlockFaultTolerance {

	const CHANNEL = 'block-fault-tolerance';

	/**
	 * @return array{0: array, 1: array} [обработанный project_data, отчёт о карантине]
	 */
	public static function quarantine( array $project_data ): array {
		$report = array();

		if ( ! empty( $project_data['pages'] ) && is_array( $project_data['pages'] ) ) {
			foreach ( $project_data['pages'] as &$page ) {
				if ( empty( $page['frames'] ) || ! is_array( $page['frames'] ) ) {
					continue;
				}
				foreach ( $page['frames'] as &$frame ) {
					if ( ! empty( $frame['component'] ) && is_array( $frame['component'] ) ) {
						self::quarantine_children( $frame['component'], $report );
					}
				}
				unset( $frame );
			}
			unset( $page );
		}

		foreach ( $report as $entry ) {
			Diagnostics::log( self::CHANNEL, $entry['message'], $entry['context'] );
		}

		return array( $project_data, $report );
	}

	private static function quarantine_children( array &$component, array &$report ) {
		if ( empty( $component['components'] ) || ! is_array( $component['components'] ) ) {
			return;
		}

		foreach ( $component['components'] as $index => &$child ) {
			if ( ! is_array( $child ) ) {
				$report[] = array(
					'message' => 'Удалён структурно повреждённый узел (ожидался массив).',
					'context' => array( 'index' => $index ),
				);
				unset( $component['components'][ $index ] );
				continue;
			}

			$block_id = $child['attributes']['data-wpb-block'] ?? null;
			if ( null !== $block_id && ! BlockLibrary::get( $block_id ) ) {
				$report[] = array(
					'message' => sprintf( 'Блок "%s" отсутствует в библиотеке — заменён заглушкой, публикация продолжена.', $block_id ),
					'context' => array( 'block_id' => $block_id ),
				);
				$child = self::placeholder( $block_id );
				continue;
			}

			self::quarantine_children( $child, $report );
		}
		unset( $child );

		$component['components'] = array_values( $component['components'] );
	}

	/** Невидимая заглушка вместо утраченного/испорченного блока — не показывается посетителю, но не ломает дерево компонентов. */
	private static function placeholder( ?string $block_id ): array {
		return array(
			'type'       => 'text',
			'attributes' => array(
				'data-wpb-frozen-block' => $block_id ?: 'unknown',
				'style'                 => 'display:none',
			),
			'components' => array(),
		);
	}
}
