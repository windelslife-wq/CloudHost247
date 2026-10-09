{* Bulk domain search — submit a list or keywords; large lists run as a job. *}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Bulk domain search</strong></div>
  <div class="panel-body">
    <form method="post" action="{$modulelink}&action=bulk">
      {$csrf_field}<input type="hidden" name="do" value="submit">
      <div class="form-group">
        <label for="chs-bulk-domains">Domains or keywords (one per line, or comma/space separated)</label>
        <textarea class="form-control" id="chs-bulk-domains" name="domains" rows="8" maxlength="20000"
          placeholder="cloudhost247.com&#10;windels&#10;hosting">{$old.domains|escape}</textarea>
        <p class="help-block">Paste full domains (<code>example.com</code>) and/or bare keywords (<code>windels</code>) — keywords are expanded across every extension we sell. Up to {$max} names per search. Large lists are processed in the background by our worker; you can watch progress on the results page.</p>
      </div>
      {if $errors}<div class="alert alert-danger">{foreach $errors as $err}<div>{$err|escape}</div>{/foreach}</div>{/if}
      <button type="submit" class="btn btn-primary">Search availability</button>
    </form>
  </div>
</div>

{if $searches}
<div class="panel panel-default">
  <div class="panel-heading"><strong>Your recent bulk searches</strong></div>
  <div class="table-responsive">
  <table class="table table-striped table-condensed">
    <thead><tr><th>#</th><th>Submitted</th><th>Names</th><th>Done</th><th>Status</th><th></th></tr></thead>
    <tbody>
    {foreach $searches as $s}
      <tr>
        <td>{$s.id}</td>
        <td>{$s.created_at|date_format:"%e %b %Y %H:%M"}</td>
        <td>{$s.total}</td>
        <td>{$s.completed}</td>
        <td>{$s.status|ucfirst}</td>
        <td><a class="btn btn-xs btn-default" href="{$modulelink}&action=bulkview&id={$s.id}">Results</a></td>
      </tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
{/if}
