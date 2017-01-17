<?php
/**
Plugin Name: Gravity Forms Dropbox Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with Dropbox, enabling end users to upload files to Dropbox through Gravity Forms.
Version: 1.3
Author: rocketgenius
Author URI: http://www.rocketgenius.com
Text Domain: gravityformsdropbox
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2012-2016 Rocketgenius Inc.

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License
along with this program; if not, write to the Free Software
Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA 02111-1307 USA
**/

define( 'GF_DROPBOX_VERSION', '1.3' );

// If Gravity Forms is loaded, bootstrap the Dropbox Add-On.
add_action( 'gform_loaded', array( 'GF_Dropbox_Bootstrap', 'load' ), 5 );

// If Dropbox SSL reset query argument is set, reset the Dropbox SSL setting.
add_action( 'admin_init', array( 'GF_Dropbox_Bootstrap', 'maybe_clear_ssl_option' ), 5 );

/**
 * Class GF_Dropbox_Bootstrap
 *
 * Handles the loading of the Dropbox Add-On and registers with the Add-On Framework.
 */
class GF_Dropbox_Bootstrap {

	/**
	 * If the Feed Add-On Framework exists, Dropbox Add-On is loaded.
	 *
	 * @access public
	 * @static
	 */
	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}

		if ( self::compatibility_test() ) {
			require_once( 'class-gf-dropbox.php' );
		} else {
			require_once( 'class-gf-dropbox-incompatible.php' );
		}

		GFAddOn::register( 'GFDropbox' );

	}

	/**
	 * Determine if current server environment matches
	 * requirements to run the Dropbox Add-On.
	 *
	 * @access public
	 * @static
	 * @return bool
	 */
	public static function compatibility_test() {

		/* PHP must be version 5.3 or greater. */
		if ( version_compare( PHP_VERSION, '5.3.4', '<' ) ) {
			return false;
		}

		/* PHP must be 64-bit. */
		if ( PHP_INT_MAX !== 9223372036854775807 ) {
			return false;
		}

		/* openssl_random_pseudo_bytes or mcrypt_create_iv must exist. */
		if ( ! function_exists( 'openssl_random_pseudo_bytes' ) && ! function_exists( 'mcrypt_create_iv' ) ) {
			return false;
		}

		/* WordPress must be able to run under SSL. */
		$ssl_support = get_option( 'gravityformsaddon_gravityformsdropbox_ssl', null );

		if ( is_null( $ssl_support ) ) {

			$ssl_test = wp_remote_get( admin_url( '/admin.php?page=gf_settings&subview=gravityformsdropbox', 'https' ), array(
				'timeout'   => 30,
				'sslverify' => false,
			) );

			$valid_responses = array( 200, 404 );
			$ssl_support     = ( is_wp_error( $ssl_test ) || ( ! is_wp_error( $ssl_test ) && ! in_array( $ssl_test['response']['code'], $valid_responses ) ) ) ? '0' : '1';
			/**
			 * Overrides SSL compatibility checks.
			 *
			 * Useful if a false negative is reported for an installed SSL certificate.
			 *
			 * @since 1.2
			 *
			 * @param bool $ssl_support If SSL is supported. If the gravityformsaddon_gravityformsdropbox_ssl option is present, returns true. Otherwise, result of checks.
			 */
			$ssl_support     = gf_apply_filters( array( 'gform_dropbox_ssl_compatibility' ), $ssl_support );

			update_option( 'gravityformsaddon_gravityformsdropbox_ssl', $ssl_support );

			if ( ! $ssl_support ) {
				return false;
			}

		}

		if ( ! is_null( $ssl_support ) && ! filter_var( $ssl_support, FILTER_VALIDATE_BOOLEAN ) ) {
			return false;
		}

		return true;

	}

	/**
	 * Clear Dropbox SSL option.
	 *
	 * @access public
	 * @static
	 */
	public static function maybe_clear_ssl_option() {

		/* If the SSL reset query argument is not present, exit. */
		if ( ! isset( $_GET['gfdropboxsslreset'] ) ) {
			return;
		}

		/* Delete option. */
		delete_option( 'gravityformsaddon_gravityformsdropbox_ssl' );

		/* Redirect to URL without the query argument. */
		wp_redirect( remove_query_arg( 'gfdropboxsslreset' ) );

	}

}

/**
 * Returns an instance of the GFDropbox class
 *
 * @see    GFDropbox::get_instance()
 * @return object GFDropbox
 */
function gf_dropbox(){
	return GFDropbox::get_instance();
}
