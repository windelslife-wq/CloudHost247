{* Live studio: concepts render server-side via AJAX against module endpoints; final save is a normal POST *}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Logo Studio</strong>
    <span class="text-muted pull-right small">Vector concepts generated live · SVG export always included</span></div>
  <div class="panel-body">
    {if $errors}<div class="alert alert-danger">{foreach $errors as $e}<div>{$e|escape}</div>{/foreach}</div>{/if}
    <form class="form-horizontal chs-studio-controls" id="chs-logo-form" method="post" action="{$modulelink}&action=logostudio">
      {$csrf_field}
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="concept" id="chs-concept-input" value="{$prefill.company|default:''|escape}">
      <div class="row">
        <div class="col-sm-4">
          <div class="form-group">
            <label for="chs-company">Company name</label>
            <input class="form-control" id="chs-company" name="company" maxlength="40"
                   value="{$prefill.company|escape}" placeholder="e.g. CloudHost247" required>
          </div>
          <div class="form-group">
            <label for="chs-industry">Industry</label>
            <select class="form-control" id="chs-industry" name="industry">
              {foreach $industries as $key => $i}
              <option value="{$key|escape}"{if $prefill.industry eq $key} selected{/if}>{$i.label|escape}</option>
              {/foreach}
            </select>
          </div>
                    <div class="form-group">
            <label for="chs-style">Style</label>
            <select class="form-control" id="chs-style" name="style">
              {foreach $libraries.fonts as $f}
              <option value="{$f|escape}"{if $prefill.style eq $f} selected{/if}>{$f|ucwords}</option>
              {/foreach}
            </select>
          </div>
          <div class="form-group">
            <label>Palette</label>
            <div class="chs-palette-row" id="chs-palettes">
              {foreach $libraries.palettes as $pk => $p}
              <label class="chs-palette{if $prefill.palette eq $pk} chs-selected{/if}" title="{$pk|ucwords}">
                <input type="radio" name="palette" value="{$pk|escape}"{if $prefill.palette eq $pk} checked{/if}>
                <span style="background:{$p.primary}"></span><span style="background:{$p.accent}"></span><span style="background:{$p.bg}"></span>
              </label>
              {/foreach}
            </div>
          </div>
        </div>
        <div class="col-sm-8">
          <div id="chs-concept-grid" class="chs-concept-grid"
               data-modulelink="{$modulelink}"
               data-loading="Generating concepts…">
            <div class="chs-concept-loading text-muted">Enter your company name — concepts appear here live.</div>
          </div>
          <div id="chs-studio-actions" style="display:none;margin-top:12px">
            <button class="btn btn-primary btn-lg" id="chs-save-btn" type="submit">Save selected concept</button>
            <span class="text-muted" style="margin-left:10px">SVG download included{ if $png_available} · PNG too{/if}.</span>
          </div>
        </div>
      </div>
    </form>
  </div>
</div>
