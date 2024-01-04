<?php
/*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 */

class PaygatePayVaultModuleFrontController extends ModuleFrontController
{
    /**
     * @return array
     */
    public function getBreadcrumbLinks(): array
    {
        $breadcrumb            = parent::getBreadcrumbLinks();
        $breadcrumb['links'][] = $this->addMyAccountToBreadcrumb();
        $breadcrumb['links'][] = [
            'title' => $this->trans('My cards', [], 'Modules.Paygate.Shop'),
            'url'   => $this->context->link->getModuleLink('paygate', 'payvault'),
        ];

        return $breadcrumb;
    }

    /**
     *
     * @throws \PrestaShopException
     */
    public function initContent()
    {
        parent::initContent();
        $this->context = Context::getContext();
        $id_customer   = $this->context->customer->id;

        if (!Context::getContext()->customer->isLogged()) {
            Tools::redirect('index.php?controller=authentication&redirect=module&module=paygate&action=payvault');
        }

        $deleteUrl = Context::getContext()->link->getModuleLink(
            $this->module->name,
            'payvault'
        );

        if (isset($_GET['method']) && $_GET['method'] === 'deleteVault') {
            $data = file_get_contents('php://input');
            if ($data !== '') {
                $data = json_decode($data);

                echo $this->deleteVault($data->vaultId);
                die();
            }
        }

        $vaults = PayVault::customerVaults($id_customer);
        $this->setVaultTemplate($vaults, $deleteUrl);
    }

    private function setVaultTemplate(array $vaults, string $deleteUrl)
    {
        if (Context::getContext()->customer->id) {
            $this->context->smarty->assign('id_customer', Context::getContext()->customer->id);
            $this->context->smarty->assign(
                'vaults',
                $vaults
            );
            $this->context->smarty->assign(
                'deleteUrl',
                $deleteUrl
            );

            $this->setTemplate('module:paygate/views/templates/front/card.tpl');
        }
    }

    /**
     * @param int $vaultId
     *
     * @return string
     */
    private function deleteVault(int $vaultId): string
    {
        try {
            Db::getInstance()->delete(
                'paygate_vaults',
                "id_vault = $vaultId"
            );

            return json_encode(['message' => 'Success']);
        } catch (Exception $exception) {
            return json_encode(['error' => $exception->getMessage()]);
        }
    }
}
