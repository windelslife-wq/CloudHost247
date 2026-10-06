<div class="chs-valuation">
  <div class="panel panel-default">
    <div class="panel-heading"><strong>Estimate a domain's market value</strong></div>
    <div class="panel-body">
      <form method="post" action="{$modulelink}&action=valuation" class="chs-inline-form">
        {$csrf_field}
        <div class="input-group">
          <input class="form-control input-lg" name="domain" placeholder="yourbrand.com"
                 value="{if $smarty.post.domain}{$smarty.post.domain|escape}{/if}" required>
          <span class="input-group-btn"><button class="btn btn-primary btn-lg">Estimate</button></span>
        </div>
      </form>
      {if $errors}<div class="alert alert-danger" style="margin-top:10px">{foreach $errors as $e}<div>{$e|escape}</div>{/foreach}</div>{/if}
      <p class="text-muted small" style="margin-top:10px">Engine: {$engine.id|escape} v{$engine.version|escape} — every estimate shows its full factor breakdown.</p>
    </div>
  </div>

  {if $result}
  <div class="panel panel-success chs-valuation-result" id="valuation-result">
    <div class="panel-heading"><strong>{$result.domain|escape}</strong></div>
    <div class="panel-body">
      <div class="chs-valuation-figure">
        <span class="chs-valuation-amount">
          {if $result.currency eq 'USD'}${elseif $result.currency eq 'EUR'}€{elseif $result.currency eq 'GBP'}£{/if}
          {math equation="x / 100" x=$result.estimate_minor format="%.0f"}
          {if $result.currency neq 'USD' && $result.currency neq 'EUR' && $result.currency neq 'GBP'} {$result.currency|escape}{/if}
        </span>
        <span class="chs-valuation-score">Score {$result.score}/100 · {$result.confidence|ucfirst|escape} confidence</span>
      </div>
      {if $result.summary}<p>{$result.summary|escape}</p>{/if}
      <div class="table-responsive">
      <table class="table table-condensed">
        <thead><tr><th>Factor</th><th>Assessment</th><th style="width:130px">Score</th></tr></thead>
        <tbody>
        {foreach $result.breakdown as $f}
          <tr>
            <td><strong>{$f.label|escape}</strong></td>
            <td>{$f.detail|escape}</td>
            <td>
              <div class="progress chs-progress">
                <div class="progress-bar progress-bar-{if $f.direction eq 'positive'}success{elseif $f.direction eq 'neutral'}info{else}danger{/if}"
                     style="width:{$f.score}%"></div>
              </div>
            </td>
          </tr>
        {/foreach}
        </tbody>
      </table>
      </div>
      <div class="alert alert-warning chs-disclaimer"><strong>Please read:</strong> {$result.disclaimer|escape}</div>
      <p>
        <a class="btn btn-default" href="{$WEB_ROOT}/domainchecker.php">Register a domain</a>
        <a class="btn btn-default" href="{$WEB_ROOT}/domain-broker.php">Ask a broker to acquire this name</a>
      </p>
    </div>
  </div>
  {/if}

  {if $history}
  <div class="panel panel-default">
    <div class="panel-heading"><strong>Your recent estimates</strong></div>
    <div class="table-responsive">
    <table class="table table-striped table-condensed">
      <thead><tr><th>Domain</th><th>Estimate</th><th>Score</th><th>When</th></tr></thead>
      <tbody>
      {foreach $history as $h}
      <tr>
        <td>{$h.domain|escape}</td>
        <td>{math equation="x / 100" x=$h.estimate_minor format="%.2f"} {$h.currency|escape}</td>
        <td>{$h.score}</td>
        <td>{$h.created_at|date_format:"%e %b %Y"}</td>
      </tr>
      {/foreach}
      </tbody>
    </table>
    </div>
  </div>
  {/if}
</div>
