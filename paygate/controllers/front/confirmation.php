<?php
/*
 * Copyright (c) 2018 PayGate (Pty) Ltd
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

        $status = null;

        $cart = new Cart( $this->context->cookie->cart_id );

        if ( $cart->secure_key == Tools::getValue( 'key' ) && isset( $_POST['TRANSACTION_STATUS'] ) && !empty( $_POST['TRANSACTION_STATUS'] ) ) {

            switch ( $_POST['TRANSACTION_STATUS'] ) {
                case '1':
                    Tools::redirect( $this->context->link->getPageLink( 'order-confirmation', null, null, 'key=' . $cart->secure_key . '&id_cart=' . (int) ( $cart->id ) . '&id_module=' . (int) ( $this->module->id ) ) );
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
