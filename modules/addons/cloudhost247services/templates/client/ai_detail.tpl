{assign var=r value=$generation.result}
<div class="panel panel-default">
  <div class="panel-heading"><strong>{$r.site_title|escape}</strong>
    <span class="text-muted pull-right small">{$generation.model|escape} · {$generation.duration_ms}ms · {$generation.created_at|date_format:"%e %b %Y"}</span></div>
  <div class="panel-body">
    {if $r.tagline}<p class="lead">{$r.tagline|escape}</p>{/if}
    <p class="text-muted">Brief: “{$generation.prompt|escape}”</p>

    <h3>Pages &amp; sections</h3>
    {foreach $r.pages as $p}
    <div class="panel panel-default chs-ai-page">
      <div class="panel-heading"><strong>{$p.title|escape}</strong> <code>/{$p.slug|escape}</code>
        {if $p.purpose}<div class="text-muted small">{$p.purpose|escape}</div>{/if}</div>
      <div class="panel-body">
        {foreach $p.sections as $s}
        <div class="chs-ai-section">
          <span class="label label-default">{$s.type|escape}</span>
          {if $s.heading}<strong> {$s.heading|escape}</strong>{/if}
          {if $s.body}<p>{$s.body|escape}</p>{/if}
          {if $s.cta}<button class="btn btn-xs btn-primary">{$s.cta|escape}</button>{/if}
        </div>
        {/foreach}
      </div>
    </div>
    {/foreach}

    <h3>SEO</h3>
    <table class="table table-condensed">
      <tr><td>Title</td><td>{$r.seo.title|escape}</td></tr>
      <tr><td>Description</td><td>{$r.seo.description|escape}</td></tr>
      {if $r.seo.keywords}<tr><td>Keywords</td><td>{", "|implode:$r.seo.keywords}</td></tr>{/if}
    </table>

    <h3>Design direction</h3>
    <p>{if $r.design.mood}<span class="label label-info">{$r.design.mood|escape}</span>{/if}
      {if $r.design.typography}{$r.design.typography|escape}{/if}</p>
    {if $r.design.palette}
    <div class="chs-palette-row">
      {foreach $r.design.palette as $c}<span class="chs-swatch" style="background:{$c|escape}" title="{$c|escape}"></span>{/foreach}
    </div>
    {/if}

    {if $r.images}
    <h3>Imagery recommendations</h3>
    <ul>{foreach $r.images as $img}<li>{$img|escape}</li>{/foreach}</ul>
    {/if}

    <p style="margin-top:16px">
      <a class="btn btn-primary" href="{$WEB_ROOT}/store/sitebuilder/index">Build this with the Website Builder</a>
      <a class="btn btn-default" href="{$modulelink}&action=requestnew&type=website_development">Have our team implement it</a>
    </p>
  </div>
</div>
