<?php

namespace WPGJBuilder\Admin;

use WPGJBuilder\AI\TwcAiClient;
use WPGJBuilder\Core\Activation;
use WPGJBuilder\Core\Diagnostics;
use WPGJBuilder\Storage\Cleanup;
use WPGJBuilder\Storage\DocumentsTable;
use WPGJBuilder\Storage\RetentionPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * "Настройки" → вкладка "Права и безопасность" (раздел 4.2 спеки).
 * Остальные вкладки (Тема и стили / Производительность / Диагностика
 * общего вида) — вне объёма этой фазы (пользователь расставил приоритет:
 * Части сайта + безопасность сначала); эта страница целиком посвящена
 * матрице ролей→капабилити и аудит-логу произвольного кода.
 */
class SecurityPage {

	const SLUG = 'wpgjb-settings';

	/**
	 * Метод, не const: значения проходят через __() для перевода интерфейса
	 * (раздел 13) — константы класса не могут содержать вызовы функций.
	 *
	 * @return array<string,string>
	 */
	private static function cap_labels(): array {
		return array(
			'wpgjb_edit_pages'      => __( 'Доступ к конструктору', 'wp-gj-builder' ),
			'wpgjb_use_zero_mode'   => __( 'Zero-режим', 'wp-gj-builder' ),
			'wpgjb_insert_raw_code' => __( 'Вставка произвольного кода', 'wp-gj-builder' ),
			'wpgjb_manage_settings' => __( 'Управление настройками конструктора', 'wp-gj-builder' ),
		);
	}

	public static function register_hooks() {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_action_wpgjb_save_capabilities', array( self::class, 'handle_save' ) );
		add_action( 'admin_action_wpgjb_save_retention', array( self::class, 'handle_save_retention' ) );
		add_action( 'admin_action_wpgjb_full_cleanup', array( self::class, 'handle_full_cleanup' ) );
		add_action( 'admin_action_wpgjb_save_ai_settings', array( self::class, 'handle_save_ai_settings' ) );
	}

	public static function register_menu() {
		add_submenu_page(
			SitePartsPage::SLUG,
			__( 'Настройки', 'wp-gj-builder' ),
			__( 'Настройки', 'wp-gj-builder' ),
			'wpgjb_manage_settings',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	private static function editable_roles(): array {
		$roles = wp_roles()->roles;
		// Администратор всегда имеет всё — управлять им через чекбоксы бессмысленно
		// и опасно (риск случайно отобрать у себя доступ).
		unset( $roles['administrator'] );
		return $roles;
	}

	public static function render() {
		if ( ! current_user_can( 'wpgjb_manage_settings' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		echo '<div class="wrap"><h1>' . esc_html__( 'Настройки конструктора', 'wp-gj-builder' ) . '</h1>';

		if ( isset( $_GET['wpgjb_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Права сохранены.', 'wp-gj-builder' ) . '</p></div>';
		}
		if ( isset( $_GET['wpgjb_retention_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Политика хранения ревизий сохранена.', 'wp-gj-builder' ) . '</p></div>';
		}
		if ( isset( $_GET['wpgjb_cleaned'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Полная очистка данных конструктора выполнена.', 'wp-gj-builder' ) . '</p></div>';
		}
		if ( isset( $_GET['wpgjb_ai_saved'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Настройки AI сохранены.', 'wp-gj-builder' ) . '</p></div>';
		}

		echo '<h2>' . esc_html__( 'Права и безопасность', 'wp-gj-builder' ) . '</h2>';
		self::render_matrix();

		echo '<h2>' . esc_html__( 'Аудит-лог вставки произвольного кода', 'wp-gj-builder' ) . '</h2>';
		self::render_audit_log();

		echo '<h2>' . esc_html__( 'Хранение и очистка', 'wp-gj-builder' ) . '</h2>';
		self::render_retention_section();

		echo '<h2>' . esc_html__( 'Интеграция с AI (генерация страниц)', 'wp-gj-builder' ) . '</h2>';
		self::render_ai_settings_section();

		echo '</div>';
	}

	private static function render_matrix() {
		$roles = self::editable_roles();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=wpgjb_save_capabilities' ) ); ?>">
			<?php wp_nonce_field( 'wpgjb_save_capabilities' ); ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Право', 'wp-gj-builder' ); ?></th>
						<?php foreach ( $roles as $role_key => $role ) : ?>
							<th><?php echo esc_html( translate_user_role( $role['name'] ) ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( array_keys( Activation::CAPABILITIES ) as $cap ) : ?>
						<tr>
							<td><strong><?php echo esc_html( self::cap_labels()[ $cap ] ?? $cap ); ?></strong><br><code><?php echo esc_html( $cap ); ?></code></td>
							<?php foreach ( $roles as $role_key => $role_data ) :
								$role = get_role( $role_key );
								$checked = $role && $role->has_cap( $cap );
								?>
								<td>
									<input type="checkbox" name="caps[<?php echo esc_attr( $role_key ); ?>][<?php echo esc_attr( $cap ); ?>]" value="1" <?php checked( $checked ); ?>>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Администратор всегда обладает всеми правами конструктора и не показан здесь — управлять его правами через эту таблицу небезопасно.', 'wp-gj-builder' ); ?></p>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить', 'wp-gj-builder' ); ?></button></p>
		</form>
		<?php
	}

	private static function render_audit_log() {
		$entries = array_filter(
			Diagnostics::recent( 100 ),
			fn( $entry ) => 'raw-code-audit' === ( $entry['channel'] ?? '' )
		);

		if ( empty( $entries ) ) {
			echo '<p>' . esc_html__( 'Пока нет записей — блок «Вставка кода» ещё не использовался.', 'wp-gj-builder' ) . '</p>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Время', 'wp-gj-builder' ) . '</th><th>' . esc_html__( 'Пользователь', 'wp-gj-builder' ) . '</th><th>' . esc_html__( 'Хэш сниппета', 'wp-gj-builder' ) . '</th><th>' . esc_html__( 'Длина', 'wp-gj-builder' ) . '</th></tr></thead><tbody>';
		foreach ( array_reverse( $entries ) as $entry ) {
			$context = $entry['context'] ?? array();
			$user    = get_userdata( $context['user_id'] ?? 0 );
			echo '<tr>';
			echo '<td>' . esc_html( $entry['time'] ?? '' ) . '</td>';
			echo '<td>' . esc_html( $user ? $user->user_login : ( $context['user_id'] ?? '—' ) ) . '</td>';
			echo '<td><code>' . esc_html( $context['snippet_hash'] ?? '' ) . '</code></td>';
			echo '<td>' . esc_html( $context['snippet_length'] ?? '' ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Раздел 11/12 спеки: настраиваемая политика хранения ревизий (сколько
	 * старых публикаций документа хранить сверх текущей) + два отдельных
	 * механизма очистки — галочка "удалить данные при удалении плагина"
	 * (пассивная, срабатывает в uninstall.php) и явная кнопка "Полная
	 * очистка сейчас" (активная, доступна пока плагин работает — например
	 * для запроса на удаление персональных данных без деактивации плагина).
	 */
	private static function render_retention_section() {
		$keep           = RetentionPolicy::keep_count();
		$delete_on_uninstall = (bool) get_option( Cleanup::OPTION_DELETE_ON_UNINSTALL, false );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=wpgjb_save_retention' ) ); ?>">
			<?php wp_nonce_field( 'wpgjb_save_retention' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wpgjb_keep_count"><?php esc_html_e( 'Хранить публикаций на документ', 'wp-gj-builder' ); ?></label></th>
					<td>
						<input type="number" min="1" id="wpgjb_keep_count" name="keep_count" value="<?php echo esc_attr( $keep ); ?>" style="width:80px;">
						<p class="description"><?php esc_html_e( 'Текущая опубликованная версия хранится всегда сверх этого числа — лимит применяется только к истории. Плановая очистка запускается ежедневно (wp-cron).', 'wp-gj-builder' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'При удалении плагина', 'wp-gj-builder' ); ?></th>
					<td>
						<label><input type="checkbox" name="delete_on_uninstall" value="1" <?php checked( $delete_on_uninstall ); ?>> <?php esc_html_e( 'Удалить все данные конструктора (по умолчанию выключено)', 'wp-gj-builder' ); ?></label>
						<p class="description"><?php esc_html_e( 'Готовый HTML уже опубликованных страниц не зависит от плагина и останется читаемым в любом случае — эта опция удаляет только служебные данные конструктора (таблицу документов, части сайта, настройки).', 'wp-gj-builder' ); ?></p>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить', 'wp-gj-builder' ); ?></button></p>
		</form>

		<h3><?php esc_html_e( 'Полная очистка сейчас', 'wp-gj-builder' ); ?></h3>
		<p class="description"><?php esc_html_e( 'Немедленно удаляет ВСЕ данные конструктора (документы, части сайта, настройки) без удаления самого плагина. Необратимо.', 'wp-gj-builder' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=wpgjb_full_cleanup' ) ); ?>" onsubmit="return window.confirm('<?php echo esc_js( __( 'Необратимо удалить ВСЕ данные конструктора? Это действие нельзя отменить.', 'wp-gj-builder' ) ); ?>');">
			<?php wp_nonce_field( 'wpgjb_full_cleanup' ); ?>
			<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Полная очистка сейчас', 'wp-gj-builder' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Учётные данные Timeweb Cloud AI Agent API (`docs/TWC AI API.md`) —
	 * раздел 9 спеки, AI-фаза. Bearer-токен намеренно не выводится обратно
	 * в поле значением (только плейсхолдер "уже задан") — форма не должна
	 * раскрывать уже сохранённый секрет через простой просмотр исходного
	 * кода страницы; пустое поле при сохранении = "оставить как есть", не
	 * "стереть".
	 */
	private static function render_ai_settings_section() {
		$agent_access_id = TwcAiClient::agent_access_id();
		$has_token        = '' !== TwcAiClient::bearer_token();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=wpgjb_save_ai_settings' ) ); ?>">
			<?php wp_nonce_field( 'wpgjb_save_ai_settings' ); ?>
			<table class="form-table">
				<tr>
					<th scope="row"><label for="wpgjb_ai_agent_access_id"><?php esc_html_e( 'Agent Access ID', 'wp-gj-builder' ); ?></label></th>
					<td>
						<input type="text" id="wpgjb_ai_agent_access_id" name="agent_access_id" value="<?php echo esc_attr( $agent_access_id ); ?>" class="regular-text">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="wpgjb_ai_bearer_token"><?php esc_html_e( 'Bearer Token', 'wp-gj-builder' ); ?></label></th>
					<td>
						<input type="password" id="wpgjb_ai_bearer_token" name="bearer_token" value="" class="regular-text" autocomplete="off" placeholder="<?php echo esc_attr( $has_token ? __( '••••••••  (уже задан — оставьте пустым, чтобы не менять)', 'wp-gj-builder' ) : '' ); ?>">
						<p class="description"><?php esc_html_e( 'Пустое поле при сохранении оставит уже сохранённый токен без изменений.', 'wp-gj-builder' ); ?></p>
					</td>
				</tr>
			</table>
			<p><button type="submit" class="button button-primary"><?php esc_html_e( 'Сохранить', 'wp-gj-builder' ); ?></button></p>
		</form>
		<?php
	}

	public static function handle_save_ai_settings() {
		check_admin_referer( 'wpgjb_save_ai_settings' );

		if ( ! current_user_can( 'wpgjb_manage_settings' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		if ( isset( $_POST['agent_access_id'] ) ) {
			update_option( TwcAiClient::OPTION_AGENT_ACCESS_ID, sanitize_text_field( wp_unslash( $_POST['agent_access_id'] ) ) );
		}
		if ( ! empty( $_POST['bearer_token'] ) ) {
			update_option( TwcAiClient::OPTION_BEARER_TOKEN, sanitize_text_field( wp_unslash( $_POST['bearer_token'] ) ) );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'wpgjb_ai_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_save_retention() {
		check_admin_referer( 'wpgjb_save_retention' );

		if ( ! current_user_can( 'wpgjb_manage_settings' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		$keep = isset( $_POST['keep_count'] ) ? absint( $_POST['keep_count'] ) : RetentionPolicy::DEFAULT_KEEP_COUNT;
		RetentionPolicy::set_keep_count( $keep );

		update_option( Cleanup::OPTION_DELETE_ON_UNINSTALL, ! empty( $_POST['delete_on_uninstall'] ), false );

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'wpgjb_retention_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_full_cleanup() {
		check_admin_referer( 'wpgjb_full_cleanup' );

		if ( ! current_user_can( 'wpgjb_manage_settings' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		Cleanup::full_cleanup();

		// В отличие от uninstall.php (плагин целиком удаляется следом),
		// здесь плагин остаётся АКТИВНЫМ — таблицу нужно тут же пересоздать
		// пустой, иначе следующий же вызов редактора получит ошибку БД.
		DocumentsTable::maybe_upgrade();

		wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG, 'wpgjb_cleaned' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function handle_save() {
		check_admin_referer( 'wpgjb_save_capabilities' );

		if ( ! current_user_can( 'wpgjb_manage_settings' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		$submitted = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash

		foreach ( self::editable_roles() as $role_key => $role_data ) {
			$role = get_role( $role_key );
			if ( ! $role ) {
				continue;
			}
			foreach ( array_keys( Activation::CAPABILITIES ) as $cap ) {
				$should_have = ! empty( $submitted[ $role_key ][ $cap ] );
				if ( $should_have && ! $role->has_cap( $cap ) ) {
					$role->add_cap( $cap );
				} elseif ( ! $should_have && $role->has_cap( $cap ) ) {
					$role->remove_cap( $cap );
				}
			}
		}

		wp_safe_redirect( add_query_arg( array( 'page' => SecurityPage::SLUG, 'wpgjb_saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}
}
