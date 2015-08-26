<?php
/*
Plugin Name: Gravity Forms Dropbox Add-On
Plugin URI: http://www.gravityforms.com
Description: Integrates Gravity Forms with Dropbox, enabling end users to upload files to Dropbox through Gravity Forms.
Version: 1.0.1
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

define( 'GF_DROPBOX_VERSION', '1.0.1' );

add_action( 'gform_loaded', array( 'GF_Dropbox_Bootstrap', 'load' ), 5 );

class GF_Dropbox_Bootstrap {

	public static function load() {
		
		if ( ! method_exists( 'GFForms', 'include_feed_addon_framework' ) ) {
			return;
		}
		
		if ( version_compare( PHP_VERSION, '5.3.0', '<' ) ) {
			require_once( 'class-gf-dropbox-incompatible.php' );
		} else {
			require_once( 'class-gf-dropbox.php' );
		}
		
		GFAddOn::register( 'GFDropbox' );
		
	}
	
}

function gf_dropbox(){
	return GFDropbox::get_instance();
}
