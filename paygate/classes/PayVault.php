<?php
/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PayVault
{
    /**
     * @param int $customerId
     * @param array $result
     *
     * @return void
     * @throws \PrestaShopDatabaseException
     */
    public static function storeVault(int $customerId, array $result): void
    {
        PrestaShopLogger::addLog('In storeVault: ' . $customerId);
        if ((int)Configuration::get('PAYGATE_PAY_VAULT') === 1 && !empty($result['VAULT_ID'])) {
            $dbPrefix = _DB_PREFIX_;
            $existing = Db::getInstance()->getValue(
                "SELECT id_vault FROM `{$dbPrefix}paygate_vaults` WHERE vault_id='{$result['VAULT_ID']}'"
            );

            $firstSix = substr($result['PAYVAULT_DATA_1'], 0, 6);
            $lastFour = substr($result['PAYVAULT_DATA_1'], -4, 4);

            if (!$existing) {
                Db::getInstance()->insert(
                    'paygate_vaults',
                    [
                        'id_customer' => $customerId,
                        'vault_id'    => $result['VAULT_ID'],
                        'first_six'   => $firstSix,
                        'last_four'   => $lastFour,
                        'expiry'      => $result['PAYVAULT_DATA_2'],
                    ]
                );
            } else {
                Db::getInstance()->update(
                    'paygate_vaults',
                    [
                        'id_customer' => $customerId,
                        'first_six'   => $firstSix,
                        'last_four'   => $lastFour,
                        'expiry'      => $result['PAYVAULT_DATA_2'],
                    ],
                    "vault_id = '$result[VAULT_ID]'"
                );
            }
        }
    }

    /**
     * @param int $customerId
     *
     * @return array
     * @throws \PrestaShopDatabaseException
     */
    public static function customerVaults(int $customerId): array
    {
        $dbPrefix = _DB_PREFIX_;
        $db       = Db::getInstance();
        $query    = $db->query(
            "SELECT * FROM `{$dbPrefix}paygate_vaults` WHERE id_customer='$customerId'"
        );
        $vaults   = [];
        while ($row = $db->nextRow($query)) {
            $vaults[] = $row;
        }

        return $vaults;
    }

    /**
     * @param int $customerId
     * @param string $idVault
     *
     * @return bool|string
     */
    public static function customerVault(int $customerId, string $idVault): bool|string
    {
        $dbPrefix = _DB_PREFIX_;
        $db       = Db::getInstance();
        $vault    = $db->getValue(
            "select vault_id from `{$dbPrefix}paygate_vaults` where id_vault='$idVault' 
      and id_customer='$customerId'"
        ) ?? '';

        return $vault;
    }
}
