<?php
/**
 * Спайк 3 (план Phase 0): доказать, что (а) миграционная цепочка
 * project_json применяется корректно по шагам, и (б) сбой миграции
 * или повреждённый JSON "замораживает" документ вместо падения,
 * не трогая post_content опубликованной страницы. Не является
 * автоматизированным юнит-тестом (в среде разработки нет PHPUnit) —
 * гоняется вручную через дев-only REST-маршрут DevSpikeController.
 * Удалить перед Phase 7 (приёмка MVP), см. план: "Критические файлы".
 */

namespace WPGJBuilder\Tests\Spikes;

use WPGJBuilder\Storage\DocumentRepository;
use WPGJBuilder\Storage\Migrations\Migration;
use WPGJBuilder\Storage\MigrationRunner;

defined( 'ABSPATH' ) || exit;

class FakeMigration_1_to_2 implements Migration {
	public function from_version(): int {
		return 1;
	}

	public function migrate( array $project_data ): array {
		if ( ! isset( $project_data['blocks'] ) ) {
			throw new \RuntimeException( 'ожидалось поле "blocks" в project_data v1' );
		}
		// Демонстрационное переименование поля v1 -> v2, как реальная миграция будет делать.
		$project_data['sections'] = $project_data['blocks'];
		unset( $project_data['blocks'] );
		$project_data['schema_version'] = 2;
		return $project_data;
	}
}

class Spike3Runner {

	public static function run() {
		$results = array();

		$results['migration_chain'] = self::check_migration_chain();
		$results['migration_bad_input'] = self::check_migration_bad_input();
		$results['freeze_on_corrupt_json'] = self::check_freeze_on_corrupt_json();

		return $results;
	}

	private static function check_migration_chain() {
		$runner = new MigrationRunner();
		$runner->register( new FakeMigration_1_to_2() );

		$input  = array( 'schema_version' => 1, 'blocks' => array( array( 'type' => 'hero' ) ) );
		$result = $runner->migrate( $input, 1, 2 );

		$ok = $result['ok']
			&& isset( $result['data']['sections'] )
			&& ! isset( $result['data']['blocks'] )
			&& 2 === $result['data']['schema_version'];

		return array(
			'pass'   => $ok,
			'detail' => $ok ? 'v1 -> v2 миграция переименовала blocks -> sections корректно' : 'миграция вернула неожиданный результат',
			'result' => $result,
		);
	}

	private static function check_migration_bad_input() {
		$runner = new MigrationRunner();
		$runner->register( new FakeMigration_1_to_2() );

		// Документ v1 без поля "blocks" — миграция обязана упасть контролируемо.
		$input  = array( 'schema_version' => 1 );
		$result = $runner->migrate( $input, 1, 2 );

		$ok = ! $result['ok'] && null === $result['data'] && ! empty( $result['error'] );

		return array(
			'pass'   => $ok,
			'detail' => $ok ? 'некорректный вход контролируемо провалил миграцию (ok=false), без исключения наружу' : 'миграция должна была вернуть ok=false',
			'result' => $result,
		);
	}

	private static function check_freeze_on_corrupt_json() {
		global $wpdb;

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => 'WPGJB Spike3 test page',
				'post_content' => '<p>Последний валидный опубликованный HTML — не должен измениться.</p>',
			)
		);

		$table = \WPGJBuilder\Storage\DocumentsTable::table_name();
		$wpdb->insert(
			$table,
			array(
				'post_id'        => $post_id,
				'type'           => 'page',
				'revision_type'  => 'draft',
				'is_current'     => 1,
				'project_json'   => '{ this is not valid json',
				'schema_version' => 1,
				'doc_version'    => 1,
				'updated_at'     => current_time( 'mysql', true ),
				'updated_by'     => get_current_user_id(),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d' )
		);

		$runner     = new MigrationRunner();
		$repository = new DocumentRepository( $runner );
		$edit       = $repository->get_for_edit( $post_id, 'page' );

		$post_after = get_post( $post_id );

		$ok = DocumentRepository::STATUS_FROZEN === $edit['status']
			&& null === $edit['project_data']
			&& ! empty( $edit['error'] )
			&& $post_after->post_content === '<p>Последний валидный опубликованный HTML — не должен измениться.</p>';

		wp_delete_post( $post_id, true );
		$wpdb->delete( $table, array( 'post_id' => $post_id ) );

		return array(
			'pass'   => $ok,
			'detail' => $ok
				? 'повреждённый project_json дал status=frozen без исключения; post_content опубликованной страницы не тронут'
				: 'ожидалось status=frozen и нетронутый post_content',
			'result' => $edit,
		);
	}
}
