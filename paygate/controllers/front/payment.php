<?php
/*
 * Copyright (c) 2023 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PaygatePaymentModuleFrontController extends ModuleFrontController
{
    protected $vaultableMethods = ['creditcard'];
    protected $paygatePayMethods = [];

    public function initContent()
    {
        parent::initContent();

        /** @noinspection PhpUndefinedConstantInspection */
        require_once _PS_MODULE_DIR_ . $this->module->name . '/classes/countries.php';
        /** @noinspection PhpUndefinedConstantInspection */
        require_once _PS_MODULE_DIR_ . $this->module->name . '/classes/methods.php';
        /** @noinspection PhpUndefinedConstantInspection */
        require_once _PS_MODULE_DIR_ . $this->module->name . '/paygate.php';

        $iso_code    = $this->context->language->iso_code;
        $cart_id     = $this->context->cart->id;
        $customer_id = $this->context->cart->id_customer;
        $secure_key  = $this->context->cart->secure_key;

        $paygateMethodsList      = new PaygateMethodsList();
        $this->paygatePayMethods = $paygateMethodsList->getPaygateMethodsList();

        // Buyer details
        $customer     = new Customer((int)($customer_id));
        $user_address = new Address(intval($this->context->cart->id_address_invoice));

        // Retrieve country codes
        $country       = new Country();
        $country_code2 = $country->getIsoById($user_address->id_country);
        $countries     = new CountriesArray();
        $country_code3 = $countries->getCountryDetails($country_code2);
        /** @noinspection PhpUndefinedConstantInspection */
        $sql           = 'SELECT cart_total FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int)$cart_id . ';';
        $total         = Db::getInstance()->getValue($sql);
        $data          = array();
        $currency      = new Currency((int)$this->context->cart->id_currency);

        if ($this->context->cart->id_currency != $currency->id) {
            // If paygate currency differs from local currency
            $this->context->cart->id_currency   = (int)$currency->id;
            $this->context->cookie->id_currency = (int)$this->context->cart->id_currency;
            $this->context->cart->update();
        }

        $dateTime                          = new DateTime();
        $time                              = $dateTime->format('YmdHis');
        $this->context->cookie->order_time = $time;
        $this->context->cookie->cart_id    = $cart_id;
        $paygateID                         = filter_var(Configuration::get('PAYGATE_ID'), FILTER_SANITIZE_STRING);
        $reference                         = filter_var(
            $cart_id . '_' . $dateTime->format('Y-m-d'),
            FILTER_SANITIZE_STRING
        );
        $this->context->cookie->reference  = $reference;
        $amount                            = filter_var($total * 100, FILTER_SANITIZE_NUMBER_INT);
        $currency                          = filter_var($currency->iso_code, FILTER_SANITIZE_STRING);
        $returnUrl                         = filter_var(
            $this->context->link->getModuleLink(
                $this->module->name,
                'confirmation',
                ['secure_key' => $secure_key, 'cart_id' => $cart_id],
                true
            ),
            FILTER_SANITIZE_URL
        );
        $transDate                         = filter_var(date('Y-m-d H:i:s'), FILTER_SANITIZE_STRING);
        $locale                            = filter_var($iso_code, FILTER_SANITIZE_STRING);
        $country                           = filter_var($country_code3, FILTER_SANITIZE_STRING);
        $email                             = filter_var($customer->email, FILTER_SANITIZE_EMAIL);
        $notifyUrl                         = filter_var(
            $this->context->link->getModuleLink($this->module->name, 'validate', array(), true),
            FILTER_SANITIZE_STRING
        );
        $userField1                        = $cart_id;
        $userField2                        = $secure_key;
        $userField3                        = 'prestashop-v' . $this->module->version;
        $encryption_key                    = Configuration::get('PAYGATE_ENCRYPTION_KEY');

        $initiateData = array(
            'PAYGATE_ID'        => $paygateID,
            'REFERENCE'         => $reference,
            'AMOUNT'            => $amount,
            'CURRENCY'          => $currency,
            'RETURN_URL'        => urlencode($returnUrl),
            'TRANSACTION_DATE'  => $transDate,
            'LOCALE'            => $locale,
            'COUNTRY'           => $country,
            'EMAIL'             => $email,
            'PAY_METHOD'        => 'CC',
            'PAY_METHOD_DETAIL' => 'Card',
            'NOTIFY_URL'        => $notifyUrl,
            'USER1'             => $userField1,
            'USER2'             => $userField2,
            'USER3'             => $userField3
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

        $checksum_source = '';
        foreach ($initiateData as $initiateDatum) {
            $checksum_source .= $initiateDatum;
        }
        $checksum_source          .= $encryption_key;
        $initiateData['CHECKSUM'] = md5($checksum_source);

        try {
            // Open connection
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_URL, 'https://secure.paygate.co.za/payweb3/initiate.trans');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_NOBODY, false);
            curl_setopt($ch, CURLOPT_REFERER, $_SERVER['HTTP_HOST']);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $initiateData);

            // Execute post
            $result = curl_exec($ch);

            // Close connection
            curl_close($ch);
        } catch (Exception $e) {
            echo $e->getMessage();
        }

        $r = [];
        if (isset($result) && $result !== '') {
            parse_str($result, $r);
            $data['CHECKSUM']       = isset($r['CHECKSUM']) ? $r['CHECKSUM'] : '';
            $data['PAY_REQUEST_ID'] = isset($r['PAY_REQUEST_ID']) ? $r['PAY_REQUEST_ID'] : '';
            $this->context->smarty->assign('data', $data);
            $this->setTemplate('module:paygate/views/templates/front/payment-redirect.tpl');
        }
    }

}
