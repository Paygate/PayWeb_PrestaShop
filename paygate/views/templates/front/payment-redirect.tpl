{*
 * Copyright (c) 2024 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *}
<div class="payViaPaygate hidden">
    <form id="payViaPaygate" action="https://secure.paygate.co.za/payweb3/process.trans" method="post">
        <p class="payment_module">
            <input type="hidden" name="PAY_REQUEST_ID" value="{$data.PAY_REQUEST_ID}"/>
            <input type="hidden" name="CHECKSUM" value="{$data.CHECKSUM}"/>
        </p>
    </form>
</div>
<div class="clear"></div>
<script type="text/javascript">
  document.getElementById('payViaPaygate').submit()
</script>
