{*
* Copyright (c) 2025 Payfast (Pty) Ltd
*
* Author: App Inlet (Pty) Ltd
*
* Released under the GNU General Public License
*}

<div class="box">
    <h3 class="page-subheading">{l s='Your order on %s is complete.' sprintf=[$shop_name] d='Modules.Paygate.Shop'}</h3>
    <p>
        <strong>{l s='Your order reference:' d='Modules.Paygate.Shop'}</strong>
        <span>{$reference}</span>
    </p>
    <p>
        {l s='An email has been sent with this information.' d='Modules.Paygate.Shop'}
        <br>
        {l s='If you have questions, comments or concerns, please contact our' d='Modules.Paygate.Shop'}
        <a href="{$contact_url}">{l s='expert customer support team' d='Modules.Paygate.Shop'}</a>.
    </p>
</div>
