<?php
/*
 * Copyright (c) 2023 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if ( ! defined('_PS_VERSION_')) {
    exit;
}


class Paygate extends PaymentModule
{

    const PAYGATE_ADMIN = 'Modules.Paygate.Admin';
    protected $vaultableMethods = ['creditcard'];
    protected $paygatePayMethods = [];
    private $_postErrors = array();

    public function __construct()
    {
        /** @noinspection PhpUndefinedConstantInspection */
        require_once _PS_MODULE_DIR_ . 'paygate/classes/methods.php';
        $this->name        = 'paygate';
        $this->tab         = 'payments_gateways';
        $this->version     = '1.8.2';
        $this->author      = 'Paygate';
        $this->controllers = array('payment', 'validation');

        $paygateMethodsList      = new PaygateMethodsList();
        $this->paygatePayMethods = $paygateMethodsList->getPaygateMethodsList();

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName            = $this->trans('Paygate', array(), self::PAYGATE_ADMIN);
        $this->description            = $this->trans('Accept payments via Paygate.', array(), self::PAYGATE_ADMIN);
        $this->confirmUninstall       = $this->trans(
            'Are you sure you want to delete your details ?',
            array(),
            self::PAYGATE_ADMIN
        );
        /** @noinspection PhpUndefinedConstantInspection */
        $this->ps_versions_compliancy = array('min' => '1.7.1.0', 'max' => _PS_VERSION_);
    }

    public function install()
    {
        /** @noinspection PhpUndefinedConstantInspection */
        /** @noinspection PhpUndefinedConstantInspection */
        Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paygate` (
                                `cart_id` INT NOT NULL,
                                `delivery_option_list` MEDIUMTEXT NULL,
                                `package_list` MEDIUMTEXT NULL,
                                `cart_delivery_option` MEDIUMTEXT NULL,
                                `totals` MEDIUMTEXT NULL,
                                `cart_total` DOUBLE NULL,
                                `date_time` VARCHAR(200) NULL
                                ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8;
            '
        );

        return parent::install()
               && $this->registerHook('paymentOptions')
               && $this->registerHook('paymentReturn');
    }

    public function uninstall()
    {
        /** @noinspection PhpUndefinedConstantInspection */
        Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paygate`');

        return (parent::uninstall());
    }

    /**
     * @param $params
     *
     * @return array|PaymentOption[]
     */
    public function hookPaymentOptions($params)
    {
        if ( ! $this->active) {
            return [];
        }

        $this->updateOrAddToTable($params);
        $this->clearOldOrders();

        // Get and display Pay Methods set in configuration
        $action         = $this->context->link->getModuleLink($this->name, 'payment', [], true);
        $payOptionsHtml = <<<HTML
<form method="post" action="$action">
<p>Make Payment Via Paygate</p>
HTML;
        $pt             = 0;
        foreach ($this->paygatePayMethods as $key => $paygatePayMethod) {
            $k = 'PAYGATE_PAYMENT_METHODS_' . $key;
            if (Configuration::get($k) != '') {
                $pt++;
            }
        }

        if ($pt > 0) {
            $payOptionsHtml .= <<<HTML
<p>Choose a Paygate Payment Method below:</p>
HTML;
        }
        $i = 0;
        foreach ($this->paygatePayMethods as $key => $paygatePayMethod) {
            $k = 'PAYGATE_PAYMENT_METHODS_' . $key;
            $checked = $i === 0 ? ' checked' : '';
            $i++;
            if (Configuration::get($k) != '') {
                $payOptionsHtml .= <<<HTML
<p><input type="radio" value="$key" name="paygatePayMethodRadio" {{ $checked }}>
{$paygatePayMethod['label']}
<img src="{$paygatePayMethod['img']}" alt="{$paygatePayMethod['label']}" height="15px;"></p>
HTML;
            }
        }

        $inputs = [];
        foreach ($this->paygatePayMethods as $key => $paygatePayMethod) {
            $k = 'PAYGATE_PAYMENT_METHODS_' . $key;
            if (Configuration::get($k) != '') {
                $inputs[$key] = $paygatePayMethod;
            }
        }

        $payOptionsHtml .= '</form>';

        $paymentOption = new PaymentOption();
        /** @noinspection PhpUndefinedConstantInspection */
        $paymentOption->setCallToActionText('Pay via Paygate')
                      ->setForm($payOptionsHtml)
                      ->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/logo.png'))
                      ->setAction($this->context->link->getModuleLink($this->name, 'payment', [], true));


        return [$paymentOption];
    }

    public function clearOldOrders()
    {
        /** @noinspection PhpUndefinedConstantInspection */
        $sql     = 'SELECT `cart_id` FROM ' . _DB_PREFIX_ . 'paygate;';
        $results = Db::getInstance()->ExecuteS($sql);
        foreach ($results as $id) {
            foreach ($id as $cartID) {
                $check_cart = new cart($cartID);
                if ($check_cart->orderExists()) {
                    Db::getInstance()->delete('paygate', 'cart_id =' . $cartID);
                }
                unset($check_cart);
            }
        }

        /** @noinspection PhpUndefinedConstantInspection */
        $sql2     = 'SELECT `cart_id`,`date_time` FROM ' . _DB_PREFIX_ . 'paygate;';
        $results2 = Db::getInstance()->ExecuteS($sql2);
        foreach ($results2 as $cart) {
            $json_last_Updated         = $cart['date_time'];
            $json_decoded_last_Updated = json_decode($json_last_Updated);
            $last_Updated              = new DateTime($json_decoded_last_Updated->date);
            $now                       = new DateTime();
            $diff                      = $last_Updated->diff($now);
            if ($diff->h >= 5 || $diff->d > 0 || $diff->m > 0 || $diff->y > 0) {
                Db::getInstance()->delete('paygate', 'cart_id =' . $cart['cart_id']);
            }
            unset($last_Updated);
            unset($now);
        }
    }

    public function updateOrAddToTable($params)
    {
        global $cookie;
        $cart = $params['cart'];
        $cart_id                   = $cart->id;
        $cart_total                = $cart->getOrderTotal();
        $cart_delivery_option      = $cart->getDeliveryOption();
        $delivery_option_list      = $cart->getDeliveryOptionList();
        $package_list              = $cart->getPackageList();
        $json_delivery_option_list = json_encode($delivery_option_list, JSON_NUMERIC_CHECK);
        $json_package_list         = json_encode($package_list, JSON_NUMERIC_CHECK);
        $json_cart_delivery_option = json_encode($cart_delivery_option, JSON_NUMERIC_CHECK);

        foreach ($cart_delivery_option as $id_address => $key_carriers) {
            foreach ($delivery_option_list[$id_address][$key_carriers]['carrier_list'] as $id_carrier => $data) {
                foreach ($data['package_list'] as $id_package) {
                    // Rewrite the id_warehouse
                    $package_list[$id_address][$id_package]['id_warehouse'] = (int)$this->context->cart->getPackageIdWarehouse(
                        $package_list[$id_address][$id_package],
                        (int)$id_carrier
                    );
                    $package_list[$id_address][$id_package]['id_carrier']   = $id_carrier;
                }
            }
        }
        foreach ($package_list as $id_address => $packageByAddress) {
            foreach ($packageByAddress as $id_package => $package) {
                $product_list = $package['product_list'];
                $carrierId    = isset($package['id_carrier']) ? $package['id_carrier'] : null;
                /** @noinspection PhpUndefinedConstantInspection */
                /** @noinspection PhpUndefinedConstantInspection */
                /** @noinspection PhpUndefinedConstantInspection */
                $totals       = array(
                    "total_products"           => (float)$cart->getOrderTotal(
                        false,
                        Cart::ONLY_PRODUCTS,
                        $product_list,
                        $carrierId
                    ),
                    'total_products_wt'        => (float)$cart->getOrderTotal(
                        true,
                        Cart::ONLY_PRODUCTS,
                        $product_list,
                        $carrierId
                    ),
                    'total_discounts_tax_excl' => (float)abs(
                        $cart->getOrderTotal(false, Cart::ONLY_DISCOUNTS, $product_list, $carrierId)
                    ),
                    'total_discounts_tax_incl' => (float)abs(
                        $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $product_list, $carrierId)
                    ),
                    'total_discounts'          => (float)abs(
                        $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS, $product_list, $carrierId)
                    ),
                    'total_shipping_tax_excl'  => (float)$cart->getPackageShippingCost(
                        $carrierId,
                        false,
                        null,
                        $product_list
                    ),
                    'total_shipping_tax_incl'  => (float)$cart->getPackageShippingCost(
                        $carrierId,
                        true,
                        null,
                        $product_list
                    ),
                    'total_shipping'           => (float)$cart->getPackageShippingCost(
                        $carrierId,
                        true,
                        null,
                        $product_list
                    ),
                    'total_wrapping_tax_excl'  => (float)abs(
                        $cart->getOrderTotal(false, Cart::ONLY_WRAPPING, $product_list, $carrierId)
                    ),
                    'total_wrapping_tax_incl'  => (float)abs(
                        $cart->getOrderTotal(true, Cart::ONLY_WRAPPING, $product_list, $carrierId)
                    ),
                    'total_wrapping'           => (float)abs(
                        $cart->getOrderTotal(true, Cart::ONLY_WRAPPING, $product_list, $carrierId)
                    ),
                    'total_paid_tax_excl'      => (float)Tools::ps_round(
                        (float)$cart->getOrderTotal(false, Cart::BOTH, $product_list, $carrierId),
                        _PS_PRICE_COMPUTE_PRECISION_
                    ),
                    'total_paid_tax_incl'      => (float)Tools::ps_round(
                        (float)$cart->getOrderTotal(true, Cart::BOTH, $product_list, $carrierId),
                        _PS_PRICE_COMPUTE_PRECISION_
                    ),
                    '$total_paid'              => (float)Tools::ps_round(
                        (float)$cart->getOrderTotal(true, Cart::BOTH, $product_list, $carrierId),
                        _PS_PRICE_COMPUTE_PRECISION_
                    ),
                );
            }
        }

        /** @noinspection PhpUndefinedVariableInspection */
        $total = json_encode($totals, JSON_NUMERIC_CHECK);

        /** @noinspection PhpUndefinedConstantInspection */
        $check_if_row_exists = Db::getInstance()->getValue(
            'SELECT cart_id FROM ' . _DB_PREFIX_ . 'paygate WHERE cart_id="' . (int)$cart_id . '"'
        );
        if ($check_if_row_exists == '') {
            Db::getInstance()->insert(
                'paygate',
                array(
                    'cart_id'              => (int)$cart_id,
                    'delivery_option_list' => pSQL($json_delivery_option_list),
                    'package_list'         => pSQL($json_package_list),
                    'cart_delivery_option' => pSQL($json_cart_delivery_option),
                    'cart_total'           => (double)$cart_total,
                    'totals'               => pSQL($total),
                    'date_time'            => pSQL(json_encode(new DateTime())),

                )
            );
        } else {
            Db::getInstance()->update(
                'paygate',
                array(
                    'delivery_option_list' => pSQL($json_delivery_option_list),
                    'package_list'         => pSQL($json_package_list),
                    'cart_delivery_option' => pSQL($json_cart_delivery_option),
                    'cart_total'           => (double)$cart_total,
                    'totals'               => pSQL($total),
                    'date_time'            => pSQL(json_encode(new DateTime())),
                ),
                'cart_id = ' . (int)$cart_id
            );
        }
    }

    public function getContent()
    {
        $this->_html = '';

        if (Tools::isSubmit('btnSubmit')) {
            $this->_postValidation();
            if ( ! count($this->_postErrors)) {
                $this->_postProcess();
            } else {
                foreach ($this->_postErrors as $err) {
                    $this->_html .= $this->displayError($err);
                }
            }
        }

        $this->_html .= $this->renderForm();

        return $this->_html;
    }

    public function renderForm()
    {
        $fields_form = array(
            'form' => array(
                'legend' => array(
                    'title' => $this->trans('Settings', array(), self::PAYGATE_ADMIN),
                    'icon'  => 'icon-envelope',
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans('Paygate ID', array(), self::PAYGATE_ADMIN),
                        'name'     => 'PAYGATE_ID',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans('Encryption Key', array(), self::PAYGATE_ADMIN),
                        'name'     => 'PAYGATE_ENCRYPTION_KEY',
                        'required' => true,
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->trans('Disable IPN', array(), self::PAYGATE_ADMIN),
                        'name'   => 'PAYGATE_IPN_TOGGLE',
                        'values' => array(
                            array(
                                'id'    => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('IPN', array(), self::PAYGATE_ADMIN),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('Redirect', array(), self::PAYGATE_ADMIN),
                            ),
                        ),
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->trans('Debug', array(), self::PAYGATE_ADMIN),
                        'name'   => 'PAYGATE_LOGS',
                        'values' => array(
                            array(
                                'id'    => 'active_on',
                                'value' => 1,
                                'label' => $this->trans('Yes', array(), self::PAYGATE_ADMIN),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => 0,
                                'label' => $this->trans('No', array(), self::PAYGATE_ADMIN),
                            ),
                        ),
                    ),
                    array(
                        'type'   => 'checkbox',
                        'label'  => $this->trans('Enable Payment Method(s)', array(), self::PAYGATE_ADMIN),
                        'name'   => 'PAYGATE_PAYMENT_METHODS',
                        'values' => array(
                            'query' => array(
                                array(
                                    'id'   => 'creditcard',
                                    'name' => 'Credit Cards<img src="../modules/paygate/assets/images/mastercard-visa.svg" alt="Credit Cards" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'creditcard',
                                ),
                                array(
                                    'id'   => 'banktransfer',
                                    'name' => 'Bank Transfer<img src="../modules/paygate/assets/images/sid.svg" alt="Bank Transfer" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'banktransfer',
                                ),
                                array(
                                    'id'   => 'zapper',
                                    'name' => 'Zapper<img src="../modules/paygate/assets/images/zapper.svg" alt="Zapper" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'zapper',
                                ),
                                array(
                                    'id'   => 'snapscan',
                                    'name' => 'SnapScan<img src="../modules/paygate/assets/images/snapscan.svg" alt="SnapScan" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'snapscan',
                                ),
                                array(
                                    'id'   => 'paypal',
                                    'name' => 'PayPal<img src="../modules/paygate/assets/images/paypal.svg" alt="PayPal" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'paypal',
                                ),
                                array(
                                    'id'   => 'mobicred',
                                    'name' => 'MobiCred<img src="../modules/paygate/assets/images/mobicred.svg" alt="MobiCred" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'mobicred',
                                ),
                                array(
                                    'id'   => 'momopay',
                                    'name' => 'MomoPay<img src="../modules/paygate/assets/images/momopay.svg" alt="MomoPay" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'momopay',
                                ),
                                array(
                                    'id'   => 'scantopay',
                                    'name' => 'ScanToPay<img src="../modules/paygate/assets/images/scan-to-pay.svg" alt="ScanToPay" style="height: 15px; margin-left: 10px;">',
                                    'val'  => 'scantopay',
                                ),
                            ),
                            'id'    => 'id',
                            'name'  => 'name',
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans('Save', array(), 'Admin.Actions'),
                ),
            ),
        );

        $helper                = new HelperForm();
        $helper->show_toolbar  = false;
        $helper->identifier    = $this->identifier;
        $helper->submit_action = 'btnSubmit';
        $helper->currentIndex  = $this->context->link->getAdminLink(
                'AdminModules',
                false
            ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token         = Tools::getAdminTokenLite('AdminModules');
        $helper->tpl_vars      = array(
            'fields_value' => $this->getConfigFieldsValues(),
        );

        $this->fields_form = array();

        return $helper->generateForm(array($fields_form));
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PAYGATE_ID'                           => Tools::getValue('PAYGATE_ID', Configuration::get('PAYGATE_ID')),
            'PAYGATE_ENCRYPTION_KEY'               => Tools::getValue(
                'PAYGATE_ENCRYPTION_KEY',
                Configuration::get('PAYGATE_ENCRYPTION_KEY')
            ),
            'PAYGATE_LOGS'                         => Tools::getValue(
                'PAYGATE_LOGS',
                Configuration::get('PAYGATE_LOGS')
            ),
            'PAYGATE_IPN_TOGGLE'                   => Tools::getValue(
                'PAYGATE_IPN_TOGGLE',
                Configuration::get('PAYGATE_IPN_TOGGLE')
            ),
            'PAYGATE_PAYMENT_METHODS_creditcard'   => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_creditcard',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_creditcard'
                )
            ),
            'PAYGATE_PAYMENT_METHODS_banktransfer' => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_banktransfer',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_banktransfer'
                )
            ),
            'PAYGATE_PAYMENT_METHODS_zapper'       => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_zapper',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_zapper'
                )
            ),
            'PAYGATE_PAYMENT_METHODS_snapscan'     => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_snapscan',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_snapscan'
                )
            ),
            'PAYGATE_PAYMENT_METHODS_paypal'       => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_paypal',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_paypal'
                )
            ),
            'PAYGATE_PAYMENT_METHODS_mobicred'     => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_mobicred',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_mobicred'
                )
            ),
            'PAYGATE_PAYMENT_METHODS_momopay'      => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_momopay',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_momopay'
                )
            ),
            'PAYGATE_PAYMENT_METHODS_scantopay'   => Tools::getValue(
                'PAYGATE_PAYMENT_METHODS_scantopay',
                Configuration::get(
                    'PAYGATE_PAYMENT_METHODS_scantopay'
                )
            ),
        );
    }

    public function logData($post_data)
    {
        if (Configuration::get('PAYGATE_LOGS')) {
            $logFile = fopen(__DIR__ . '/paygate_prestashop_logs.txt', 'a+') or die('fopen failed');
            fwrite($logFile, $post_data) or die('fwrite failed');
            fclose($logFile);
        }
    }

    public function createOrderViaPaygate(
        Cart $cart,
        Currency $currency,
        $productList,
        $addressId,
        $context,
        $reference,
        $secure_key,
        $payment_method,
        $name,
        $dont_touch_amount,
        $amount_paid,
        $warehouseId,
        $cart_total_paid,
        $debug,
        $order_status,
        $id_order_state,
        $carrierId = null
    ) {
        $order               = new Order();
        $order->product_list = $productList;

        if (Configuration::get('PS_TAX_ADDRESS_TYPE') == 'id_address_delivery') {
            $address          = new Address((int)$addressId);
            $context->country = new Country((int)$address->id_country, (int)$cart->id_lang);
            if ( ! $context->country->active) {
                throw new PrestaShopException('The delivery address country is not active.');
            }
        }

        $carrier = null;
        if ( ! $cart->isVirtualCart() && isset($carrierId)) {
            $carrier           = new Carrier((int)$carrierId, (int)$cart->id_lang);
            $order->id_carrier = (int)$carrier->id;
            $carrierId         = (int)$carrier->id;
        } else {
            $order->id_carrier = 0;
            $carrierId         = 0;
        }
        /** @noinspection PhpUndefinedConstantInspection */
        $sql1  = 'SELECT totals FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int)$cart->id . ';';
        $test  = Db::getInstance()->getValue($sql1);
        $test1 = json_decode($test);
        // Typcast object to array recursively to allow for integer keys
        $toArray = function ($x) use (&$toArray) {
            return is_scalar($x)
                ? $x
                : array_map($toArray, (array)$x);
        };
        $totals  = $toArray($test1);

        $order->id_customer         = (int)$cart->id_customer;
        $order->id_address_invoice  = (int)$cart->id_address_invoice;
        $order->id_address_delivery = (int)$addressId;
        $order->id_currency         = $currency->id;
        $order->id_lang             = (int)$cart->id_lang;
        $order->id_cart             = (int)$cart->id;
        $order->reference           = $reference;
        $order->id_shop             = (int)$context->shop->id;
        $order->id_shop_group       = (int)$context->shop->id_shop_group;

        $order->secure_key = ($secure_key ? pSQL($secure_key) : pSQL($context->customer->secure_key));
        $order->payment    = $payment_method;
        if (isset($name)) {
            $order->module = $name;
        }
        $order->recyclable      = $cart->recyclable;
        $order->gift            = (int)$cart->gift;
        $order->gift_message    = $cart->gift_message;
        $order->mobile_theme    = $cart->mobile_theme;
        $order->conversion_rate = $currency->conversion_rate;
        /** @noinspection PhpUndefinedConstantInspection */
        $amount_paid            = ! $dont_touch_amount ? Tools::ps_round(
            (float)$amount_paid,
            _PS_PRICE_COMPUTE_PRECISION_
        ) : $amount_paid;
        $order->total_paid_real = $amount_paid;

        $order->total_products           = $totals['total_products'];
        $order->total_products_wt        = $totals['total_products_wt'];
        $order->total_discounts_tax_excl = $totals['total_discounts_tax_excl'];
        $order->total_discounts_tax_incl = $totals['total_discounts_tax_incl'];
        $order->total_discounts          = $totals['total_discounts'];
        $order->total_shipping_tax_excl  = $totals['total_shipping_tax_excl'];
        $order->total_shipping_tax_incl  = $totals['total_shipping_tax_incl'];
        $order->total_shipping           = $totals['total_shipping'];

        if (null !== $carrier && Validate::isLoadedObject($carrier)) {
            $order->carrier_tax_rate = $carrier->getTaxesRate(
                new Address((int)$cart->{Configuration::get('PS_TAX_ADDRESS_TYPE')})
            );
        }

        $order->total_wrapping_tax_excl = $totals['total_wrapping_tax_excl'];
        $order->total_wrapping_tax_incl = $totals['total_wrapping_tax_incl'];
        $order->total_wrapping          = $totals['total_wrapping'];

        $order->total_paid_tax_excl = $totals['total_paid_tax_excl'];
        $order->total_paid_tax_incl = $totals['total_paid_tax_incl'];
        $order->total_paid          = $order->total_paid_tax_incl;
        $order->round_mode          = Configuration::get('PS_PRICE_ROUND_MODE');
        $order->round_type          = Configuration::get('PS_ROUND_TYPE');

        $order->invoice_date  = '0000-00-00 00:00:00';
        $order->delivery_date = '0000-00-00 00:00:00';

        // Creating order
        $result = $order->add();
        if ( ! $result) {
            $this->logData("test\n");
        }

        // Insert new Order detail list using cart for the current order
        $order_detail = new OrderDetail(null, null, $context);
        $order_detail->createList($order, $cart, $id_order_state, $order->product_list, 0, true, $warehouseId);

        // Adding an entry in order_carrier table
        if (null !== $carrier) {
            $order_carrier                         = new OrderCarrier();
            $order_carrier->id_order               = (int)$order->id;
            $order_carrier->id_carrier             = $carrierId;
            $order_carrier->weight                 = (float)$order->getTotalWeight();
            $order_carrier->shipping_cost_tax_excl = (float)$order->total_shipping_tax_excl;
            $order_carrier->shipping_cost_tax_incl = (float)$order->total_shipping_tax_incl;
            $order_carrier->add();
        }

        return ['order' => $order, 'orderDetail' => $order_detail];
    }

    public function createOrderCartRulesViaPaygate(
        Order $order,
        Cart $cart,
        $order_list,
        $total_reduction_value_ti,
        $total_reduction_value_tex,
        $id_order_state
    ) {
        // Prepare cart calculator to correctly get the value of each cart rule
        $calculator = $cart->newCalculator($order->product_list, $cart->getCartRules(), $order->id_carrier);
        /** @noinspection PhpUndefinedConstantInspection */
        $calculator->processCalculation(_PS_PRICE_COMPUTE_PRECISION_);
        $cartRulesData = $calculator->getCartRulesData();

        $cart_rules_list = array();
        foreach ($cartRulesData as $cartRuleData) {
            $cartRule = $cartRuleData->getCartRule();
            // Here we need to get actual values from cart calculator
            $values = array(
                'tax_incl' => $cartRuleData->getDiscountApplied()->getTaxIncluded(),
                'tax_excl' => $cartRuleData->getDiscountApplied()->getTaxExcluded(),
            );

            // If the reduction is not applicable to this order, continue with the next one
            if ( ! $values['tax_excl']) {
                continue;
            }

            // IF
            //  This is not multi-shipping
            //  The value of the voucher is greater than the total of the order
            //  Partial use is allowed
            //  This is an "amount" reduction, not a reduction in % or a gift
            // THEN
            //  The voucher is cloned with a new value corresponding to the remainder
            $cartRuleReductionAmountConverted = $cartRule->reduction_amount;
            if ((int)$cartRule->reduction_currency !== $cart->id_currency) {
                $cartRuleReductionAmountConverted = Tools::convertPriceFull(
                    $cartRule->reduction_amount,
                    new Currency((int)$cartRule->reduction_currency),
                    new Currency($cart->id_currency)
                );
            }
            $remainingValue = $cartRuleReductionAmountConverted - $values[$cartRule->reduction_tax ? 'tax_incl' : 'tax_excl'];
            /** @noinspection PhpUndefinedConstantInspection */
            $remainingValue = Tools::ps_round($remainingValue, _PS_PRICE_COMPUTE_PRECISION_);
            if (count(
                    $order_list
                ) == 1 && $remainingValue > 0 && $cartRule->partial_use == 1 && $cartRuleReductionAmountConverted > 0) {
                $values = $this->createNewVoucher(
                    $cartRule,
                    $order,
                    $values,
                    $total_reduction_value_ti,
                    $total_reduction_value_tex
                );
            }
            $total_reduction_value_ti  += $values['tax_incl'];
            $total_reduction_value_tex += $values['tax_excl'];

            $order->addCartRule($cartRule->id, $cartRule->name, $values, 0, $cartRule->free_shipping);

            $this->updateCartRuleData($cartRule, $id_order_state);

            $cart_rules_list[] = array(
                'voucher_name'      => $cartRule->name,
                'voucher_reduction' => ($values['tax_incl'] != 0.00 ? '-' : '') . Tools::displayPrice(
                        $values['tax_incl'],
                        $this->context->currency,
                        false
                    ),
            );
        }

        return $cart_rules_list;
    }

    public function updateCartRuleData($cartRule, $id_order_state)
    {
        $cart_rule_used = array();
        if ($id_order_state != Configuration::get('PS_OS_ERROR') && $id_order_state != Configuration::get(
                'PS_OS_CANCELED'
            ) && ! in_array($cartRule->id, $cart_rule_used)) {
            $cart_rule_used[] = $cartRule->id;

            // Create a new instance of Cart Rule without id_lang, in order to update its quantity
            $cart_rule_to_update           = new CartRule((int)$cartRule->id);
            $cart_rule_to_update->quantity = max(0, $cart_rule_to_update->quantity - 1);
            $cart_rule_to_update->update();
        }
    }

    public function createNewVoucher($cartRule, $order, $values, $total_reduction_value_ti, $total_reduction_value_tex)
    {
        // Create a new voucher from the original
        $voucher = new CartRule(
            (int)$cartRule->id
        ); // We need to instantiate the CartRule without lang parameter to allow saving it
        unset($voucher->id);

        // Set a new voucher code
        $voucher->code = empty($voucher->code) ? substr(
            md5($order->id . '-' . $order->id_customer . '-' . $cartRule->id),
            0,
            16
        ) : $voucher->code . '-2';
        if (preg_match(
                '/\-(\d{1,2})\-(\d{1,2})$/',
                $voucher->code,
                $matches
            ) && $matches[1] == $matches[2]) {
            $voucher->code = preg_replace(
                '/' . $matches[0] . '$/',
                '-' . (intval($matches[1]) + 1),
                $voucher->code
            );
        }

        // Set the new voucher value
        /** @noinspection PhpUndefinedVariableInspection */
        $voucher = $this->setNewVoucherValue($voucher, $remainingValue, $order);

        $voucher->quantity           = 1;
        $voucher->reduction_currency = $order->id_currency;
        $voucher->quantity_per_user  = 1;
        if ($voucher->add() && $voucher->reduction_amount > 0) {
            // If the voucher has conditions, they are now copied to the new voucher
            CartRule::copyConditions($cartRule->id, $voucher->id);
            $orderLanguage = new Language((int)$order->id_lang);

            $params = array(
                '{voucher_amount}' => Tools::displayPrice(
                    $voucher->reduction_amount,
                    $this->context->currency,
                    false
                ),
                '{voucher_num}'    => $voucher->code,
                '{firstname}'      => $this->context->customer->firstname,
                '{lastname}'       => $this->context->customer->lastname,
                '{id_order}'       => $order->reference,
                '{order_name}'     => $order->getUniqReference(),
            );
            /** @noinspection PhpUndefinedConstantInspection */
            Mail::Send(
                (int)$order->id_lang,
                'voucher',
                Context::getContext()->getTranslator()->trans(
                    'New voucher for your order %s',
                    array($order->reference),
                    'Emails.Subject',
                    $orderLanguage->locale
                ),
                $params,
                $this->context->customer->email,
                $this->context->customer->firstname . ' ' . $this->context->customer->lastname,
                null,
                null,
                null,
                null,
                _PS_MAIL_DIR_,
                false,
                (int)$order->id_shop
            );
        }

        $values['tax_incl'] = $order->total_products_wt - $total_reduction_value_ti;
        $values['tax_excl'] = $order->total_products - $total_reduction_value_tex;
        if (1 == $voucher->free_shipping) {
            $values['tax_incl'] += $order->total_shipping_tax_incl;
            $values['tax_excl'] += $order->total_shipping_tax_excl;
        }

        return $values;
    }

    public function setNewVoucherValue($voucher, $remainingValue, $order)
    {
        $voucher->reduction_amount = $remainingValue;
        if ($voucher->reduction_tax) {
            // Add total shipping amout only if reduction amount > total shipping
            if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_incl) {
                $voucher->reduction_amount -= $order->total_shipping_tax_incl;
            }
        } else {
            // Add total shipping amout only if reduction amount > total shipping
            if ($voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_excl) {
                $voucher->reduction_amount -= $order->total_shipping_tax_excl;
            }
        }

        if ($this->context->customer->isGuest()) {
            $voucher->id_customer = 0;
        } else {
            $voucher->id_customer = $order->id_customer;
        }

        return $voucher;
    }

    private function _postValidation()
    {
        if (Tools::isSubmit('btnSubmit')) {
            if ( ! Tools::getValue('PAYGATE_ID')) {
                $this->_postErrors[] = $this->trans(
                    'The "Paygate ID" field is required.',
                    array(),
                    self::PAYGATE_ADMIN
                );
            } elseif ( ! Tools::getValue('PAYGATE_ENCRYPTION_KEY')) {
                $this->_postErrors[] = $this->trans(
                    'The "Encryption Key" field is required.',
                    array(),
                    self::PAYGATE_ADMIN
                );
            }
        }
    }

    private function _postProcess()
    {
        if (Tools::isSubmit('btnSubmit')) {
            Configuration::updateValue('PAYGATE_ID', Tools::getValue('PAYGATE_ID'));
            Configuration::updateValue('PAYGATE_ENCRYPTION_KEY', Tools::getValue('PAYGATE_ENCRYPTION_KEY'));
            Configuration::updateValue('PAYGATE_LOGS', Tools::getValue('PAYGATE_LOGS'));
            Configuration::updateValue('PAYGATE_IPN_TOGGLE', Tools::getValue('PAYGATE_IPN_TOGGLE'));
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_creditcard',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_creditcard')
            );
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_banktransfer',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_banktransfer')
            );
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_zapper',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_zapper')
            );
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_snapscan',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_snapscan')
            );
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_paypal',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_paypal')
            );
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_mobicred',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_mobicred')
            );
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_momopay',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_momopay')
            );
            Configuration::updateValue(
                'PAYGATE_PAYMENT_METHODS_scantopay',
                Tools::getValue('PAYGATE_PAYMENT_METHODS_scantopay')
            );
        }
        $this->_html .= $this->displayConfirmation(
            $this->trans('Settings updated', array(), 'Admin.Notifications.Success')
        );
    }

}
