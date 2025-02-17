<?php
/*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

use Payfast\PayfastCommon\Gateway\Request\PaymentRequest;

require_once __DIR__ . '/../../vendor/autoload.php';
require_once _PS_MODULE_DIR_ . 'paygate/classes/PayVault.php';

class PaygateConfirmationModuleFrontController extends ModuleFrontController
{

    /**
     * Function returned to by normal redirect
     * This is always hit
     * @throws PrestaShopDatabaseException|PrestaShopException
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

                    try {
                        $paymentRequest   = new PaymentRequest($pg_id, $pg_key);
                        $response = $paymentRequest->query($post['PAY_REQUEST_ID'], $reference);
                    } catch (Exceptione $e) {
                        echo 'Error querying transaction: ' . $e->getMessage();
                    }
                    $result = [];
                    if (!empty($response)) {
                        parse_str($response, $result);
                        // Update purchase status
                        $extra_vars['transaction_id'] = $result['USER1'];
                        $method_name                  = $this->module->displayName;
                        if (!$order) {
                            $this->module->validateOrder(
                                (int)$cart->id,
                                _PS_OS_PAYMENT_,
                                $result['AMOUNT'] / 100.0,
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
                                    $result['AMOUNT'] / 100.0,
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

}
