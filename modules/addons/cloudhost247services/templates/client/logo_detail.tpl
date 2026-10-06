<div class="panel panel-default">
  <div class="panel-heading"><strong>{$project.company_name|escape}</strong>
    <span class="text-muted pull-right small">{$project.concept_key|ucwords} · {$project.palette|ucwords} palette · {$project.style|ucwords}</span></div>
  <div class="panel-body">
    <div class="chs-logo-preview">{$project.svg nofilter}</div>
    <div class="chs-logo-actions" style="margin-top:16px">
      <a class="btn btn-primary" href="{$modulelink}&action=logo&id={$project.id}&export=svg">Download SVG</a>
      {if $png_available}
      <a class="btn btn-default" href="{$modulelink}&action=logo&id={$project.id}&export=png">Download PNG (1200px)</a>
      {else}
      <span class="btn btn-default disabled" title="PNG rendering needs the Imagick extension on this server">PNG unavailable on this server</span>
      {/if}
      <form method="post" action="{$modulelink}&action=logos" class="chs-inline-form pull-right"
            onsubmit="return confirm('Delete this project?')">
        {$csrf_field}<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="{$project.id}">
        <button class="btn btn-danger">Delete project</button>
      </form>
    </div>
    <p class="text-muted" style="margin-top:12px">The SVG stays yours forever: edit it in Figma, Illustrator or any
    browser-ready vector tool. Need variations or a full brand kit?
    <a href="{$modulelink}&action=requestnew&type=website_design">Brief our design team</a>.</p>
  </div>
</div>
