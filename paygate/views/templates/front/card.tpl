{*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *}
{extends file='customer/page.tpl'}

{block name='page_title'}
    {l s='My cards' d='Modules.Paygate.Card'}
{/block}

{block name="page_content"}
    {if !empty($vaults)}
        <ul>
            {foreach from=$vaults item=vault}
                <li class="p-1 m-1"
                    style="display:flex;align-items:center;background:white;justify-content: space-between">
                    <span>Card ending {$vault.last_four} expiring {$vault.expiry}</span>
                    <button type="button" class="paygate-vault-delete" data-id="{$vault.id_vault}">Delete</button>
                </li>
            {/foreach}
        </ul>
    {else}
        <div class="alert alert-info" role="alert"
             data-alert="info">{l s='No cards are stored yet.' d='Modules.Paygate.Shop'}</div>
    {/if}
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        document.body.addEventListener('click', function (event) {
          if (event.target && event.target.matches('button.paygate-vault-delete')) {
            let vaultId = event.target.getAttribute('data-id');
            if (!confirm('Are you sure you want to delete this card?')) {
              return;
            }
            let url = `{$deleteUrl}?method=deleteVault`;
            let data = {
              vaultId: vaultId
            };
            fetch(url, {
              method: 'POST',
              headers: {
                'Content-Type': 'application/json;charset=utf-8'
              },
              body: JSON.stringify(data)
            })
              .then(response => response.json())
              .then(result => {
                if (result.message === 'Success') {
                  window.location.href = `{$deleteUrl}`;
                } else {
                  alert(result.error);
                }
              })
              .catch(error => {
                console.error('Error:', error);
              });
          }
        });
      });
    </script>
{/block}
