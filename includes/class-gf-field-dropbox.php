<?php

if ( ! class_exists( 'GFForms' ) ) {
	die();
}

class GF_Field_Dropbox extends GF_Field {

	public $type = 'dropbox';

	/**
	 * Return the field input HTML.
	 *
	 * @access public
	 * @param  array  $form
	 * @param  string $value (default: '')
	 * @param  array  $entry (default: null)
	 * @return string
	 */
	public function get_field_input( $form, $value = '', $entry = null ) {

		$dropbox_app_key = gf_dropbox()->get_app_key();

		$form_id         = absint( $form['id'] );
		$is_entry_detail = $this->is_entry_detail();
		$is_form_editor  = $this->is_form_editor();

		if ( $is_form_editor ) {
			return sprintf(
				"<img src='%s' alt='%s' title='%s' />",
				gf_dropbox()->get_base_url() . '/images/button-preview.png',
				esc_html__( 'Dropbox Button Preview', 'gravityformsdropbox' ),
				esc_html__( 'Dropbox Button Preview', 'gravityformsdropbox' )
			);
		}

		$logic_event = ! $is_form_editor && ! $is_entry_detail ? $this->get_conditional_logic_event( 'keyup' ) : '';
		$id          = (int) $this->id;
		$field_id    = $is_entry_detail || $is_form_editor || 0 === $form_id ? "input_$id" : 'input_' . $form_id . "_$id";
		$value       = esc_attr( $value );
		$size        = $this->size;

		$html  = "<input name='input_{$id}' id='{$field_id}' type='hidden' value='{$value}'  {$logic_event}/>";
		$html .= "<script type='text/javascript' src='//www.dropbox.com/static/api/2/dropins.js' id='dropboxjs' data-app-key='{$dropbox_app_key}'></script>";

		return sprintf( "<div class='ginput_container'>%s</div><div id='gform_preview_%s_%s'></div>", $html, $form_id, $id );

	}

	/**
	 * Return the button for the form editor.
	 *
	 * @access public
	 * @return array
	 */
	public function get_form_editor_button() {
		return array(
			'group' => 'advanced_fields',
			'text'  => $this->get_form_editor_field_title(),
		);
	}

	/**
	 * Returns array of field settings supported by field type.
	 *
	 * @access public
	 * @return array
	 */
	public function get_form_editor_field_settings() {
		return array(
			'conditional_logic_field_setting',
			'error_message_setting',
			'label_setting',
			'label_placement_setting',
			'admin_label_setting',
			'rules_setting',
			'file_extensions_setting',
			'visibility_setting',
			'description_setting',
			'css_class_setting',
			'link_type_setting',
			'multiselect_setting',
		);
	}

	/**
	 * Return the field title.
	 *
	 * @access public
	 * @return string
	 */
	public function get_form_editor_field_title() {
		return esc_attr__( 'Dropbox Upload', 'gravityformsdropbox' );
	}

	/**
	 * Prepare inline script for field settings.
	 *
	 * @access public
	 * @return string $js
	 */
	public function get_form_editor_inline_script_on_page_render() {
		$js = sprintf( "function SetDefaultValues_%s(field) {field.label = '%s';}", $this->type, $this->get_form_editor_field_title() ) . PHP_EOL;

		$js .= 'jQuery( document ).bind( "gform_load_field_settings", function( event, field, form ) {';
		$js .= 'jQuery( "#field_multiselect" ).attr( "checked", field["multiselect"] == true );';
		$js .= '} );';

		return $js;

	}

	/**
	 * Prepare inline script to load Dropbox button.
	 *
	 * @access public
	 * @param  array $form Form object.
	 * @return string
	 */
	public function get_form_inline_script_on_page_render( $form ) {

		$options = array(
			'deleteImage' => GFCommon::get_base_url() . '/images/delete.png',
			'deleteText'  => esc_attr__( 'Delete file', 'gravityforms' ),
			'extensions'  => ! empty( $this->allowedExtensions ) ? GFCommon::clean_extensions( explode( ',', strtolower( $this->allowedExtensions ) ) ) : array(),
			'formId'      => $form['id'],
			'inputId'     => $this->id,
			'linkType'    => gf_apply_filters( 'gform_dropbox_link_type', array( $form['id'], $this->id ), 'preview', $form, $this->id ),
			'multiselect' => $this->multiselect,
		);

		$script = 'new GFDropbox(' . json_encode( $options ) . ');';

		return $script;

	}

	/**
	 * Get file list for entry detail view.
	 *
	 * @access public
	 * @param  string $value
	 * @param  string $currency (default: '')
	 * @param  bool   $use_text (default: false)
	 * @param  string $format (default: 'html')
	 * @param  string $media (default: 'screen')
	 * @return string
	 */
	public function get_value_entry_detail( $value, $currency = '', $use_text = false, $format = 'html', $media = 'screen' ) {

		$html = '';

		if ( ! empty( $value ) ) {

			$output = array();
			$files  = json_decode( $value, true );

			foreach ( $files as $file ) {

				$file_name = explode( '?dl=', basename( $file ) );
				$file_name = $file_name[0];
				$alt_text  = esc_attr__( 'Click to view', 'gravityforms' );
				$output[]  = 'text' === $format ? $file . PHP_EOL : "<li><a href='{$file}' target='_blank' title='{$alt_text}'>{$file_name}</a></li>";

			}

			$html = join( PHP_EOL, $output );

		}

		$html = empty( $html ) || 'text' === $format ? $html : sprintf( '<ul>%s</ul>', $html );

		return $html;

	}

	/**
	 * Get value for entry list view.
	 *
	 * @access public
	 * @param  string $value
	 * @param  array  $entry
	 * @param  int    $field_id
	 * @param  mixed  $columns
	 * @param  array  $form
	 * @return string
	 */
	public function get_value_entry_list( $value, $entry, $field_id, $columns, $form ) {

		$files = json_decode( $value, true );

		if ( empty( $files ) ) {
			return;
		}

		if ( count( $files ) === 1 ) {

			$file_name = explode( '?dl=', basename( $files[0] ) );
			$file_name = $file_name[0];
			$thumb     = GFEntryList::get_icon_url( $file_name );
			$file_path = esc_attr( $files[0] );

			return "<a href='$file_path' target='_blank' title='" . esc_attr__( 'Click to view', 'gravityforms' ) . "'><img src='$thumb'/></a>";

		}

		return sprintf( esc_html__( '%d files', 'gravityforms' ), count( $files ) );

	}

	/**
	 * Get value for entry export.
	 *
	 * @access public
	 * @param  array  $entry
	 * @param  string $input_id (default: '')
	 * @param  bool   $use_text (default: false)
	 * @param  bool   $is_csv (default: false)
	 * @return string
	 */
	public function get_value_export( $entry, $input_id = '', $use_text = false, $is_csv = false ) {
		if ( empty( $input_id ) ) {
			$input_id = $this->id;
		}

		$value = rgar( $entry, $input_id );

		if ( ! empty( $value ) ) {
			return implode( ' , ', json_decode( $value, true ) );
		}

		return $value;

	}

	/**
	 * Get value for merge tag.
	 *
	 * @access public
	 * @return string $value
	 */
	public function get_value_merge_tag( $value, $input_id, $entry, $form, $modifier, $raw_value, $url_encode, $esc_html, $format, $nl2br ) {

		$files = json_decode( $value, true );

		if ( empty( $files ) ) {
			return '';
		}

		if ( 'html' === $format ) {

			$value  = '<ul>';

			foreach ( $files as $file ) {
				$value .= sprintf( '<li><a href="%s">%s</a></li>', $file, basename( $file ) );
			}

			$value .= '</ul>';

		} else {

			$value = '';

			foreach ( $files as $file ) {
				$value .= $file . "\r\n";
			}

		}

		return $value;

	}

	/**
	 * Validate selected files.
	 *
	 * @access public
	 * @param  string $value Entry value.
	 * @param  array  $form Form object.
	 */
	public function validate( $value, $form ) {

		// Get selected files.
		$file_names = json_decode( $value, true );

		if ( empty( $file_names ) ) {
			return;
		}

		// Get allowed extensions.
		$allowed_extensions = ! empty( $this->allowedExtensions ) ? GFCommon::clean_extensions( explode( ',', strtolower( $this->allowedExtensions ) ) ) : array();

		// Check each file for allowed extensions.
		foreach ( $file_names as $file_name ) {

			// Get file path without extension.
			$file_path = reset( ( explode( '?', $file_name ) ) );

			// If no allowed extensions are set, use default allowed extensions.
			if ( empty( $allowed_extensions ) ) {
				if ( GFCommon::file_name_has_disallowed_extension( $file_path ) ) {
					$this->failed_validation = true;
					$this->validation_message = empty( $this->errorMessage ) ? esc_html__( 'The uploaded file type is not allowed.', 'gravityformsdropbox' ) : $this->errorMessage;
				}
			} else {
				if ( ! GFCommon::match_file_extension( $file_path, $allowed_extensions ) ) {
					$this->failed_validation  = true;
					$this->validation_message = empty( $this->errorMessage ) ? sprintf( esc_html__( 'The uploaded file type is not allowed. Must be one of the following: %s', 'gravityformsdropbox' ), strtolower( $this->allowedExtensions ) ) : $this->errorMessage;
				}
			}

		}

	}

}

GF_Fields::register( new GF_Field_Dropbox() );
