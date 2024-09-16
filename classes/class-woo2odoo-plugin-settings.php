<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

require_once 'class-odooclient.php';

/**
 * Woo2Odoo_Plugin_Settings Class
 *
 * Text Domain: woo2odoo-plugin
 * Domain Path: /languages/
 * 
 * @class Woo2Odoo_Plugin_Settings
 * @version	1.0.0
 * @since 1.0.0
 * @package	Woo2Odoo_Plugin
 * @author Jeffikus
 */
final class Woo2Odoo_Plugin_Settings {
	/**
	 * The single instance of Woo2Odoo_Plugin_Admin.
	 * @var 	object
	 * @access  private
	 * @since 	1.0.0
	 */
	private static $instance = null;

	/**
	 * Whether or not a 'select' field is present.
	 * @var     boolean
	 * @access  private
	 * @since   1.0.0
	 */
	private $has_select;

	private $odooclient;

	/**
	 * Main Woo2Odoo_Plugin_Settings Instance
	 *
	 * Ensures only one instance of Woo2Odoo_Plugin_Settings is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @return Main Woo2Odoo_Plugin_Settings instance
	 */
	public static function instance () {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor function.
	 * @access  public
	 * @since   1.0.0
	 */
	public function __construct () {
	}

	/**
	 * Validate the settings.
	 * @access  public
	 * @since   1.0.0
	 * @param   array $input Inputted data.
	 * @param   string $section field section.
	 * @return  array        Validated data.
	 */
	public function validate_settings ( $input, $section ) {
		if ( is_array( $input ) && 0 < count( $input ) ) {
			$fields = $this->get_settings_fields( $section );

			foreach ( $input as $k => $v ) {
				if ( ! isset( $fields[ $k ] ) ) {
					continue;
				}

				// Determine if a method is available for validating this field.
				$method = 'validate_field_' . $fields[ $k ]['type'];

				if ( ! method_exists( $this, $method ) ) {
					if ( true === (bool) apply_filters( 'woo2odoo-plugin_validate_field_' . $fields[ $k ]['type'] . '_use_default', true ) ) {
						$method = 'validate_field_text';
					} else {
						$method = '';
					}
				}

				// If we have an internal method for validation, filter and apply it.
				if ( '' !== $method ) {
					add_filter( 'woo2odoo-plugin_validate_field_' . $fields[ $k ]['type'], array( $this, $method ) );
				}

				$method_output = apply_filters( 'woo2odoo-plugin_validate_field_' . $fields[ $k ]['type'], $v, $fields[ $k ] );

				if ( ! is_wp_error( $method_output ) ) {
					$input[ $k ] = $method_output;
				}
			}
		}
		return $input;
	}

	/**
	 * Validate the given data, assuming it is from a text input field.
	 * @access  public
	 * @since   6.0.0
	 * @return  void
	 */
	public function validate_field_text ( $v ) {
		return (string) wp_kses_post( $v );
	}

	/**
	 * Validate the given data, assuming it is from a textarea field.
	 * @access  public
	 * @since   6.0.0
	 * @return  void
	 */
	public function validate_field_textarea ( $v ) {
		// Allow iframe, object and embed tags in textarea fields.
		$allowed           = wp_kses_allowed_html( 'post' );
		$allowed['iframe'] = array(
			'src'    => true,
			'width'  => true,
			'height' => true,
			'id'     => true,
			'class'  => true,
			'name'   => true,
		);
		$allowed['object'] = array(
			'src'    => true,
			'width'  => true,
			'height' => true,
			'id'     => true,
			'class'  => true,
			'name'   => true,
		);
		$allowed['embed']  = array(
			'src'    => true,
			'width'  => true,
			'height' => true,
			'id'     => true,
			'class'  => true,
			'name'   => true,
		);

		return wp_kses( $v, $allowed );
	}

	/**
	 * Validate the given data, assuming it is from a checkbox input field.
	 * @access public
	 * @since  6.0.0
	 * @param  string $v
	 * @return string
	 */
	public function validate_field_checkbox ( $v ) {
		if ( 'true' !== $v ) {
			return 'false';
		} else {
			return 'true';
		}
	}

	/**
	 * Validate the given data, assuming it is from a URL field.
	 * @access public
	 * @since  6.0.0
	 * @param  string $v
	 * @return string
	 */
	public function validate_field_url ( $v ) {
		return trim( esc_url( $v ) );
	}

	/**
	 * Render a field of a given type.
	 * @access  public
	 * @since   1.0.0
	 * @param   array $args The field parameters.
	 * @return  void
	 */
	public function render_field ( $args ) {
		if ( ! in_array( $args['type'], $this->get_supported_fields(), true ) ) {
			return ''; // Supported field type sanity check.
		}

		// Make sure we have some kind of default, if the key isn't set.
		if ( ! isset( $args['default'] ) ) {
			$args['default'] = '';
		}

		$method = 'render_field_' . $args['type'];

		if ( ! method_exists( $this, $method ) ) {
			$method = 'render_field_text';
		}

		// Construct the key.
		$key = Woo2Odoo_Plugin::instance()->token . '-' . $args['section'] . '[' . $args['id'] . ']';

		echo $this->$method( $key, $args ); /* phpcs:ignore */

		// Output the description, if the current field allows it.
		if ( isset( $args['type'] ) && ! in_array( $args['type'], (array) apply_filters( 'Woo2Odoo_plugin_no_description_fields', array( 'checkbox' ) ), true ) ) {
			if ( isset( $args['description'] ) ) {
				$description = $args['description'];
				if ( in_array( $args['type'], (array) apply_filters( 'Woo2Odoo_plugin_new_line_description_fields', array( 'textarea', 'select' ) ), true ) ) {
					$description = wpautop( $description );
				}
				echo '<p class="description">' . wp_kses_post( $description ) . '</p>';
			}
		}
	}

	/**
	 * Retrieve the settings fields details
	 * @access  public
	 * @since   1.0.0
	 * @return  array        Settings fields.
	 */
	public function get_settings_sections () {
		$settings_sections = array();

		$settings_sections['connection']  = __( 'Connection', 'woo2odoo-plugin' );
		$settings_sections['export'] = __( 'Export', 'woo2odoo-plugin' );
		// Add your new sections below here.
		// Admin tabs will be created for each section.
		// Don't forget to add fields for the section in the get_settings_fields() function below
		return (array) apply_filters( 'Woo2Odoo_plugin_settings_sections', $settings_sections );
	}

	/**
	 * Retrieve the settings fields details
	 * @access  public
	 * @param  string $section field section.
	 * @since   1.0.0
	 * @return  array        Settings fields.
	 */
	public function get_settings_fields ( $section ) {
		$settings_fields = array();
		// Declare the default settings fields.

		switch ( $section ) {
			case 'connection':
				$settings_fields['odoo_url']     = array(
					'name'        => __( 'Odoo URL', 'woo2odoo-plugin' ),
					'type'        => 'text',
					'default'     => '',
					'section'     => 'connection',
					'description' => __( 'URL to Odoo', 'woo2odoo-plugin' ),
				);
				$settings_fields['dbname']    = array(
					'name'        => __( 'Database Name', 'woo2odoo-plugin' ),
					'type'        => 'text',
					'default'     => '',
					'section'     => 'connection',
					'description' => __( 'The Database Name for Odoo', 'woo2odoo-plugin' ),
				);
				$settings_fields['odoo_user'] = array(
					'name'        => __( 'Odoo Username', 'woo2odoo-plugin' ),
					'type'        => 'text',
					'default'     => '',
					'section'     => 'connection',
					'description' => __( 'Odoo User Name', 'woo2odoo-plugin' ),
				);
				$settings_fields['odoo_password'] = array(
					'name'        => __( 'Odoo Password', 'woo2odoo-plugin' ),
					'type'        => 'password',
					'default'     => '',
					'section'     => 'connection',
					'description' => __( 'Odoo password for user', 'woo2odoo-plugin' ),
				);
				$settings_fields['odoo_connected'] = array(
					'name'        => __( 'Connection Ok?', 'woo2odoo-plugin' ),
					'type'        => 'connection',
					'default'     => '',
					'section'     => 'connection',
				);

				break;
			case 'export':
				$settings_fields['export_order'] = array(
					'name'        => __( 'Export Orders', 'woo2odoo-plugin' ),
					'type'        => 'checkbox',
					'default'     => '',
					'section'     => 'export',
					'description' => __( 'Export order on checkout?', 'woo2odoo-plugin' ),
				);
				$settings_fields['export_order_invoice'] = array(
					'name'        => __( 'Export Invoice', 'woo2odoo-plugin' ),
					'type'        => 'checkbox',
					'default'     => '',
					'section'     => 'export',
					'description' => __( 'Export invoice for paid orders?', 'woo2odoo-plugin' ),
				);
				// Get the company list in Odoo and populate the select field
				$settings_fields['export_order_company'] = array(
					'name'        => __( 'Company', 'woo2odoo-plugin' ),
					'type'        => 'select',
					'default'     => '',
					'section'     => 'export',
					'description' => __( 'Select the company to export orders to', 'woo2odoo-plugin' ),
					'options'     => $this->get_companies_select(),
				);
				// Get the journal list in Odoo and populate the select field
				$settings_fields['export_order_journal'] = array(
					'name'        => __( 'Journal', 'woo2odoo-plugin' ),
					'type'        => 'select',
					'default'     => '',
					'section'     => 'export',
					'description' => __( 'Select the journal to export invoices to', 'woo2odoo-plugin' ),
					'options'     => $this->get_journals_select(),
				);
				$settings_fields['export_order_use_journal_zero'] = array(
					'name'        => __( 'Zero invoice journal', 'woo2odoo-plugin' ),
					'type'        => 'checkbox',
					'default'     => '',
					'section'     => 'export',
					'description' => __( 'Export zero ammount invoices to other journal?', 'woo2odoo-plugin' ),
				);
				$settings_fields['export_order_journal_zero'] = array(
					'name'        => __( 'Zero ammount invoices journal', 'woo2odoo-plugin' ),
					'type'        => 'select',
					'default'     => '',
					'section'     => 'export',
					'description' => __( 'Select the journal to export invoices to', 'woo2odoo-plugin' ),
					'options'     => $this->get_journals_select(),
				);
				break;
			default:
				# code...
				break;
		}

		return (array) apply_filters( 'Woo2Odoo_plugin_settings_fields', $settings_fields );
	}

	/**
	 * Render HTML markup for the "text" field type.
	 * @access  protected
	 * @since   6.0.0
	 * @param   string $key  The unique ID of this field.
	 * @param   array $args  Arguments used to construct this field.
	 * @return  string       HTML markup for the field.
	 */
	protected function render_field_text ( $key, $args ) {
		$html = '<input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" size="40" type="text" value="' . esc_attr( $this->get_value( $args['id'], $args['default'], $args['section'] ) ) . '" />' . "\n";
		return $html;
	}

	/**
	 * Render HTML markup for the "password" field type.
	 * @access  protected
	 * @since   6.0.0
	 * @param   string $key  The unique ID of this field.
	 * @param   array $args  Arguments used to construct this field.
	 * @return  string       HTML markup for the field.
	 */
	protected function render_field_password ( $key, $args ) {
		$html = '<input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" size="40" type="password" value="' . esc_attr( $this->get_value( $args['id'], $args['default'], $args['section'] ) ) . '" />' . "\n";
		return $html;
	}

	/**
	 * Render HTML markup for the "radio" field type.
	 * @access  protected
	 * @since   6.0.0
	 * @param   string $key  The unique ID of this field.
	 * @param   array $args  Arguments used to construct this field.
	 * @return  string       HTML markup for the field.
	 */
	protected function render_field_radio ( $key, $args ) {
		$html = '';
		if ( isset( $args['options'] ) && ( 0 < count( (array) $args['options'] ) ) ) {
			$html = '';
			foreach ( $args['options'] as $k => $v ) {
				$html .= '<input type="radio" name="' . esc_attr( $key ) . '" value="' . esc_attr( $k ) . '"' . checked( esc_attr( $this->get_value( $args['id'], $args['default'], $args['section'] ) ), $k, false ) . ' /> ' . esc_html( $v ) . '<br />' . "\n";
			}
		}
		return $html;
	}

	/**
	 * Render HTML markup for the "textarea" field type.
	 * @access  protected
	 * @since   6.0.0
	 * @param   string $key  The unique ID of this field.
	 * @param   array $args  Arguments used to construct this field.
	 * @return  string       HTML markup for the field.
	 */
	protected function render_field_textarea ( $key, $args ) {
		// Explore how best to escape this data, as esc_textarea() strips HTML tags, it seems.
		$html = '<textarea id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" cols="42" rows="5">' . $this->get_value( $args['id'], $args['default'], $args['section'] ) . '</textarea>' . "\n";
		return $html;
	}

	/**
	 * Render HTML markup for the "checkbox" field type.
	 * @access  protected
	 * @since   6.0.0
	 * @param   string $key  The unique ID of this field.
	 * @param   array $args  Arguments used to construct this field.
	 * @return  string       HTML markup for the field.
	 */
	protected function render_field_checkbox ( $key, $args ) {
		$has_description = false;
		$html            = '';
		if ( isset( $args['description'] ) ) {
			$has_description = true;
			$html           .= '<label for="' . esc_attr( $key ) . '">' . "\n";
		}
		$html .= '<input id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '" type="checkbox" value="true"' . checked( esc_attr( $this->get_value( $args['id'], $args['default'], $args['section'] ) ), 'true', false ) . ' />' . "\n";
		if ( $has_description ) {
			$html .= wp_kses_post( $args['description'] ) . '</label>' . "\n";
		}
		return $html;
	}

	/**
	 * Render HTML markup for the "select2" field type.
	 * @access  protected
	 * @since   6.0.0
	 * @param   string $key  The unique ID of this field.
	 * @param   array $args  Arguments used to construct this field.
	 * @return  string       HTML markup for the field.
	 */
	protected function render_field_select ( $key, $args ) {
		$this->has_select = true;

		$html = '';
		if ( isset( $args['options'] ) && ( 0 < count( (array) $args['options'] ) ) ) {
			$html .= '<select id="' . esc_attr( $key ) . '" name="' . esc_attr( $key ) . '">' . "\n";
			foreach ( $args['options'] as $k => $v ) {
				$html .= '<option value="' . esc_attr( $k ) . '"' . selected( esc_attr( $this->get_value( $args['id'], $args['default'], $args['section'] ) ), $k, false ) . '>' . esc_html( $v ) . '</option>' . "\n";
			}
			$html .= '</select>' . "\n";
		}
		return $html;
	}

	/**
	 * Return an array of field types expecting an array value returned.
	 * @access public
	 * @since  1.0.0
	 * @return array
	 */
	public function get_array_field_types () {
		return array();
	}

	/**
	 * Return an array of field types where no label/header is to be displayed.
	 * @access protected
	 * @since  1.0.0
	 * @return array
	 */
	protected function get_no_label_field_types () {
		return array( 'info' );
	}

	/**
	 * Return a filtered array of supported field types.
	 * @access  public
	 * @since   1.0.0
	 * @return  array Supported field type keys.
	 */
	public function get_supported_fields () {
		return (array) apply_filters( 'Woo2Odoo_plugin_supported_fields', array( 'text', 'checkbox', 'radio', 'textarea', 'select', 'password', 'connection' ) );
	}

	/**
	 * Return a value, using a desired retrieval method.
	 * @access  public
	 * @param  string $key option key.
	 * @param  string $default default value.
	 * @param  string $section field section.
	 * @since   1.0.0
	 * @return  mixed Returned value.
	 */
	public function get_value ( $key, $default, $section ) {
		$values = get_option( 'Woo2Odoo-plugin-' . $section, array() );

		if ( is_array( $values ) && isset( $values[ $key ] ) ) {
			$response = $values[ $key ];
		} else {
			$response = $default;
		}

		return $response;
	}

	/**
	 * Return all settings keys.
	 * @access  public
	 * @param  string $section field section.
	 * @since   1.0.0
	 * @return  mixed Returned value.
	 */
	public function get_settings ( $section = '' ) {
		$response = false;

		$sections = array_keys( (array) $this->get_settings_sections() );

		if ( in_array( $section, $sections, true ) ) {
			$sections = array( $section );
		}

		if ( 0 < count( $sections ) ) {
			foreach ( $sections as $k => $v ) {
				$fields = $this->get_settings_fields( $v );
				$values = get_option( 'Woo2Odoo-plugin-' . $v, array() );

				if ( is_array( $fields ) && 0 < count( $fields ) ) {
					foreach ( $fields as $i => $j ) {
						// If we have a value stored, use it.
						if ( isset( $values[ $i ] ) ) {
							$response[ $i ] = $values[ $i ];
						} else {
							// Otherwise, check for a default value. If we have one, use it. Otherwise, return an empty string.
							if ( isset( $fields[ $i ]['default'] ) ) {
								$response[ $i ] = $fields[ $i ]['default'];
							} else {
								$response[ $i ] = '';
							}
						}
					}
				}
			}
		}

		return $response;
	}

	/**
	 * Render HTML markup for the "connection" field type.
	 * Try to check if the client is authenticated.
	 * @access  protected
	 * @since   6.0.0
	 * @param   string $key  The unique ID of this field.
	 * @param   array $args  Arguments used to construct this field.
	 * @return  string       HTML markup for the field.
	 */
	protected function render_field_connection ( $key, $args ) {
		$has_description = false;
		$html            = '';
		if ( isset( $args['description'] ) ) {
			$has_description = true;
			$html           .= '<div for="' . esc_attr( $key ) . '">' . "\n";
		}
		$authenticated = (new OdooClient())->authenticate();
		if ( $authenticated ) {
			$html .= '<span class="dashicons dashicons-yes-alt" style="color:green"></span>' . "\n";
		} else {
			$html .= '<span class="dashicons dashicons-no-alt" style="color:red"></span>' . "\n";
		}
		if ( $has_description ) {
			$html .= wp_kses_post( $args['description'] ) . '</div>' . "\n";
		}
		return $html;
	}

	private function get_companies_select() {
		$companies = (new OdooClient())->search_read('res.company', null,  ['id', 'name'], null, 5);
		//for each company, add to the select
		foreach ($companies as $company) {
			$companies_select[$company->id] = $company->name;
		}

		return $companies_select;
	}

	private function get_journals_select() {
		//Check if there is a value setted for company
		$company = $this->get_value('export_order_company', '', 'export');
		if (empty($company)) {
			return ['' => __('Select a company first', 'woo2odoo-plugin')];
		}

		$journals = (new OdooClient())->search_read('account.journal', [
			[
				'company_id',
				'=',
				(int) $company,
			],
			[
				'type',
				'=',
				'sale',
			],
		],  ['id', 'name'], null, 5);
		//for each journal, add to the select
		foreach ($journals as $journal) {
			$journals_select[$journal->id] = $journal->name;
		}

		return $journals_select;
	}
}
