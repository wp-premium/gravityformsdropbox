window.GFDropboxSettings = null;

( function( $ ) {
	
	GFDropboxSettings = function () {
		
		var GFDropboxSettingsObj = this;

		this.init = function() {
			
			this.bindAppKeyUpdate();
			
			this.bindDeauthorize();
			
			this.setupValidationIcons();
			
			$( '#gform-settings-save' ).hide();
			
		}
		
		this.bindAppKeyUpdate = function() {
			
			$( 'input#customAppKey, input#customAppSecret' ).on( 'blur', this.checkAppKeyValidity );
			
		}
		
		this.bindDeauthorize = function() {
			
			$( '#gform_dropbox_deauth_button' ).on( 'click', function( e ) {
				
				e.preventDefault();
				
				$( 'input[name="_gaddon_setting_accessToken"], input[name="_gaddon_setting_defaultAppEnabled"]' ).val( '' );
				
				$( '#gform-settings-save' ).trigger( 'click' );
				
			} );
			
		}
		
		this.checkAppKeyValidity = function() {
			
			GFDropboxSettingsObj.lockAppKeyFields( true );
			GFDropboxSettingsObj.resetAppKeyStatus();
			
			$.ajax( {
				'url':      ajaxurl,
				'dataType': 'json',
				'type':     'GET',
				'data':     {
					'action':     'gfdropbox_valid_app_key_secret',
					'app_key':    GFDropboxSettingsObj.getAppKey(),
					'app_secret': GFDropboxSettingsObj.getAppSecret()
				},
				'success':  function( result ) {
					GFDropboxSettingsObj.setAppKeyStatus( result.valid_app_key, result.auth_url );
					GFDropboxSettingsObj.lockAppKeyFields( false );			
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
			
 			$( '#gaddon-setting-row-customAppKey .fa, #gaddon-setting-row-customAppSecret .fa' ).removeClass( 'icon-check icon-remove fa-check fa-times gf_valid gf_invalid' );
	
		}
		
		this.setAppKeyStatus = function( valid, auth_url ) {
		
			if ( valid === true ) {
				$( '#gaddon-setting-row-customAppKey .fa, #gaddon-setting-row-customAppSecret .fa' ).addClass( 'icon-check fa-check gf_valid' );
				$( '#gform_dropbox_auth_message' ).hide();
				$( '#gform_dropbox_auth_button' ).attr( 'href', auth_url ).show();
			}
			
			if ( valid === false ) {
				$( '#gaddon-setting-row-customAppKey .fa, #gaddon-setting-row-customAppSecret .fa' ).addClass( 'icon-remove fa-times gf_invalid' );
			}
			
			if ( valid === false || valid === null ) {
				$( '#gform_dropbox_auth_message' ).show();
				$( '#gform_dropbox_auth_button' ).hide();
			}
			
		}
		
		this.setupValidationIcons = function() {
			
			if ( $( '#gaddon-setting-row-customAppKey .fa' ).length == 0 ) {
				$( ' <i class="fa"></i>' ).insertAfter( $( '#customAppKey') );
			}

			if ( $( '#gaddon-setting-row-customAppSecret .fa' ).length == 0 ) {
				$( '<i class="fa"></i>' ).insertAfter( $( '#customAppSecret') );
			}
			
		}
		
		this.init();
		
	}
	
	$( document ).ready( GFDropboxSettings );
	
} )( jQuery );