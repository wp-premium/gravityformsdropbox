<?php
/*
Plugin Name: Gravity Forms Dropbox Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with Dropbox, enabling end users to upload files to Dropbox through Gravity Forms.
Version: 1.1.1
Author: rocketgenius
Author URI: http://www.rocketgenius.com
Text Domain: gravityformsdropbox
Domain Path: /languages

------------------------------------------------------------------------
Copyright 2012-2014 Rocketgenius Inc.

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
*/

define( 'GF_DROPBOX_VERSION', '1.1.1' );

add_action( 'gform_loaded', array( 'GF_Dropbox_Bootstrap', 'load' ), 5 );
add_action( 'admin_init', array( 'GF_Dropbox_Bootstrap', 'maybe_clear_ssl_option' ), 5 );

class GF_Dropbox_Bootstrap {

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
	
	public static function compatibility_test() {
		
		/* PHP must be version 5.3 or greater. */
		if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
			return false;
		}
		
		/* PHP must be 64-bit. */
		if ( PHP_INT_MAX !== 9223372036854775807 ) {
			return false;
		}
		
		/* WordPress must be able to run under SSL. */
		$ssl_support = get_option( 'gravityformsaddon_gravityformsdropbox_ssl', null );
		
		if ( is_null( $ssl_support ) ) {
			
			$ssl_test = wp_remote_get( admin_url( '/admin.php?page=gf_settings&subview=gravityformsdropbox', 'https' ), array(
				'timeout'   => 30,
				'sslverify' => false
			) );
			$ssl_test = ( is_wp_error( $ssl_test ) || ( ! is_wp_error( $ssl_test ) && $ssl_test['response']['code'] !== 200 ) ) ? '0' : '1';
			
			update_option( 'gravityformsaddon_gravityformsdropbox_ssl', $ssl_test );
			
			if ( ! $ssl_test ) {
				return false;
			}
			
		}
		
		if ( ! is_null( $ssl_support ) && $ssl_support == '0' ) {
			return false;
		}
		
		return true;
		
	}
	
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

function gf_dropbox(){
	return GFDropbox::get_instance();
}
