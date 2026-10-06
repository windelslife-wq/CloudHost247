<link rel="stylesheet" type="text/css" href="{$WEB_ROOT}/modules/gateways/blockonomics/assets/css/order.css">

<div class="bnomics-order-container">
  <div class="bnomics-select-container">
    <tr>
      {foreach $cryptos as $code => $crypto}
        <a href="{$WEB_ROOT}/modules/gateways/blockonomics/payment.php?show_order={$order_hash}&crypto={$code}">
          <button class="bnomics-select-options button btn btn-lg">
            <span class="bnomics-icon-{$code} bnomics-rotate-{$code}"></span>
            <span class="vertical-line">
              {if $code == 'usdt' && $usdt_network_display}
                {$_BLOCKLANG.payWith} {$usdt_network_display}
              {else}
                {$_BLOCKLANG.payWith} {$crypto['name']}
              {/if}
            </span>
          </button>
        </a>
      {/foreach}
    </tr>
  </div>
</div>
