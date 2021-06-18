<?php
/*
 * Copyright (c) 2021 PayGate (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */
class PaygateMethodsList
{
	
	public function getPaygateMethodsList(){
		
		return $paygatePayMethods = [
			'creditcard'   => [
				'name'  => 'paygate-paymethod',
				'label' => 'Credit Card',
				'img'   => '../modules/paygate/assets/images/mastercard-visa.svg',
				'ptype' => 'CC',
				'type'  => 'radio',
				'value' => 'creditcard',
				'title' => 'Credit Card',
			],
			'banktransfer' => [
				'name'  => 'paygate-paymethod',
				'label' => 'Bank Transfer',
				'img'   => '../modules/paygate/assets/images/sid.svg',
				'ptype' => 'BT',
				'type'  => 'radio',

			],
			'zapper'       => [
				'name'  => 'paygate-paymethod',
				'label' => 'Zapper',
				'img'   => '../modules/paygate/assets/images/zapper.svg',
				'ptype' => '',
				'type' => 'radio',
			],
			'snapscan'     => [
				'name'  => 'paygate-paymethod',
				'label' => 'SnapScan',
				'img'   => '../modules/paygate/assets/images/snapscan.svg',
				'ptype' => 'EW',
				'type' => 'radio',
			],
			'mobicred'     => [
				'name'  => 'paygate-paymethod',
				'label' => 'MobiCred',
				'img'   => '../modules/paygate/assets/images/mobicred.svg',
				'ptype' => 'EW',
				'type' => 'radio',
			],
			'momopay'      => [
				'name'  => 'paygate-paymethod',
				'label' => 'MomoPay',
				'img'   => '../modules/paygate/assets/images/momopay.svg',
				'ptype' => 'EW',
				'type' => 'radio',
			],
			'masterpass'   => [
				'name'  => 'paygate-paymethod',
				'label' => 'MasterPass',
				'img'   => '../modules/paygate/assets/images/masterpass.svg',
				'ptype' => 'EW',
				'type' => 'radio',
			],
		];
	}

}
