<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly
}

/**
 * Woo2Odoo_Plugin_Admin Class
 *
 * @class Woo2Odoo_Plugin_Admin
 * @version	1.0.0
 * @since 1.0.0
 * @package	Woo2Odoo_Plugin
 * @author Jeffikus
 */

require_once 'class-woo2odoo-plugin.php';

final class Woo2Odoo_Plugin_Admin {
	/**
	 * Woo2Odoo_Plugin_Admin The single instance of Woo2Odoo_Plugin_Admin.
	 * @var 	object
	 * @access  private
	 * @since 	1.0.0
	 */
	private static $instance = null;

	/**
	 * The string containing the dynamically generated hook token.
	 * @var     string
	 * @access  private
	 * @since   1.0.0
	 */
	private $hook;

	/**
	 * Constructor function.
	 * @access  public
	 * @since   1.0.0
	 */
	public function __construct () {
		// Register the settings with WordPress.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// Register the settings screen within WordPress.
		add_action( 'admin_menu', array( $this, 'register_settings_screen' ) );

		// Handle custom button click
        add_action( 'admin_post_woo2odoo_delete_cache', array( $this, 'handle_delete_cache' ) );

		// Handle custom button click
		add_action( 'admin_post_woo2odoo_sync_order', array( $this, 'handle_sync_order' ) );
	}

	/**
	 * Main Woo2Odoo_Plugin_Admin Instance
	 *
	 * Ensures only one instance of Woo2Odoo_Plugin_Admin is loaded or can be loaded.
	 *
	 * @since 1.0.0
	 * @static
	 * @return Main Woo2Odoo_Plugin_Admin instance
	 */
	public static function instance () {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register the admin screen.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function register_settings_screen () {
		$this->hook = add_submenu_page( 'options-general.php', __( 'Woo2Odoo Plugin Settings', 'woo2odoo-plugin' ), __( 'Woo2Odoo Plugin', 'woo2odoo-plugin' ), 'manage_options', 'woo2odoo-plugin', array( $this, 'settings_screen' ) );
	}

	/**
	 * Output the markup for the settings screen.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function settings_screen () {
		global $title;
		$sections = Woo2Odoo_Plugin::instance()->settings->get_settings_sections();
		$tab      = $this->get_current_tab( $sections );
		//xdebug_break();
		?>
		<div class="wrap woo2odoo-plugin-wrap">
			<?php
				$this->admin_header_html( $sections, $title );
			if ( 'tools' !== $tab ) { ?>
			<form action="options.php" method="post">
				<?php
					settings_fields( 'woo2odoo-plugin-settings-' . $tab );
					do_settings_sections( 'woo2odoo-plugin-' . $tab );
					submit_button( __( 'Save Changes', 'woo2odoo-plugin' ) );
				?>
			</form>
			<?php } else { ?>
			<h2><?php _e( 'Tools', 'woo2odoo-plugin' ); ?></h2>
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php _e('Delete cache?', 'woo2odoo-plugin') ?></th>
						<td><p><?php _e('This is useful if you are experiencing issues with the plugin.', 'woo2odoo-plugin') ?></p>
							<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
								<input type="hidden" name="action" value="woo2odoo_delete_cache">
								<input type="hidden" name="woo2odoo_delete_cache" value="1">
								<?php submit_button( __( 'Delete cache', 'woo2odoo-plugin' ), 'secondary' ); ?>
							</form>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Sync order:', 'woo2odoo-plugin') ?></th>
						<td>
							<form method="post" action="<?php echo admin_url( 'admin-post.php' ); ?>">
								<input type="hidden" name="action" value="woo2odoo_sync_order">
								<input type="hidden" name="woo2odoo_sync_order" value="1">
								<input id="woo2odoo-plugin-tools[order]" name="woo2odoo-plugin-tools[order]" size="40" type="text" value="">
								<?php submit_button( __( 'Sync', 'woo2odoo-plugin' ), 'secondary' ); ?>
							</form>
						</td>
				</tbody>
			</table>
			<?php } ?>
		</div><!--/.wrap-->
		<?php
	}

	/**
	 * Register the settings within the Settings API.
	 * @access  public
	 * @since   1.0.0
	 * @return  void
	 */
	public function register_settings () {
		$sections = Woo2Odoo_Plugin::instance()->settings->get_settings_sections();
		if ( 0 < count( $sections ) ) {
			foreach ( $sections as $k => $v ) {
				register_setting( 'woo2odoo-plugin-settings-' . sanitize_title_with_dashes( $k ), 'woo2odoo-plugin-' . $k, array( $this, 'validate_settings' ) );
				add_settings_section( sanitize_title_with_dashes( $k ), $v, array( $this, 'render_settings' ), 'woo2odoo-plugin-' . $k, $k, $k );
			}
		}
	}

	/**
	 * Render the settings.
	 * @access  public
	 * @param  array $args arguments.
	 * @since   1.0.0
	 * @return  void
	 */
	public function render_settings ( $args ) {
		$token  = $args['id'];
		$fields = Woo2Odoo_Plugin::instance()->settings->get_settings_fields( $token );

		if ( 0 < count( $fields ) ) {
			foreach ( $fields as $k => $v ) {
				$args 		= $v;
				$args['id'] = $k;

				add_settings_field( $k, $v['name'], array( Woo2Odoo_Plugin::instance()->settings, 'render_field' ), 'woo2odoo-plugin-' . $token, $v['section'], $args );
			}
		}
	}

	/**
	 * Validate the settings.
	 * @access  public
	 * @since   1.0.0
	 * @param   array $input Inputted data.
	 * @return  array        Validated data.
	 */
	public function validate_settings ( $input ) {
		$sections = Woo2Odoo_Plugin::instance()->settings->get_settings_sections();
		$tab      = $this->get_current_tab( $sections );
		return Woo2Odoo_Plugin::instance()->settings->validate_settings( $input, $tab );
	}

	/**
	 * Return marked up HTML for the header tag on the settings screen.
	 * @access  public
	 * @since   1.0.0
	 * @param   array  $sections Sections to scan through.
	 * @param   string $title    Title to use, if only one section is present.
	 * @return  string 			 The current tab key.
	 */
	public function get_admin_header_html ( $sections, $title ) {
		$defaults = array(
			'tag'     => 'h2',
			'atts'    => array( 'class' => 'woo2odoo-plugin-wrapper' ),
			'content' => $title,
		);

		$args = $this->get_admin_header_data( $sections, $title );

		$args = wp_parse_args( $args, $defaults );

		$atts = '';
		if ( 0 < count( $args['atts'] ) ) {
			foreach ( $args['atts'] as $k => $v ) {
				$atts .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
			}
		}

		$response = '<' . esc_attr( $args['tag'] ) . $atts . '>' . $args['content'] . '</' . esc_attr( $args['tag'] ) . '>' . "\n";

		return $response;
	}

	/**
	 * Print marked up HTML for the header tag on the settings screen.
	 * @access  public
	 * @since   1.0.0
	 * @param   array  $sections Sections to scan through.
	 * @param   string $title    Title to use, if only one section is present.
	 * @return  string 			 The current tab key.
	 */
	public function admin_header_html ( $sections, $title ) {
		echo $this->get_admin_header_html( $sections, $title ); /* phpcs:ignore */
	}

	/**
	 * Return the current tab key.
	 * @access  private
	 * @since   1.0.0
	 * @param   array  $sections Sections to scan through for a section key.
	 * @return  string 			 The current tab key.
	 */
	private function get_current_tab ( $sections = array() ) {
		$response = key( $sections );

		if ( isset( $_GET['tab'] ) && check_admin_referer( 'woo2odoo_plugin_switch_settings_tab', 'woo2odoo_plugin_switch_settings_tab' ) ) {
			$response = sanitize_title_with_dashes( $_GET['tab'] );
		}

		return $response;
	}

	/**
	 * Return an array of data, used to construct the header tag.
	 * @access  private
	 * @since   1.0.0
	 * @param   array  $sections Sections to scan through.
	 * @param   string $title    Title to use, if only one section is present.
	 * @return  array 			 An array of data with which to mark up the header HTML.
	 */
	private function get_admin_header_data ( $sections, $title ) {
		$response = array(
			'tag'     => 'h2',
			'atts'    => array( 'class' => 'woo2odoo-plugin-wrapper' ),
			'content' => $title,
		);

		if ( is_array( $sections ) && 1 < count( $sections ) ) {
			$response['content']       = '';
			$response['atts']['class'] = 'nav-tab-wrapper';

			$tab = $this->get_current_tab( $sections );

			foreach ( $sections as $key => $value ) {
				$class = 'nav-tab';
				if ( $tab === $key ) {
					$class .= ' nav-tab-active';
				}

				$response['content'] .= '<a href="' . wp_nonce_url( admin_url( 'options-general.php?page=woo2odoo-plugin&tab=' . sanitize_title_with_dashes( $key ) ), 'woo2odoo_plugin_switch_settings_tab', 'woo2odoo_plugin_switch_settings_tab' ) . '" class="' . esc_attr( $class ) . '">' . esc_html( $value ) . '</a>';
			}
		}

		return (array) apply_filters( 'woo2odoo_plugin_get_admin_header_data', $response );
	}

	public function handle_delete_cache() {
        if ( isset( $_POST['woo2odoo_delete_cache'] ) && $_POST['woo2odoo_delete_cache'] == '1' ) {
            $this->delete_cache();
			add_settings_error(
				'woo2odoo_messages',
				'woo2odoo_message',
				__( 'Cache deleted successfully.', 'woo2odoo-plugin' ),
				'updated'
			);
			set_transient('settings_errors', get_settings_errors(), 30);
			wp_redirect( add_query_arg( array( 'settings-updated' => 'true' ), admin_url( 'options-general.php?page=woo2odoo-plugin' ) ) );
			exit;
        }
    }

	public function handle_sync_order() {
        if ( isset( $_POST['woo2odoo_sync_order'] ) && $_POST['woo2odoo_sync_order'] == '1' && isset( $_POST['woo2odoo-plugin-tools']['order'] ) && ! empty( $_POST['woo2odoo-plugin-tools']['order'] ) ) {
			// Pass the order id to the order_sync function.
			$this->order_sync( sanitize_text_field( $_POST['woo2odoo-plugin-tools']['order'] ) );
			add_settings_error(
                'woo2odoo_messages',
                'woo2odoo_message',
                __( 'Order', 'woo2odoo-plugin' ) . ' ' . sanitize_text_field( $_POST['woo2odoo-plugin-tools']['order'] ) . ' ' . __( 'synced successfully.', 'woo2odoo-plugin' ),
                'updated'
            );
            set_transient( 'settings_errors', get_settings_errors(), 30 );
            wp_redirect( add_query_arg( array( 'settings-updated' => 'true' ), admin_url( 'options-general.php?page=woo2odoo-plugin' ) ) );
            exit;
        }
    }

	public function delete_cache() {
		wp_cache_flush_group( 'woo2odoo' );
    }

	public function order_sync($order_id) {
		wc_get_logger()->info('Starting Order Sync', array(
			'order_id' => $order_id
		));
		$odooclient = new OdooClient();
		$odooclient->order_sync($order_id);
		wc_get_logger()->info('Order Sync Complete', array(
			'order_id' => $order_id
		));
	}
}
