<?php
/*
 * Copyright (c) 2018 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 * 
 * Released under the GNU General Public License
 */
class PaygateValidateModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();
        $error_msg = '';
        $errors    = false;

        $notify_data    = array();
        $post_data      = '';
        $checkSumParams = '';

        $this->module->logData( "=========Notify Response: " . date( 'Y-m-d H:i:s' ) . "============\n\n" );

        if ( !$errors ) {

            foreach ( $_POST as $key => $val ) {
                $post_data .= $key . '=' . $val . "\n";
                $notify_data[$key] = stripslashes( $val );

                if ( $key == 'PAYGATE_ID' ) {
                    $checkSumParams .= Configuration::get( 'PAYGATE_ID' );
                } else if ( $key != 'CHECKSUM' ) {
                    $checkSumParams .= $val;
                }
                if ( sizeof( $notify_data ) == 0 ) {
                    $error_msg = 'Notify post response is empty';
                    $errors    = true;
                }
            }

            $checkSumParams .= Configuration::get( 'ENCRYPTION_KEY' );
        }

        $this->module->logData( $post_data );
        $this->module->logData( "\n" );

        if ( empty( Context::getContext()->link ) ) {
            Context::getContext()->link = new Link();
        }

        // Verify security signature
        if ( !$errors ) {
            $checkSumParams = md5( $checkSumParams );
            if ( $checkSumParams != $notify_data['CHECKSUM'] ) {
                $error_message = 'Invalid checksum, checksum: ' . $checkSumParams;
            }
        }

        // Check status and update order
        if ( !$errors ) {
            $transaction_id = $notify_data['TRANSACTION_ID'];
            $method_name    = $this->module->displayName;

            if ( $notify_data['PAY_METHOD_DETAIL'] != '' ) {
                $method_name = $notify_data['PAY_METHOD_DETAIL'] . ' via PayGate';
            }

            switch ( $notify_data['TRANSACTION_STATUS'] ) {
                case '1':
                    // Update the purchase status
                    $this->module->validateOrder( (int) $notify_data['USER1'], _PS_OS_PAYMENT_, ( (int) $notify_data['AMOUNT'] ) / 100,
                        $method_name, null, array( 'transaction_id' => $transaction_id ), null, false, $notify_data['USER2'] );
                    $this->module->logData( "Done updating order status\n\n" );
                    break;

                case '2':
                    // Update the purchase status - uncomment if you want an order to be created
                    //$this->module->validateOrder( (int) $notify_data['USER1'], _PS_OS_ERROR_, ( (int) $notify_data['AMOUNT'] ) / 100,
                     //   $method_name, null, array( 'transaction_id' => $transaction_id ), null, false, $notify_data['USER2'] );
                    //$this->module->logData( "Done updating order status\n\n" );
                    break;

                case '4':
                    // Update the purchase status - uncomment if you want an order to be created
                   // $this->module->validateOrder( (int) $notify_data['USER1'], _PS_OS_CANCELED_, ( (int) $notify_data['AMOUNT'] ) / 100,
                   //     $method_name, null, array( 'transaction_id' => $transaction_id ), null, false, $notify_data['USER2'] );
                   // $this->module->logData( "Done updating order status\n\n" );
                    break;

                default:
                    // If unknown status, do nothing (safest course of action)
                    break;
            }
        }

        if ( $errors ) {
            $this->module->logData( $error_msg . "\n" );
        }

        // Notify PayGate that information has been received
        die( 'OK' );
    }
}
