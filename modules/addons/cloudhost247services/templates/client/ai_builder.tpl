{if !$status.configured}
<div class="alert alert-info">
  <h4><strong>Setting up the AI Website Builder</strong></h4>
  <p>Our AI builder drafts real, structured websites — pages, sections, copy, SEO metadata and design direction —
  from a plain-language brief. It answers only through a configured provider, and the provider connection is being
  finalised by our team (missing: {foreach $status.missing as $m}<code>{$m|escape}</code> {/foreach}).</p>
  <p>Meanwhile, two fully working options:</p>
  <a class="btn btn-primary" href="{$WEB_ROOT}/store/sitebuilder/index">Build it yourself — Website Builder plans</a>
  <a class="btn btn-default" href="{$modulelink}&action=requestnew&type=ai_website">Have our team build it for you</a>
</div>
{else}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Describe your website</strong></div>
  <div class="panel-body">
    {if $errors}<div class="alert alert-danger">{foreach $errors as $e}<div>{$e|escape}</div>{/foreach}</div>{/if}
    <form method="post" action="{$modulelink}&action=ai">
      {$csrf_field}
      <div class="form-group">
        <textarea class="form-control" name="brief" rows="4" maxlength="500" required
          placeholder="e.g. A professional website for an international logistics company with shipment tracking, quote requests and multilingual support.">{if $smarty.post.brief}{$smarty.post.brief|escape}{/if}</textarea>
        <span class="help-block">One or two sentences is perfect. 20–500 characters.</span>
      </div>
      <div class="row">
        <div class="col-sm-6"><div class="form-group"><label>Industry (optional)</label>
          <select class="form-control" name="industry">
            <option value="">Let the AI decide</option>
            {foreach $industries as $key => $i}<option value="{$key|escape}">{$i.label|escape}</option>{/foreach}
          </select></div></div>
        <div class="col-sm-6"><div class="form-group"><label>Pages</label>
          <select class="form-control" name="pages">
            {foreach [3,4,5,6] as $p}<option value="{$p}"{if $p eq 4} selected{/if}>{$p} pages</option>{/foreach}
          </select></div></div>
      </div>
      <button class="btn btn-primary btn-lg">Generate my website outline</button>
      <span class="help-block">Generates structure, copy and design direction. You keep editing everything afterwards.</span>
    </form>
  </div>
</div>
{/if}

{if $history}
<div class="panel panel-default">
  <div class="panel-heading"><strong>My generated outlines</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>Site</th><th>Brief</th><th>Pages</th><th>When</th></tr></thead>
    <tbody>
    {foreach $history as $g}
    <tr>
      <td><a href="{$modulelink}&action=generation&id={$g.id}">{$g.summary.site_title|escape}</a></td>
      <td class="text-muted">{$g.prompt|escape|truncate:80}</td>
      <td>{$g.summary.pages}</td>
      <td>{$g.created_at|date_format:"%e %b %Y"}</td>
    </tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
{/if}
