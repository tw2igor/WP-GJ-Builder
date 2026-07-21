<?php

namespace WPGJBuilder\Core;

use WPGJBuilder\Storage\DocumentsTable;
use WPGJBuilder\Storage\RetentionPolicy;

defined( 'ABSPATH' ) || exit;

class Activation {

	const CAPABILITIES = array(
		'wpgjb_edit_pages'      => array( 'administrator', 'editor' ),
		'wpgjb_use_zero_mode'   => array( 'administrator' ),
		'wpgjb_insert_raw_code' => array( 'administrator' ),
		'wpgjb_manage_settings' => array( 'administrator' ),
	);

	public static function activate() {
		DocumentsTable::maybe_upgrade();
		self::register_capabilities();
		RetentionPolicy::schedule();
	}

	public static function deactivate() {
		// Деактивация не удаляет ни таблицу, ни опции, ни капабилити —
		// это делает только uninstall.php, и только по явному согласию
		// пользователя (раздел 12 спеки). Cron планового прунинга ревизий —
		// исключение: это не данные, а фоновая задача, оставлять её висеть
		// после деактивации бессмысленно и просто засоряет wp_cron.
		RetentionPolicy::unschedule();
	}

	private static function register_capabilities() {
		foreach ( self::CAPABILITIES as $cap => $roles ) {
			foreach ( $roles as $role_name ) {
				$role = get_role( $role_name );
				if ( $role && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				}
			}
		}
	}
}
