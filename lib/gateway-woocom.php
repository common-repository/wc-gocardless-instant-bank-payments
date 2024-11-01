<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class gateway_woocom extends WC_Payment_Gateway {

    public bool $testMode;
    public bool $active = false;
    public bool $frontEndLogging;
    public string $sandboxToken;
    public string $liveToken;
    public string $customerID;
    public string $paymentRef;
    public string $paymentID;
    public string $paymentStatus;

    public function __construct() {

        // define gateway properties
        $this->id = 'gc_ob_wc_gateway';
        $this->icon = '';
        $this->has_fields = false;
        $this->method_title = 'GoCardless Instant Bank Pay';
        $this->method_description = 'Instant bank payments using open banking technology. <br/><br/>Support recurring payments with Instant Bank Pay for WooCommerce via GoCardless Premium Plugin <a href="' . GCOB_PREMIUM_URL . '">available here</a>. <i>(Requires WooCommerce Subscriptions)</i>';

        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            // settings
            $this->init_form_fields();
            $this->init_settings();

            // save settings hook
            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options'] );
        }

        if ($this->get_option('test_mode') == 'yes') {
            $this->testMode = true;
        }
        else {
            $this->testMode = false;
        }

        if ($this->get_option('front_end_logging') == 'yes') {
            $this->frontEndLogging = true;
        }
        else {
            $this->frontEndLogging = false;
        }

        $this->sandboxToken = $this->get_option('sandbox_access_token');
        $this->liveToken = $this->get_option('live_access_token');
        $this->title = $this->get_option('payment_method_title');
        $this->description = $this->get_option('description');
        

        // enable
        if ($this->enabled !== 'no') {
            $this->active = true;
        }
    }


    /**
     *  WOOCOM PAYMENT METHOD SETTING FIELDS
     */
    public function init_form_fields() {

        $webhookURL = get_home_url() . '/wp-json/' . GCOB_WEBHOOK_NAMESPACE . '/' . GCOB_WEBHOOK_ROUTE_PAYMENTS;

        // ENABLE, TITLE, DESCRIPTION, TEST MODE, ACCESS TOKEN

        $this->form_fields = array(
            'enabled' => array(
                'title'   => 'Enable/Disable',
                'type'    => 'checkbox',
                'label'   => 'Enable GoCardless Instant Bank Pay Gateway',
                'default' => 'yes'
            ),
            'payment_method_title' => array(
                'title'   => 'Payment Method Title *',
                'type'    => 'text',
                'default' => 'Instant bank payment',
                'required'=> true
            ),
            'description' => array(
                'title'   => 'Payment Method Description *',
                'type'    => 'text',
                'default' => 'Pay with an instant bank payment',
                'required'=> true
            ),
            'test_mode' => array(
                'title'   => 'Enable Sandbox Mode',
                'type'    => 'checkbox',
                'label'   => 'Turn on test mode',
                'default' => 'no'
            ),
            'front_end_logging' => array(
                'title'   => 'Enable client side error logging',
                'type'    => 'checkbox',
                'label'   => 'Turn on client side error logging (bad for performance / good for sorting issues)',
                'default' => 'no'
            ),
            'sandbox_access_token' => array(
                'title'   => 'Sandbox access token',
                'type'    => 'text'
            ),
            'live_access_token' => array(
                'title'   => 'Live access token *',
                'type'    => 'text',
                'required'=> true
            ),
            'webhook_secret' => array(
                'title'   => 'Webhook secret *',
                'type'    => 'text',
                'required'=> true,
                'description' => 'Generate your webhook secret in the GoCardless Dashboard: <br/><br/> - Give your webhook a meaningful name such as your website address. <br/> - Use this URL: "' . $webhookURL . '".<br/> - Paste the secret generated above.'
            )
        );

    }


    /**
     * WC INVOKED PROCESS PAYMENT METHOD
     * 
     * @param int $order_id
     * @return array $response
     */
    public function process_payment($order_id) {

        global $woocommerce;
        $logger = wc_get_logger();

        $order = new WC_Order($order_id);

        // capture payment ref & ID if present
        $this->customerID = sanitize_text_field($_POST['gc_ob_customer_id']);
        $this->paymentRef = sanitize_text_field($_POST['gc_ob_payment_ref']);
        $this->paymentID  = sanitize_text_field($_POST['gc_ob_payment_id']);

        update_post_meta( $order_id, 'gc_ob_payment_ref', $this->paymentRef );
        update_post_meta( $order_id, 'gc_ob_payment_id', $this->paymentID );
        
        // get payment status
        $this->paymentStatus = $this->verifyPayment();

        error_log('GoCardless processing payment for orderid ' . $order_id);
        error_log($this->paymentStatus);

        // Bail if payment status is failed
        if ($this->paymentStatus == 'failed') {
            $logger->info('GC payment was declined during checkout flow -> order: ' . $order_id, array( 'source' => 'GoCardless Gateway' ));
            $order->update_status('failed', 'GC Payment was declined by the customers bank');
            wc_add_notice( __('GoCardless payment error: payment was declined by your bank', 'woothemes'), 'error' );
            return;
        }

        // set order status if it's confirmed
        else if ($this->paymentStatus == 'confirmed') {
            $orderNote = 'GoCardless Payment Succesful: CustomerID - ' . $this->customerID . ' PaymentRef - ' . $this->paymentRef . ' PaymentID - ' . $this->paymentID;
            $logger->info('GC payment was confirmed during checkout flow -> order: ' . $order_id, array( 'source' => 'GoCardless Gateway' ));
            $order->update_status('processing', $orderNote);
        }

        // else Customer bank authorised / awaiting payment
        else {
            $orderNote = 'GoCardless Instant bank payment authorised (awaiting payment): CustomerID - ' . $this->customerID . ' PaymentRef - ' . $this->paymentRef . ' PaymentID - ' . $this->paymentID;
            $logger->info('GC payment was successful but payment is still pending at checkout completion -> order: ' . $order_id, array( 'source' => 'GoCardless Gateway' ));
            if ($order->has_status('pending')) {
                $order->add_order_note($orderNote);
            }
            else {
                $order->update_status('pending', $orderNote);
            }
        }

        // Empty cart
        $woocommerce->cart->empty_cart();

        // unset order awaiting payment from session to make way for further orders
        WC()->session->__unset( 'order_awaiting_payment' );

        // Return thankyou redirect
        return [
            'result'   => 'success',
            'redirect' => $this->get_return_url($order)
        ];

    }


    /**
     * VERIFY PAYMENT WITH GOCARDLESS
     */
    public function verifyPayment() {

        /**
         *  Instantiate gocardless class as this is accessed 
         *  by ajax and is out of context otherwise
         */ 

        $gatewayGocardless = (object) [];

        if ($this->testMode) {
            $gatewayGocardless = new gateway_gocardless(
                $this->sandboxToken,
                GCOB_SANDBOX_API_BASE,
                $this->testMode
            );
        }
        else {
            $gatewayGocardless = new gateway_gocardless(
                $this->liveToken,
                GCOB_LIVE_API_BASE,
                $this->testMode,
                $this->reuseCustomers
            );
        }

        $paymentStatus = $gatewayGocardless->verifyPayment($this->paymentID);

        return $paymentStatus;

    }


    /**
     *  CHECKOUT FIELD VALIDATION
     */
    public static function gcValidateCheckoutFields($errors, $checkoutFields, $requiredFields) {

        $checkoutFields = json_decode(stripslashes($checkoutFields), true);
        $requiredFields = json_decode(stripslashes($requiredFields));

        foreach ($requiredFields as $requiredField) {

            // don't require shipping fields if shipping to same address
            if (!isset($checkoutFields['ship_to_different_address'])) {
                if (strpos($requiredField, 'shipping_') !== false) {
                    continue;
                }
            }

            // add error if required field is empty
            if (empty($checkoutFields[$requiredField])) {
                array_push($errors, '<strong>' . $requiredField . '</strong> is a required field' );
            }

        }

        return $errors;
    }


    /**
     * AJAX CREATE ORDER
     * 
     * @param  string $gcCustomerID
     * @param  int $billingRequestID
     * @return int|WP_ERROR $orderID
     */
    public static function ajaxCreateOrder($gcCustomerID, $billingRequestID) {

        $logger = wc_get_logger();

        $checkout = new WC_Checkout();
        $checkoutData = json_decode(stripslashes($_POST['checkout_fields']), true);

        // fill shipping address from billing if required
        if (empty($checkoutData['ship_to_different_address'])) {
            foreach ($checkoutData as $key => $value) {
                if (strpos($key, 'billing_') !== false) {
                    $shippingFieldKey = str_replace('billing_', 'shipping_', $key);
                    $checkoutData[$shippingFieldKey] = $value;
                    WC()->customer->set_props([$shippingFieldKey => $value]);
                }
            }
        }

        WC()->customer->save();
		WC()->cart->calculate_shipping();

        WC()->session->set('chosen_payment_method', 'gc_ob_wc_gateway');

        $orderID = $checkout->create_order($checkoutData);

        /**
         * Check for order creation error - bail
         */
        if (is_wp_error($orderID)) {
            $logger->error('Error creating order', ['source' => 'GoCardless Gateway']);
            return $orderID;
        }

        /**
         * Else order created - set order_awaiting_payment session var to 
         * avoid subsequent duplicate orders being created, and return orderID
         */
        else {
            // order created
            $response['order_id'] = $orderID;

            WC()->session->set( 'order_awaiting_payment', $orderID );

            // add GC customer ID & BR ID to order data
            update_post_meta( $orderID, 'gc_ob_customer_id', $gcCustomerID );
            update_post_meta( $orderID, 'gc_ob_billing_request_id', $billingRequestID );

            $logger->notice('Created order ' . $orderID . ' with ' . $gcCustomerID . ' ' . $billingRequestID, ['source' => 'GoCardless Gateway']);
        }
    }


    /**
     * GET THE FIRST ORDER WITH BILLING REQUEST ID WITHOUT A PAYMENT ID
     * 
     * @param string $billingRequestID
     * @return WC_Order|null $order
     */
    public function getOrderByBillingRequest($billingRequestID) {

        $orders = wc_get_orders([
            'limit'        => -1,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'meta_key'     => 'gc_ob_billing_request_id',
            'meta_value'   => $billingRequestID
        ]);

        if (!empty($orders)) {
            foreach ($orders as $order) {
                $paymentID = get_post_meta($order->get_id(), 'gc_ob_payment_id');

                if (empty($paymentID)) {
                    return $order;
                }
            }
        }

        return;
    }

}

?>