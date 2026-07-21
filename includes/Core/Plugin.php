<?php

namespace WPGJBuilder\Core;

use WPGJBuilder\Rest\DevSpikeController;
use WPGJBuilder\Rest\DevSpike2Controller;
use WPGJBuilder\Rest\DevSpike4Controller;
use WPGJBuilder\Rest\DevPhase1Controller;
use WPGJBuilder\Rest\DevPhase2Controller;
use WPGJBuilder\Rest\DevChecklistController;
use WPGJBuilder\Rest\DevPhase5Controller;
use WPGJBuilder\Rest\DevPhase6Controller;
use WPGJBuilder\Rest\DevSnapshotsController;
use WPGJBuilder\Rest\DevPhase7Controller;
use WPGJBuilder\Rest\DevSecondTierController;
use WPGJBuilder\Rest\DocumentsController;
use WPGJBuilder\Rest\PublishController;
use WPGJBuilder\Rest\ThemeStylesController;
use WPGJBuilder\Rest\BlocksCatalogController;
use WPGJBuilder\Rest\AiController;
use WPGJBuilder\Rest\SitePartsController;
use WPGJBuilder\Render\FrontendRenderer;
use WPGJBuilder\Render\PageTemplates;
use WPGJBuilder\Admin\EditorPage;
use WPGJBuilder\Admin\SitePartsPage;
use WPGJBuilder\Admin\SecurityPage;
use WPGJBuilder\SiteParts\PartsPostType;
use WPGJBuilder\SiteParts\ClassicThemeInjector;
use WPGJBuilder\SiteParts\BlockThemeInjector;
use WPGJBuilder\SiteParts\CacheCascade;
use WPGJBuilder\Storage\RetentionPolicy;

defined( 'ABSPATH' ) || exit;

/**
 * Точка входа плагина после того как все плагины загружены.
 * Регистрирует i18n, реальные REST-маршруты плагина (по мере реализации
 * фаз плана) и дев-only маршруты для ручной проверки спайков (гейт WP_DEBUG,
 * удаляются перед Phase 7).
 */
class Plugin {

	public static function boot() {
		load_plugin_textdomain( 'wp-gj-builder', false, dirname( plugin_basename( WPGJB_PLUGIN_FILE ) ) . '/languages' );

		add_action( 'init', array( PartsPostType::class, 'register' ) );

		add_action( 'rest_api_init', array( DocumentsController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( PublishController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( ThemeStylesController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( BlocksCatalogController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( AiController::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( SitePartsController::class, 'register_routes' ) );
		FrontendRenderer::register_hooks();
		PageTemplates::register_hooks();
		EditorPage::register_hooks();
		SitePartsPage::register_hooks();
		SecurityPage::register_hooks();
		ClassicThemeInjector::register_hooks();
		BlockThemeInjector::register_hooks();
		CacheCascade::register_hooks();
		RetentionPolicy::register_hooks();

		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			add_action( 'rest_api_init', array( DevSpikeController::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevSpike2Controller::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevSpike4Controller::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevPhase1Controller::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevPhase2Controller::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevChecklistController::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevPhase5Controller::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevPhase6Controller::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevSnapshotsController::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevPhase7Controller::class, 'register_routes' ) );
			add_action( 'rest_api_init', array( DevSecondTierController::class, 'register_routes' ) );
		}
	}
}
