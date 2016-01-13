<?php

GFForms::include_feed_addon_framework();

class GFDropbox extends GFFeedAddOn {

	protected $_version = GF_DROPBOX_VERSION;
	protected $_min_gravityforms_version = '1.9.14';
	protected $_slug = 'gravityformsdropbox';
	protected $_path = 'gravityformsdropbox/dropbox.php';
	protected $_full_path = __FILE__;
	protected $_url = 'http://www.gravityforms.com';
	protected $_title = 'Gravity Forms Dropbox Add-On';
	protected $_short_title = 'Dropbox';

	/* Permissions */
	protected $_capabilities_settings_page = 'gravityforms_dropbox';
	protected $_capabilities_form_settings = 'gravityforms_dropbox';
	protected $_capabilities_uninstall = 'gravityforms_dropbox_uninstall';
	protected $_enable_rg_autoupgrade = true;

	/* Members plugin integration */
	protected $_capabilities = array( 'gravityforms_dropbox', 'gravityforms_dropbox_uninstall' );

	protected $api = null;
	protected $dropbox_client_identifier = 'Gravity-Forms-Dropbox/1.0';
	protected $dropbox_feeds_to_process = array();
	protected $nonce_action = 'gform_dropbox_upload';

	private static $_instance = null;

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
	 * Add Dropbox feed processing hooks.
	 * 
	 * @access public
	 * @return void
	 */
	public function init() {
		
		parent::init();
		
		/* Load Dropbox library */
		if ( ! function_exists( '\Dropbox\autoload' ) ) {
			require_once 'includes/api/autoload.php';
		}

		/* Load the Dropbox field class. */
		if ( ! $this->get_plugin_setting( 'defaultAppEnabled' ) && $this->initialize_api() ) {
			require_once 'includes/class-gf-field-dropbox.php';
		}
				
		/* Delete any files to not be stored locally after processing feeds. */
		add_filter( 'shutdown', array( $this, 'maybe_delete_files' ), 11 );

		/* Setup feed processing on shutdown */
		add_action( 'shutdown', array( $this, 'maybe_process_feed_on_shutdown' ), 10 );
		
		/* Process feeds upon admin POST request */
		add_action( 'admin_init', array( $this, 'maybe_process_feed_on_post_request' ) );

		/* Add Dropbox field settings */
		add_action( 'gform_field_standard_settings', array( $this, 'add_field_settings' ), 10, 2 );

		/* Save Dropbox auth token before rendering plugin settings page */
		add_action( 'admin_init', array( $this, 'save_auth_token' ) );

	}

	/**
	 * Add AJAX callback for retrieving folder contents.
	 * 
	 * @access public
	 * @return void
	 */
	public function init_ajax() {
		
		parent::init_ajax();

		/* Add AJAX callback for retreiving folder contents */
		add_action( 'wp_ajax_gfdropbox_folder_contents', array( $this, 'ajax_get_folder_contents' ) );

		/* Add AJAX callback for checking app key/secret validity */
		add_action( 'wp_ajax_gfdropbox_valid_app_key_secret', array( $this, 'ajax_is_valid_app_key_secret' ) );
		
	}

	/**
	 * Enqueue admin scripts.
	 * 
	 * @access public
	 * @return array $scripts
	 */
	public function scripts() {
		
		$scripts = array(
			array(
				'handle'  => 'gform_dropbox_jstree',
				'deps'    => array( 'jquery' ),
				'src'     => $this->get_base_url() . '/js/vendor/jstree.min.js',
				'version' => '3.0.4',
			),
			array(
				'handle'  => 'gform_dropbox_formeditor',
				'deps'    => array( 'jquery', 'gform_dropbox_jstree' ),
				'src'     => $this->get_base_url() . '/js/form_editor.js',
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' )
					),
				),
			),
			array(
				'handle'  => 'gform_dropbox_pluginsettings',
				'deps'    => array( 'jquery' ),
				'src'     => $this->get_base_url() . '/js/plugin_settings.js',
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'admin_page' => array( 'plugin_settings' )
					),
				),
			),
			array(
				'handle'  => 'gform_dropbox_frontend',
				'deps'    => array( 'jquery' ),
				'src'     => $this->get_base_url() . '/js/frontend.js',
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'field_types' => array( 'dropbox' )
					),
				),
			),
		);

		return array_merge( parent::scripts(), $scripts );
		
	}

	/**
	 * Enqueue folder tree styling.
	 * 
	 * @access public
	 * @return array $styles
	 */
	public function styles() {
		
		$styles = array(
			array(
				'handle'  => 'gfdropbox',
				'src'     => $this->get_base_url() . '/css/jstree/style.min.css',
				'version' => $this->_version,
				'enqueue' => array(
					array(
						'admin_page' => array( 'form_settings' )
					),
				),
			),
		);

		return array_merge( parent::styles(), $styles );
		
	}

	/**
	 * Save Dropbox auth token before rendering plugin settings page.
	 * 
	 * @access public
	 * @return void
	 */
	public function save_auth_token() {
		
		/* Confirm we're on the Dropbox plugin settings page. */
		if ( rgget( 'page' ) !== 'gf_settings' || rgget( 'subview' ) !== 'gravityformsdropbox' ) {
			return;
		}
		
		/* If we're not using SSL, redirect. */
		if ( ! is_ssl() ) {
			$redirect_url = admin_url( 'admin.php', 'https' );
			$redirect_url = add_query_arg( array( 'page' => 'gf_settings', 'subview' => 'gravityformsdropbox' ), $redirect_url );
			wp_redirect( $redirect_url );
		}
		
		/* Start the session. */
		session_start();
		
		/* Save auth token. */
		if ( rgget( 'state' ) ) {
			
			try {
				
				/* Get authentication result. */
				$auth_result = $this->setup_web_auth()->finish( $_GET );
				
				/* Save access token. */
				$settings = $this->get_plugin_settings();
				$settings['accessToken'] = $auth_result[0];
				$this->update_plugin_settings( $settings );
				
				/* Redirect page. */
				$redirect_url = admin_url( 'admin.php', 'https' );
				$redirect_url = add_query_arg( array( 'page' => 'gf_settings', 'subview' => 'gravityformsdropbox' ), $redirect_url );
				wp_redirect( $redirect_url );
				
			} catch ( Exception $e ) {
				
				GFCommon::add_error_message( sprintf(
					esc_html__( 'Unable to authorize with Dropbox: %1$s', 'gravityformsdropbox' ),
					$e->getMessage()
				) );
				
			}
			
		}
		
	}

	/**
	 * Setup plugin settings fields.
	 * 
	 * @access public
	 * @return array $settings
	 */
	public function plugin_settings_fields() {

		$settings = $this->get_plugin_settings();

		return array(
			array(
				'description' => $this->plugin_settings_description(),
				'fields'      => array(
					array(
						'name'              => 'customAppKey',
						'label'             => esc_html__( 'App Key', 'gravityformsdropbox' ),
						'type'              => 'text',
						'class'             => 'small',
						'placeholder'       => rgar( $settings, 'defaultAppEnabled' ) == '1' ? $this->get_app_key() : '',
						'feedback_callback' => array( $this, 'is_valid_app_key_secret' )
					),
					array(
						'name'              => 'customAppSecret',
						'label'             => esc_html__( 'App Secret', 'gravityformsdropbox' ),
						'type'              => 'text',
						'class'             => 'small',
						'placeholder'       => rgar( $settings, 'defaultAppEnabled' ) == '1' ? $this->get_app_secret() : '',
						'feedback_callback' => array( $this, 'is_valid_app_key_secret' )
					),
					array(
						'name'              => 'authCode',
						'label'             => esc_html__( 'Authentication Code', 'gravityformsdropbox' ),
						'type'              => 'auth_code'
					),
					array(
						'name'              => 'accessToken',
						'type'              => 'hidden'
					),
					array(
						'name'              => 'defaultAppEnabled',
						'type'              => 'hidden',
					),
				),
			),
		);
		
	}
		
	/**
	 * Prepare custom app settings settings description.
	 * 
	 * @access public
	 * @return string $description
	 */
	public function plugin_settings_description() {
		
		/* Introduction. */
		$description  = '<p>' . esc_html__( 'To use the Gravity Forms Dropbox Add-On, you will need to create your own Dropbox App by following the steps below.', 'gravityformsdropbox' ) . '</p>';
		
		$description .= '<ol>';
		$description .= '<li>' . sprintf( esc_html__( 'Visit the %sDropbox App Console%s and create a new app.', 'gravityformsdropbox' ), '<a href="https://www.dropbox.com/developers/apps" target="_blank">', '</a>' ) . '</li>';
		$description .= '<li>' . esc_html__( 'Select "Dropbox API app" as the type of app you want to create.', 'gravityformsdropbox' ) . '</li>';
		$description .= '<li>' . esc_html__( 'Select "Files and datastores" as the type of data your app needs to store on Dropbox.', 'gravityformsdropbox' ) . '</li>';
		$description .= '<li>' . esc_html__( 'Select "No &mdash; My app needs access to files already on Dropbox" in response to if your app can be limited to its own folder.', 'gravityformsdropbox' ) . '</li>';
		$description .= '<li>' . esc_html__( 'Select "All file types My app needs access to a user\'s full Dropbox." in response to what type of files your app needs access to.', 'gravityformsdropbox' ) . '</li>';
		$description .= '<li>' . esc_html__( 'Enter a name and create your app.', 'gravityformsdropbox' ) . '</li>';
		$description .= '<li>' . sprintf( esc_html__( 'Under App Settings, add %s to the Drop-ins domains list.', 'gravityformsdropbox' ), '<strong>' . preg_replace( "(^https?://)", '', get_site_url() ) . '</strong>' ) . '</li>';
		$description .= '<li>' . sprintf( esc_html__( 'Under App Settings, add %s to the OAuth 2 Redirect URIs list.', 'gravityformsdropbox' ), '<strong>' . admin_url( 'admin.php?page=gf_settings&subview=gravityformsdropbox', 'https' ) . '</strong>' ) . '</li>';
		$description .= '</ol>';

		return $description;
		
	}
	
	/**
	 * Create Generate Authentication Code settings field.
	 * 
	 * @access public
	 * @param array $field
	 * @param bool $echo (default: true)
	 * @return string $html
	 */
	public function settings_auth_code( $field, $echo = true ) {
		
		/* Get plugin settings. */
		$settings = $this->get_plugin_settings();
		
		if ( ! rgar( $settings, 'accessToken' ) || ( rgar( $settings, 'accessToken' ) && ! $this->initialize_api() ) ) {
			
			$html  = sprintf(
				'<div style="%2$s" id="gform_dropbox_auth_message">%1$s</div>',
				esc_html__( 'You must provide a valid app key and secret before authenticating with Dropbox.', 'gravityformsdropbox' ),
				! rgar( $settings, 'customAppKey' ) || ! rgar( $settings, 'customAppSecret' ) ? 'display:block' : 'display:none'
			);
			
			$html .= sprintf(
				'<a href="%3$s" class="button" id="gform_dropbox_auth_button" style="%2$s">%1$s</a>',
				esc_html__( 'Click here to authenticate with Dropbox.', 'gravityformsdropbox' ),
				! rgar( $settings, 'customAppKey' ) || ! rgar( $settings, 'customAppSecret' ) ? 'display:none' : 'display:inline-block',
				rgar( $settings, 'customAppKey' ) && rgar( $settings, 'customAppSecret' ) ? $this->setup_web_auth()->start() : '#'
			);
			
		} else {
			
			$html  = esc_html__( 'Dropbox has been authenticated with your account.', 'gravityformsdropbox' );
			$html .= "&nbsp;&nbsp;<i class=\"fa icon-check fa-check gf_valid\"></i><br /><br />";
			$html .= sprintf(
				' <a href="#" class="button" id="gform_dropbox_deauth_button">%1$s</a>',
				esc_html__( 'De-Authorize Dropbox', 'gravityformsdropbox' )
			);
			
		}
		
		if ( $echo ) {
			echo $html;
		}

		return $html;
		
	}

	/**
	 * Setup fields for feed settings.
	 * 
	 * @access public
	 * @return array
	 */
	public function feed_settings_fields() {

		return array(
			array(
				'title'  => '',
				'fields' => array(
					array(
						'name'           => 'feedName',
						'type'           => 'text',
						'required'       => true,
						'label'          => __( 'Name', 'gravityformsdropbox' ),
						'tooltip'        => '<h6>' . esc_html__( 'Name', 'gravityformsdropbox' ) . '</h6>' . __( 'Enter a feed name to uniquely identify this setup.', 'gravityformsdropbox' )
					),
					array(
						'name'           => 'fileUploadField',
						'type'           => 'select',
						'required'       => true,
						'label'          => __( 'File Upload Field', 'gravityformsdropbox' ),
						'choices'        => $this->get_file_upload_field_choices(),
						'tooltip'        => '<h6>' . esc_html__( 'File Upload Field', 'gravityformsdropbox' ) . '</h6>' . __( 'Select the specific File Upload field that you want to be uploaded to Dropbox.', 'gravityformsdropbox' )
					),
					array(
						'name'           => 'destinationFolder',
						'type'           => 'folder',
						'required'       => true,
						'label'          => __( 'Destination Folder', 'gravityformsdropbox' ),
						'tooltip'        => '<h6>' . esc_html__( 'Destination Folder', 'gravityformsdropbox' ) . '</h6>' . esc_html__( 'Select the folder in your Dropbox account where the files will be uploaded to.', 'gravityformsdropbox' ) . '<br /><br />' . esc_html__( 'By default, all files are stored in the "Gravity Forms Add-On" folder within the Dropbox Apps folder in your Dropbox account.', 'gravityformsdropbox' )
					),
					array(
						'name'           => 'feedCondition',
						'type'           => 'feed_condition',
						'label'          => __( 'Upload Condition', 'gravityformsdropbox' ),
						'checkbox_label' => __( 'Enable Condition', 'gravityformsdropbox' ),
						'instructions'   => __( 'Upload to Dropbox if', 'gravityformsdropbox' )
					),
				)
			),
		);

	}

	/**
	 * Set if feeds can be created.
	 * 
	 * @access public
	 * @return bool
	 */
	public function can_create_feed() {
		
		return $this->initialize_api();
		
	}

	/**
	 * Setup columns for feed list table.
	 * 
	 * @access public
	 * @return array
	 */
	public function feed_list_columns() {
		
		return array(
			'feedName'          => esc_html__( 'Name', 'gravityformsdropbox' ),
			'destinationFolder' => esc_html__( 'Destination Folder', 'gravityformsdropbox' ),
		);
		
	}

	/**
	 * Notify user form requires file upload fields if not present.
	 * 
	 * @access public
	 * @return string|bool
	 */
	public function feed_list_message() {
		
		if ( ! $this->has_file_upload_fields() ) {
			return $this->requires_file_upload_message();
		}
		
		return parent::feed_list_message();
		
	}

	/**
	 * Link user to form editor to add file upload fields.
	 * 
	 * @access public
	 * @return string
	 */
	public function requires_file_upload_message() {
		
		$url = add_query_arg( array( 
			'view'    => null,
			'subview' => null
		) );
		
		return sprintf(
			esc_html__( "You must add a File Upload field to your form before creating a feed. Let's go %sadd one%s!", 'gravityformsdropbox' ),
			'<a href="' . esc_url( $url ) . '">', '</a>'
		);
		
	}

	/**
	 * Get file upload fields for feed setting.
	 * 
	 * @access public
	 * @return array $choices
	 */
	public function get_file_upload_field_choices() {

		/* Setup choices array. */
		$choices = array(
			array(
				'label' => esc_html__( 'All File Upload Fields', 'gravityformsdropbox' ),
				'value' => 'all',
			),
		);

		/* Get file upload fields for form. */
		$fields = $this->has_file_upload_fields();

		/* Add fields to choices. */
		if ( ! empty ( $fields ) ) {
			
			foreach ( $fields as $field ) {
				
				$choices[] = array(
					'label' => RGFormsModel::get_label( $field ),
					'value' => $field->id,
				);
				
			}

		}

		return $choices;
		
	}
	
	/**
	 * Get file upload fields for form.
	 * 
	 * @access public
	 * @param int $form_id (default: null)
	 * @return array $fields
	 */
	public function has_file_upload_fields( $form_id = null ) {
		
		/* Get form. */
		$form = rgblank( $form_id ) ? $this->get_current_form() : GFAPI::get_form( $form_id );
		
		/* Get file upload fields for form. */
		return GFAPI::get_fields_by_type( $form, array( 'fileupload', 'dropbox' ), true );
		
	} 

	/**
	 * Create folder tree settings field.
	 * 
	 * @access public
	 * @param array $field
	 * @param bool $echo (default: true)
	 * @return string $html
	 */
	public function settings_folder( $field, $echo = true ) {

		$attributes    = $this->get_field_attributes( $field );
		$site_url      = parse_url( get_option( 'home' ) );
		$default_value = '/' . rgar( $site_url, 'host' );
		$value         = $this->get_setting( $field['name'], $default_value );
		$name          = esc_attr( $field['name'] );

		$html = sprintf(
			'<input name="%1$s" type="hidden" value="%2$s" /><div data-target="%1$s" class="folder_tree"></div>',
			'_gaddon_setting_' . $name,
			$value
		);

		if ( $this->field_failed_validation( $field ) ) {
			$html .= $this->get_error_icon( $field );
		}

		/* Setup JSON for folder path */
		$html .= sprintf(
			'<script type="text/javascript">var gfdropbox_path = \'%1$s\';</script>',
			$value
		);

		if ( $echo ) {
			echo $html;
		}

		return $html;
		
	}

	/**
	 * Get folder tree for feed settings field.
	 * 
	 * @access public
	 * @param string $path
	 * @param bool $first_load (default: false)
	 * @return array
	 */
	public function get_folder_tree( $path, $first_load = false ) {

		/* If the Dropbox instance isn't initialized, return an empty folder array. */
		if ( ! $this->initialize_api() ) {
			$this->log_error( __METHOD__ . '(): Unable to get folder tree because API is not initialized.' );
			return array();
		}

		$base_folder = $this->get_folder( $path );

		/* If this is the first load of the tree, we need to get all the parent items. */
		if ( $first_load == 'true' ) {

			$folders = array(
				array(
					'id'            => strtolower( $base_folder['id'] ),
					'text'          => $base_folder['text'],
					'parent'        => ( $base_folder['id'] == '/' ) ? '#' : strtolower( dirname( $base_folder['id'] ) ),
					'children'      => $base_folder['children'],
					'child_folders' => $base_folder['child_folders'],
					'state'         => array(
						'selected' => true,
						'opened'   => true,
					)
				)
			);

			/* Go up the path until we reach the root folder. */
			$current_path = $base_folder['id'];
			
			while ( $current_path !== '/' ) {

				$current_path = dirname( $current_path );

				$folder = $this->get_folder( $current_path );

				if ( rgar( $folder, 'children' ) ) {
					
					foreach ( $folder['child_folders'] as $index => $child ) {
						
						if ( $child['id'] !== $folders[0]['id'] ) {
							
							unset( $child['child_folders'] );
							$folders[] = $child;
							
						}
						
					}
					
				}

				$folders[] = array(
					'id'     => $folder['id'],
					'text'   => $folder['text'],
					'parent' => ( $folder['id'] == '/' ) ? '#' : $folder['parent'],
				);
				
			}

			/* Make sure only unique items are in the array */
			$folders = $this->unique_folder_tree( $folders );

		} else {

			$folders = rgar( $base_folder, 'children' ) ? $base_folder['child_folders'] : array();
			
			foreach ( $folders as &$folder ) {
				
				if ( ! rgar( $folder, 'children' ) ) {
					$folder['children'] = '';
				}
				
			}
			
		}

		usort( $folders, array( $this, 'sort_folder_tree' ) );

		return $folders;
		
	}

	/**
	 * Ensure folder tree does not contain any duplicate folders.
	 * 
	 * @access public
	 * @param array $tree
	 * @return array $tree
	 */
	public function unique_folder_tree( $tree ) {

		for ( $i = 0; $i < count( $tree ); $i ++ ) {
			
			$duplicate = null;
			
			for ( $ii = $i + 1; $ii < count( $tree ); $ii ++ ) {
				
				if ( strcmp( $tree[ $ii ]['id'], $tree[ $i ]['id'] ) === 0 ) {
					
					$duplicate = $ii;
					break;
					
				}
				
			}

			if ( ! is_null( $duplicate ) ) {
				array_splice( $tree, $duplicate, 1 );
			}
			
		}

		return $tree;
		
	}

	/**
	 * Sort folder tree in alphabetical order.
	 * 
	 * @access public
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	public function sort_folder_tree( $a, $b ) {
		
		return strcmp( $a['text'], $b['text'] );
		
	}

	/**
	 * Get Dropbox folder tree for AJAX requests.
	 * 
	 * @access public
	 * @return string
	 */
	public function ajax_get_folder_contents() {
		
		$path = ( rgget( 'path' ) == '#' ) ? '/' : rgget( 'path' );

		echo json_encode( $this->get_folder_tree( $path, rgget( 'first_load' ) ) );
		die();
		
	}

	/**
	 * Add settings fields for Dropbox field.
	 * 
	 * @access public
	 * @param int $position
	 * @param int $form_id
	 * @return void
	 */
	public function add_field_settings( $position, $form_id ) {
		
		if ( $position == 20 ) {
			
			$html  = '<li class="multiselect_setting field_setting">';
			$html .= '<input type="checkbox" id="field_multiselect" onclick="SetFieldProperty(\'multiselect\', this.checked);" />';
			$html .= '<label for="field_multiselect" class="inline">' . esc_html__( 'Allow multiple files to be selected', 'gravityformsdropbox' ) . '</label>';
			$html .= '<br class="clear" />';
			$html .= '</li>';
			
			echo $html;
			
		}
		
	}

	/**
	 * Add feed to processing queue.
	 * 
	 * @access public
	 * @param mixed $feed
	 * @param mixed $entry
	 * @param mixed $form
	 * @return void
	 */
	public function process_feed( $feed, $entry, $form ) {

		/* If the Dropbox instance isn't initialized, do not process the feed. */
		if ( ! $this->initialize_api() ) {
			$this->add_feed_error( esc_html__( 'Feed was not processed because API was not initialized.', 'gravityformsdropbox' ), $feed, $entry, $form );
			return;
		}
			
		/* Set flag for adding feed to processing queue. */
		$process_feed = false;
		
		/* Loop through the form fields and work with just the file upload fields. */
		foreach ( $form['fields'] as $field ) {

			$input_type = $field->get_input_type();

			if ( ! in_array( $input_type, array( 'dropbox', 'fileupload' ) ) ) {
				continue;
			}
			
			if ( rgars( $feed, 'meta/fileUploadField' ) != 'all' && rgars( $feed, 'meta/fileUploadField' ) != $field->id ) {
				continue;
			}

			/* If entry value is not empty, flag for processing. */
			if ( ! rgblank( $entry[ $field->id ] ) ) {
				$process_feed = true;
			}
				
		}

		/* If this feed is being added to the processing queue, add it and disable form notifications. */
		if ( $process_feed ) {
			
			/* Log that we're adding the feed to the queue. */
			$this->log_debug( __METHOD__ . '(): Adding feed #' . $feed['id'] . ' to the processing queue.' );

			/* Add the feed to the queue. */
			$this->dropbox_feeds_to_process[] = array( $feed, $entry, $form['id'] );
			
			/* Disable notifications. */
			add_filter( 'gform_disable_notification_'. $form['id'], '__return_true' );

		}

	}

	/**
	 * Process queued feeds on shutdown.
	 * 
	 * @access public
	 * @return void
	 */
	public function maybe_process_feed_on_shutdown() {
		
		if ( ! empty( $this->dropbox_feeds_to_process ) ) {
			
			foreach ( $this->dropbox_feeds_to_process as $index => $feed_to_process ) {
				
				/* Log that we're sending this feed to processing. */
				$this->log_debug( __METHOD__ . '(): Sending processing request for feed #' . $feed_to_process[0]['id'] . '.' );

				/* Prepare the request. */
				$post_request = array(
					'action'       => $this->nonce_action,
					'feed'         => $feed_to_process,
					'is_last_feed' => ( $index == ( count( $this->dropbox_feeds_to_process ) - 1 ) ),
					'_nonce'       => $this->create_nonce()
				);
		
				/* Execute. */
				$response = wp_remote_post( admin_url( 'admin-post.php' ), array(
					'timeout'   => 0.01,
					'blocking'  => false,
					'sslverify' => apply_filters( 'https_local_ssl_verify', true ),
					'body'      => $post_request,
				) );

				if ( is_wp_error( $response ) ) {
					$this->log_error( __METHOD__ . '(): Aborting. ' . $response->get_error_message() );
				}
			}
			
		}
		
	}

	/**
	 * Process queued feed.
	 * 
	 * @access public
	 * @return void
	 */
	public function maybe_process_feed_on_post_request() {
		
		require_once( ABSPATH .'wp-includes/pluggable.php' );
		
		global $_gfdropbox_delete_files, $_gfdropbox_update_entry_fields;
		$_gfdropbox_delete_files = $_gfdropbox_update_entry_fields = array();
		
		$nonce = rgpost( '_nonce' );
		
		if ( rgpost( 'action' ) == $this->nonce_action && $this->verify_nonce( $nonce ) !== false ) {

			$this->log_debug( __METHOD__ . '(): Nonce verified preparing to process request.' );

			$feed  = $_POST['feed'][0];
			$entry = $_POST['feed'][1];
			$form  = GFAPI::get_form( $_POST['feed'][2] );

			/* Process feed. */
			$entry = GFDropbox::process_feed_files( $feed, $entry, $form );
						
			/* Update entry links and send notifications if last feed being processed. */
			if ( rgpost( 'is_last_feed' ) ) {
				$entry = $this->update_entry_links( $entry );
				GFAPI::send_notifications( $form, $entry );
			}
			
		}
			
	}

	/**
	 * Create nonce for Dropbox upload request.
	 * 
	 * @access public
	 * @return string
	 */
	public function create_nonce() {
			
		$action = $this->nonce_action;
		$i      = wp_nonce_tick();

		return substr( wp_hash( $i . $action, 'nonce' ), - 12, 10 );
	
	}

	/**
	 * Verify nonce for Dropbox upload request.
	 * 
	 * @access public
	 * @param string $nonce
	 * @return int|bool
	 */
	public function verify_nonce( $nonce ) {
		
		$action = $this->nonce_action;
		$i      = wp_nonce_tick();

		/* Nonce generated 0-12 hours ago. */
		if ( substr( wp_hash( $i . $this->nonce_action, 'nonce' ), - 12, 10 ) == $nonce ) {
			return 1;
		}

		/* Nonce generated 12-24 hours ago. */
		if ( substr( wp_hash( ( $i - 1 ) . $this->nonce_action, 'nonce' ), - 12, 10 ) == $nonce ) {
			return 2;
		}

		$this->log_error( __METHOD__ . '(): Aborting. Unable to verify nonce.' );

		/* Invalid nonce. */
		return false;
		
	}

	/**
	 * Process feed.
	 * 
	 * @access public
	 * @param array $feed
	 * @param array $entry
	 * @param array $form
	 * @return array $entry
	 */
	public function process_feed_files( $feed, $entry, $form ) {
		
		/* If the Dropbox instance isn't initialized, do not process the feed. */
		if ( ! gf_dropbox()->initialize_api() ) {
			$this->add_feed_error( esc_html__( 'Feed was not processed because API was not initialized.', 'gravityformsdropbox' ), $feed, $entry, $form );
			return $entry;
		}

		$this->log_debug( __METHOD__ . '(): Checking form fields for files to process.' );

		foreach ( $form['fields'] as $field ) {

			$input_type = $field->get_input_type();
		
			/* If feed is not a file upload or Dropbox field, skip it. */
			if ( ! in_array( $input_type, array( 'dropbox', 'fileupload' ) ) ) {
				continue;
			}

			/* If feed is not uploading all file upload fields or this specifc field, skip it. */
			if ( rgars( $feed, 'meta/fileUploadField' ) !== 'all' && rgars( $feed, 'meta/fileUploadField' ) != $field->id ) {
				continue;
			}
						
			call_user_method_array( 'process_' . $input_type . '_fields', $this, array( $field, $feed, $entry, $form ) );
			
		}
		
		return $entry;
		
	}
	
	/**
	 * Process Dropbox upload fields.
	 * 
	 * @access public
	 * @param array $field
	 * @param array $feed
	 * @param array $entry
	 * @param array $form
	 * @return void
	 */
	public function process_dropbox_fields( $field, $feed, $entry, $form ) {

		global $wpdb, $_gfdropbox_update_entry_fields;

		/* Get field value. */
		$field_value = $entry[ $field->id ];

		/* If field value is empty, return. */
		if ( rgblank( $field_value ) ) {
			$this->log_debug( __METHOD__ . '(): Not uploading Dropbox Upload field #' . $field->id . ' because field value is empty.' );
			return;
		}

		$this->log_debug( __METHOD__ . '(): Beginning upload of Dropbox Upload field #' . $field->id . '.' );

		/* Decode field value. */
		$files = json_decode( stripslashes_deep( $field_value ), true );

		/* Copy files to Dropbox. */
		foreach ( $files as &$file ) {

			/* Get destination path. */
			$destination_path = gf_apply_filters( 'gform_dropbox_folder_path', $form['id'], rgars( $feed, 'meta/destinationFolder' ), $form, $field->id, $entry, $feed );
			$destination_path = strpos( $destination_path, '/' ) !== 0 ? '/' . $destination_path : $destination_path;
			
			/* Get destination folder metadata. */
			$original_md = $this->api->getMetadataWithChildren( $destination_path );
			
			/* Prepare file name. */
			$file_name = basename( $file );
			$file_name = explode( '?dl=', $file_name );
			$file_name = $file_name[0];
			$file_name = gf_apply_filters( 'gform_dropbox_file_name', $form['id'], $file_name, $form, $field->id, $entry, $feed );

			/* Begin saving the URL to Dropbox. */
			$save_url        = $this->save_url( $file, trailingslashit( $destination_path ) . $file_name );
			$save_url_id     = $save_url['job'];
			$save_url_status = $save_url['status'];
			
			/* If save URL failed, log error. */
			if ( $save_url_status === 'FAILED' ) {
				
				$this->add_feed_error( sprintf( esc_html__( 'Unable to upload file: %s', 'gravityformsdropbox' ), $upload_response['error'] ), $feed, $entry, $form );
				continue;				
				
			}
			
			while ( ! in_array( $save_url_status, array( 'FAILED', 'COMPLETE' ) ) ) {
				
				$save_url_job    = $this->save_url_job( $save_url_id );
				$save_url_status = $save_url_job['status'];
			
				sleep( 2 );
				
			}
					
			if ( $save_url_status === 'COMPLETE' ) {
				
				list( $changed, $new_md ) = $this->api->getMetadataWithChildrenIfChanged( $destination_path, $original_md['hash'] );

				$new_file = $this->get_new_files( $original_md, $new_md );

				$file = $this->get_shareable_link( $new_file[0]['path'] );

				continue;
				
			}
			
		}
		
		/* Encode the files string for lead detail. */
		$files = json_encode( $files );
		
		/* Update lead detail */	
		GFAPI::update_entry_field( $entry['id'], $field->id, $files );
		
		/* Add to array to update entry value for notification */
		$_gfdropbox_update_entry_fields[ $field->id ] = json_encode( $files );
		
	}
	
	/**
	 * Process file upload fields.
	 * 
	 * @access public
	 * @param array $feed
	 * @param array $entry
	 * @param array $form
	 * @return void
	 */
	public function process_fileupload_fields( $field, $feed, $entry, $form ) {
		
		global $_gfdropbox_update_entry_fields, $wpdb;

		$this->log_debug( __METHOD__ . '(): Beginning upload of file upload field #' . $field->id . '.' );

		/* Handle multiple files separately */
		if ( $field->multipleFiles ) {

			/* Decode the string of files */
			$files = json_decode( stripslashes_deep( $entry[ $field->id ] ), true );

			/* Process each file separately */
			foreach ( $files as &$file ) {

				/* Prepare file info */
				$file_info = array(
					'name'        => basename( $file ),
					'path'        => str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $file ),
					'url'         => $file,
					'destination' => rgars( $feed, 'meta/destinationFolder' ),
				);

				/* Upload file */
				$file = gf_dropbox()->upload_file( $file_info, $form, $field->id, $entry, $feed );

			}
			
			/* Encode the files string for lead detail */
			$file_for_lead = json_encode( $files );

		} else {

			/* Prepare file info */
			$file_info = array(
				'name'        => basename( $entry[ $field->id ] ),
				'path'        => str_replace( WP_CONTENT_URL, WP_CONTENT_DIR, $entry[ $field->id ] ),
				'url'         => $entry[ $field->id ],
				'destination' => rgars( $feed, 'meta/destinationFolder' ),
			);

			/* Upload file */
			$file_for_lead = gf_dropbox()->upload_file( $file_info, $form, $field->id, $entry, $feed );
						
		}

		/* Update lead detail */	
		GFAPI::update_entry_field( $entry['id'], $field->id, $file_for_lead );

		/* Add to array to update entry value for notification */
		$_gfdropbox_update_entry_fields[ $field->id ] = $file_for_lead;

	}
	
	/**
	 * Delete files that do not need a local version.
	 * 
	 * @access public
	 * @return void
	 */
	public function maybe_delete_files() {
		
		global $_gfdropbox_delete_files;

		if ( ! empty ( $_gfdropbox_delete_files ) ) {
			
			array_map( 'unlink', $_gfdropbox_delete_files );
			
		}
		
	}
	
	/**
	 * Update entry with Dropbox links.
	 * 
	 * @access public
	 * @param mixed $entry
	 * @return void
	 */
	public function update_entry_links( $entry ) {

		global $_gfdropbox_update_entry_fields;
		
		if ( ! empty ( $_gfdropbox_update_entry_fields ) ) {

			foreach ( $_gfdropbox_update_entry_fields as $field_id => $value ) {
				
				if ( strpos( $value, '"' ) === 0 ) {
					$value = stripslashes( substr( substr( $value, 0, -1 ), 1 ) );
				}
				
				$entry[$field_id] = $value;
				
			}
			
		}
		
		return $entry;
		
	}

	/**
	 * Initializes Dropbox API if credentials are valid.
	 * 
	 * @access public
	 * @param string $access_token (default: null)
	 * @return bool
	 */
	public function initialize_api( $access_token = null ) {
		
		/* If API object is already setup, return true. */
		if ( ! is_null( $this->api ) ) {
			return true;
		}
		
		/* Load Dropbox library */
		if ( ! function_exists( '\Dropbox\autoload' ) ) {
			require_once 'includes/api/autoload.php';
		}

		/* If access token parameter is null, set to the plugin setting. */
		$access_token = rgblank( $access_token ) ? $this->get_plugin_setting( 'accessToken' ) : $access_token;		

		/* If access token is empty, return null. */
		if ( rgblank( $access_token ) ) {
			return null;
		}
	
		/* Log that were testing the API credentials. */
		$this->log_debug( __METHOD__ . '(): Testing API credentials.' );
	
		try {

			/* Setup a new Dropbox API object. */
			$dropbox = new \Dropbox\Client( $access_token, $this->dropbox_client_identifier );
			
			/* Make a test request. */
			$dropbox->getAccountInfo();

			/* Set the Dropbox API object to the class. */
			$this->api = $dropbox;
			
			/* Log that test passed. */
			$this->log_debug( __METHOD__ . '(): API credentials are valid.' );
			
			return true;

		} catch ( Exception $e ) {
			
			/* Log that test failed. */
			$this->log_error( __METHOD__ . '(): API credentials are invalid; ' . $e->getMessage() );
			
			return false;
			
		}
			
		return false;
		
	}

	/**
	 * Get Dropbox app key.
	 * 
	 * @access public
	 * @return string - Dropbox app key
	 */
	public function get_app_key() {
		
		/* Get plugin settings. */
		$settings = $this->get_plugin_settings();
		
		return rgar( $settings, 'defaultAppEnabled' ) == '1' ? 'eylx4df4olbnm48' : rgar( $settings, 'customAppKey' );
		
	}
	
	/**
	 * Get Dropbox app secret.
	 * 
	 * @access public
	 * @return string - Dropbox app secret
	 */
	public function get_app_secret() {
	
		/* Get plugin settings. */
		$settings = $this->get_plugin_settings();
		
		return rgar( $settings, 'defaultAppEnabled' ) == '1' ?  '13w3fwg3k504onk' : rgar( $settings, 'customAppSecret' );
		
	}
	
	/**
	 * Check if Dropbox app key and secret are valid.
	 * 
	 * @access public
	 * @param string $app_key
	 * @param string $app_secret
	 * @return bool If the app key and secret are valid
	 */
	public function is_valid_app_key_secret( $app_key = null, $app_secret = null ) {
		
		/* If app secret is an array, retrieve the app key and secret from plugin settings. */
		if ( is_array( $app_secret ) ) {
			$app_key    = $this->get_app_key();
			$app_secret = $this->get_app_secret();
		}
		
		/* If app key or secret are empty, return false. */
		if ( rgblank( $app_key ) || rgblank( $app_secret ) ) {
			return null;
		}
		
		/* Load Dropbox application info. */
		$application_info = Dropbox\AppInfo::loadFromJson( array( 'key' => $app_key, 'secret' => $app_secret ) );
		
		/* Create a Dropbox web auth instance. */
		$web_auth = new Dropbox\WebAuthNoRedirect( $application_info, $this->dropbox_client_identifier );
		
		/* Get web auth URL. */
		$auth_url = $web_auth->start();
		
		/* Make a request to the web auth URL. */
		$auth_request = wp_remote_get( $auth_url );
		
		/* Return result based on response code. */
		return $auth_request['response']['code'] == 200;
		
	}

	/**
	 * Validate and save Dropbox app key and secret.
	 * 
	 * @access public
	 * @return void
	 */
	public function ajax_is_valid_app_key_secret() {
		
		$app_key    = sanitize_text_field( rgget( 'app_key' ) );
		$app_secret = sanitize_text_field( rgget( 'app_secret' ) );
		$is_valid   = $this->is_valid_app_key_secret( $app_key, $app_secret );
		$auth_url   = null;
		
		if ( $is_valid ) {
			
			$settings = $this->get_plugin_settings();
			
			$settings['customAppKey']    = $app_key;
			$settings['customAppSecret'] = $app_secret;
			
			unset( $settings['defaultAppEnabled'] );
			
			$this->update_plugin_settings( $settings );
			
			$auth_url = $this->setup_web_auth()->start();
			
		}
		
		echo json_encode( array( 'valid_app_key' => $is_valid, 'auth_url' => $auth_url ) );
		die();
		
	}

	/**
	 * Get Dropbox authorization URL.
	 * 
	 * @access public
	 * @return string
	 */
	public function setup_web_auth() {

		/* Setup API credentials array */
		$api_credentials = array(
			'key'    => $this->get_app_key(),
			'secret' => $this->get_app_secret()
		);

		$application_info = Dropbox\AppInfo::loadFromJson( $api_credentials );
		$csrf_token_store = new Dropbox\ArrayEntryStore( $_SESSION, 'dropbox-auth-csrf-token' );
		$redirect_uri     = admin_url( 'admin.php?page=gf_settings&subview=gravityformsdropbox', 'https' );
		
		return new Dropbox\WebAuth( $application_info, $this->dropbox_client_identifier, $redirect_uri, $csrf_token_store );

	}

	/**
	 * Generate a Dropbox access token.
	 * 
	 * @access public
	 * @param string $auth_code
	 * @return array
	 */
	public function generate_access_token( $auth_code ) {

		/* Setup Dropbox web auth */
		$web_auth = $this->setup_web_auth();

		/* Get access token */
		try {
			
			list( $access_token, $dropbox_user_id ) = $web_auth->finish( $auth_code );

			return array( 'access_token' => $access_token );
			
		} catch ( Exception $e ) {

			/* Get error message and strip down to just JSON data */
			$message = explode( '{', $e->getMessage() );
			$message = json_decode( '{' . $message[1], true );

			if ( $message['error'] == 'invalid_grant' ) {
				
				$this->log_error( __METHOD__ . '(): The authentication code provided does not exist or has expired.' );
				
				return array( 'error' => __( 'The authentication code you provided does not exist or has expired.', 'gravityformsdropbox' ) );
				
			}
			
		}

	}
	
	/**
	 * Get folder contents.
	 * 
	 * @access public
	 * @param string $path
	 * @return array $folder
	 */
	public function get_folder( $path ) {

		/* If the Dropbox instance isn't configured, exit. */
		if ( ! $this->initialize_api() ) {
			$this->log_error( __METHOD__ . '(): Unable to get contents of folder (' . $path . ') because API was not initialized.' );
			return array();
		}

		$folder_metadata = $this->api->getMetadataWithChildren( $path );

		/* If folder no longer exists, set folder to root folder. */
		if ( is_null( $folder_metadata ) ) {
			$folder_metadata = $this->api->getMetadataWithChildren( '/' );
		}

		/* Explode the folder path. */
		$folder_metadata['exploded_path'] = explode( '/', $folder_metadata['path'] );

		/* Setup folder object. */
		$folder = array(
			'id'            => strtolower( $folder_metadata['path'] ),
			'text'          => end( $folder_metadata['exploded_path'] ),
			'parent'        => strtolower( dirname( $folder_metadata['path'] ) ),
			'children'      => false,
			'child_folders' => array()
		);
		
		/* Set folder name to "Gravity Forms Add-On" if root folder. */
		if ( end( $folder_metadata['exploded_path'] ) == '' && $this->get_plugin_setting( 'defaultAppEnabled' ) == '1' ) {
			$folder['text'] = esc_html__( 'Gravity Forms Add-On', 'gravityformsdropbox' );
		}

		foreach ( $folder_metadata['contents'] as $item ) {
			
			if ( rgar( $item, 'is_dir' ) ) {

				$item                  = $this->api->getMetadataWithChildren( $item['path'] );
				$item['exploded_path'] = explode( '/', $item['path'] );
				$item['has_children']  = false;

				foreach ( $item['contents'] as $child_item ) {
					if ( rgar( $child_item, 'is_dir' ) ) {
						$item['has_children'] = true;
					}
				}

				$folder['child_folders'][] = array(
					'id'       => strtolower( $item['path'] ),
					'text'     => end( $item['exploded_path'] ),
					'parent'   => strtolower( dirname( $item['path'] ) ),
					'children' => rgar( $item, 'has_children' ),
				);
			}
			
		}

		if ( count( $folder['child_folders'] ) > 0 ) {
			$folder['children'] = true;
		}

		return $folder;

	}

	/**
	 * Get Dropbox link for file.
	 * 
	 * @access public
	 * @param string $file
	 * @return string
	 */
	public function get_shareable_link( $file ) {

		return is_null( $this->api ) ? null : preg_replace( '/\?.*/', '', $this->api->createShareableLink( $file ) );
		
	}

	/**
	 * Create Dropbox folder for site.
	 * 
	 * @access public
	 * @return void
	 */
	public function create_site_folder() {

		/* If the Dropbox instance doesn't exist, exit. */
		if ( ! $this->initialize_api() ) {
			$this->log_error( __METHOD__ . '(): Unable to create site folder because API is not initialized.' );
			return false;
		}

		/* Get site URL. */
		$site_url = parse_url( get_option( 'home' ) );
		
		/* Create folder. */
		return $this->api->createFolder( '/' . rgar( $site_url, 'host' ) );
		
	}

	/**
	 * Save Dropbox URL.
	 * 
	 * @access public
	 * @param string $url
	 * @param string $destination
	 * @return array
	 */
	public function save_url( $url, $destination ) {
				
		/* Execute request. */
		$result = wp_remote_post( 'https://api.dropbox.com/1/save_url/auto' . $destination, array(
			'body'    => array( 'url' => $url ),
			'headers' => array( 'Authorization' => 'Bearer ' . $this->get_plugin_setting( 'accessToken' ) )
		) );
		
		/* If WP_Error, log feed error. */
		if ( is_wp_error( $result ) ) {
			$this->add_feed_error( sprintf( esc_html__( 'Unable to upload file: %s', 'gravityformsdropbox' ), $result->get_error_messages() ), $feed, $entry, $form );
			return null;
		}
		
		/* Decode JSON response. */
		$result = json_decode( $result['body'], true );

		/* If the result is an error, log it. */
		if ( isset( $result['error'] ) ) {
			$this->add_feed_error( sprintf( esc_html__( 'Unable to upload file: %s', 'gravityformsdropbox' ), $result['error'] ), $feed, $entry, $form );
			return null;
		}

		return $result;
		
	}

	/**
	 * Get Dropbox URL Save status.
	 * 
	 * @access public
	 * @param id $job_id
	 * @return array
	 */
	public function save_url_job( $job_id ) {
		
		/* Execute request. */
		$result = wp_remote_get( 'https://api.dropbox.com/1/save_url_job/' . $job_id, array(
			'headers' => array( 'Authorization' => 'Bearer ' . $this->get_plugin_setting( 'accessToken' ) )
		) );
		
		/* If WP_Error, log feed error. */
		if ( is_wp_error( $result ) ) {
			$this->add_feed_error( sprintf( esc_html__( 'Unable to upload file: %s', 'gravityformsdropbox' ), $result->get_error_messages() ), $feed, $entry, $form );
			return null;
		}

		/* Decode JSON response. */
		$result = json_decode( $result['body'], true );

		/* If the result is an error, log it. */
		if ( isset( $result['error'] ) ) {
			$this->add_feed_error( sprintf( esc_html__( 'Unable to upload file: %s', 'gravityformsdropbox' ), $result['error'] ), $feed, $entry, $form );
			return null;
		}

		return $result;
		
	}

	/**
	 * Upload file to Dropbox.
	 * 
	 * @access public
	 * @param array $file
	 * @param array $form
	 * @param int $field_id
	 * @param array $entry
	 * @param array $feed
	 * @return string
	 */
	public function upload_file( $file, $form, $field_id, $entry, $feed ) {

		global $_gfdropbox_delete_files;

		/* If the Dropbox instance isn't initialized, do not upload the file. */
		if ( ! $this->initialize_api() ) {
			return rgar( $file, 'url' );
		}

		/* Filter the folder folder path */
		$folder_path        = gf_apply_filters( 'gform_dropbox_folder_path', $form['id'], $file['destination'], $form, $field_id, $entry, $feed );
		$destination_folder = $this->api->getMetadataWithChildren( $folder_path );

		/* If the folder path provided is not a folder, return the current file url. */
		if ( ! rgblank( $destination_folder ) && ! rgar( $destination_folder, 'is_dir' ) ) {
			$this->log_error( __METHOD__ . '(): Unable to upload file because destination is not a folder.' );
			return rgar( $file, 'url' );
		}

		/* If the folder doesn't exist, attempt to create it. If it can't be created, return the current file url. */
		if ( rgblank( $destination_folder ) ) {
			
			try {
				
				$this->api->createFolder( $folder_path );
				$destination_folder = $this->api->getMetadataWithChildren( $folder_path );
				
			} catch ( Exception $e ) {

				$this->log_error( __METHOD__ . '(): Unable to upload file because destination folder could not be created.' );
				return $file['url'];
				
			}
			
		}

		/* Filter the file name. */
		$file['name'] = gf_apply_filters( 'gform_dropbox_file_name', $form['id'], $file['name'], $form, $field_id, $entry, $feed );
		if ( rgblank( $file['name'] ) ) {
			$file['name'] = basename( $file['path'] );
		}

		/* Upload the file */
		$file_handler  = fopen( $file['path'], 'rb' );		
		$uploaded_file = $this->api->uploadFileChunked( trailingslashit( $folder_path ) . $file['name'], Dropbox\WriteMode::add(), $file_handler );
		$this->log_debug( __METHOD__ . '(): Result => ' . print_r( $uploaded_file, 1 ) );
		fclose( $file_handler );

		/* Check if we're storing a local version. */
		$store_local_version = gf_apply_filters( 'gform_dropbox_store_local_version', array( $form['id'], $field_id ), false, $file, $field_id, $form, $entry, $feed );

		/* If we're not saving a local copy, set this file for deletion. */
		if ( ! $store_local_version && ! in_array( $file['path'], $_gfdropbox_delete_files ) ) {
			$_gfdropbox_delete_files[] = $file['path'];
		}
		
		/* If we are saving a local copy, remove the file from the deletion array. */
		if ( $store_local_version && ( $file_array_key = array_search( $file['path'], $_gfdropbox_delete_files ) ) !== false ) {
			unset( $_gfdropbox_delete_files[ $file_array_key ] );
		}

		/* Return the public link */
		return $this->get_shareable_link( trailingslashit( $folder_path ) . basename( $uploaded_file['path'] ) );
		
	}

	/**
	 * Get newly uploaded Dropbox files by comparing folder metadata.
	 * 
	 * @access public
	 * @param array $metadata_a
	 * @param array $metadata_b
	 * @return array $new_files
	 */
	public function get_new_files( $metadata_a, $metadata_b ) {
		
		$file_revisions = array();
		$new_files      = array();
		
		foreach ( $metadata_a['contents'] as $content ) {
			
			$file_revisions[] = $content['rev'];
			
		}
		
		foreach ( $metadata_b['contents'] as $i => $content ) {
			
			if ( ! in_array( $content['rev'], $file_revisions ) ) {
				$new_files[] = $content;
			}
			
		}
		
		return $new_files;
		
	}

	/**
	 * Checks if a previous version was installed and enable default app flag if no custom app was used.
	 * 
	 * @access public
	 * @param string $previous_version The version number of the previously installed version.
	 */
	public function upgrade( $previous_version ) {

		$previous_is_pre_custom_app_only = ! empty( $previous_version ) && version_compare( $previous_version, '1.0.6', '<' );

		if ( $previous_is_pre_custom_app_only ) {

			$settings = $this->get_plugin_settings();
			
			if ( ! rgar( $settings, 'customAppEnable' ) && $this->initialize_api() ) {
				$settings['defaultAppEnabled'] = '1';
			}
			
			unset( $settings['customAppEnable'] );

			$this->update_plugin_settings( $settings );

		}

	}

}
