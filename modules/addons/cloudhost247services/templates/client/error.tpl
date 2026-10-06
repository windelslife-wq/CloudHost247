<div class="alert alert-danger chs-alert-top">
  <strong>{$pagetitle|escape}.</strong>
  {if $error_message}<p style="margin:8px 0 0">{$error_message|escape}</p>{/if>
  {if $error_fields}
  <ul style="margin:8px 0 0">
    {foreach $error_fields as $field => $message}
      <li><strong>{$field|escape}</strong>: {$message|escape}</li>
    {/foreach}
  </ul>
  {/if}
  <p style="margin:8px 0 0"><a href="javascript:history.back()">← Go back and try again</a> · <a href="{$modulelink}">Services dashboard</a></p>
</div>
