<?php

namespace WPGJBuilder\Admin;

use WPGJBuilder\SiteParts\DisplayConditions;
use WPGJBuilder\SiteParts\PartsPostType;

defined( 'ABSPATH' ) || exit;

/**
 * Экран "Части сайта" (раздел 4 спеки) — единственный НЕ скрытый пункт
 * меню плагина (родитель для "Настройки", см. SecurityPage). Вкладки
 * Шапки/Подвалы; "Мои блоки"/"Мои шаблоны" из спеки не реализованы в
 * этой фазе (нет соответствующей функциональности вообще, не только
 * экрана — честно не показываем вкладку, которая ничего бы не показала).
 *
 * Форма условий отображения в MVP этого экрана — ОДНО правило на часть
 * (простая пара scope+mode+target), без JS. Сам движок
 * (DisplayConditions) поддерживает произвольное число правил — это
 * ограничение конкретно UI, не архитектуры.
 */
class SitePartsPage {

	const SLUG = 'wpgjb-site-parts';

	public static function register_hooks() {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_action_wpgjb_new_part', array( self::class, 'handle_new_part' ) );
		add_action( 'admin_action_wpgjb_save_conditions', array( self::class, 'handle_save_conditions' ) );
	}

	public static function register_menu() {
		add_menu_page(
			__( 'Конструктор', 'wp-gj-builder' ),
			__( 'Конструктор', 'wp-gj-builder' ),
			'wpgjb_edit_pages',
			self::SLUG,
			array( self::class, 'render' ),
			'dashicons-layout',
			25
		);
		add_submenu_page(
			self::SLUG,
			__( 'Части сайта', 'wp-gj-builder' ),
			__( 'Части сайта', 'wp-gj-builder' ),
			'wpgjb_edit_pages',
			self::SLUG,
			array( self::class, 'render' )
		);
	}

	private static function current_tab(): string {
		$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'header'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return in_array( $tab, PartsPostType::PART_TYPES, true ) ? $tab : 'header';
	}

	public static function render() {
		if ( ! current_user_can( 'wpgjb_edit_pages' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		$tab   = self::current_tab();
		$tabs  = array(
			'header'  => __( 'Шапки', 'wp-gj-builder' ),
			'footer'  => __( 'Подвалы', 'wp-gj-builder' ),
			'sidebar' => __( 'Сайдбары', 'wp-gj-builder' ),
		);
		$parts = PartsPostType::list_by_type( $tab );

		echo '<div class="wrap"><h1>' . esc_html__( 'Части сайта', 'wp-gj-builder' ) . '</h1>';

		echo '<h2 class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			$class = $key === $tab ? 'nav-tab nav-tab-active' : 'nav-tab';
			printf(
				'<a class="%s" href="%s">%s</a>',
				esc_attr( $class ),
				esc_url( add_query_arg( array( 'page' => self::SLUG, 'tab' => $key ), admin_url( 'admin.php' ) ) ),
				esc_html( $label )
			);
		}
		echo '</h2>';

		if ( empty( $parts ) ) {
			printf(
				'<p>%s</p>',
				esc_html__( 'Здесь появятся ваши шапки/подвалы. Создайте первую ниже.', 'wp-gj-builder' )
			);
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Название', 'wp-gj-builder' ) . '</th><th>' . esc_html__( 'Статус', 'wp-gj-builder' ) . '</th><th>' . esc_html__( 'Условия показа', 'wp-gj-builder' ) . '</th><th></th></tr></thead><tbody>';
			foreach ( $parts as $post ) {
				self::render_row( $post, $tab );
			}
			echo '</tbody></table>';
		}

		self::render_new_part_form( $tab );

		echo '</div>';
	}

	private static function render_row( \WP_Post $post, string $tab ) {
		$conditions   = PartsPostType::get_conditions( $post->ID );
		$first_rule   = $conditions[0] ?? array( 'scope' => DisplayConditions::SCOPE_ENTIRE_SITE, 'mode' => 'include', 'target' => '' );
		$edit_url     = EditorPage::editor_url( $post->ID, $tab );

		echo '<tr><td><strong>' . esc_html( $post->post_title ) . '</strong></td>';
		echo '<td>' . esc_html( $post->post_status ) . '</td>';
		echo '<td>';
		self::render_conditions_form( $post->ID, $first_rule );
		echo '</td>';
		echo '<td><a class="button" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Редактировать в конструкторе', 'wp-gj-builder' ) . '</a></td></tr>';
	}

	private static function render_conditions_form( int $post_id, array $rule ) {
		$scopes = array(
			DisplayConditions::SCOPE_ENTIRE_SITE   => __( 'Весь сайт', 'wp-gj-builder' ),
			DisplayConditions::SCOPE_FRONT_PAGE    => __( 'Главная страница', 'wp-gj-builder' ),
			DisplayConditions::SCOPE_ALL_PAGES     => __( 'Все страницы', 'wp-gj-builder' ),
			DisplayConditions::SCOPE_SPECIFIC_PAGE => __( 'Конкретная страница (ID)', 'wp-gj-builder' ),
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=wpgjb_save_conditions' ) ); ?>" style="display:flex;gap:6px;align-items:center;">
			<?php wp_nonce_field( 'wpgjb_save_conditions_' . $post_id ); ?>
			<input type="hidden" name="post_id" value="<?php echo esc_attr( $post_id ); ?>">
			<select name="scope">
				<?php foreach ( $scopes as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $rule['scope'], $key ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
			<input type="number" name="target" placeholder="<?php esc_attr_e( 'ID страницы', 'wp-gj-builder' ); ?>" value="<?php echo esc_attr( is_numeric( $rule['target'] ?? '' ) ? $rule['target'] : '' ); ?>" style="width:100px;">
			<button type="submit" class="button button-small"><?php esc_html_e( 'Сохранить', 'wp-gj-builder' ); ?></button>
		</form>
		<?php
	}

	private static function render_new_part_form( string $tab ) {
		?>
		<h2><?php esc_html_e( 'Создать новую', 'wp-gj-builder' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?action=wpgjb_new_part' ) ); ?>">
			<?php wp_nonce_field( 'wpgjb_new_part' ); ?>
			<input type="hidden" name="part_type" value="<?php echo esc_attr( $tab ); ?>">
			<input type="text" name="title" placeholder="<?php esc_attr_e( 'Название', 'wp-gj-builder' ); ?>" required>
			<button type="submit" class="button button-primary"><?php esc_html_e( 'Создать в конструкторе', 'wp-gj-builder' ); ?></button>
		</form>
		<?php
	}

	public static function handle_new_part() {
		check_admin_referer( 'wpgjb_new_part' );
		if ( ! current_user_can( 'wpgjb_edit_pages' ) || ! current_user_can( 'publish_pages' ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		$type  = isset( $_POST['part_type'] ) ? sanitize_key( wp_unslash( $_POST['part_type'] ) ) : 'header';
		$type  = in_array( $type, PartsPostType::PART_TYPES, true ) ? $type : 'header';
		$title = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : __( 'Новая часть', 'wp-gj-builder' );

		$post_id = PartsPostType::create( $type, $title, get_current_user_id() );

		wp_safe_redirect( EditorPage::editor_url( $post_id, $type ) );
		exit;
	}

	public static function handle_save_conditions() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		check_admin_referer( 'wpgjb_save_conditions_' . $post_id );

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Недостаточно прав.', 'wp-gj-builder' ) );
		}

		$scope  = isset( $_POST['scope'] ) ? sanitize_key( wp_unslash( $_POST['scope'] ) ) : DisplayConditions::SCOPE_ENTIRE_SITE;
		$target = isset( $_POST['target'] ) && '' !== $_POST['target'] ? absint( $_POST['target'] ) : null;

		PartsPostType::set_conditions(
			$post_id,
			array(
				array( 'scope' => $scope, 'mode' => 'include', 'target' => $target ),
			)
		);

		$part_type = PartsPostType::get_part_type( $post_id );
		wp_safe_redirect(
			add_query_arg(
				array( 'page' => self::SLUG, 'tab' => $part_type ?: 'header', 'wpgjb_saved' => '1' ),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
