<?php

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class gateway_gocardless {

    public $successURI;
    public $failedURI;
    public $accessToken;
    public $apiBaseURL;
    public $billingRequestID;
    public $billingRequestFlowID;
    public $paymentCurrency;
    public $paymentAmount;
    public $paymentDescription;
    public $mode;
    public $gcCustomerID;


    public function __construct($accesstoken, $apiBaseUrl, $testmode) {

        $this->accessToken = $accesstoken;
        $this->apiBaseURL = $apiBaseUrl;
        
        if ($testmode !== true) {
            $this->mode = 'live';
        }
        else {
            $this->mode = 'sandbox';
        }

    }


    /**
     * INIT BILLING REQUEST
     * 
     * @return array $response
     */
    public function initBillingRequest() {

        global $woocommerce;
        $response = [];

        // prepare order details
        $this->paymentAmount = round($woocommerce->cart->total * 100, 2);
        $this->paymentCurrency = get_woocommerce_currency();
        $this->paymentDescription = $this->createPaymentDesc();

        $response['paymentAmount'] = $this->paymentAmount;
        $response['currency'] = $this->paymentCurrency;
        $response['paymentDescription'] = $this->paymentDescription;
        $response['mode'] = $this->mode;


        // billing request
        $billingRequestResponse = $this->createBillingRequest();

        if (isset($billingRequestResponse->error)) {
            $response['status'] = 'error';
            $response['error'] = $billingRequestResponse->error;
            die(json_encode($response));
        }
        
        if (isset($billingRequestResponse->billing_requests->id)) {
            $this->billingRequestID = $billingRequestResponse->billing_requests->id;
            $response['billing_request_id'] = $this->billingRequestID;
        }

        if (isset($billingRequestResponse->billing_requests->resources->customer->id)) {
            $this->gcCustomerID = $billingRequestResponse->billing_requests->resources->customer->id;
            $response['customer_id'] = $this->gcCustomerID;
        }

        // billing request flow
        $billingRequestFlowResponse = $this->createBillingRequestFlow();

        if (isset($billingRequestFlowResponse->error)) {
            $response['status'] = 'error';
            $response['error'] = $billingRequestFlowResponse->error;
            die(json_encode($response));
        }
        
        if (isset($billingRequestFlowResponse->billing_request_flows->id)) {
            $this->billingRequestFlowID = $billingRequestFlowResponse->billing_request_flows->id;
            $response['BR_Flow_ID'] = $this->billingRequestFlowID;
        }

        $response['status'] = 'success';

        return $response;
    }


    /**
     * RETRIEVE GC CUSTOMER
     */
    private function retrieveCustomer($customerEmail) {
        
    }


    /**
     * CREATE BILLING REQUEST
     * 
     * @return object $response
     */
    public function createBillingRequest() {
        $params = (object) [];

        // customer exists in GC
        if (!empty($this->gcCustomerID)) {
            $params = (object) [
                'billing_requests' => (object) [
                    'payment_request' => (object) [
                        'currency'    => $this->paymentCurrency,
                        'amount'      => $this->paymentAmount,
                        'description' => $this->paymentDescription
                    ],
                    'links'           => (object) [
                        'customer'    => $this->gcCustomerID
                    ]
                ]
            ];
        }

        // customer doesn't exist in GC
        else {
            $params = (object) [
                'billing_requests' => (object) [
                    'payment_request' => (object) [
                        'currency'    => $this->paymentCurrency,
                        'amount'      => $this->paymentAmount,
                        'description' => $this->paymentDescription
                    ]
                ]
            ];
        }


        $response = $this->postAPIRequest($params, GCOB_BILLING_REQUEST_ENDPOINT);

        return $response;
    }


    /**
     * CREATE BILLING REQUEST FLOW
     * 
     * @return object $response
     */
    public function createBillingRequestFlow() {

        $params = (object) [
            'billing_request_flows' => (object) [
                'redirect_uri' => get_site_url() . GCOB_WC_ORDER_RECIEVED_URL,
                'links' => (object) [
                    'billing_request' => $this->billingRequestID
                ]
            ]
        ];

        $response = $this->postAPIRequest($params, GCOB_BILLING_REQUEST_FLOW_ENDPOINT);

        return $response;
    }


    /**
     * CREATE PAYMENT DESCRIPTION
     * 
     * @return string $paymentDescription
     */
    private function createPaymentDesc() {

        global $woocommerce;
        $paymentDescription = [];

        foreach ( WC()->cart->get_cart() as $key => $val ) {
            $product = $val['data'];
            array_push($paymentDescription, $product->get_name());
        }

        return implode(', ', $paymentDescription);
    }


    /**
     * VERIFY PAYMENT STATUS
     * 
     * @param string payment id
     * @return string payment status
     */
    public function verifyPayment($paymentID) {

        $getURL = $this->apiBaseURL . GCOB_PAYMENTS_ENDPOINT . '/' . $paymentID;
        $response = $this->getAPIRequest($getURL);
        
        $status = '';

        if (isset($response->payments->status)){
            $status = $response->payments->status;
        }

        return $status;
    }


    /**
     * SEND API POST REQUEST
     * 
     * @param object $body
     * @param string $endpoint
     * @return object $responseObj
     */
    private function postAPIRequest($body, $endpoint) {
        $response = '';

        $URI = $this->apiBaseURL . $endpoint;

        $response = wp_remote_post($URI, [
            'method'  => 'POST',
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
                'GoCardless-Version' => GCOB_API_VERSION
            ],
            'body'    => json_encode($body)
        ]);

        if (is_wp_error($response)) {
            error_log('GCOB error: ' . $response->get_error_message());
        }

        $responseObj = json_decode(wp_remote_retrieve_body($response));

        if (isset($responseObj->error)) {
            error_log('GCOB API response error:' . json_encode($responseObj->error));
        }
 
        return $responseObj;
    }


    /**
     * SEND API GET REQUEST
     * 
     * @param string URI
     * @return object $responseObj
     */
    private function getAPIRequest($URI) {

        $response = wp_remote_get($URI, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->accessToken,
                'GoCardless-Version' => GCOB_API_VERSION
            ]
        ]);

        if (is_wp_error($response)) {
            error_log('GCOB error: ' . $response->get_error_message());
        }

        $responseObj = json_decode(wp_remote_retrieve_body($response));

        if (isset($responseObj->error)) {
            error_log('GCOB API response error:' . json_encode($responseObj->error));
        }
 
        return $responseObj;
    }
}

?>