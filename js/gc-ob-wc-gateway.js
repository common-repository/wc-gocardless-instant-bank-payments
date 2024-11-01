(function($) {

    var checkoutSubmitBtn;


    /**
     * DOM READY
     */
    $(document).ready(function() {

        // CHECKOUT SUBMIT BUTTON CLICK
        let checkoutForm  = $( 'form.woocommerce-checkout' );

        $(checkoutForm).on( 'click', ':submit', function(e) {

            checkoutSubmitBtn = this;
            console.log('btn clicked');

            var payment_method = $('.woocommerce-page input[type=radio][name=payment_method]:checked').val();

            if (payment_method == 'gc_ob_wc_gateway') {

                e.preventDefault();
                e.stopPropagation();
 
                // disable btn
                $(checkoutSubmitBtn).prop('disabled', true);
                $(checkoutSubmitBtn).addClass('disabled');
                
                console.log('gcob place order init');
                sendNotice('Submit button clicked');

                initGCFlow();
            }
        });


        // IS CHECKOUT PAGE
        if (gcGateway.is_checkout) {
            console.log('is checkout');
            sendNotice('Reached checkout');
        }

        // IS ORDER RECEIVED PAGE
        if (gcGateway.is_order_recieved) {
            console.log('is order recieved');
            sendNotice('Reached order recieved page');
        }

        // SEND ERRORS TO SERVER
        window.addEventListener('error', (event) => {
            var error = event.type + ' ' + event.message + ' ' + event.filename + ' ' + event.lineno;
            sendError(error);
        });

    });


    /**
     * INIT GOCARDLESS FLOW
     */
    function initGCFlow() {

        // GET ENTERED BILLING EMAIL
        var billingEmail = $('input[name="billing_email"]');

        // TRIGGER SERVER BILLING REQUEST
        var formdata = new FormData();
        formdata.append('action', 'initBillingRequest');
        formdata.append('billing_email', billingEmail);
        formdata.append('security', gcGateway.security)

        var checkoutFields = getFormData($( 'form.checkout' ));
        formdata.append('checkout_fields', JSON.stringify(checkoutFields));

        var requiredFields = getRequiredCheckoutFields(checkoutFields);
        formdata.append('required_fields', JSON.stringify(requiredFields));

        console.log(JSON.stringify(checkoutFields));
        ajaxTriggerBillingRequest(formdata);
    }


    /**
     * AJAX TRIGGER BILLING REQUEST
     * 
     * @param {*} formdata 
     */
    function ajaxTriggerBillingRequest(formdata) {

        $.ajax({
            type: 'POST',
            url: gcGateway.ajax_url,
            contentType: false,
            processData: false,
            data: formdata,
            success: function(data) {
                triggerGCModal(data);
            },
            error: function(data) {
                billingRequestSetupError(data);
            },
            timeout: 4000
        });

    }


    /**
     * TRIGGER GC MODAL
     * 
     * @param {*} response 
     * @returns 
     */
    function triggerGCModal(response) {
        console.log('BR setup response: ' + response);
        var responseObj = JSON.parse(response);
        sendNotice('Billing request, serverside checkout validation success');

        // bail if order creation error

        // BAIL IF MODAL LAUNCH ERRORS
        if (responseObj.status == 'error') {
            console.log('Modal launch error: ' + JSON.stringify(responseObj.error));

            var modalLaunchErrors = [];
            var modalLaunchErrorObjs = responseObj.error.errors;
            modalLaunchErrorObjs.forEach(function(errorObj) {
                modalLaunchErrors.push(errorObj.field + ': ' + errorObj.message);
            });

            displayWoocomErrors(modalLaunchErrors);
            sendError('Modal launch error: ' + JSON.stringify(responseObj.error));
            enableCheckoutBtn();
            return;
        }

        // BAIL IF SERVERSIDE CHECKOUT VALIDATION ERRORS
        if (responseObj.validation_error) {
            console.log('validation error: ' + responseObj.validation_error);
            displayWoocomErrors(responseObj.validation_error);
            sendNotice('Serverside checkout validation errors');
            enableCheckoutBtn();
            return;
        }

        // BAIL IF BILLING REQUEST FLOW AND MODE ARE NOT PRESENT
        if (!responseObj.BR_Flow_ID || !responseObj.mode) {
            console.log('error: server response object does not contain flow ID or mode');
            sendError('error: server response object does not contain flow ID or mode');
            enableCheckoutBtn();
            return;
        }

        // BAIL IF ORDER CREATION ERRORS
        if (responseObj.order_create_error) {
            console.log('Order creation error');
            console.log(responseObj.order_create_error);
            return;
        }

        // NO VALIDATION ISSUES, REMOVE PRE-EXISTING WC ERRORS FROM DOM
        displayWoocomErrors(null);

        // CREATE HANDLER
        const handler = GoCardlessDropin.create({
            billingRequestFlowID: responseObj.BR_Flow_ID,
            environment: responseObj.mode,
            onSuccess: (billingRequest, billingRequestFlow) => {
                paymentFlowComplete(billingRequest, billingRequestFlow);
            },
            onExit: (error, metadata) => {
                paymentFlowExit(error, metadata);
            },
        });

        sendNotice('Opening GC modal');

        // OPEN DROPIN
        handler.open();

    }


    /**
     * PAYMENT FLOW COMPLETE
     * 
     * @param {*} billingRequest 
     * @param {*} billingRequestFlow 
     */
    function paymentFlowComplete(billingRequest, billingRequestFlow) {
        
        // Add to form: customerID, paymentRef, paymentID
        var customerID = billingRequest.resources.customer.id;
        var paymentRef = billingRequest.links.payment_request;
        var paymentID  = billingRequest.links.payment_request_payment;

        var checkoutForm = $('form.checkout');

        if (customerID && paymentRef && paymentID) {
            $(checkoutForm).append('<input type="hidden" name="gc_ob_customer_id" value="' + customerID + '">');
            $(checkoutForm).append('<input type="hidden" name="gc_ob_payment_ref" value="' + paymentRef + '">');
            $(checkoutForm).append('<input type="hidden" name="gc_ob_payment_id" value="' + paymentID + '">');
        }
        else {
            // BAIL IF MISSING: customerID, paymentRef, paymentID
            sendError('CustomerID, PaymentRef, PaymentID not present after flow complete');
            sendError(JSON.stringify(billingRequest));
            console.log('CustomerID, PaymentRef, PaymentID not present after flow complete');
            displayWoocomErrors('Payment flow was completed but there was an issue retrieving your payment reference, you may have been charged. Please contact the merchant.')
            return;
        }

        // submit checkout & re-enable btn incase order fails and returns to checkout
        sendNotice('GC payment flow complete - submitting checkout form');
        enableCheckoutBtn();
        $('form.checkout').submit();

        // redirect to order recieved

    }


    /**
     * PAYMENT WINDOW CLOSED OR ERROR
     * 
     * @param {*} error 
     * @param {*} metadata 
     * @returns 
     */
    function paymentFlowExit(error, metadata) {

        console.log('Payment flow was not completed');
        displayWoocomErrors('Sorry we have not been able to process your payment - please retry');
        sendNotice('Payment flow was not completed');
        $('body').removeClass('using-gc');
        enableCheckoutBtn();

        return;
    }


    /**
     * RE-ENABLE CHECKOUT SUBMIT BTN
     */
    function enableCheckoutBtn() {
        $(checkoutSubmitBtn).prop('disabled', false);
        $(checkoutSubmitBtn).removeClass('disabled');
    }


    /**
     * BILLING REQUEST SETUP ERROR
     * 
     * @param {*} response
     */
    function billingRequestSetupError(response) {
        console.log('ajax error: ' + JSON.stringify(response));
        sendError('Billing request setup error: ' + JSON.stringify(response));
        displayWoocomErrors('There was a problem contacing GoCardless');
    }


    /**
     * DISPLAY WOOCOM VALIDATION ERRORS
     * 
     * @param {*} errors 
     * @returns 
     */
    function displayWoocomErrors(errors) {
        
        var errorUL = $( '.checkout ul.woocommerce-error' );

        if (errorUL.length == 0) {
            $( 'form.checkout').prepend('<ul class="woocommerce-error"></ul>');
        }

        var parent = $('form.checkout ul.woocommerce-error');

        if (errors == null || errors.length < 1) {
            parent.empty();
            return;
        }

        if (Array.isArray(errors)) {
            errors.forEach(function(error) {
                parent.append('<li>' + error + '</li>');
                console.log(error);
            });
        }
        else {
            parent.append('<li>' + errors + '</li>');
            console.log(errors);
        }


    }


    /**
     * GET FORM DATA
     * 
     * @param {*} $form 
     * @returns 
     */
    function getFormData($form) {
        var unindexed_array = $form.serializeArray();
        var indexed_array = {};
    
        $.map(unindexed_array, function(n, i){
            indexed_array[n['name']] = n['value'];
        });
    
        return indexed_array;
    }


    /**
     * GET REQUIRED CHECKOUT FIELDS
     * 
     * @param {*} fields 
     * @returns 
     */
    function getRequiredCheckoutFields(fields) {

        var requiredFields = [];

        Object.keys(fields).forEach(function(key) {
            var parentRow = $('#' + key).closest('p');

            if ($(parentRow).hasClass('validate-required')) {
                requiredFields.push(key);
            }
        });

        return requiredFields;
    }


    /**
     * SEND ERRORS TO SERVER AJAX
     * 
     * @param {string} error
     */
    function sendError(error) {

        if (!gcGateway.front_end_logging) {
            return;
        }

        var errorFormData = new FormData();
        errorFormData.append('action', 'frontendError');
        errorFormData.append('security', gcGateway.security);
        errorFormData.append('error', error);
        
        $.ajax({
            type: 'POST',
            url: gcGateway.ajax_url,
            contentType: false,
            processData: false,
            data: errorFormData,
            success: function(data) {

            },
            error: function(data) {

            }
        });

    }


    /**
     * SEND NOTICES TO SERVER AJAX
     * 
     * @param {string} notice
     */
    function sendNotice(notice) {

        if (!gcGateway.front_end_logging) {
            return;
        }

        var errorFormData = new FormData();
        errorFormData.append('action', 'frontendNotice');
        errorFormData.append('security', gcGateway.security);
        errorFormData.append('notice', notice);
        
        $.ajax({
            type: 'POST',
            url: gcGateway.ajax_url,
            contentType: false,
            processData: false,
            data: errorFormData,
            success: function(data) {

            },
            error: function(data) {

            }
        });

    }


})(jQuery, gcGateway);


