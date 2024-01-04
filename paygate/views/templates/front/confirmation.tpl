{*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *}
{extends file='page.tpl'}
{block name='content'}
    <div class="card">
        <div class="card-block">
            <h1>
                {if empty($status) || $status == 2}
                    {l s='Transaction declined' mod='paygate'}
                {elseif $status == 4}
                    {l s='Transaction cancelled' mod='paygate'}
                {/if}
            </h1>
            <p>Please <a href="{$link->getPageLink('cart')}?action=show">{l s='click here' mod='paygate'}</a> to try
                again.</p>
        </div>
    </div>
    <br/>
{/block}
