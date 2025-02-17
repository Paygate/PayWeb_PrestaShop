{*
 * Copyright (c) 2025 Payfast (Pty) Ltd
 *
 * Author: App Inlet (Pty) Ltd
 *
 * Released under the GNU General Public License
 *}
<div class="payViaPaygate hidden">
  <!-- Insert the generated form -->
  {$redirectHTML nofilter}
</div>
<div class="clear"></div>
<script type="text/javascript">
  document.getElementById('paygate_payment_form').submit()
</script>
