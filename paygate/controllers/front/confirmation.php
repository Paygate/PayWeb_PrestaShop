<?php
/*
 * Copyright (c) 2020 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PaygateConfirmationModuleFrontController extends ModuleFrontController
{

    public function initContent()
    {
        parent::initContent();
        $order = null;

        $status = null;

        $cart   = new Cart( $this->context->cookie->cart_id );
        $key    = Tools::getValue( 'secure_key' );
        $ispaid = false;

        // Check to see if there is already an order for this cart - it may have been created by notify (validate.php)
        if ( $cart->orderExists() ) {
            // Get order
            $order  = Order::getByCartId( $cart->id );
            $ispaid = $order->hasBeenPaid();
        }

        if ( $ispaid ) {
            Tools::redirect( $this->context->link->getPageLink( 'order-confirmation', null, null, 'key=' . $cart->secure_key . '&id_cart=' . (int) ( $cart->id ) . '&id_module=' . (int) ( $this->module->id ) ) );
        }

        if ( $key == $cart->secure_key && isset( $_POST['TRANSACTION_STATUS'] ) && !empty( $_POST['TRANSACTION_STATUS'] ) ) {

            switch ( $_POST['TRANSACTION_STATUS'] ) {
                case '1':
                    // Make POST request to PayGate to query the transaction and get full response data
                    $post         = $_POST;
                    $pg_checksum  = array_pop( $post );
                    $reference    = $this->context->cookie->reference;
                    $pg_id        = Configuration::get( 'PAYGATE_ID' );
                    $pg_key       = Configuration::get( 'PAYGATE_ENCRYPTION_KEY' );
                    $our_checksum = md5( $pg_id . implode( '', $post ) . $reference . $pg_key );

                    if ( hash_equals( $our_checksum, $pg_checksum ) ) {
                        $data                   = [];
                        $data['PAYGATE_ID']     = $pg_id;
                        $data['PAY_REQUEST_ID'] = $post['PAY_REQUEST_ID'];
                        $data['REFERENCE']      = $reference;
                        $data['CHECKSUM']       = md5( implode( '', $data ) . $pg_key );
                        $fieldsString           = http_build_query( $data );

                        // Open connection
                        $ch = curl_init();

                        // Set the url, number of POST vars, POST data
                        curl_setopt( $ch, CURLOPT_URL, 'https://secure.paygate.co.za/payweb3/query.trans' );
                        curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
                        curl_setopt( $ch, CURLOPT_NOBODY, false );
                        curl_setopt( $ch, CURLOPT_REFERER, $_SERVER['HTTP_HOST'] );
                        curl_setopt( $ch, CURLOPT_POST, true );
                        curl_setopt( $ch, CURLOPT_POSTFIELDS, $fieldsString );

                        // Execute post
                        $result = curl_exec( $ch );
                        parse_str( $result, $result );
                        curl_close( $ch );

                        if ( is_array( $result ) && isset( $result['TRANSACTION_STATUS'] ) ) {
                            // Update purchase status
                            $method_name = $this->module->displayName;
                            if (Configuration::get( 'PAYGATE_IPN_TOGGLE' )) {
                                if ( !$order ) {
                                    $this->module->validateOrder( $cart->id, _PS_OS_PAYMENT_, (float) ( $result['AMOUNT'] / 100.0 ),
                                        $method_name, null, array( 'transaction_id' => $result['USER1'] ), null, false, $cart->secure_key );
                                } else {
                                    $order->addOrderPayment( (float) ( $result['AMOUNT'] / 100.0 ), $method_name, $cart->secure_key );
                                }
                            }
                            Tools::redirect( $this->context->link->getPageLink( 'order-confirmation', null, null, 'key=' . $cart->secure_key . '&id_cart=' . (int) ( $cart->id ) . '&id_module=' . (int) ( $this->module->id ) ) );
                        }
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

        }

        $this->context->smarty->assign( 'status', $status );

        $this->setTemplate( 'module:paygate/views/templates/front/confirmation.tpl' );
    }

}
