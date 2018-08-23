<?php
/*
 * Copyright (c) 2018 PayGate (Pty) Ltd
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

    public function __construct()
    {
        $this->name        = 'paygate';
        $this->tab         = 'payments_gateways';
        $this->version     = '1.7.4';
        $this->author      = 'PayGate';
        $this->controllers = array( 'payment', 'validation' );

        $this->bootstrap = true;
        parent::__construct();

        $this->displayName            = $this->trans( 'PayGate', array(), 'Modules.Paygate.Admin' );
        $this->description            = $this->trans( 'Accept payments via PayGate.', array(), 'Modules.Paygate.Admin' );
        $this->confirmUninstall       = $this->trans( 'Are you sure you want to delete your details ?', array(), 'Modules.Paygate.Admin' );
        $this->ps_versions_compliancy = array( 'min' => '1.7.1.0', 'max' => _PS_VERSION_ );
    }

    public function install()
    {
        return parent::install()
        && $this->registerHook( 'paymentOptions' )
        && $this->registerHook( 'paymentReturn' )
        ;
    }

    public function hookPaymentOptions( $params )
    {
        if ( !$this->active ) {
            return;
        }

        $paymentOption = new PaymentOption();
        $paymentOption->setModuleName( $this->name )
            ->setCallToActionText( $this->trans( 'Pay using PayGate', array(), 'Modules.Paygate.Admin' ) )
            ->setAction( $this->context->link->getModuleLink( $this->name, 'payment', array(), true ) );

        return [$paymentOption];
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
                    'title' => $this->trans( 'Settings', array(), 'Modules.Paygate.Admin' ),
                    'icon'  => 'icon-envelope',
                ),
                'input'  => array(
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans( 'PayGate ID', array(), 'Modules.Checkpayment.Admin' ),
                        'name'     => 'PAYGATE_ID',
                        'required' => true,
                    ),
                    array(
                        'type'     => 'text',
                        'label'    => $this->trans( 'Encryption Key', array(), 'Modules.Paygate.Admin' ),
                        'name'     => 'PAYGATE_ENCRYPTION_KEY',
                        'required' => true,
                    ),
                    array(
                        'type'   => 'switch',
                        'label'  => $this->trans( 'Debug', array(), 'Modules.Paygate.Admin' ),
                        'name'   => 'PAYGATE_LOGS',
                        'values' => array(
                            array(
                                'id'    => 'active_on',
                                'value' => 1,
                                'label' => $this->trans( 'Yes', array(), 'Modules.Paygate.Admin' ),
                            ),
                            array(
                                'id'    => 'active_off',
                                'value' => 0,
                                'label' => $this->trans( 'No', array(), 'Modules.Paygate.Admin' ),
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
        $helper->currentIndex  = $this->context->link->getAdminLink( 'AdminModules', false ) . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->token         = Tools::getAdminTokenLite( 'AdminModules' );
        $helper->tpl_vars      = array(
            'fields_value' => $this->getConfigFieldsValues(),
        );

        $this->fields_form = array();

        return $helper->generateForm( array( $fields_form ) );
    }

    private function _postValidation()
    {
        if ( Tools::isSubmit( 'btnSubmit' ) ) {
            if ( !Tools::getValue( 'PAYGATE_ID' ) ) {
                $this->_postErrors[] = $this->trans( 'The "PayGate ID" field is required.', array(), 'Modules.Checkpayment.Admin' );
            } elseif ( !Tools::getValue( 'PAYGATE_ENCRYPTION_KEY' ) ) {
                $this->_postErrors[] = $this->trans( 'The "Encryption Key" field is required.', array(), 'Modules.Checkpayment.Admin' );
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
        $this->_html .= $this->displayConfirmation( $this->trans( 'Settings updated', array(), 'Admin.Notifications.Success' ) );
    }

    public function getConfigFieldsValues()
    {
        return array(
            'PAYGATE_ID'             => Tools::getValue( 'PAYGATE_ID', Configuration::get( 'PAYGATE_ID' ) ),
            'PAYGATE_ENCRYPTION_KEY' => Tools::getValue( 'PAYGATE_ENCRYPTION_KEY', Configuration::get( 'PAYGATE_ENCRYPTION_KEY' ) ),
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

}
