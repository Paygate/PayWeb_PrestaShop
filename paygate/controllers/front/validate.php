<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PaygateValidateModuleFrontController extends ModuleFrontController
{

    /**
     * Function returned to by IPN if enabled in configuration
     */
    public function initContent()
    {
        parent::initContent();
        $notify_data = array();

        // Sanitize POST data
        foreach ($_POST as $key => $value) {
            $notify_data[$key] = stripslashes($value);
        }

        $this->module->logData("=========Notify Response: " . date('Y-m-d H:i:s') . "============\n\n");

        if ( ! $this->validateResponse()) {
            // Notify PayGate that information has been received
            die('OK');
        }

        if (empty(Context::getContext()->link)) {
            Context::getContext()->link = new Link();
        }

        // Check status and update order
        $method_name = $this->module->displayName;

        if ($notify_data['PAY_METHOD_DETAIL'] != '') {
            $method_name = 'PayGate';
        }
        switch ($notify_data['TRANSACTION_STATUS']) {
            case '1':
                $cart_id = $notify_data['USER1'];
                $cart    = new Cart($cart_id);
                if ($cart->orderExists()) {
                    $order = Order::getByCartId($cart_id);
                    if ($order && $order->hasBeenPaid()) {
                        exit();
                    }
                }

                // Update the purchase status
                if ( ! $order) {
                    $extra_vars['transaction_id'] = $notify_data['USER1'];
                    $this->module->validateOrder(
                        (int)$cart->id,
                        _PS_OS_PAYMENT_,
                        (float)($notify_data['AMOUNT'] / 100.0),
                        $method_name,
                        null,
                        $extra_vars,
                        null,
                        false,
                        $cart->secure_key
                    );
                } else {
                    $order->addOrderPayment(
                        (float)($notify_data['AMOUNT'] / 100.0),
                        $method_name,
                        $notify_data['USER1']
                    );
                }

                $this->module->logData("Done updating order status\n\n");
                break;

            case '2':
                // Failed status
                break;

            case '4':
                // Cancelled status
                break;

            default:
                // If unknown status, do nothing (safest course of action)
                break;
        }


        // Notify PayGate that information has been received
        die('OK');
    }

    private function validateResponse()
    {
        $post_data      = '';
        $checkSumParams = '';
        $disableIPN     = Configuration::get('PAYGATE_IPN_TOGGLE');
        if ($disableIPN) {
            return false;
        }
        foreach ($_POST as $key => $val) {
            $post_data .= $key . '=' . $val . "\n";

            if ($key == 'PAYGATE_ID') {
                $checkSumParams .= Configuration::get('PAYGATE_ID');
            } else {
                if ($key != 'CHECKSUM') {
                    $checkSumParams .= $val;
                }
            }
        }
        $this->module->logData($post_data);
        $this->module->logData("\n");

        $checkSumParams .= Configuration::get('ENCRYPTION_KEY');
        // Verify security signature
        $checkSumParams = md5($checkSumParams);
        if ( ! hash_equals($checkSumParams, $_POST['CHECKSUM'])) {
            $this->module->logData('Invalid checksum, checksum: ' . $checkSumParams);

            return false;
        }
    }
}
