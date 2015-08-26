<?php

GFForms::include_feed_addon_framework();

class GFDropbox extends GFFeedAddOn {

	protected $_version = GF_DROPBOX_VERSION;
	protected $_min_gravityforms_version = '1.9.12.17';
	protected $_slug = 'gravityformsdropbox';
	protected $_path = 'gravityformsdropbox/dropbox.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'Gravity Forms Dropbox Add-On';
	protected $_short_title = 'Dropbox';
	private static $_instance = null;

	/* Permissions */
	protected $_capabilities_settings_page = 'gravityforms_dropbox';
	protected $_capabilities_form_settings = 'gravityforms_dropbox';
	protected $_capabilities_uninstall = 'gravityforms_dropbox_uninstall';
	protected $_enable_rg_autoupgrade = true;

	/* Members plugin integration */
	protected $_capabilities = array( 'gravityforms_dropbox', 'gravityforms_dropbox_uninstall' );

	/**
	 * Get instance of this class.
	 * 
	 * @access public
	 * @static
	 * @return $_instance
	 */
	public static function get_instance() {
		
		if ( self::$_instance == null ) {
			self::$_instance = new self;
		}

		return self::$_instance;
		
	}
	
	/**
	 * Checks whether the current Add-On has a settings page.
	 * 
	 * @access public
	 * @return bool true
	 */
	public function has_plugin_settings_page() {
		return true;
	}
	
	/**
	 * Setup plugin settings page.
	 * 
	 * @access public
	 * @return void
	 */
	public function plugin_settings_page() {
		
		$icon = $this->plugin_settings_icon();
		if ( empty( $icon ) ) {
			$icon = '<i class="fa fa-cogs"></i>';
		}

		$html  = sprintf( '<h3><span>%s %s</span></h3>', $icon, $this->plugin_settings_title() );
		$html .= '<p>' . esc_html__( 'Gravity Forms Dropbox Add-On requires PHP 5.3 or greater to run. To continue using this Add-On, please upgrade PHP.', 'gravityformsdropbox' ) . '</p>';
		
		echo $html;
		
	}

}