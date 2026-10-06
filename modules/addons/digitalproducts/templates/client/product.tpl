<div class="container-fluid ch247-digital-product">
    <p><a href="{$modulelink|escape}"><i class="fas fa-arrow-left mr-1"></i> Back to My Downloads</a></p>
    <div class="card shadow-sm mb-4"><div class="card-body">
        <span class="badge badge-light text-uppercase">{$product.product_type|escape}</span>
        <h1 class="h2 mt-2">{$product.name|escape}</h1>
        <p class="text-muted">{$product.description|escape|nl2br}</p>
        {if $product.purchased_version}<p class="small">Purchased release: <strong>{$product.purchased_version|escape}</strong></p>{/if}
    </div></div>
    {if $license}<div class="card shadow-sm mb-4"><div class="card-body"><strong>License</strong> <span class="badge badge-secondary">{$license.status|escape}</span><br><code>{$license.key|escape}</code>{if $license.expires_at}<span class="small text-muted ml-2">Expires {$license.expires_at|escape}</span>{/if}</div></div>{/if}
    <div class="card shadow-sm"><div class="card-header"><strong>Release history and compatibility</strong></div><div class="table-responsive"><table class="table mb-0">
        <thead><tr><th>Version</th><th>Released</th><th>File</th><th>SHA-256</th><th>Compatibility</th><th>Status</th></tr></thead>
        <tbody>{foreach from=$product.versions item=version}<tr>
            <td><strong>{$version.version|escape}</strong></td><td>{$version.release_date|escape}</td><td>{$version.file_size} bytes</td>
            <td><code>{$version.checksum_sha256|escape}</code></td>
            <td class="small">PHP {$version.min_php|escape} – {$version.max_php|escape}<br>WHMCS {$version.min_whmcs|escape} – {$version.max_whmcs|escape}{if $version.required_extensions}<br>{$version.required_extensions|escape}{/if}</td>
            <td><span class="badge badge-secondary">{$version.status|escape}</span></td>
        </tr>{if $version.release_notes || $version.changelog}<tr><td colspan="6" class="small text-muted"><strong>Notes:</strong> {$version.release_notes|default:$version.changelog|escape|nl2br}</td></tr>{/if}{foreachelse}<tr><td colspan="6" class="text-muted">No releases are currently available.</td></tr>{/foreach}</tbody>
    </table></div></div>
</div>
