{*
 * CLOUDHOST247 — Bulk domain search (bulk=true to cart.php).
 *}
<section class="hero-banner chs-hero">
    <div class="container">
        <div class="row">
            <div class="col-12">
                <div class="hero-content text-center">
                    <h1>Check your whole shortlist at once</h1>
                    <p class="hero-subtitle">Paste up to 500 domains — whitespace, commas or line breaks — and
                        the registry answers for all of them in one pass.</p>
                    <div class="breadcrumb-wrapper">
                        <ol class="breadcrumb">
                            <li class="breadcrumb-item"><a href="index.php">{$LANG.globalsystemname}</a></li>
                            <li class="breadcrumb-item active" aria-current="page">Bulk Domain Search</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="chs-section">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9 col-xl-8">
                <div class="card border-0 shadow-sm chs-card-primary">
                    <div class="card-body">
                        <h2 class="h4 text-center mb-3">Bulk availability check</h2>
                        <form method="get" action="cart.php" class="chs-bulk-form">
                            <input type="hidden" name="a" value="add">
                            <input type="hidden" name="domain" value="register">
                            <input type="hidden" name="bulk" value="true">
                            <div class="form-group">
                                <label for="chs-bulk-list">One name per line (or separated by spaces/commas)</label>
                                <textarea class="form-control chs-bulk-textarea" id="chs-bulk-list" name="query"
                                          rows="10" spellcheck="false" autocomplete="off"
                                          placeholder="bluepinestudio.com&#10;bluepinestudio.co&#10;bluepine.studio&#10;..."></textarea>
                                <small class="form-text text-muted">
                                    IDs, paste, CSV dumps — all fine. Bad names are skipped, never silently
                                    treated as available.
                                </small>
                            </div>
                            <div class="text-center">
                                <button class="btn btn-primary btn-lg" type="submit">Check the list</button>
                            </div>
                        </form>
                    </div>
                </div>
                <p class="text-muted mt-3">
                    Moving the whole portfolio instead? Head over to the
                    <a href="{$chsTransferUrl}">transfer desk</a>. Just pricing first?
                    Try the <a href="{$chsDirectoryUrl}">TLD directory</a>.
                </p>
            </div>
        </div>
    </div>
</section>
