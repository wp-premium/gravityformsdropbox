window.GFDropboxSettings = null;

( function( $ ) {

	GFDropboxSettings = function () {

		var self = this;

		this.init = function() {

			this.initialValues = {
				appKey:    this.getAppKey(),
				appSecret: this.getAppSecret()
			};

			this.legacyUI = ! $( '#customAppKey' ).parent().hasClass( 'gform-settings-input__container' );
			
			this.pageURL = gform_dropbox_pluginsettings_strings.settings_url;

			this.bindAppKeyUpdate();

			this.bindDeauthorize();
			
			this.bindDisableCustomApp();
			
			this.bindEnableCustomApp();

			this.setupValidationIcons();

			// Hide save plugin settings button.
			$( '#tab_gravityformsdropbox #gform-settings-save' ).hide();

		}

		this.bindAppKeyUpdate = function() {

			$( 'input#customAppKey, input#customAppSecret' ).on( 'blur', this.checkAppKeyValidity );

		}

		this.bindDeauthorize = function() {

			// De-Authorize Dropbox.
			$( '#gform_dropbox_deauth_button' ).on( 'click', function( e ){

				// Prevent default event.
				e.preventDefault();

				// Set disabled state.
				$( this ).attr( 'disabled', 'disabled' );

				// De-Authorize.
				$.ajax( {
					async:    false,
					url:      ajaxurl,
					dataType: 'json',
					data:     {
						action: 'gfdropbox_deauthorize',
						nonce:  gform_dropbox_pluginsettings_strings.nonce_deauthorize
					},
					success:  function ( response ) {

						if ( response.success ) {
							window.location.href = self.pageURL;
						} else {
							alert( response.data.message );
						}

						$( this ).removeAttr( 'disabled' );

					}
				} );

			} );

		}

		this.bindDisableCustomApp = function() {

			$( '#gform_dropbox_disable_customApp' ).on( 'click', function( e ) {

				// Prevent default event.
				e.preventDefault();

				// Set custom app value.
				$( 'input#customAppEnable' ).val( '' );

				// Submit form.
				$( '#gform-settings-save' ).trigger( 'click' );

			} );

		}

		this.bindEnableCustomApp = function() {

			$( '#gform_dropbox_enable_customApp' ).on( 'click', function( e ) {

				// Prevent default event.
				e.preventDefault();

				// Set custom app value.
				$( 'input#customAppEnable' ).val( '1' );

				// Submit form.
				$( '#gform-settings-save' ).trigger( 'click' );

			} );

		}

		this.checkAppKeyValidity = function() {

			self.lockAppKeyFields( true );
			self.resetAppKeyStatus();

			$.ajax( {
				'url':      ajaxurl,
				'dataType': 'json',
				'type':     'GET',
				'data':     {
					'action':     'gfdropbox_valid_app_key_secret',
					'app_key':    self.getAppKey(),
					'app_secret': self.getAppSecret(),
					'nonce':      gform_dropbox_pluginsettings_strings.nonce_validation
				},
				'success':  function ( result ) {

					if ( ! result.success ) {
						if ( result.data ) {
							alert( result.data.message );
						}
						self.lockAppKeyFields( false );
						self.setAppKeyStatus( result.success, null );
					} else {
						self.setAppKeyStatus( result.success, result.data.auth_url );
						self.lockAppKeyFields( false );
					}
				}
			} );

		}

		this.getAppKey = function() {

			return $( 'input#customAppKey' ).val();

		}

		this.getAppSecret = function() {

			return $( 'input#customAppSecret' ).val();

		}

		this.lockAppKeyFields = function( locked ) {

			$( 'input#customAppKey, input#customAppSecret' ).prop( 'disabled', locked );

		}

		this.resetAppKeyStatus = function() {

			if ( this.legacyUI ) {
				$( '#gaddon-setting-row-customAppKey .fa, #gaddon-setting-row-customAppSecret .fa' ).removeClass( 'icon-check icon-remove fa-check fa-times gf_valid gf_invalid' );
			} else {
				$( '#gform_setting_customAppKey .gform-settings-field__feedback, #gform_setting_customAppSecret .gform-settings-field__feedback' ).removeClass( 'gform-settings-field__feedback--valid gform-settings-field__feedback--invalid' )
			}

		}

		this.setAppKeyStatus = function( valid, auth_url ) {

			if ( valid === true ) {
				window.location.href = self.pageURL;
			}

			if ( valid === false ) {
				if ( this.legacyUI ) {
					$( '#gaddon-setting-row-customAppKey .fa, #gaddon-setting-row-customAppSecret .fa' ).addClass( 'icon-remove fa-times gf_invalid' );
				} else {
					$( '#gform_setting_customAppKey .gform-settings-field__feedback, #gform_setting_customAppSecret .gform-settings-field__feedback' ).addClass( 'gform-settings-field__feedback--invalid' )
				}
			}

			if ( valid === false || valid === null ) {
				$( '#gform_dropbox_auth_message' ).show();
				$( '#gform_dropbox_auth_button' ).hide();
			}

		}

		this.setupValidationIcons = function() {

			if ( $( '#gaddon-setting-row-customAppKey .fa' ).length == 0 ) {
				if ( this.legacyUI ) {
					$( ' <i class="fa"></i>' ).insertAfter( $( '#customAppKey' ) );
				} else {
					$( '<span class="gform-settings-field__feedback" aria-hidden="true"></span>' ).insertAfter( $( '#customAppKey' ) );
				}
			}

			if ( $( '#gaddon-setting-row-customAppSecret .fa' ).length == 0 ) {
				if ( this.legacyUI ) {
					$( ' <i class="fa"></i>' ).insertAfter( $( '#customAppSecret' ) );
				} else {
					$( '<span class="gform-settings-field__feedback" aria-hidden="true"></span>' ).insertAfter( $( '#customAppSecret' ) );
				}
			}

		}

		this.init();

	}

	$( document ).ready( GFDropboxSettings );

} )( jQuery );
