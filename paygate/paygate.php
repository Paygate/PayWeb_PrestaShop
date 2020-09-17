<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if ( !defined( '_PS_VERSION_' ) ) {
    exit;
}

class Paygate extends PaymentModule
{

    private $_postErrors = array();
    const PAYGATE_ADMIN  = 'Modules.Paygate.Admin';

    public function __construct()
    {
        $this->name        = 'paygate';
        $this->tab         = 'payments_gateways';
        $this->version     = '1.7.7';
        $this->author      = 'PayGate';
        $this->controllers = array( 'payment', 'validation' );

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName      = $this->trans( 'PayGate', array(), PAYGATE_ADMIN );
        $this->description      = $this->trans( 'Accept payments via PayGate.', array(), PAYGATE_ADMIN );
        $this->confirmUninstall = $this->trans(
            'Are you sure you want to delete your details ?',
            array(),
            PAYGATE_ADMIN
        );
        $this->ps_versions_compliancy = array( 'min' => '1.7.1.0', 'max' => _PS_VERSION_ );
    }

    public function install()
    {
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
        && $this->registerHook( 'paymentOptions' )
        && $this->registerHook( 'paymentReturn' );
    }

    public function uninstall()
    {
        Db::getInstance()->execute( 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paygate`' );
        return ( parent::uninstall() );
    }

    public function hookPaymentOptions( $params )
    {
        if ( !$this->active ) {
            return;
        }

        $this->updateOrAddToTable();
        $this->clearOldOrders();

        $paymentOption = new PaymentOption();
        $paymentOption->setCallToActionText( 'Pay Via Paygate' )
            ->setAction( $this->context->link->getModuleLink( $this->name, 'payment', [], true ) )
            ->setAdditionalInformation( "<p>Make Payment Via Paygate</p><p>Visa and MasterCard accepted.</p>" )
            ->setLogo( Media::getMediaPath( _PS_MODULE_DIR_ . $this->name . '/logo.png' ) );

        return [$paymentOption];
    }

    public function clearOldOrders()
    {
        $sql     = 'SELECT `cart_id` FROM ' . _DB_PREFIX_ . 'paygate;';
        $results = Db::getInstance()->ExecuteS( $sql );
        foreach ( $results as $id ) {
            foreach ( $id as $key => $cartID ) {
                $check_cart = new cart( $cartID );
                if ( $check_cart->orderExists() ) {
                    Db::getInstance()->delete( 'paygate', 'cart_id =' . $cartID );
                }
                unset( $check_cart );
            }
        }

        $sql2     = 'SELECT `cart_id`,`date_time` FROM ' . _DB_PREFIX_ . 'paygate;';
        $results2 = Db::getInstance()->ExecuteS( $sql2 );
        foreach ( $results2 as $cart ) {
            $json_last_Updated         = $cart['date_time'];
            $json_decoded_last_Updated = json_decode( $json_last_Updated );
            $last_Updated              = new DateTime( $json_decoded_last_Updated->date );
            $now                       = new DateTime();
            $diff                      = $last_Updated->diff( $now );
            if ( $diff->h >= 5 || $diff->d > 0 || $diff->m > 0 || $diff->y > 0 ) {
                Db::getInstance()->delete( 'paygate', 'cart_id =' . $cart['cart_id'] );
            }
            unset( $last_Updated );
            unset( $now );
        }
    }

    public function updateOrAddToTable()
    {
        global $cart;
        $cart_id                   = $cart->id;
        $cart_total                = $cart->getOrderTotal();
        $cart_delivery_option      = $cart->getDeliveryOption();
        $delivery_option_list      = $cart->getDeliveryOptionList();
        $package_list              = $cart->getPackageList();
        $json_delivery_option_list = json_encode( $delivery_option_list, JSON_NUMERIC_CHECK );
        $json_package_list         = json_encode( $package_list, JSON_NUMERIC_CHECK );
        $json_cart_delivery_option = json_encode( $cart_delivery_option, JSON_NUMERIC_CHECK );

        foreach ( $cart_delivery_option as $id_address => $key_carriers ) {
            foreach ( $delivery_option_list[$id_address][$key_carriers]['carrier_list'] as $id_carrier => $data ) {
                foreach ( $data['package_list'] as $id_package ) {
                    // Rewrite the id_warehouse
                    $package_list[$id_address][$id_package]['id_warehouse'] = (int) $this->context->cart->getPackageIdWarehouse(
                        $package_list[$id_address][$id_package],
                        (int) $id_carrier
                    );
                    $package_list[$id_address][$id_package]['id_carrier'] = $id_carrier;
                }
            }
        }
        foreach ( $package_list as $id_address => $packageByAddress ) {
            foreach ( $packageByAddress as $id_package => $package ) {
                $product_list = $package['product_list'];
                $carrierId    = isset( $package['id_carrier'] ) ? $package['id_carrier'] : null;
                $totals       = array(
                    "total_products"           => (float) $cart->getOrderTotal(
                        false,
                        Cart::ONLY_PRODUCTS,
                        $product_list,
                        $carrierId
                    ),
                    'total_products_wt'        => (float) $cart->getOrderTotal(
                        true,
                        Cart::ONLY_PRODUCTS,
                        $product_list,
                        $carrierId
                    ),
                    'total_discounts_tax_excl' => (float) abs(
                        $cart->getOrderTotal( false, Cart::ONLY_DISCOUNTS, $product_list, $carrierId )
                    ),
                    'total_discounts_tax_incl' => (float) abs(
                        $cart->getOrderTotal( true, Cart::ONLY_DISCOUNTS, $product_list, $carrierId )
                    ),
                    'total_discounts'          => (float) abs(
                        $cart->getOrderTotal( true, Cart::ONLY_DISCOUNTS, $product_list, $carrierId )
                    ),
                    'total_shipping_tax_excl'  => (float) $cart->getPackageShippingCost(
                        $carrierId,
                        false,
                        null,
                        $product_list
                    ),
                    'total_shipping_tax_incl'  => (float) $cart->getPackageShippingCost(
                        $carrierId,
                        true,
                        null,
                        $product_list
                    ),
                    'total_shipping'           => (float) $cart->getPackageShippingCost(
                        $carrierId,
                        true,
                        null,
                        $product_list
                    ),
                    'total_wrapping_tax_excl'  => (float) abs(
                        $cart->getOrderTotal( false, Cart::ONLY_WRAPPING, $product_list, $carrierId )
                    ),
                    'total_wrapping_tax_incl'  => (float) abs(
                        $cart->getOrderTotal( true, Cart::ONLY_WRAPPING, $product_list, $carrierId )
                    ),
                    'total_wrapping'           => (float) abs(
                        $cart->getOrderTotal( true, Cart::ONLY_WRAPPING, $product_list, $carrierId )
                    ),
                    'total_paid_tax_excl'      => (float) Tools::ps_round(
                        (float) $cart->getOrderTotal( false, Cart::BOTH, $product_list, $carrierId ),
                        _PS_PRICE_COMPUTE_PRECISION_
                    ),
                    'total_paid_tax_incl'      => (float) Tools::ps_round(
                        (float) $cart->getOrderTotal( true, Cart::BOTH, $product_list, $carrierId ),
                        _PS_PRICE_COMPUTE_PRECISION_
                    ),
                    '$total_paid'              => (float) Tools::ps_round(
                        (float) $cart->getOrderTotal( true, Cart::BOTH, $product_list, $carrierId ),
                        _PS_PRICE_COMPUTE_PRECISION_
                    ),
                );
            }
        }

        $total = json_encode( $totals, JSON_NUMERIC_CHECK );

        $check_if_row_exists = Db::getInstance()->getValue(
            'SELECT cart_id FROM ' . _DB_PREFIX_ . 'paygate WHERE cart_id="' . (int) $cart_id . '"'
        );
        if ( $check_if_row_exists == false ) {
            Db::getInstance()->insert(
                'paygate',
                array(
                    'cart_id'              => (int) $cart_id,
                    'delivery_option_list' => pSQL( $json_delivery_option_list ),
                    'package_list'         => pSQL( $json_package_list ),
                    'cart_delivery_option' => pSQL( $json_cart_delivery_option ),
                    'cart_total'           => (double) $cart_total,
                    'totals'               => pSQL( $total ),
                    'date_time'            => pSQL( json_encode( new DateTime() ) ),

                )
            );
        } else {
            Db::getInstance()->update(
                'paygate',
                array(
                    'delivery_option_list' => pSQL( $json_delivery_option_list ),
                    'package_list'         => pSQL( $json_package_list ),
                    'cart_delivery_option' => pSQL( $json_cart_delivery_option ),
                    'cart_total'           => (double) $cart_total,
                    'totals'               => pSQL( $total ),
                    'date_time'            => pSQL( json_encode( new DateTime() ) ),
                ),
                'cart_id = ' . (int) $cart_id
            );
        }
    }

    public function getContent()
    {
        $this->_html = '';

        if ( Tools::isSubmit( 'btnSubmit' ) ) {
            $this->_postValidation();
            if ( !count( $this->_postErrors ) ) {
                $this->_postProcess();
            } else {
                foreach ( $this->_postErrors as $err ) {
                    $this->_html .= $this->displayError( $err );
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
                    'title' => $this->trans( 'Settings', array(), PAYGATE_ADMIN ),
                    'icon'  => 'icon-envelope',
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans( 'PayGate ID', array(), PAYGATE_ADMIN ),
                        'name'     => 'PAYGATE_ID',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans( 'Encryption Key', array(), PAYGATE_ADMIN ),
                        'name'     => 'PAYGATE_ENCRYPTION_KEY',
                        'required' => true,
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->trans( 'Debug', array(), PAYGATE_ADMIN ),
                        'name'   => 'PAYGATE_LOGS',
                        'values' => array(
                            array(
                                'id'    => 'active_on',
                                'value' => 1,
                                'label' => $this->trans( 'Yes', array(), PAYGATE_ADMIN ),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => 0,
                                'label' => $this->trans( 'No', array(), PAYGATE_ADMIN ),
                            ),
                        ),
                    ),
                ),
                'submit' => array(
                    'title' => $this->trans( 'Save', array(), 'Admin.Actions' ),
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
        $helper->token    = Tools::getAdminTokenLite( 'AdminModules' );
        $helper->tpl_vars = array(
            'fields_value' => $this->getConfigFieldsValues(),
        );

        $this->fields_form = array();

        return $helper->generateForm( array( $fields_form ) );
    }

    private function _postValidation()
    {
        if ( Tools::isSubmit( 'btnSubmit' ) ) {
            if ( !Tools::getValue( 'PAYGATE_ID' ) ) {
                $this->_postErrors[] = $this->trans(
                    'The "PayGate ID" field is required.',
                    array(),
                    PAYGATE_ADMIN
                );
            } elseif ( !Tools::getValue( 'PAYGATE_ENCRYPTION_KEY' ) ) {
                $this->_postErrors[] = $this->trans(
                    'The "Encryption Key" field is required.',
                    array(),
                    PAYGATE_ADMIN
                );
            }
        }
    }

    private function _postProcess()
    {
        if ( Tools::isSubmit( 'btnSubmit' ) ) {
            Configuration::updateValue( 'PAYGATE_ID', Tools::getValue( 'PAYGATE_ID' ) );
            Configuration::updateValue( 'PAYGATE_ENCRYPTION_KEY', Tools::getValue( 'PAYGATE_ENCRYPTION_KEY' ) );
            Configuration::updateValue( 'PAYGATE_LOGS', Tools::getValue( 'PAYGATE_LOGS' ) );
        }
        $this->_html .= $this->displayConfirmation(
            $this->trans( 'Settings updated', array(), 'Admin.Notifications.Success' )
        );
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PAYGATE_ID'             => Tools::getValue( 'PAYGATE_ID', Configuration::get( 'PAYGATE_ID' ) ),
            'PAYGATE_ENCRYPTION_KEY' => Tools::getValue(
                'PAYGATE_ENCRYPTION_KEY',
                Configuration::get( 'PAYGATE_ENCRYPTION_KEY' )
            ),
            'PAYGATE_LOGS'           => Tools::getValue( 'PAYGATE_LOGS', Configuration::get( 'PAYGATE_LOGS' ) ),
        );
    }

    public function logData( $post_data )
    {
        if ( Configuration::get( 'PAYGATE_LOGS' ) ) {
            $logFile = fopen( __DIR__ . '/paygate_prestashop_logs.txt', 'a+' ) or die( 'fopen failed' );
            fwrite( $logFile, $post_data ) or die( 'fwrite failed' );
            fclose( $logFile );
        }
    }

    public function paygateValidate(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = array(),
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        Shop $shop = null
    ) {
        if ( !isset( $this->context ) ) {
            $this->context = Context::getContext();
        }
        $cart     = new Cart( (int) $id_cart );
        $customer = new Customer( (int) $cart->id_customer );
        // Re-cache the tax calculation method as the tax cart is loaded before the customer
        $cart->setTaxCalculationMethod();

        $language = new Language( (int) $cart->id_lang );
        $shop     = ( $shop ? $shop : new Shop( (int) $cart->id_shop ) );
        ShopUrl::resetMainDomainCache();
        $id_currency = $currency_special ? (int) $currency_special : (int) $cart->id_currency;
        $currency    = new Currency( (int) $id_currency, null, (int) $shop->id );
        if ( Configuration::get( 'PS_TAX_ADDRESS_TYPE' ) == 'id_address_delivery' ) {
            $context_country = $this->context->country; ///csk
        }

        $order_status = new OrderState( (int) $id_order_state, (int) $language->id );

        // Check if the order already exists
        if ( $cart->OrderExists() == false ) {
            $sql1                      = 'SELECT delivery_option_list FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int) $cart->id . ';';
            $test                      = Db::getInstance()->getValue( $sql1 );
            $json_delivery_option_list = json_decode( $test );
            $sql3                      = 'SELECT package_list FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int) $cart->id . ';';
            $test2                     = Db::getInstance()->getValue( $sql3 );
            $json_package_list         = json_decode( $test2 );
            $sql2                      = 'SELECT cart_delivery_option FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int) $cart->id . ';';
            $test1                     = Db::getInstance()->getValue( $sql2 );
            $json_cart_delivery_option = json_decode( $test1 );

            // Typcast object to array recursively to allow for integer keys
            $toArray = function ( $x ) use ( &$toArray ) {
                return is_scalar( $x )
                ? $x
                : array_map( $toArray, (array) $x );
            };
            $delivery_option_list = $toArray( $json_delivery_option_list );
            $package_list         = $toArray( $json_package_list );
            $cart_delivery_option = $toArray( $json_cart_delivery_option );

            // If some delivery options are not defined, or not valid, use the first valid option
            foreach ( $delivery_option_list as $id_address => $package ) {
                if ( !isset( $cart_delivery_option[$id_address] ) || !array_key_exists(
                    $cart_delivery_option[$id_address],
                    $package
                ) ) {
                    foreach ( $package as $key => $val ) {
                        $cart_delivery_option[$id_address] = $key;

                        break;
                    }
                }
            }

            $order_list        = array();
            $order_detail_list = array();
            do {
                $reference = Order::generateReference();
            } while ( Order::getByReference( $reference )->count() );

            $currentOrderReference = $reference;
            $sql                   = 'SELECT cart_total FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int) $id_cart . ';';
            $test                  = Db::getInstance()->getValue( $sql );
            $cart_total_paid       = (float) Tools::ps_round( (float) $test, 2 );
            foreach ( $cart_delivery_option as $id_address => $key_carriers ) {
                foreach ( $delivery_option_list[$id_address][$key_carriers]['carrier_list'] as $id_carrier => $data ) {
                    foreach ( $data['package_list'] as $id_package ) {
                        // Rewrite the id_warehouse
                        $package_list[$id_address][$id_package]['id_warehouse'] = (int) $this->context->cart->getPackageIdWarehouse(
                            $package_list[$id_address][$id_package],
                            (int) $id_carrier
                        );
                        $package_list[$id_address][$id_package]['id_carrier'] = $id_carrier;
                    }
                }
            }
            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            $cart_rules = $this->context->cart->getCartRules();
            foreach ( $cart_rules as $cart_rule ) {
                if (  ( $rule = new CartRule( (int) $cart_rule['obj']->id ) ) && Validate::isLoadedObject( $rule ) ) {
                    if ( $error = $rule->checkValidity( $this->context, true, true ) ) {
                        $this->context->cart->removeCartRule( (int) $rule->id );
                        if ( isset( $this->context->cookie, $this->context->cookie->id_customer ) && $this->context->cookie->id_customer && !empty( $rule->code ) ) {
                            Tools::redirect(
                                'index.php?controller=order&submitAddDiscount=1&discount_name=' . urlencode( $rule->code )
                            );
                        } else {
                            $rule_name = isset( $rule->name[(int) $this->context->cart->id_lang] ) ? $rule->name[(int) $this->context->cart->id_lang] : $rule->code;
                            $error     = $this->trans(
                                'The cart rule named "%1s" (ID %2s) used in this cart is not valid and has been withdrawn from cart',
                                array( $rule_name, (int) $rule->id ),
                                'Admin.Payment.Notification'
                            );
                            PrestaShopLogger::addLog( $error, 3, '0000002', 'Cart', (int) $this->context->cart->id );
                        }
                    }
                }
            }

            foreach ( $package_list as $id_address => $packageByAddress ) {
                foreach ( $packageByAddress as $id_package => $package ) {
                    $orderData = $this->createOrderViaPaygate(
                        $cart,
                        $currency,
                        $package['product_list'],
                        $id_address,
                        $this->context,
                        $reference,
                        $secure_key,
                        $payment_method,
                        $this->name,
                        $dont_touch_amount,
                        $amount_paid,
                        $package_list[$id_address][$id_package]['id_warehouse'],
                        $cart_total_paid,
                        self::DEBUG_MODE,
                        $order_status,
                        $id_order_state,
                        isset( $package['id_carrier'] ) ? $package['id_carrier'] : null
                    );
                    $order               = $orderData['order'];
                    $order_list[]        = $order;
                    $order_detail_list[] = $orderData['orderDetail'];
                }
            }

            // The country can only change if the address used for the calculation is the delivery address,
            // and if multi-shipping is activated
            if ( Configuration::get( 'PS_TAX_ADDRESS_TYPE' ) == 'id_address_delivery' ) {
                $this->context->country = $context_country;
            }

            // Register Payment only if the order status validates the order
            if ( $order_status->logable ) {
                // The last order loop in the foreach and linked to the order reference, not id
                if ( isset( $extra_vars['transaction_id'] ) ) {
                    $transaction_id = $extra_vars['transaction_id'];
                } else {
                    $transaction_id = null;
                }

                if ( !$order->addOrderPayment( $amount_paid, null, $transaction_id ) ) {
                }
            }

            $only_one_gift = false;
            $products      = $this->context->cart->getProducts();

            // Make sure CartRule caches are empty
            CartRule::cleanCache();
            foreach ( $order_detail_list as $key => $order_detail ) {
                /** @var OrderDetail $order_detail */
                $order = $order_list[$key];
                if ( isset( $order->id ) ) {
                    if ( !$secure_key ) {
                        $message .= '<br />' . $this->trans(
                            'Warning: the secure key is empty, check your payment account before validation',
                            array(),
                            'Admin.Payment.Notification'
                        );
                    }
                    if ( isset( $message ) & !empty( $message ) ) {
                        $msg     = new Message();
                        $message = strip_tags( $message, '<br>' );
                        if ( Validate::isCleanHtml( $message ) ) {
                            if ( self::DEBUG_MODE ) {
                                PrestaShopLogger::addLog(
                                    'PaymentModule::validateOrder - Message is about to be added',
                                    1,
                                    null,
                                    'Cart',
                                    (int) $id_cart,
                                    true
                                );
                            }
                            $msg->message     = $message;
                            $msg->id_cart     = (int) $id_cart;
                            $msg->id_customer = (int) ( $order->id_customer );
                            $msg->id_order    = (int) $order->id;
                            $msg->private     = 1;
                            $msg->add();
                        }
                    }

                    $products_list   = '';
                    $virtual_product = true;

                    $product_var_tpl_list = array();
                    foreach ( $order->product_list as $product ) {
                        $price = Product::getPriceStatic(
                            (int) $product['id_product'],
                            false,
                            ( $product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null ),
                            6,
                            null,
                            false,
                            true,
                            $product['cart_quantity'],
                            false,
                            (int) $order->id_customer,
                            (int) $order->id_cart,
                            (int) $order->{Configuration::get( 'PS_TAX_ADDRESS_TYPE' )},
                            $specific_price,
                            true,
                            true,
                            null,
                            true,
                            $product['id_customization']
                        );
                        $price_wt = Product::getPriceStatic(
                            (int) $product['id_product'],
                            true,
                            ( $product['id_product_attribute'] ? (int) $product['id_product_attribute'] : null ),
                            2,
                            null,
                            false,
                            true,
                            $product['cart_quantity'],
                            false,
                            (int) $order->id_customer,
                            (int) $order->id_cart,
                            (int) $order->{Configuration::get( 'PS_TAX_ADDRESS_TYPE' )},
                            $specific_price,
                            true,
                            true,
                            null,
                            true,
                            $product['id_customization']
                        );

                        $product_price = Product::getTaxCalculationMethod() == PS_TAX_EXC ? Tools::ps_round(
                            $price,
                            2
                        ) : $price_wt;

                        $product_var_tpl = array(
                            'id_product'    => $product['id_product'],
                            'reference'     => $product['reference'],
                            'name'          => $product['name'] . ( isset( $product['attributes'] ) ? ' - ' . $product['attributes'] : '' ),
                            'price'         => Tools::displayPrice(
                                $product_price * $product['quantity'],
                                $this->context->currency,
                                false
                            ),
                            'quantity'      => $product['quantity'],
                            'customization' => array(),
                        );

                        if ( isset( $product['price'] ) && $product['price'] ) {
                            $product_var_tpl['unit_price'] = Tools::displayPrice(
                                $product_price,
                                $this->context->currency,
                                false
                            );
                            $product_var_tpl['unit_price_full'] = Tools::displayPrice(
                                $product_price,
                                $this->context->currency,
                                false
                            )
                                . ' ' . $product['unity'];
                        } else {
                            $product_var_tpl['unit_price'] = $product_var_tpl['unit_price_full'] = '';
                        }

                        $customized_datas = Product::getAllCustomizedDatas(
                            (int) $order->id_cart,
                            null,
                            true,
                            null,
                            (int) $product['id_customization']
                        );
                        if ( isset( $customized_datas[$product['id_product']][$product['id_product_attribute']] ) ) {
                            $product_var_tpl['customization'] = array();
                            foreach ( $customized_datas[$product['id_product']][$product['id_product_attribute']][$order->id_address_delivery] as $customization ) {
                                $customization_text = '';
                                if ( isset( $customization['datas'][Product::CUSTOMIZE_TEXTFIELD] ) ) {
                                    foreach ( $customization['datas'][Product::CUSTOMIZE_TEXTFIELD] as $text ) {
                                        $customization_text .= '<strong>' . $text['name'] . '</strong>: ' . $text['value'] . '<br />';
                                    }
                                }

                                if ( isset( $customization['datas'][Product::CUSTOMIZE_FILE] ) ) {
                                    $customization_text .= $this->trans(
                                        '%d image(s)',
                                        array(
                                            count(
                                                $customization['datas'][Product::CUSTOMIZE_FILE]
                                            ),
                                        ),
                                        'Admin.Payment.Notification'
                                    ) . '<br />';
                                }

                                $customization_quantity = (int) $customization['quantity'];

                                $product_var_tpl['customization'][] = array(
                                    'customization_text'     => $customization_text,
                                    'customization_quantity' => $customization_quantity,
                                    'quantity'               => Tools::displayPrice(
                                        $customization_quantity * $product_price,
                                        $this->context->currency,
                                        false
                                    ),
                                );
                            }
                        }

                        $product_var_tpl_list[] = $product_var_tpl;
                        // Check if is not a virutal product to display shipping
                        if ( !$product['is_virtual'] ) {
                            $virtual_product &= false;
                        }
                    }

                    $product_list_txt  = '';
                    $product_list_html = '';
                    if ( empty( $product_var_tpl_list ) ) {
                        $product_list_txt = $this->getEmailTemplateContent(
                            'order_conf_product_list.txt',
                            Mail::TYPE_TEXT,
                            $product_var_tpl_list
                        );
                        $product_list_html = $this->getEmailTemplateContent(
                            'order_conf_product_list.tpl',
                            Mail::TYPE_HTML,
                            $product_var_tpl_list
                        );
                    }

                    $total_reduction_value_ti  = 0;
                    $total_reduction_value_tex = 0;

                    $cart_rules_list = $this->createOrderCartRulesViaPaygate(
                        $order,
                        $this->context->cart,
                        $order_list,
                        $total_reduction_value_ti,
                        $total_reduction_value_tex,
                        $id_order_state
                    );

                    $cart_rules_list_txt  = '';
                    $cart_rules_list_html = '';
                    if ( count( $cart_rules_list ) > 0 ) {
                        $cart_rules_list_txt = $this->getEmailTemplateContent(
                            'order_conf_cart_rules.txt',
                            Mail::TYPE_TEXT,
                            $cart_rules_list
                        );
                        $cart_rules_list_html = $this->getEmailTemplateContent(
                            'order_conf_cart_rules.tpl',
                            Mail::TYPE_HTML,
                            $cart_rules_list
                        );
                    }

                    // Specify order id for message
                    $old_message = Message::getMessageByCartId( (int) $this->context->cart->id );
                    if ( $old_message && !$old_message['private'] ) {
                        $update_message           = new Message( (int) $old_message['id_message'] );
                        $update_message->id_order = (int) $order->id;
                        $update_message->update();

                        // Add this message in the customer thread
                        $customer_thread              = new CustomerThread();
                        $customer_thread->id_contact  = 0;
                        $customer_thread->id_customer = (int) $order->id_customer;
                        $customer_thread->id_shop     = (int) $this->context->shop->id;
                        $customer_thread->id_order    = (int) $order->id;
                        $customer_thread->id_lang     = (int) $this->context->language->id;
                        $customer_thread->email       = $this->context->customer->email;
                        $customer_thread->status      = 'open';
                        $customer_thread->token       = Tools::passwdGen( 12 );
                        $customer_thread->add();

                        $customer_message                     = new CustomerMessage();
                        $customer_message->id_customer_thread = $customer_thread->id;
                        $customer_message->id_employee        = 0;
                        $customer_message->message            = $update_message->message;
                        $customer_message->private            = 1;

                        if ( !$customer_message->add() ) {
                            $this->errors[] = $this->trans(
                                'An error occurred while saving message',
                                array(),
                                'Admin.Payment.Notification'
                            );
                        }
                    }

                    if ( self::DEBUG_MODE ) {
                        PrestaShopLogger::addLog(
                            'PaymentModule::validateOrder - Hook validateOrder is about to be called',
                            1,
                            null,
                            'Cart',
                            (int) $id_cart,
                            true
                        );
                    }

                    // Hook validate order
                    Hook::exec(
                        'actionValidateOrder',
                        array(
                            'cart'        => $this->context->cart,
                            'order'       => $order,
                            'customer'    => $this->context->customer,
                            'currency'    => $this->context->currency,
                            'orderStatus' => $order_status,
                        )
                    );

                    foreach ( $this->context->cart->getProducts() as $product ) {
                        if ( $order_status->logable ) {
                            ProductSale::addProductSale( (int) $product['id_product'], (int) $product['cart_quantity'] );
                        }
                    }

                    if ( self::DEBUG_MODE ) {
                        PrestaShopLogger::addLog(
                            'PaymentModule::validateOrder - Order Status is about to be added',
                            1,
                            null,
                            'Cart',
                            (int) $id_cart,
                            true
                        );
                    }

                    // Set the order status
                    $new_history           = new OrderHistory();
                    $new_history->id_order = (int) $order->id;
                    $new_history->changeIdOrderState( (int) $id_order_state, $order, true );
                    $new_history->addWithemail( true, $extra_vars );

                    // Switch to back order if needed
                    if ( Configuration::get( 'PS_STOCK_MANAGEMENT' ) &&
                        ( $order_detail->getStockState() ||
                            $order_detail->product_quantity_in_stock < 0 ) ) {
                        $history           = new OrderHistory();
                        $history->id_order = (int) $order->id;
                        $history->changeIdOrderState(
                            Configuration::get(
                                $order->hasBeenPaid() ? 'PS_OS_OUTOFSTOCK_PAID' : 'PS_OS_OUTOFSTOCK_UNPAID'
                            ),
                            $order,
                            true
                        );
                        $history->addWithemail();
                    }

                    unset( $order_detail );

                    // Order is reloaded because the status just changed
                    $order = new Order( (int) $order->id );

                    // Send an e-mail to customer (one order = one email)
                    if ( $id_order_state != Configuration::get( 'PS_OS_ERROR' ) && $id_order_state != Configuration::get(
                        'PS_OS_CANCELED'
                    ) && $this->context->customer->id ) {
                        $invoice        = new Address( (int) $order->id_address_invoice );
                        $delivery       = new Address( (int) $order->id_address_delivery );
                        $delivery_state = $delivery->id_state ? new State( (int) $delivery->id_state ) : false;
                        $invoice_state  = $invoice->id_state ? new State( (int) $invoice->id_state ) : false;
                        $carrier        = $order->id_carrier ? new Carrier( $order->id_carrier ) : false;

                        $data = array(
                            '{firstname}'               => $this->context->customer->firstname,
                            '{lastname}'                => $this->context->customer->lastname,
                            '{email}'                   => $this->context->customer->email,
                            '{delivery_block_txt}'      => $this->_getFormatedAddress(
                                $delivery,
                                AddressFormat::FORMAT_NEW_LINE
                            ),
                            '{invoice_block_txt}'       => $this->_getFormatedAddress(
                                $invoice,
                                AddressFormat::FORMAT_NEW_LINE
                            ),
                            '{delivery_block_html}'     => $this->_getFormatedAddress(
                                $delivery,
                                '<br />',
                                array(
                                    'firstname' => '<span style="font-weight:bold;">%s</span>',
                                    'lastname'  => '<span style="font-weight:bold;">%s</span>',
                                )
                            ),
                            '{invoice_block_html}'      => $this->_getFormatedAddress(
                                $invoice,
                                '<br />',
                                array(
                                    'firstname' => '<span style="font-weight:bold;">%s</span>',
                                    'lastname'  => '<span style="font-weight:bold;">%s</span>',
                                )
                            ),
                            '{delivery_company}'        => $delivery->company,
                            '{delivery_firstname}'      => $delivery->firstname,
                            '{delivery_lastname}'       => $delivery->lastname,
                            '{delivery_address1}'       => $delivery->address1,
                            '{delivery_address2}'       => $delivery->address2,
                            '{delivery_city}'           => $delivery->city,
                            '{delivery_postal_code}'    => $delivery->postcode,
                            '{delivery_country}'        => $delivery->country,
                            '{delivery_state}'          => $delivery->id_state ? $delivery_state->name : '',
                            '{delivery_phone}'          => ( $delivery->phone ) ? $delivery->phone : $delivery->phone_mobile,
                            '{delivery_other}'          => $delivery->other,
                            '{invoice_company}'         => $invoice->company,
                            '{invoice_vat_number}'      => $invoice->vat_number,
                            '{invoice_firstname}'       => $invoice->firstname,
                            '{invoice_lastname}'        => $invoice->lastname,
                            '{invoice_address2}'        => $invoice->address2,
                            '{invoice_address1}'        => $invoice->address1,
                            '{invoice_city}'            => $invoice->city,
                            '{invoice_postal_code}'     => $invoice->postcode,
                            '{invoice_country}'         => $invoice->country,
                            '{invoice_state}'           => $invoice->id_state ? $invoice_state->name : '',
                            '{invoice_phone}'           => ( $invoice->phone ) ? $invoice->phone : $invoice->phone_mobile,
                            '{invoice_other}'           => $invoice->other,
                            '{order_name}'              => $order->getUniqReference(),
                            '{date}'                    => Tools::displayDate( date( 'Y-m-d H:i:s' ), null, 1 ),
                            '{carrier}'                 => ( $virtual_product || !isset( $carrier->name ) ) ? $this->trans(
                                'No carrier',
                                array(),
                                'Admin.Payment.Notification'
                            ) : $carrier->name,
                            '{payment}'                 => Tools::substr( $order->payment, 0, 255 ),
                            '{products}'                => $product_list_html,
                            '{products_txt}'            => $product_list_txt,
                            '{discounts}'               => $cart_rules_list_html,
                            '{discounts_txt}'           => $cart_rules_list_txt,
                            '{total_paid}'              => Tools::displayPrice(
                                $order->total_paid,
                                $this->context->currency,
                                false
                            ),
                            '{total_products}'          => Tools::displayPrice(
                                Product::getTaxCalculationMethod(
                                ) == PS_TAX_EXC ? $order->total_products : $order->total_products_wt,
                                $this->context->currency,
                                false
                            ),
                            '{total_discounts}'         => Tools::displayPrice(
                                $order->total_discounts,
                                $this->context->currency,
                                false
                            ),
                            '{total_shipping}'          => Tools::displayPrice(
                                $order->total_shipping,
                                $this->context->currency,
                                false
                            ),
                            '{total_shipping_tax_excl}' => Tools::displayPrice(
                                $order->total_shipping_tax_excl,
                                $this->context->currency,
                                false
                            ),
                            '{total_shipping_tax_incl}' => Tools::displayPrice(
                                $order->total_shipping_tax_incl,
                                $this->context->currency,
                                false
                            ),
                            '{total_wrapping}'          => Tools::displayPrice(
                                $order->total_wrapping,
                                $this->context->currency,
                                false
                            ),
                            '{total_tax_paid}'          => Tools::displayPrice(
                                ( $order->total_products_wt - $order->total_products ) + ( $order->total_shipping_tax_incl - $order->total_shipping_tax_excl ),
                                $this->context->currency,
                                false
                            ),
                        );

                        if ( is_array( $extra_vars ) ) {
                            $data = array_merge( $data, $extra_vars );
                        }

                        // Join PDF invoice
                        if ( (int) Configuration::get( 'PS_INVOICE' ) && $order_status->invoice && $order->invoice_number ) {
                            $order_invoice_list = $order->getInvoicesCollection();
                            Hook::exec( 'actionPDFInvoiceRender', array( 'order_invoice_list' => $order_invoice_list ) );
                            $pdf = new PDF(
                                $order_invoice_list,
                                PDF::TEMPLATE_INVOICE,
                                $this->context->smarty
                            );
                            $file_attachement['content'] = $pdf->render( false );
                            $file_attachement['name']    = Configuration::get(
                                'PS_INVOICE_PREFIX',
                                (int) $order->id_lang,
                                null,
                                $order->id_shop
                            ) . sprintf( '%06d', $order->invoice_number ) . '.pdf';
                            $file_attachement['mime'] = 'application/pdf';
                        } else {
                            $file_attachement = null;
                        }

                        if ( self::DEBUG_MODE ) {
                            PrestaShopLogger::addLog(
                                'PaymentModule::validateOrder - Mail is about to be sent',
                                1,
                                null,
                                'Cart',
                                (int) $id_cart,
                                true
                            );
                        }

                        $orderLanguage = new Language( (int) $order->id_lang );

                        if ( Validate::isEmail( $this->context->customer->email ) ) {
                            Mail::Send(
                                (int) $order->id_lang,
                                'order_conf',
                                Context::getContext()->getTranslator()->trans(
                                    'Order confirmation',
                                    array(),
                                    'Emails.Subject',
                                    $orderLanguage->locale
                                ),
                                $data,
                                $this->context->customer->email,
                                $this->context->customer->firstname . ' ' . $this->context->customer->lastname,
                                null,
                                null,
                                $file_attachement,
                                null,
                                _PS_MAIL_DIR_,
                                false,
                                (int) $order->id_shop
                            );
                        }
                    }

                    // Updates stock in shops
                    if ( Configuration::get( 'PS_ADVANCED_STOCK_MANAGEMENT' ) ) {
                        $product_list = $order->getProducts();
                        foreach ( $product_list as $product ) {
                            // If the available quantities depend on the physical stock
                            if ( StockAvailable::dependsOnStock( $product['product_id'] ) ) {
                                StockAvailable::synchronize( $product['product_id'], $order->id_shop );
                            }
                        }
                    }

                    $order->updateOrderDetailTax();

                    // Sync all stock
                    ( new StockManager() )->updatePhysicalProductQuantity(
                        (int) $order->id_shop,
                        (int) Configuration::get( 'PS_OS_ERROR' ),
                        (int) Configuration::get( 'PS_OS_CANCELED' ),
                        null,
                        (int) $order->id
                    );
                } else {
                    $error = $this->trans( 'Order creation failed', array(), 'Admin.Payment.Notification' );
                    PrestaShopLogger::addLog( $error, 4, '0000002', 'Cart', (int) ( $order->id_cart ) );
                    die( Tools::displayError( $error ) );
                }
            }

            // Use the last order as currentOrder
            if ( isset( $order ) && $order->id ) {
                $this->currentOrder = (int) $order->id;
            }

            if ( self::DEBUG_MODE ) {
                PrestaShopLogger::addLog(
                    'PaymentModule::validateOrder - End of validateOrder',
                    1,
                    null,
                    'Cart',
                    (int) $id_cart,
                    true
                );
            }

            return true;
        } else {
            $error = $this->trans(
                'Cart cannot be loaded or an order has already been placed using this cart',
                array(),
                'Admin.Payment.Notification'
            );
            PrestaShopLogger::addLog( $error, 4, '0000001', 'Cart', (int) ( $this->context->cart->id ) );
            $this->logData(
                "Cart cannot be loaded or an order has already been placed using this cart with id =>" . $id_cart . "\n"
            );
            die( Tools::displayError( $error ) );
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

        if ( Configuration::get( 'PS_TAX_ADDRESS_TYPE' ) == 'id_address_delivery' ) {
            $address          = new Address( (int) $addressId );
            $context->country = new Country( (int) $address->id_country, (int) $cart->id_lang );
            if ( !$context->country->active ) {
                throw new PrestaShopException( 'The delivery address country is not active.' );
            }
        }

        $carrier = null;
        if ( !$cart->isVirtualCart() && isset( $carrierId ) ) {
            $carrier           = new Carrier( (int) $carrierId, (int) $cart->id_lang );
            $order->id_carrier = (int) $carrier->id;
            $carrierId         = (int) $carrier->id;
        } else {
            $order->id_carrier = 0;
            $carrierId         = 0;
        }
        $sql1  = 'SELECT totals FROM `' . _DB_PREFIX_ . 'paygate` WHERE cart_id = ' . (int) $cart->id . ';';
        $test  = Db::getInstance()->getValue( $sql1 );
        $test1 = json_decode( $test );
        // Typcast object to array recursively to allow for integer keys
        $toArray = function ( $x ) use ( &$toArray ) {
            return is_scalar( $x )
            ? $x
            : array_map( $toArray, (array) $x );
        };
        $totals = $toArray( $test1 );

        $order->id_customer         = (int) $cart->id_customer;
        $order->id_address_invoice  = (int) $cart->id_address_invoice;
        $order->id_address_delivery = (int) $addressId;
        $order->id_currency         = $currency->id;
        $order->id_lang             = (int) $cart->id_lang;
        $order->id_cart             = (int) $cart->id;
        $order->reference           = $reference;
        $order->id_shop             = (int) $context->shop->id;
        $order->id_shop_group       = (int) $context->shop->id_shop_group;

        $order->secure_key = ( $secure_key ? pSQL( $secure_key ) : pSQL( $context->customer->secure_key ) );
        $order->payment    = $payment_method;
        if ( isset( $name ) ) {
            $order->module = $name;
        }
        $order->recyclable      = $cart->recyclable;
        $order->gift            = (int) $cart->gift;
        $order->gift_message    = $cart->gift_message;
        $order->mobile_theme    = $cart->mobile_theme;
        $order->conversion_rate = $currency->conversion_rate;
        $amount_paid            = !$dont_touch_amount ? Tools::ps_round(
            (float) $amount_paid,
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

        if ( null !== $carrier && Validate::isLoadedObject( $carrier ) ) {
            $order->carrier_tax_rate = $carrier->getTaxesRate(
                new Address( (int) $cart->{Configuration::get( 'PS_TAX_ADDRESS_TYPE' )} )
            );
        }

        $order->total_wrapping_tax_excl = $totals['total_wrapping_tax_excl'];
        $order->total_wrapping_tax_incl = $totals['total_wrapping_tax_incl'];
        $order->total_wrapping          = $totals['total_wrapping'];

        $order->total_paid_tax_excl = $totals['total_paid_tax_excl'];
        $order->total_paid_tax_incl = $totals['total_paid_tax_incl'];
        $order->total_paid          = $order->total_paid_tax_incl;
        $order->round_mode          = Configuration::get( 'PS_PRICE_ROUND_MODE' );
        $order->round_type          = Configuration::get( 'PS_ROUND_TYPE' );

        $order->invoice_date  = '0000-00-00 00:00:00';
        $order->delivery_date = '0000-00-00 00:00:00';

        // Creating order
        $result = $order->add();
        if ( !$result ) {
            $this->logData( "test\n" );
        }

        // Insert new Order detail list using cart for the current order
        $order_detail = new OrderDetail( null, null, $context );
        $order_detail->createList( $order, $cart, $id_order_state, $order->product_list, 0, true, $warehouseId );

        // Adding an entry in order_carrier table
        if ( null !== $carrier ) {
            $order_carrier                         = new OrderCarrier();
            $order_carrier->id_order               = (int) $order->id;
            $order_carrier->id_carrier             = $carrierId;
            $order_carrier->weight                 = (float) $order->getTotalWeight();
            $order_carrier->shipping_cost_tax_excl = (float) $order->total_shipping_tax_excl;
            $order_carrier->shipping_cost_tax_incl = (float) $order->total_shipping_tax_incl;
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
        $cart_rule_used = array();

        // Prepare cart calculator to correctly get the value of each cart rule
        $calculator = $cart->newCalculator( $order->product_list, $cart->getCartRules(), $order->id_carrier );
        $calculator->processCalculation( _PS_PRICE_COMPUTE_PRECISION_ );
        $cartRulesData = $calculator->getCartRulesData();

        $cart_rules_list = array();
        foreach ( $cartRulesData as $cartRuleData ) {
            $cartRule = $cartRuleData->getCartRule();
            // Here we need to get actual values from cart calculator
            $values = array(
                'tax_incl' => $cartRuleData->getDiscountApplied()->getTaxIncluded(),
                'tax_excl' => $cartRuleData->getDiscountApplied()->getTaxExcluded(),
            );

            // If the reduction is not applicable to this order, continue with the next one
            if ( !$values['tax_excl'] ) {
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
            if ( (int) $cartRule->reduction_currency !== $cart->id_currency ) {
                $cartRuleReductionAmountConverted = Tools::convertPriceFull(
                    $cartRule->reduction_amount,
                    new Currency( (int) $cartRule->reduction_currency ),
                    new Currency( $cart->id_currency )
                );
            }
            $remainingValue = $cartRuleReductionAmountConverted - $values[$cartRule->reduction_tax ? 'tax_incl' : 'tax_excl'];
            $remainingValue = Tools::ps_round( $remainingValue, _PS_PRICE_COMPUTE_PRECISION_ );
            if ( count(
                $order_list
            ) == 1 && $remainingValue > 0 && $cartRule->partial_use == 1 && $cartRuleReductionAmountConverted > 0 ) {
                // Create a new voucher from the original
                $voucher = new CartRule(
                    (int) $cartRule->id
                ); // We need to instantiate the CartRule without lang parameter to allow saving it
                unset( $voucher->id );

                // Set a new voucher code
                $voucher->code = empty( $voucher->code ) ? substr(
                    md5( $order->id . '-' . $order->id_customer . '-' . $cartRule->id ),
                    0,
                    16
                ) : $voucher->code . '-2';
                if ( preg_match(
                    '/\-([0-9]{1,2})\-([0-9]{1,2})$/',
                    $voucher->code,
                    $matches
                ) && $matches[1] == $matches[2] ) {
                    $voucher->code = preg_replace(
                        '/' . $matches[0] . '$/',
                        '-' . ( intval( $matches[1] ) + 1 ),
                        $voucher->code
                    );
                }

                // Set the new voucher value
                $voucher->reduction_amount = $remainingValue;
                if ( $voucher->reduction_tax ) {
                    // Add total shipping amout only if reduction amount > total shipping
                    if ( $voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_incl ) {
                        $voucher->reduction_amount -= $order->total_shipping_tax_incl;
                    }
                } else {
                    // Add total shipping amout only if reduction amount > total shipping
                    if ( $voucher->free_shipping == 1 && $voucher->reduction_amount >= $order->total_shipping_tax_excl ) {
                        $voucher->reduction_amount -= $order->total_shipping_tax_excl;
                    }
                }
                if ( $voucher->reduction_amount <= 0 ) {
                    continue;
                }

                if ( $this->context->customer->isGuest() ) {
                    $voucher->id_customer = 0;
                } else {
                    $voucher->id_customer = $order->id_customer;
                }

                $voucher->quantity           = 1;
                $voucher->reduction_currency = $order->id_currency;
                $voucher->quantity_per_user  = 1;
                if ( $voucher->add() ) {
                    // If the voucher has conditions, they are now copied to the new voucher
                    CartRule::copyConditions( $cartRule->id, $voucher->id );
                    $orderLanguage = new Language( (int) $order->id_lang );

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
                    Mail::Send(
                        (int) $order->id_lang,
                        'voucher',
                        Context::getContext()->getTranslator()->trans(
                            'New voucher for your order %s',
                            array( $order->reference ),
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
                        (int) $order->id_shop
                    );
                }

                $values['tax_incl'] = $order->total_products_wt - $total_reduction_value_ti;
                $values['tax_excl'] = $order->total_products - $total_reduction_value_tex;
                if ( 1 == $voucher->free_shipping ) {
                    $values['tax_incl'] += $order->total_shipping_tax_incl;
                    $values['tax_excl'] += $order->total_shipping_tax_excl;
                }
            }
            $total_reduction_value_ti += $values['tax_incl'];
            $total_reduction_value_tex += $values['tax_excl'];

            $order->addCartRule( $cartRule->id, $cartRule->name, $values, 0, $cartRule->free_shipping );

            if ( $id_order_state != Configuration::get( 'PS_OS_ERROR' ) && $id_order_state != Configuration::get(
                'PS_OS_CANCELED'
            ) && !in_array( $cartRule->id, $cart_rule_used ) ) {
                $cart_rule_used[] = $cartRule->id;

                // Create a new instance of Cart Rule without id_lang, in order to update its quantity
                $cart_rule_to_update           = new CartRule( (int) $cartRule->id );
                $cart_rule_to_update->quantity = max( 0, $cart_rule_to_update->quantity - 1 );
                $cart_rule_to_update->update();
            }

            $cart_rules_list[] = array(
                'voucher_name'      => $cartRule->name,
                'voucher_reduction' => ( $values['tax_incl'] != 0.00 ? '-' : '' ) . Tools::displayPrice(
                    $values['tax_incl'],
                    $this->context->currency,
                    false
                ),
            );
        }

        return $cart_rules_list;
    }

}
