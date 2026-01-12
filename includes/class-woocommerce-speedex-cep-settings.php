<?php
if (!defined('ABSPATH'))
    exit;

class WC_Speedex_CEP_Admin_Settings {
	private static $_this;

	public function __construct() {
			self::$_this = $this;	
			
			add_action( 'admin_menu', array( $this, 'speedex_admin_menu') );
		
			add_action( 'admin_init', array( $this, 'register_speedex_settings') );
	}

	function speedex_admin_menu() {
	
		if(!current_user_can('manage_woocommerce'))
			return;
	
		/* add new top level */
		add_menu_page( __( 'Speedex', 'woocommerce-speedex-cep' ), __( 'Speedex', 'woocommerce-speedex-cep' ), 'manage_woocommerce', 'wc-speedex-cep',  array( $this, 'speedex_settings_admin_page' ), plugins_url( '../assets/images/menu-icon.png', __FILE__ ) ); 
		add_submenu_page( 'wc-speedex-cep', __( 'Settings','woocommerce-speedex-cep' ), __( 'Settings','woocommerce-speedex-cep' ), 'manage_woocommerce', 'wc-speedex-cep-settings', array( $this, 'speedex_settings_admin_page' ) );
				
		remove_submenu_page( 'wc-speedex-cep', 'wc-speedex-cep');	
	}
	
	
	function speedex_settings_admin_page() 
	{
		wp_enqueue_style( 'wc_speedex_cep_wc_admin_css', plugins_url( '../assets/css/admin.css' , __FILE__ ) );
		// Assuming tiptip is handled by WooCommerce or provided in assets. 
		// If not found in assets, we might need to check if it's available.
		
		global $woocommerce;
		?>
		<div class="wrap" id="woocommerce-speedex-cep-settings-page">

		<h1> <?php _e('Settings page for Speedex', 'woocommerce-speedex-cep'); ?> </h1><form method="post" action="options.php">
		<?php settings_fields( 'speedex-cep-group' ); 
		do_settings_sections( 'speedex-cep-group' ); ?>
		<table class="form-table">

		<tr valign="top">
		<th scope="row">
		<label><?php _e('Username:','woocommerce-speedex-cep'); echo wc_help_tip( __( 'Username is provided by Speedex.', 'woocommerce-speedex-cep' )); ?></label></th> 
		<td><input type="text" name="username" value="<?php echo get_option('username'); ?>" /></td>
		</tr>

		<tr valign="top">
		<th scope="row"> 
		<label><?php _e('Password:','woocommerce-speedex-cep'); echo wc_help_tip( __( 'Password is provided by Speedex.', 'woocommerce-speedex-cep' )); ?></label></th>
		<td><input type="password" name="password" value="<?php echo get_option('password'); ?>" /></td>
		</tr>
			
		<tr valign="top">
		<th scope="row"> 
		<label> <?php _e('Customer ID:','woocommerce-speedex-cep'); echo wc_help_tip( __( 'Customer ID is provided by Speedex. Default value for test mode is: ΠΕ145031', 'woocommerce-speedex-cep' )); ?> </label></th>
		<td><input type="text" name="customer_id" value="<?php echo get_option('customer_id'); ?>" /></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"> 
		<label><?php _e('Agreement ID:','woocommerce-speedex-cep'); echo wc_help_tip( __( 'Agreement ID is provided by Speedex. Default value for test mode is: 88499', 'woocommerce-speedex-cep' )); ?> </label></th>
		<td><input type="text" name="agreement_id" value="<?php echo get_option('agreement_id'); ?>" /></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"> 
		<label><?php _e('Branch ID:','woocommerce-speedex-cep'); echo wc_help_tip( __( 'Branch ID is provided by Speedex. Default value for test mode is: 1000;0101', 'woocommerce-speedex-cep' )); ?> </label></th>
		<td><input type="text" name="branch_id" value="<?php echo get_option('branch_id'); ?>" /></td>
		</tr>

		<tr valign="top">
		<th scope="row"> <label><?php _e('Test Mode:','woocommerce-speedex-cep'); ?> </label></th>
		<?php $checked = ( (int)get_option ('testmode') == 1 ) ? 'checked="checked"' : ''; ?>
		<td><input type="checkbox" name="testmode"  value="1"  <?php echo $checked; ?>/></td>
		</tr>
		
		<tr valign="top">
		<th scope="row"> <label><?php _e('Αυτόματη ενημέρωση αριθμού αποστολής στο Webexpert Order Tracking:','woocommerce-speedex-cep'); echo wc_help_tip( __( 'Update the "_shipping_tracking_number" field automatically when a voucher is created.', 'woocommerce-speedex-cep' )); ?> </label></th>
		<?php $checked_sync = ( (int)get_option ('sync_tracking') == 1 ) ? 'checked="checked"' : ''; ?>
		<td><input type="checkbox" name="sync_tracking"  value="1"  <?php echo $checked_sync; ?>/></td>
		</tr>

		<?php $woocommerce->shipping->load_shipping_methods(); ?>
		<tr valign="top">
		<th scope="row">
		<label><?php _e('Shipping methods:', 'woocommerce-speedex-cep'); echo wc_help_tip( __( 'Select in which shipping methods voucher creation will be available.', 'woocommerce-speedex-cep' )); ?></label></th>
		<td>
		<?php $options3 = get_option('methods'); ?>
		<select id='methods' name='methods[]' multiple='multiple' style="resize: both; min-width: 300px; min-height: 100px;">
		<?php foreach ($woocommerce->shipping->get_shipping_methods() as $shipping_method) {
			$selected = false;
			if( $options3 && in_array(  $shipping_method->id, $options3 )	) {
				$selected = true;			
			} 
			?>
			<option value='<?php echo $shipping_method->id; ?>' <?php echo selected( $selected, true, false ); ?> > <?php echo $shipping_method->get_method_title();?></option>
		<?php } ?>
		</select>
		</td>
		</tr>
		
		<?php $order_statuses = wc_get_order_statuses(); ?>
		
		<tr valign="top">
		<th scope="row"> 
		<label><?php _e( 'Order statuses for autocreating vouchers:', 'woocommerce-speedex-cep' ); echo wc_help_tip( __( 'Select the order statuses that will create a voucher. Only one voucher can autocreated per order. If more than one are needed you need to create them manually.', 'woocommerce-speedex-cep' )); ?></label></th>
		<td>
		<?php $options_statuses = get_option( 'order-statuses' );?>
		<select id='order-statuses' name='order-statuses[]' multiple='multiple' style="resize: both; overflow:auto; min-width: 300px; min-height: 100px;">
			
		<?php 
		foreach ( $order_statuses as $order_status => $order_status_name ) {
			$order_status = str_replace("wc-", "", $order_status);
			$selected_status = false;
			
			if( $options_statuses && in_array(  $order_status, $options_statuses )	) {
				$selected_status = true;			
			} 
			?>
			<option value='<?php echo $order_status; ?>' <?php echo selected( $selected_status, true, false ); ?> > <?php echo $order_status_name;?></option>
		<?php 
		} 
		?>
		</select>
		</td>
		</tr>	
		
		</table>
		<div class="submit">
			<?php submit_button( '', 'primary', 'submit', false ); ?>
			<button type="button" id="wc_speedex_cep_check_connection" class="button"><?php _e( 'Check Connection', 'woocommerce-speedex-cep' ); ?></button>
			<span id="wc_speedex_cep_connection_status" style="margin-left: 10px; font-weight: bold;"></span>
		</div>
		</form>
		</div>
		<script type="text/javascript">
		jQuery(document).ready(function($) {
			$('#wc_speedex_cep_check_connection').on('click', function() {
				var $button = $(this);
				var $status = $('#wc_speedex_cep_connection_status');
				
				$button.prop('disabled', true).text('<?php _e( 'Checking...', 'woocommerce-speedex-cep' ); ?>');
				$status.text('').css('color', 'inherit');
				
				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'wc_speedex_cep_validate_connection',
						username: $('input[name="username"]').val(),
						password: $('input[name="password"]').val(),
						testmode: $('input[name="testmode"]').is(':checked') ? 1 : 0
					},
					success: function(response) {
						if (response.success) {
							$status.text('<?php _e( 'Connection Successful!', 'woocommerce-speedex-cep' ); ?>').css('color', 'green');
						} else {
							$status.text('<?php _e( 'Connection Failed: ', 'woocommerce-speedex-cep' ); ?>' + (response.data ? response.data.message : '<?php _e( 'Unknown error', 'woocommerce-speedex-cep' ); ?>')).css('color', 'red');
						}
					},
					error: function() {
						$status.text('<?php _e( 'An error occurred during validation.', 'woocommerce-speedex-cep' ); ?>').css('color', 'red');
					},
					complete: function() {
						$button.prop('disabled', false).text('<?php _e( 'Check Connection', 'woocommerce-speedex-cep' ); ?>');
					}
				});
			});
		});
		</script>
	<?php 
	}
	
	function register_speedex_settings() { // whitelist options 
		register_setting( 'speedex-cep-group', 'username' );
		register_setting( 'speedex-cep-group', 'password' );
		register_setting( 'speedex-cep-group', 'customer_id' ); 
		register_setting( 'speedex-cep-group', 'agreement_id' );
		register_setting( 'speedex-cep-group', 'branch_id' ); 	
		register_setting( 'speedex-cep-group', 'testmode' );
		register_setting( 'speedex-cep-group', 'sync_tracking' );
		register_setting( 'speedex-cep-group', 'methods' );
		register_setting( 'speedex-cep-group', 'order-statuses' );
		
	}
	
	function speedex_cep_admin_bar( $wp_admin_bar ) {
		
		//parent
		$wp_admin_bar->add_menu(
			array(
				'id'    => 'speedex-cep',
				'title' => __( 'Speedex', 'woocommerce-speedex-cep'),
				'href'  => admin_url( 'admin.php?page=wc-speedex-cep-settings' ),
			)
		);
		
		// Settings.
		$wp_admin_bar->add_menu(
			array(
				'parent' => 'speedex-cep',
				'id'     => 'speedex-cep-settings',
				'title'  => __( 'Settings', 'woocommerce-speedex-cep' ),
				'href'   => admin_url( 'admin.php?page=wc-speedex-cep-settings' ),
			)
		);

		// Summary pdf.
		$wp_admin_bar->add_menu(
			array(
				'parent' => 'speedex-cep',
				'id'     => 'speedex-cep-bol-summary-pdf',
				'title'  => __( 'Download voucher list', 'woocommerce-speedex-cep' ),
				'href'   => '#',
			)
		);
	}	
}
new WC_Speedex_CEP_Admin_Settings();