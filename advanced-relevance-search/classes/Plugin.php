<?php
/**
 * Main Plugin Class for Advanced Relevance Search
 */

namespace ARS;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Plugin {

	private static $instance = null;

	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function __construct() {
		$this->load_classes();
		$this->init();
	}

	private function load_classes() {
		require_once ARS_PATH . 'classes/Core/Helpers.php';
		require_once ARS_PATH . 'classes/Core/Settings.php';
		require_once ARS_PATH . 'classes/Core/Database.php';

		require_once ARS_PATH . 'classes/Search/Engine.php';
		require_once ARS_PATH . 'classes/Search/Indexer.php';
		require_once ARS_PATH . 'classes/Search/QueryInterceptor.php';

		require_once ARS_PATH . 'classes/Admin/Menu.php';
		require_once ARS_PATH . 'classes/Admin/MetaBox.php';
		require_once ARS_PATH . 'classes/Admin/AjaxHandler.php';

		require_once ARS_PATH . 'classes/Frontend/UI.php';

		require_once ARS_PATH . 'classes/Hooks.php';
	}

	private function init() {
		// Register all hooks
		Hooks::register_hooks();

		// Admin area
		if ( is_admin() ) {
			Admin\Menu::register_menu();
			Admin\MetaBox::register_metabox();
			Admin\AjaxHandler::register_ajax_handlers();
		} else {
			// Frontend area
			Search\QueryInterceptor::register_hooks();
			Frontend\UI::register_frontend();
		}
	}
}
