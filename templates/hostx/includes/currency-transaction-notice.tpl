{* Browsing currency never rewrites a billing document or account currency. *}
{if $ch247TransactionNotice}
    <div class="ch247-currency-notice" role="note">{$ch247TransactionNotice|escape:'html':'UTF-8'}</div>
{/if}
