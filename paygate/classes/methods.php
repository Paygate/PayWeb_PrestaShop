<?php

/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PaygateMethodsList
{

    public function getPaygateMethodsList()
    {
        return  [
            'creditcard'   => [
                'name'        => 'paygate-paymethod',
                'label'       => 'Card',
                'img'         => '../modules/paygate/assets/images/mastercard-visa.svg',
                'ptype'       => 'CC',
                'ptypedetail' => 'Credit Card',
                'type'        => 'radio',
                'value'       => 'creditcard',
                'title'       => 'Card',
            ],
            'banktransfer' => [
                'name'        => 'paygate-paymethod',
                'label'       => 'Bank Transfer',
                'img'         => '../modules/paygate/assets/images/sid.svg',
                'ptype'       => 'BT',
                'ptypedetail' => 'SID',
                'type'        => 'radio',

            ],
            'zapper'       => [
                'name'        => 'paygate-paymethod',
                'label'       => 'Zapper',
                'img'         => '../modules/paygate/assets/images/zapper.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'Zapper',
                'type'        => 'radio',
            ],
            'snapscan'     => [
                'name'        => 'paygate-paymethod',
                'label'       => 'SnapScan',
                'img'         => '../modules/paygate/assets/images/snapscan.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'SnapScan',
                'type'        => 'radio',
            ],
            'paypal'       => [
                'name'        => 'paygate-paymethod',
                'label'       => 'PayPal',
                'img'         => '../modules/paygate/assets/images/paypal.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'PayPal',
                'type'        => 'radio',
            ],
            'mobicred'     => [
                'name'        => 'paygate-paymethod',
                'label'       => 'MobiCred',
                'img'         => '../modules/paygate/assets/images/mobicred.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'Mobicred',
                'type'        => 'radio',
            ],
            'momopay'      => [
                'name'        => 'paygate-paymethod',
                'label'       => 'MomoPay',
                'img'         => '../modules/paygate/assets/images/momopay.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'Momopay',
                'type'        => 'radio',
            ],
            'scantopay'   => [
                'name'        => 'paygate-paymethod',
                'label'       => 'ScanToPay',
                'img'         => '../modules/paygate/assets/images/scan-to-pay.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'MasterPass',
                'type'        => 'radio',
            ],
            'applepay'   => [
                'name'        => 'paygate-paymethod',
                'label'       => 'ApplePay',
                'img'         => '../modules/paygate/assets/images/apple-pay.svg',
                'ptype'       => 'CC',
                'ptypedetail' => 'Applepay',
                'type'        => 'radio',
            ],
            'rcs'   => [
                'name'        => 'paygate-paymethod',
                'label'       => 'RCS',
                'img'         => '../modules/paygate/assets/images/rcs.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'RCS',
                'type'        => 'radio',
            ],
            'samsungpay'   => [
                'name'        => 'paygate-paymethod',
                'label'       => 'SamsungPay',
                'img'         => '../modules/paygate/assets/images/samsung-pay.svg',
                'ptype'       => 'EW',
                'ptypedetail' => 'Samsungpay',
                'type'        => 'radio',
            ],
        ];
    }

}
