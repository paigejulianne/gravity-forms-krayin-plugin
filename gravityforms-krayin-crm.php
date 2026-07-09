<?php
/**
 * Plugin Name: Gravity Forms Krayin CRM Add-On
 * Plugin URI: https://crm.devinsight.site/
 * Description: Sends Gravity Forms submissions to Krayin CRM as Leads or Contacts via Krayin's REST API.
 * Version: 1.0.0
 * Author: Devinsight
 * License: GPL-2.0+
 * Text Domain: gravityforms-krayin-crm
 * Domain Path: /languages
 *
 * ------------------------------------------------------------------------
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 */

defined( 'ABSPATH' ) || die();

define( 'GF_KRAYIN_CRM_VERSION', '1.0.0' );

add_action( 'gform_loaded', array( 'GF_Krayin_CRM_Bootstrap', 'load' ), 5 );

/**
 * Handles the loading of the Krayin CRM Add-On and registers it with the Gravity Forms Add-On Framework.
 */
class GF_Krayin_CRM_Bootstrap {

	/**
	 * If the Feed Add-On Framework exists, load and register the Krayin CRM Add-On.
	 */
	public static function load() {
		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		require_once __DIR__ . '/includes/class-gf-krayin-crm.php';

		GFAddOn::register( 'GF_Krayin_CRM' );
	}
}

/**
 * @return GF_Krayin_CRM
 */
function gf_krayin_crm() {
	return GF_Krayin_CRM::get_instance();
}
