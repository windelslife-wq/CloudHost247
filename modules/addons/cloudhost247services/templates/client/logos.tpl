{if $flash}<div class="alert alert-success">{$flash|escape}</div>{/if}
<div class="panel panel-default">
  <div class="panel-heading"><strong>My logo projects</strong>
    <a class="btn btn-xs btn-primary pull-right" href="{$modulelink}&action=logostudio">Open the studio</a></div>
  <div class="table-responsive">
  <table class="table table-striped">
    <thead><tr><th>Company</th><th>Concept</th><th>Palette</th><th>Saved</th><th></th></tr></thead>
    <tbody>
    {foreach $logos as $l}
    <tr>
      <td><a href="{$modulelink}&action=logo&id={$l.id}">{$l.company_name|escape}</a></td>
      <td>{$l.concept_key|ucwords}</td>
      <td>{$l.palette|ucwords}</td>
      <td>{$l.created_at|date_format:"%e %b %Y"}</td>
      <td>
        <a class="btn btn-xs btn-default" href="{$modulelink}&action=logo&id={$l.id}">Open</a>
        <form method="post" action="{$modulelink}&action=logos" class="chs-inline-form"
              onsubmit="return confirm('Delete this project?')">
          {$csrf_field}<input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="{$l.id}">
          <button class="btn btn-xs btn-danger">Delete</button>
        </form>
      </td>
    </tr>
    {foreachelse}
    <tr><td colspan="5" class="text-muted">No saved logos yet. <a href="{$modulelink}&action=logostudio">Design your first one</a> — it takes a minute.</td></tr>
    {/foreach}
    </tbody>
  </table>
  </div>
</div>
