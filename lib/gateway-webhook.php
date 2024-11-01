<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class gateway_webhook {

    public object $woocomObj;
    public object $gocardlessObj;
    private WP_REST_Request $request;
    private WP_REST_Response $response;
    private array $responseData;


    public function __construct($woocom, $gocardless) {

        $this->woocomObj = $woocom;
        $this->gocardlessObj = $gocardless;
        $this->response = new WP_REST_Response();
        $this->responseData = [];

        // register endpoint
        add_action( 'rest_api_init', [$this, 'registerEndpoints'] );

    }


    /**
     *  REGISTER ENDPOINTS
     */

    public function registerEndpoints() {

        register_rest_route(
            GCOB_WEBHOOK_NAMESPACE,
            GCOB_WEBHOOK_ROUTE_PAYMENTS,
            [
                'methods' => ['GET', 'POST'],
                'callback' => [$this, 'webhookController'],
                'permission_callback' => [$this, 'verifySignature']
            ]
        );

    }


    /**
     *  WEBHOOK ROUTE HANDLER
     */

    public function webhookController(WP_REST_Request $request) {

        $this->request = $request;
        $requestBody = json_decode($this->request->get_body());
        $events = $requestBody->events;

        // implement handler by type
        foreach ($events as $event) {
            try {
                if ($event->resource_type == 'payments') {
                    $this->instantBankPaymentStatus($event);
                }
                else if ($event->resource_type == 'billing_requests') {
                    $this->billingRequestHandler($event);
                }
            }
            catch (Exception $e) {
                $logger->error($e->getMessage(), array( 'source' => 'GoCardless Gateway' ));
                error_log($e->getMessage());
            }
        }
        
        // return succesful response
        $this->response->set_data($this->responseData);
        $this->response->set_status( 204 );
        http_response_code(204); 
        return $this->response;

    }


    /**
     *  AUTHORIZE
     */

    public function verifySignature(WP_REST_Request $request) {

        $secret = $this->woocomObj->get_option('webhook_secret');
        $calculatedSignature = hash_hmac('sha256', $request->get_body(), $secret);
        $requestSignature = $request->get_header('Webhook-Signature');

        if ($calculatedSignature !== $requestSignature) {
            http_response_code(498);    
            return new WP_Error('invalid token', 'invalid token', ['status' => 498]);
        }
        else {
            return true;
        }

    }


    /**
     * INSTANT BANK PAYMENT STATUS CHANGE
     * 
     * @param object $event
     */
    public function instantBankPaymentStatus($event) {

        $logger = wc_get_logger();
        $orderStatus = '';
        $orderNote = '';
        $responseKey = 'event_' . $event->id;

        // validate request
        if (empty($event->links->payment)) {
            $logger->error('Webhook payment event, paymentID was not in request event: webhookID = ' . $event->id, array( 'source' => 'GoCardless Gateway' ));
            return;
        }

        if (empty($event->action)) {
            return;
        }

        // determine status
        switch ($event->action) {
            case 'confirmed' :
                $orderStatus = 'processing';
                $orderNote   = 'GC payment successful.';
                $this->responseData[$responseKey] = 'updated order to processing';
                break;
            case 'failed' :
                $orderStatus = 'failed';
                $orderNote   = 'GC payment failed.';
                $this->responseData[$responseKey] = 'updated order as failed';
                break;
            case 'cancelled' :
                $orderStatus = 'cancelled';
                $orderNote   = 'GC payment was cancelled by the customer or their bank.';
                $this->responseData[$responseKey] = 'updated order as cancelled';
                break;
        }

        // get order with this payment id and update
        $orders = wc_get_orders([
            'limit'        => -1,
            'orderby'      => 'date',
            'order'        => 'DESC',
            'meta_key'     => 'gc_ob_payment_id',
            'meta_value'   => $event->links->payment
        ]);


        if (!empty($orders)) {
            $order = $orders[0];

            // update order status accordingly / add order note
            if (!empty($orderStatus) ) {
                if ($order->has_status($orderStatus)) {
                    $order->add_order_note($orderNote);
                }
                else {
                    $order->update_status($orderStatus, $orderNote);
                }

                $logger->info('Webhook payment event, order: ' . $order->get_id() . ' status updated to: ' . $orderStatus . ' paymentID = ' . $event->links->payment . ' webhookID = ' . $event->id, array( 'source' => 'GoCardless Gateway' ));
            }
            else {
                $logger->info('Webhook payment event, order: ' . $order->get_id() . ' payment status is updated but order status stays the same. PaymentID = ' . $event->links->payment . ' webhookID = ' . $event->id, array( 'source' => 'GoCardless Gateway' ));
            }
        }
        else {
            $logger->error('Webhook payment event recieved, but no order exists with Payment ID: ' . $event->links->payment, array( 'source' => 'GoCardless Gateway' ));
        }

    }


    /**
     * BILLING REQUEST HANDLER TO ADD PAYMENT ID TO ORDER WHERE MISSING
     * 
     * @param object $event
     */
    public function billingRequestHandler($event) {

        $logger = wc_get_logger();

        // bail if payment id or billing request id isn't present in event
        if (empty($event->links->payment_request_payment) || empty($event->links->billing_request)) {
            return;
        }

        // find order with matching billing request ID that also doesn't have a payment ID
        $order = $this->woocomObj->getOrderByBillingRequest($event->links->billing_request);
 
        if (empty($order)) {
            return;
        }

        // add payment ID to order
        $logger->info('Billing request webhook - adding paymentID to order: ' . $order->get_id() . ' ' . $event->links->payment_request_payment, array( 'source' => 'GoCardless Gateway' )); 
        update_post_meta($order->get_id(), 'gc_ob_payment_id', $event->links->payment_request_payment);

    }
}

?>