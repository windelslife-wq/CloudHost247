{* Order a server — product → region → OS → version → architecture → SSH key → hostname → review. *}
{if $flash}<div class="alert alert-success">{$flash|escape}</div>{/if}
{if $error}<div class="alert alert-danger">{$error|escape}</div>{/if}

{if !$order_enabled}
  <div class="alert alert-warning">Server ordering is currently disabled. Please contact support.</div>
{elseif !$products}
  <div class="alert alert-info">No server products are available for ordering right now.</div>
{else}

{* Step 1: product cards *}
<div class="panel panel-default">
  <div class="panel-heading"><strong>1. Choose a server plan</strong></div>
  <div class="panel-body">
    <div class="row">
      {foreach $products as $p}
        <div class="col-sm-6 col-md-4">
          <div class="panel panel-{if $selected.product_id == $p.id}primary{else}default{/if} chs-os-card">
            <div class="panel-heading"><strong>{$p.name|escape}</strong></div>
            <div class="panel-body">
              <p class="text-muted small">{$p.description|escape}</p>
              <p>
                {if $p.price_minor !== null}<strong>{$p.price_minor|chs_money_format:$p.currency}</strong>{if $p.paytype == 'recurring'} <span class="text-muted small">/mo</span>{/if}{else}<span class="text-muted">Contact us</span>{/if}
              </p>
              <p class="text-muted small">{$p.os_count} OS{if $p.os_count != 1}s{/if} · {$p.architectures|@implode:', '|escape} · {$p.regions} region{if $p.regions != 1}s{/if}</p>
              <a class="btn btn-sm btn-primary" href="{$modulelink}&action=order&product_id={$p.id}">Select</a>
            </div>
          </div>
        </div>
      {/foreach}
    </div>
  </div>
</div>

{if $config}
{* Step 2+: configuration for the selected product *}
<form method="post" action="{$modulelink}&action=order">
  {$csrf_field}
  <input type="hidden" name="do" value="create">
  <input type="hidden" name="product_id" value="{$config.product.id}">

  <div class="panel panel-default">
    <div class="panel-heading"><strong>2. Region</strong></div>
    <div class="panel-body">
      {if $config.regions}
        <select name="region_id" class="form-control" style="max-width:420px" onchange="this.form.submit()">
          <option value="0">Any available region</option>
          {foreach $config.regions as $r}
            <option value="{$r.id}"{if $selected.region_id == $r.id} selected{/if}>{$r.name|escape}{if $r.datacenter} ({$r.datacenter|escape}){/if} — {$r.provider_name|escape}</option>
          {/foreach}
        </select>
      {else}
        <div class="alert alert-warning">No infrastructure providers are configured yet — no region (and no OS image) is currently available. An administrator must configure a provider under <em>Infra Providers</em> first.</div>
      {/if}
    </div>
  </div>

  {if $config.operatingSystems}
  <div class="panel panel-default">
    <div class="panel-heading"><strong>3. Operating system</strong></div>
    <div class="panel-body">
      <div class="row" id="chs-os-grid">
        {foreach $config.operatingSystems as $os}
          <div class="col-xs-6 col-sm-4 col-md-3">
            <div class="panel panel-default chs-os-card chs-os-pick" data-os="{$os.id}" style="cursor:pointer;text-align:center">
              <div class="panel-body" style="padding:14px 8px">
                {if $os.logo_url}<img src="{$os.logo_url|escape}" alt="{$os.name|escape}" style="height:44px;width:44px"><br>{/if}
                <strong>{$os.name|escape}</strong>
                <div class="text-muted small">{$os.versions|@count} version{if $os.versions|@count != 1}s{/if}</div>
              </div>
            </div>
          </div>
        {/foreach}
      </div>
      <input type="hidden" name="os_version_id" id="chs-os-version" value="">
      <div id="chs-os-detail" style="display:none;margin-top:12px">
        <h5 id="chs-os-title"></h5>
        <div class="form-inline">
          <label>Version</label>
          <select name="os_version_id_select" id="chs-version-select" class="form-control input-sm"></select>
          <label style="margin-left:12px">Architecture</label>
          <select name="architecture" id="chs-arch-select" class="form-control input-sm"></select>
        </div>
      </div>
      {literal}
      <script>
      (function () {
        var data = {/literal}{$os_json nofilter}{literal};
        var grid = document.getElementById('chs-os-grid');
        var detail = document.getElementById('chs-os-detail');
        var title = document.getElementById('chs-os-title');
        var vSel = document.getElementById('chs-version-select');
        var aSel = document.getElementById('chs-arch-select');
        var hidden = document.getElementById('chs-os-version');
        function pick(card) {
          var cards = grid.querySelectorAll('.chs-os-pick');
          for (var i = 0; i < cards.length; i++) { cards[i].className = cards[i].className.replace(' panel-primary', ' panel-default'); }
          card.className = card.className.replace(' panel-default', ' panel-primary');
          var osId = card.getAttribute('data-os');
          var os = null;
          for (var j = 0; j < data.length; j++) { if (String(data[j].id) === String(osId)) { os = data[j]; } }
          if (!os) { return; }
          title.textContent = os.name + (os.vendor ? ' — ' + os.vendor : '');
          vSel.innerHTML = '';
          aSel.innerHTML = '';
          for (var k = 0; k < os.versions.length; k++) {
            var v = os.versions[k];
            var opt = document.createElement('option');
            opt.value = v.id;
            opt.textContent = v.display_name + (v.is_lts ? ' (LTS)' : '') + (v.is_default ? ' — recommended' : '');
            if (v.is_default) { opt.selected = true; }
            vSel.appendChild(opt);
          }
          var first = os.versions[0];
          var archs = first ? Object.keys(first.architectures) : [];
          for (var m = 0; m < archs.length; m++) {
            var a = document.createElement('option');
            a.value = archs[m];
            a.textContent = archs[m];
            aSel.appendChild(a);
          }
          function sync() {
            var v = vSel.value;
            var archsNow = [];
            for (var n = 0; n < os.versions.length; n++) {
              if (String(os.versions[n].id) === String(v)) {
                archsNow = Object.keys(os.versions[n].architectures);
              }
            }
            var current = aSel.value;
            aSel.innerHTML = '';
            for (var p = 0; p < archsNow.length; p++) {
              var b = document.createElement('option');
              b.value = archsNow[p];
              b.textContent = archsNow[p];
              aSel.appendChild(b);
            }
            if (current && archsNow.indexOf(current) !== -1) { aSel.value = current; }
            hidden.value = v;
          }
          vSel.onchange = sync;
          sync();
          detail.style.display = 'block';
        }
        var cards = grid.querySelectorAll('.chs-os-pick');
        for (var i = 0; i < cards.length; i++) {
          cards[i].addEventListener('click', function () { pick(this); });
        }
        if (cards.length === 1) { pick(cards[0]); }
      })();
      </script>
      {/literal}
    </div>
  </div>

  <div class="panel panel-default">
    <div class="panel-heading"><strong>4. Server details</strong></div>
    <div class="panel-body form-horizontal">
      <div class="form-group">
        <label class="col-sm-2 control-label">Billing cycle</label>
        <div class="col-sm-4">
          <select name="billing_cycle" class="form-control">
            {foreach $pricing.cycles as $cycle => $amount}
              <option value="{$cycle|escape}"{if $cycle == 'monthly'} selected{/if}>{$cycle|ucfirst|escape} — {math equation="x / 100" x=$amount format="%.2f"} {$currency|escape}</option>
            {/foreach}
          </select>
        </div>
      </div>
      <div class="form-group">
        <label class="col-sm-2 control-label">Hostname</label>
        <div class="col-sm-4"><input class="form-control" name="hostname" placeholder="vps-01.example.com" required></div>
      </div>
      <div class="form-group">
        <label class="col-sm-2 control-label">SSH key</label>
        <div class="col-sm-4">
          <select name="ssh_key_id" class="form-control">
            <option value="0">No SSH key (password/root access at provider)</option>
            {foreach $ssh_keys as $k}
              <option value="{$k.id}">{$k.name|escape} ({$k.fingerprint|escape})</option>
            {/foreach}
          </select>
          <p class="help-block"><a href="{$modulelink}&action=server&id=0" onclick="return false" class="text-muted">Manage keys from any server page</a></p>
        </div>
      </div>
    </div>
  </div>

  <div class="panel panel-primary">
    <div class="panel-heading"><strong>5. Review &amp; pay</strong></div>
    <div class="panel-body">
      <p>You will receive a WHMCS invoice for <strong>{$config.product.name|escape}</strong>. The server is provisioned automatically once the invoice is paid.</p>
      <button class="btn btn-lg btn-success" type="submit">Continue to payment</button>
    </div>
  </div>
  {else}
    <div class="alert alert-warning">No operating systems are currently deployable for this product{if $selected.region_id} in the selected region{/if}. An administrator must map and enable provider OS images first (admin → OS Images).</div>
  {/if}
</form>
{/if}

{* JSON configuration endpoint used by dynamic selectors: *}
<p class="text-muted small">Available combinations are computed server-side from the OS catalog and provider image mappings. <code>{$modulelink}&action=orderconfig&product_id=…</code></p>

{/if}
