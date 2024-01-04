<?php
/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

require_once _PS_MODULE_DIR_ . 'paygate/classes/PayVault.php';

class PaygateConfirmationModuleFrontController extends ModuleFrontController
{

    /**
     * Function returned to by normal redirect
     * This is always hit
     * @throws \PrestaShopDatabaseException|\PrestaShopException
     */
    public function initContent(): void
    {
        parent::initContent();
        $cart_id = $_GET['cart_id'];
        $this->context->cart = new Cart($cart_id);
        $this->context->cookie->id_cart = $cart_id;
        $cart = $this->context->cart;

        $order = Order::getByCartId($cart->id);

        $status = null;

        // Check to see if there is already an order for this cart - it may have been created by notify (validate.php)
        if ($order && $order->hasBeenPaid()) {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'order-confirmation',
                    null,
                    null,
                    'key=' . $cart->secure_key . '&id_cart=' . (int)($cart_id) . '&id_module=' . (int)($this->module->id)
                )
            );
        }

        $pg_id     = Configuration::get('PAYGATE_ID');
        $pg_key    = Configuration::get('PAYGATE_ENCRYPTION_KEY');
        $sql           = 'SELECT date_time FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int)$cart_id . ';';
        $date         = json_decode(Db::getInstance()->getValue($sql))->date;
        $reference = $cart_id . '_' . explode(' ', $date)[0];

        if (!$this->isResponseValid($reference)) {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'cart',
                    null,
                    null,
                    'action=show'
                )
            );
        }

        $status = $_POST['TRANSACTION_STATUS'];

        if ((int)Configuration::get('PAYGATE_IPN_TOGGLE') === 1) {
            switch ($_POST['TRANSACTION_STATUS']) {
                case '1':
                    // Make POST request to Paygate to query the transaction and get full response data
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
                        $method_name                  = $this->module->displayName;
                        if (!$order) {
                            /** @noinspection PhpUndefinedConstantInspection */
                            $this->module->validateOrder(
                                (int)$cart->id,
                                _PS_OS_PAYMENT_,
                                (float)($result['AMOUNT'] / 100.0),
                                $method_name,
                                null,
                                $extra_vars,
                                null,
                                false,
                                $cart->secure_key
                            );
                        } else {
                            if (!$order->hasBeenPaid()) {
                                $order->addOrderPayment(
                                    (float)($result['AMOUNT'] / 100.0),
                                    $method_name,
                                    $result['USER1']
                                );
                            }
                        }
                        PayVault::storeVault($cart->id_customer, $result);

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
        } elseif ($status === '1') {
            Tools::redirect(
                $this->context->link->getPageLink(
                    'order-confirmation',
                    null,
                    null,
                    'key=' . $cart->secure_key . '&id_cart=' . (int)($cart->id) . '&id_module=' . (int)($this->module->id)
                )
            );
        }

        $this->context->smarty->assign('status', $status);

        $this->setTemplate('module:paygate/views/templates/front/confirmation.tpl');
    }

    /**
     * @param $reference
     *
     * @return bool
     */
    private function isResponseValid($reference): bool
    {
        $key = Tools::getValue('secure_key');
        if ($key != $this->context->cart->secure_key || empty($_POST['TRANSACTION_STATUS'])) {
            return false;
        }
        $post         = $_POST;
        $pg_checksum  = array_pop($post);
        $pg_id        = Configuration::get('PAYGATE_ID');
        $pg_key       = Configuration::get('PAYGATE_ENCRYPTION_KEY');
        $our_checksum = md5($pg_id . implode('', $post) . $reference . $pg_key);

        if (!hash_equals($pg_checksum, $our_checksum)) {
            return false;
        }

        return true;
    }

    /**
     * @param $fieldsString
     *
     * @return array
     */
    private function makeQueryRequest($fieldsString): array
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

        return [];
    }
}
