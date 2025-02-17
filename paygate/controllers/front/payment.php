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

class PaygatePaymentModuleFrontController extends ModuleFrontController
{
    protected array $vaultableMethods = ['creditcard'];
    protected array $paygatePayMethods = [];

    /**
     * @throws PrestaShopException
     * @throws PrestaShopDatabaseException
     */
    public function initContent(): void
    {
        parent::initContent();

        require_once _PS_MODULE_DIR_ . $this->module->name . '/classes/countries.php';
        require_once _PS_MODULE_DIR_ . $this->module->name . '/classes/methods.php';
        require_once _PS_MODULE_DIR_ . $this->module->name . '/paygate.php';

        $iso_code    = $this->context->language->iso_code;
        $cart_id     = $this->context->cart->id;
        $customer_id = $this->context->cart->id_customer;
        $secure_key  = $this->context->cart->secure_key;

        $paygateMethodsList      = new PaygateMethodsList();
        $this->paygatePayMethods = $paygateMethodsList->getPaygateMethodsList();

        // Buyer details
        $customer     = new Customer($customer_id);
        $user_address = new Address(intval($this->context->cart->id_address_invoice));

        // Retrieve country codes
        $country       = new Country();
        $country_code2 = $country->getIsoById($user_address->id_country);
        $countries     = new CountriesArray();
        $country_code3 = $countries->getCountryDetails($country_code2);
        $sql           = 'SELECT cart_total FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int)$cart_id . ';';
        $total         = Db::getInstance()->getValue($sql);
        $data          = array();
        $currency      = new Currency($this->context->cart->id_currency);

        if ($this->context->cart->id_currency != $currency->id) {
            // If paygate currency differs from local currency
            $this->context->cart->id_currency   = (int)$currency->id;
            $this->context->cookie->id_currency = $this->context->cart->id_currency;
            $this->context->cart->update();
        }

        $dateTime                          = new DateTime();
        $time                              = $dateTime->format('YmdHis');
        $this->context->cookie->order_time = $time;
        $this->context->cookie->cart_id    = $cart_id;
        $paygateID                         = filter_var(Configuration::get('PAYGATE_ID'), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $reference                         = filter_var(
            $cart_id . '_' . $dateTime->format('Y-m-d'),
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        $this->context->cookie->reference  = $reference;
        $amount                            = filter_var($total * 100, FILTER_SANITIZE_NUMBER_INT);
        $currency                          = filter_var($currency->iso_code, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $returnUrl                         = filter_var(
            $this->context->link->getModuleLink(
                $this->module->name,
                'confirmation',
                ['secure_key' => $secure_key, 'cart_id' => $cart_id],
                true
            ),
            FILTER_SANITIZE_URL
        );
        $transDate                         = filter_var(date('Y-m-d H:i:s'), FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $locale                            = filter_var($iso_code, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $country                           = filter_var($country_code3, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
        $email                             = filter_var($customer->email, FILTER_SANITIZE_EMAIL);
        $notifyUrl                         = filter_var(
            $this->context->link->getModuleLink($this->module->name, 'validate', array(), true),
            FILTER_SANITIZE_FULL_SPECIAL_CHARS
        );
        $userField1                        = $cart_id;
        $userField2                        = $secure_key;
        $userField3                        = 'prestashop-v' . $this->module->version;
        $encryptionKey                    = Configuration::get('PAYGATE_ENCRYPTION_KEY');

        $initiateData = array(
            'REFERENCE'         => $reference,
            'AMOUNT'            => $amount,
            'CURRENCY'          => $currency,
            'RETURN_URL'        => urlencode($returnUrl),
            'TRANSACTION_DATE'  => $transDate,
            'LOCALE'            => $locale,
            'COUNTRY'           => $country,
            'EMAIL'             => $email,
        );

        // Do not add notify return url if it is not enabled
        if (Configuration::get('PAYGATE_IPN_TOGGLE')) {
            unset($initiateData['NOTIFY_URL']);
        }

        if (isset($_POST['paygatePayMethodRadio'])) {
            $payMethod       = $this->paygatePayMethods[$_POST['paygatePayMethodRadio']]['ptype'];
            $payMethodDetail = $this->paygatePayMethods[$_POST['paygatePayMethodRadio']]['ptypedetail'];
            if ($payMethod !== null) {
                $initiateData['PAY_METHOD']        = $payMethod;
                $initiateData['PAY_METHOD_DETAIL'] = $payMethodDetail;
            }
        }

        $initiateData['NOTIFY_URL']   = $notifyUrl;
        $initiateData['USER1']        = $userField1;
        $initiateData['USER2']        = $userField2;
        $initiateData['USER3']        = $userField3;

        if (Configuration::get('PAYGATE_PAY_VAULT') == 1) {
            $initiateData['VAULT'] = 1;
            if (isset($_POST['paygateVaultOption'])) {
                switch ($_POST['paygateVaultOption']) {
                    case 'none':
                        $initiateData['VAULT'] = 0;
                        break;
                    case 'new':
                        $initiateData['VAULT'] = 1;
                        break;
                    default:
                        $initiateData['VAULT']    = 1;
                        $vault                    = PayVault::customerVault($customer_id, $_POST['paygateVaultOption']);
                        $initiateData['VAULT_ID'] = $vault;
                        unset($initiateData['PAY_METHOD_DETAIL']);
                        break;
                }
            }
        }

        $paymentRequest = new PaymentRequest($paygateID, $encryptionKey);

        try {
            $response = $paymentRequest->initiate($initiateData);
        } catch (Exceptione $e) {
            echo 'Error initiating payment: ' . $e->getMessage();
        }

        $parsedResponse = [];
        if ($response !== '') {
            parse_str($response, $parsedResponse);

            $checksum = $parsedResponse['CHECKSUM'];
            $payRequestId = $parsedResponse['PAY_REQUEST_ID'];

            $redirectHTML = $paymentRequest->getRedirectHTML($payRequestId, $checksum);
            $this->context->smarty->assign('redirectHTML', $redirectHTML);
            $this->setTemplate('module:paygate/views/templates/front/payment-redirect.tpl');
        }
    }

}
