<?php
/*
Plugin Name: UranX - Crypto Payments
Plugin URI: https://uranx.io
Description: UranX - Crypto Payment Plugin allows you to pay and receive through crypto
Version: 1.2.1
Author: UranX
Author URI: https://uranx.io
Text Domain: uranx-crypto-payments
*/

define('UCPP_PLUGIN_BASE_DIR', WP_PLUGIN_URL ."/". plugin_basename(dirname(__FILE__)));
define('UCPP_BASE_SERVER_URL', "https://pay.uranx.io");
define('UCPP_PLUGIN_NAME', 'UranX Crypto Payments');
define('UCPP_APP_NAME', 'UranX Crypto Payments');

function ucpp_plugin_load_textdomain() {
	load_plugin_textdomain( 'uranx-crypto-payments', false, basename( dirname( __FILE__ ) ) . '/languages/' );
}

add_action( 'init', 'ucpp_plugin_load_textdomain' );

function ucpp_woocommerce_gateway_init(){
	if ( !class_exists( 'WC_Payment_Gateway' ) ) return;

	class WC_Gateway_Ucpp extends WC_Payment_Gateway{

		// Constructor
		public function __construct(){
			$this->id = "ucpp";
			$this->icon = UCPP_PLUGIN_BASE_DIR . '/assets/img/logo.png';
			$this->method_title = UCPP_PLUGIN_NAME;
			$this->method_description = __('Pay through Crypto', "uranx-crypto-payments");
			$this->has_fields = false;
			$this->checkout_url = UCPP_BASE_SERVER_URL."/checkout";


			$this->init_form_fields();
			$this->init_settings();

			$this->title = 'Cryptocurrency';
			$this->description 	= $this->get_option('description');
			$this->wallet_address 	= $this->get_option('wallet_address');
			$this->api_key = $this->get_option('api_key');
			// $this->payment_token_wbtc 	= $this->get_option('payment_token_wbtc');

			add_action('init', array(&$this, 'ucpp_get_server_response'));
            add_action('woocommerce_api_'.strtolower("WC_Gateway_Ucpp"), array( $this, 'ucpp_get_server_response' )); 
			add_action( 'woocommerce_thankyou', 'ucpp_get_server_response', 20, 1 );

			if (version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=')) {
				add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) ); 
				add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
			}else{
                add_action( 'woocommerce_update_options_payment_gateways', array( &$this, 'process_admin_options' ) );
                add_action('woocommerce_receipt', array(&$this, 'receipt_page'));
            }

		} // END - Constructor

		// Init Form Fields
		function init_form_fields(){
			$this->form_fields = array(
                'seperator' => array(
                    'title' =>  __('General Settings', "uranx-crypto-payments"),
                    'description' => '',
                    'type' => 'title'
                ),
				'enabled' => array(
					'title' => __('Enable/Disable Plugin', "uranx-crypto-payments"),
					'type' => 'checkbox',
					'label' => __('Enable Plugin', "uranx-crypto-payments"),
					'default' =>'yes'
				),
				'description' => array(
					'title' 			=>  __('Description:', "uranx-crypto-payments"),
					'type' 			=> 'textarea',
					'default' 		=> __('Deposit via your preferred crypto', "uranx-crypto-payments"),
					'description' 	=> __('This controls the description which the user sees during checkout.', "uranx-crypto-payments"),
					'desc_tip' 		=> true
				),
				'api_key' => array(
					'title' 		=> __('Your API Key (<a target="_blank" href="https://admin.uranx.io/register">Click Here </a>to get your API Key)', "uranx-crypto-payments"),
					'type' 			=> 'text',
					'description' 	=>  __('Your API Key', "uranx-crypto-payments"),
					'desc_tip' 		=> true
				),
      			'wallet_address' => array(
					'title' 		=>  __('Your Wallet Address', "uranx-crypto-payments"),
					'type' 			=> 'text',
					'description' 	=> __('Wallet Address to transfer your payments', "uranx-crypto-payments"),
					'desc_tip' 		=> true
				)
			);
		} // END - Init Form Fields

		// Process Payment
		function process_payment( $order_id ) {
		    global $woocommerce;
		    $order = new WC_Order( $order_id );

		    $order->update_status('pending', __('Awaiting Confirmation', "uranx-crypto-payments"));

		    $woocommerce->cart->empty_cart();

		    if ( version_compare( WOOCOMMERCE_VERSION, '2.1.0', '>=' ) ) {
			  	$checkout_payment_url = $order->get_checkout_payment_url( true );
			} else {
				$checkout_payment_url = get_permalink( get_option ( 'woocommerce_pay_page_id' ) );
			}

			return array(
				'result' => 'success', 
				'redirect' => add_query_arg(
					'order-pay', 
					$order->id, 
					add_query_arg(
						'key', 
						$order->order_key, 
						$checkout_payment_url						
					)
				)
			);
		} // END -  Process Payment

		// Reciept Page
		function receipt_page($order){
			echo '<p><strong>'.__('Thank you for your order.', "uranx-crypto-payments").'</strong><br/>'.__('The payment page will open soon.', "uranx-crypto-payments").'</p>';
			echo $this->redirect_to_website_form($order);
		} // END - Reciept Page

		// Redirect to Checkout Form
		function redirect_to_website_form($order_id){
			global $woocommerce;
			$order = new WC_Order( $order_id );
			$order->update_status('pending');
			
			$redirect_url = $order->get_checkout_order_received_url();

			if ( version_compare(WOOCOMMERCE_VERSION, '2.0.0', '>=' ) ) {
				$notify_url = add_query_arg( 'wc-api', get_class( $this ), $redirect_url );
			}

			$form_args = array(
				"amount"=> $order->get_total(),
				"fiat"=> get_woocommerce_currency(),
				"status"=> 0,
				"failed_reason" => "",
				"api_key" => $this->api_key,
				"seller_wallet_address"=> $this->wallet_address,
				"metadata"=> array(
					"platform"=> "woocommerce",
					"platform_invoice_id" => $order_id,
					"redirectUrl"=> $redirect_url
				)
			);

			return '
			<script type="text/javascript">
				jQuery(function(){
					jQuery("body").block({
						message: "'.__('Redirecting you to Payment Gateway to make the payment.', "uranx-crypto-payments").'",
						overlayCSS: {
							background		: "#fff",
							opacity			: 0.8
						},
						css: {
							padding			: 20,
							textAlign		: "center",
							color			: "#333",
							border			: "1px solid #eee",
							backgroundColor	: "#fff",
							cursor			: "wait",
						}
					})
					var payload = '.json_encode($form_args).'
					fetch("'.UCPP_BASE_SERVER_URL."/api/transactions".'", {
						method: "post",
						headers: {
							"content-type": "application/json"
						},
						body: JSON.stringify(payload)
					}).then(response => response.json())
					.then(res => {
						if (res.status && res.id){
							window.location.href = "'.$this->checkout_url.'/"+res.id
						}
					})
					
				})
				</script>
			';
		} // END - Redirect to Checkout Form

	}

	// Get Callback Response
	function ucpp_get_server_response(){

		update_option('webhook_debug', $_GET);
		
		global $woocommerce;
		
		if (isset($_REQUEST['order_id']) && isset($_REQUEST['status_code'])){
			
			$order_id = sanitize_text_field($_REQUEST['order_id']);
			$status_code = sanitize_text_field($_REQUEST['status_code']);
			// $md5sig = sanitize_text_field($_REQUEST['md5sig']);

			if ($order_id == "" || $status_code == ""){
				return false;
			}

			try{
				$order = new WC_Order($order_id);
				
				if ($order->status == "completed"){
					return false;
				}

				if ($status_code == "0"){
					$order->update_status('pending');

				}else if ($status_code == "1"){
					$order->update_status('completed');
				
				}else if ($status_code == "2"){
					$order->update_status('cancelled');
				
				}else if ($status_code == "3"){
					$order->update_status('failed');
				
				}else if ($status_code == "4" || $status_code == "5"){
					$order->update_status('processing');
				}
				
				else{
					$order->update_status('failed');
					$order->add_order_note(UCPP_APP_NAME.' - '.__('Invalid Status Code', "uranx-crypto-payments"));
				}
				
				
			}catch(Exception $e){
                $msg = "Error";
			}

			wp_redirect($order->get_checkout_order_received_url());
			exit;
		}
	} // END - Get Callback Response

	// Add the Gateway to WooCommerce
	function ucpp_woocommerce_add_gateway_gateway($methods) {
		$methods[] = 'WC_Gateway_Ucpp';
		return $methods;
	}// END - Add the Gateway to WooCommerce

	add_filter('woocommerce_payment_gateways', 'ucpp_woocommerce_add_gateway_gateway' );
}

// Custom Order Statuses

// Register Pending Status
function ucpp_register_pending_order_status() {
	$label = "Pending Payment";
    register_post_status( 'wc-pending-payment', array(
        'label'                     => $label,
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( $label.' (%s)', $label.' (%s)' )
    ) );
}

// Register Pending Approval Status
function ucpp_register_pending_approval_order_status() {
	$label = "Pending Bank Slip Approval";
    register_post_status( 'wc-pending-approval', array(
        'label'                     => $label,
        'public'                    => true,
        'exclude_from_search'       => false,
        'show_in_admin_all_list'    => true,
        'show_in_admin_status_list' => true,
        'label_count'               => _n_noop( $label.' (%s)', $label.' (%s)' )
    ) );
}
// END - Custom Order Statuses

// Add Pending Approval Status to list of WC Order statuses
function ucpp_add_custom_order_statuses( $order_statuses ) {
    $new_order_statuses = array();
 
    foreach ( $order_statuses as $key => $status ) {
 
        $new_order_statuses[ $key ] = $status;
 
        if ( 'wc-pending' === $key ) {
        	$new_order_statuses['wc-pending-transaction'] = "Pending Transaction";
        }
    }
 
    return $new_order_statuses;
}

add_filter( 'wc_order_statuses', 'ucpp_add_custom_order_statuses' );

add_action( 'init', 'ucpp_register_pending_order_status');
add_action( 'plugins_loaded', 'ucpp_woocommerce_gateway_init' );