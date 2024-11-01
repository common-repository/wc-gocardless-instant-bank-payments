<?php

/*
 * Plugin Name: Instant Bank Payments via GoCardless for WooCommerce
 * Description: A payment gateway for WooCommerce and GoCardless. Take instant bank payments using open banking technology, payments clear almost instantly. Only available for customers in the UK and Germany.
 * Version: 1.3.2
 * Author: gnar software
 * Author URI: https://www.gnar.co.uk/
 * License: GPLv2 or later
 * Text Domain: wc-gocardless-instant-bank-payments
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'GCOB_PLUGIN_DIR',                     plugin_dir_path( __FILE__ ) );
define( 'GCOB_LIB_DIR',                        plugin_dir_path( __FILE__ ) . '/lib' );
define( 'GCOB_JS_DIR',                         plugin_dir_url( __FILE__ ) . '/js' );
define( 'GCOB_CSS_DIR',                        plugin_dir_url( __FILE__ ) . '/css' );
define( 'GCOB_JS_DROPIN_URI',                 'https://pay.gocardless.com/billing/static/dropin/v2/initialise.js' );
define( 'GCOB_SANDBOX_API_BASE',              'https://api-sandbox.gocardless.com/' );
define( 'GCOB_LIVE_API_BASE',                 'https://api.gocardless.com/' );
define( 'GCOB_BILLING_REQUEST_ENDPOINT',      'billing_requests' );
define( 'GCOB_BILLING_REQUEST_FLOW_ENDPOINT', 'billing_request_flows' );
define( 'GCOB_PAYMENTS_ENDPOINT',             'payments' );
define( 'GCOB_API_VERSION',                   '2015-07-06' );
define( 'GCOB_WC_ORDER_RECIEVED_URL',         '/order-recieved' );
define( 'GCOB_WEBHOOK_NAMESPACE',             'gateway_gc_wc/v1' );
define( 'GCOB_WEBHOOK_ROUTE_PAYMENTS',        'payments' );
define( 'GCOB_PREMIUM_URL',                   'https://www.gnar.co.uk/product/wc-gocardless-instant-bank-payments-plus/' );



class gc_ob_wc_gateway {

    public object $gatewayWoocom;
    public object $gatewayGocardless;


    public function __construct() {

        // INSTANTIATE GATEWAY
        add_action( 'plugins_loaded', [$this, 'instantiateGateway'] );

        // ADD GATEWAY TO GATEWAYS
        add_filter( 'woocommerce_payment_gateways', [$this, 'addGateway']);

        // REGISTER SCRIPTS
        add_action( 'wp_enqueue_scripts', [$this, 'enqueueScripts'] );

        // REGISTER AJAX ACTIONS
        add_action( 'wp_ajax_initBillingRequest', [$this, 'initBillingRequestController'] );
        add_action( 'wp_ajax_nopriv_initBillingRequest', [$this, 'initBillingRequestController'] );
        add_action( 'wp_ajax_frontendNotice', ['wc_front_end_logger', 'frontendNotice'] );
        add_action( 'wp_ajax_nopriv_frontendNotice', ['wc_front_end_logger', 'frontendNotice'] );
        add_action( 'wp_ajax_frontendError', ['wc_front_end_logger', 'frontendError'] );
        add_action( 'wp_ajax_nopriv_frontendError', ['wc_front_end_logger', 'frontendError'] );

        // CHECKOUT FIELD VALIDATION
        add_filter( 'checkout_submitted_pre_gc_flow', ['gateway_woocom', 'gcValidateCheckoutFields'], 10, 3 );

    }


    /**
     * ADD GATEWAY TO WOOCOM GATEWAYS
     * 
     * @param array $gateways
     * @return array $gateways
     */
    public function addGateway($gateways) {
        array_push($gateways, 'gateway_woocom');
        return $gateways;
    }


    /**
     * INSTANTIATE GATEWAY
     */
    public function instantiateGateway() {

        if (!class_exists('WooCommerce')) {
            return;
        }

        include_once( GCOB_LIB_DIR . '/gateway-woocom.php' );
        include_once( GCOB_LIB_DIR . '/gateway-gocardless.php' );
        include_once( GCOB_LIB_DIR . '/gateway-webhook.php' );
        include_once( GCOB_LIB_DIR . '/wc-front-end-logger.php' );

        $this->gatewayWoocom = new gateway_woocom();

        // instantiate gocardless class if gateway is enabled
        if ($this->gatewayWoocom->active) {
            
            // test mode
            if ($this->gatewayWoocom->testMode) {
                $this->gatewayGocardless = new gateway_gocardless(
                    $this->gatewayWoocom->sandboxToken,
                    GCOB_SANDBOX_API_BASE,
                    $this->gatewayWoocom->testMode
                );
            }
    
            // live mode
            else {
                $this->gatewayGocardless = new gateway_gocardless(
                    $this->gatewayWoocom->liveToken,
                    GCOB_LIVE_API_BASE,
                    $this->gatewayWoocom->testMode
                );
            }

            // init webhook
            new gateway_webhook($this->gatewayWoocom, $this->gatewayGocardless);

        }
    }


    /**
     * ENQUEUE & LOCALIZE SCRIPTS
     */
    public function enqueueScripts() {

        wp_enqueue_script( 'gc-dropin', GCOB_JS_DROPIN_URI, array(), '1.0.1' );
        wp_enqueue_script( 'gc-wc-gateway', GCOB_JS_DIR . '/gc-ob-wc-gateway.js', array( 'jquery', 'gc-dropin' ), '1.2.3' );
    
        $gcGatewayVars = [
            'ajax_url'          => admin_url('admin-ajax.php'),
            'basket_url'        => wc_get_cart_url(),
            'checkout_url'      => wc_get_checkout_url(),
            'security'          => wp_create_nonce( 'gc_ob_security_nonce' ),
            'is_checkout'       => is_wc_endpoint_url( 'checkout' ),
            'is_order_recieved' => is_wc_endpoint_url( 'order-received' ),
            'front_end_logging' => $this->gatewayWoocom->frontEndLogging
        ];

        wp_localize_script( 'gc-wc-gateway', 'gcGateway', $gcGatewayVars );
    }


    /**
     * INITIATE AJAX BILLING REQUEST & ORDER CREATION
     */
    public function initBillingRequestController() {

        // authorize
        if (!check_ajax_referer( 'gc_ob_security_nonce', 'security', false )) {
            $logger = wc_get_logger();
            $logger->warning('Unnauthorised ajax request', array( 'source' => 'GoCardless Gateway' ));
            wp_die();
        }

        // checkout fields validation hook
        wc_clear_notices();

        $errorMessages = apply_filters( 'checkout_submitted_pre_gc_flow', $errorMessages = [], $checkoutFields = $_POST['checkout_fields'], $requiredFields = $_POST['required_fields'] );

        if (!empty($errorMessages)) {
            $errorResponse = [
                'validation_error' => $errorMessages
            ];

            die(json_encode($errorResponse));
        }

        // init billing request
        $this->instantiateGateway();
        $response = $this->gatewayGocardless->initBillingRequest();

        // check customer_id is present before continuing
        if (empty($response['customer_id'])) {
            $errorResponse = [
                'order_create_error' => 'Did not create order as did not receive customer ID from GC'
            ];

            die(json_encode($errorResponse));
        }

        // check billing request ID is present before continuing
        if (empty($response['billing_request_id'])) {
            $errorResponse = [
                'order_create_error' => 'Did not create order as did not receive billing request ID from GC'
            ];

            die(json_encode($errorResponse));
        }

        else {
            $gcCustomerID = $response['customer_id'];
            $billingRequestID = $response['billing_request_id'];
        }

        // create order & attach customer id
        $orderCreationResp = gateway_woocom::ajaxCreateOrder($gcCustomerID, $billingRequestID);

        if (is_wp_error($orderCreationResp)) {
            $response['order_create_error'] = $orderCreationResp->get_error_message();
        }
        else {
            $response['order_id'] = $orderCreationResp;
        }

        // return response
        die(json_encode($response));
    }
    
}

new gc_ob_wc_gateway();

?>