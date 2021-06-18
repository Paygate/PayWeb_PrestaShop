<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PaygateConfirmationModuleFrontController extends ModuleFrontController
{

    /**
     * Function returned to by normal redirect
     * This is always hit
     */
    public function initContent()
    {
        parent::initContent();
        $cart = new Cart($this->context->cookie->cart_id);

        $order = Order::getByCartId($cart->id);

        $status = null;

        // Check to see if there is already an order for this cart - it may have been created by notify (validate.php)
        if ($order && $order->hasBeenPaid()) {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'order-confirmation',
                    null,
                    null,
                    'key=' . $cart->secure_key . '&id_cart=' . (int)($cart->id) . '&id_module=' . (int)($this->module->id)
                )
            );
        }

        $pg_id     = Configuration::get('PAYGATE_ID');
        $pg_key    = Configuration::get('PAYGATE_ENCRYPTION_KEY');
        $reference = $this->context->cookie->reference;

        if (!$this->isResponseValid($cart)) {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'cart',
                    null,
                    null,
                    'action=show'
                )
            );
        }

        switch ($_POST['TRANSACTION_STATUS']) {
            case '1':
                // Make POST request to PayGate to query the transaction and get full response data
                $post = $_POST;

                $data                   = [];
                $data['PAYGATE_ID']     = $pg_id;
                $data['PAY_REQUEST_ID'] = $post['PAY_REQUEST_ID'];
                $data['REFERENCE']      = $reference;
                $data['CHECKSUM']       = md5(implode('', $data) . $pg_key);
                $fieldsString           = http_build_query($data);

                $result = $this->makeQueryRequest($fieldsString);

                if (!empty($result)) {
                    // Update purchase status
                    $extra_vars['transaction_id'] = $result['USER1'];
                    $method_name = $this->module->displayName;
                        if (!$order) {
                            $this->module->validateOrder(
                                (int)$cart->id,
                                _PS_OS_PAYMENT_,
                                (float)($result['AMOUNT'] / 100.0),
                                $method_name,
                                NULL,
                                $extra_vars,
                                NULL,
                                false,
                                $cart->secure_key
                            );
                        } else {
                        if(!$order->hasBeenPaid()) {
                            $order->addOrderPayment(
                                (float)($result['AMOUNT'] / 100.0),
                                $method_name,
                                $result['USER1'] 
                            );
                        }
                    }
                    Tools::redirect(
                        $this->context->link->getPageLink(
                            'order-confirmation',
                            null,
                            null,
                            'key=' . $cart->secure_key . '&id_cart=' . (int)($cart->id) . '&id_module=' . (int)($this->module->id)
                        )
                    );
                }

                break;

            case '2':
                $status = 2;
                break;

            case '4':
                $status = 4;
                break;

            default:
                break;
        }


        $this->context->smarty->assign('status', $status);

        $this->setTemplate('module:paygate/views/templates/front/confirmation.tpl');
    }

    private function isResponseValid($cart)
    {
        $key = Tools::getValue('secure_key');
        if ($key != $cart->secure_key || empty($_POST['TRANSACTION_STATUS'])) {
            return false;
        }
        $post         = $_POST;
        $pg_checksum  = array_pop($post);
        $reference    = $this->context->cookie->reference;
        $pg_id        = Configuration::get('PAYGATE_ID');
        $pg_key       = Configuration::get('PAYGATE_ENCRYPTION_KEY');
        $our_checksum = md5($pg_id . implode('', $post) . $reference . $pg_key);
        if (!hash_equals($pg_checksum, $our_checksum)) {
            return false;
        }
        return true;
    }

    private function makeQueryRequest($fieldsString)
    {
        // Open connection
        $ch = curl_init();

        // Set the url, number of POST vars, POST data
        curl_setopt($ch, CURLOPT_URL, 'https://secure.paygate.co.za/payweb3/query.trans');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOBODY, false);
        curl_setopt($ch, CURLOPT_REFERER, $_SERVER['HTTP_HOST']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fieldsString);

        // Execute post
        $result = curl_exec($ch);
        parse_str($result, $result);
        curl_close($ch);

        if (is_array($result) && isset($result['TRANSACTION_STATUS'])) {
            return $result;
        }
    }
}
