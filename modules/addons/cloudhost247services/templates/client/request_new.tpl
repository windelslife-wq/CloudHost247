<div class="panel panel-default">
  <div class="panel-heading"><strong>Tell us about your project</strong></div>
  <div class="panel-body">
    {if $errors}<div class="alert alert-danger">{foreach $errors as $e}<div>{$e|escape}</div>{/foreach}</div>{/if}
    <form method="post" action="{$modulelink}&action=requestnew">
      {$csrf_field}
      <div class="form-group">
        <label>What do you need?</label>
        <div class="chs-type-grid">
          {foreach $types as $key => $t}
          <label class="chs-type-card">
            <input type="radio" name="type" value="{$key|escape}"{if $old.type eq $key || (!$old.type && $preset_type eq $key)} checked{/if}>
            <span class="chs-type-label">{$t.label|escape}</span>
            <span class="chs-type-desc">{$t.desc|escape}</span>
          </label>
          {/foreach}
        </div>
      </div>
      <div class="form-group"><label>Project title</label>
        <input class="form-control" name="title" maxlength="190" value="{$old.title|escape}"
               placeholder="e.g. Redesign our online store" required></div>
      <div class="form-group"><label>Tell us about it</label>
        <textarea class="form-control" name="brief" rows="6" required
                  placeholder="Goals, current site, audience, must-haves, deadlines…">{$old.brief|escape}</textarea></div>
      <div class="row">
        <div class="col-sm-6"><div class="form-group"><label>Budget range (optional)</label>
          <select class="form-control" name="budget_range">
            <option value="">Prefer not to say</option>
            <option value="under_500"{if $old.budget_range eq 'under_500'} selected{/if}>Under $500</option>
            <option value="500_1500"{if $old.budget_range eq '500_1500'} selected{/if}>$500 – $1,500</option>
            <option value="1500_5000"{if $old.budget_range eq '1500_5000'} selected{/if}>$1,500 – $5,000</option>
            <option value="5000_15000"{if $old.budget_range eq '5000_15000'} selected{/if}>$5,000 – $15,000</option>
            <option value="15000_plus"{if $old.budget_range eq '15000_plus'} selected{/if}>$15,000+</option>
          </select></div></div>
        <div class="col-sm-6"><div class="form-group"><label>Related domain (optional)</label>
          <input class="form-control" name="target_domain" value="{$old.target_domain|escape}" placeholder="example.com"></div></div>
      </div>
      <button class="btn btn-primary btn-lg">Submit request</button>
    </form>
  </div>
</div>
