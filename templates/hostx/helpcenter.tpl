{* Help Center Page *}
{* Template: templates/hostx/helpcenter.tpl *}
{* Compatible with HostX Theme for WHMCS *}

{* Banner / Hero Section *}
<div class="legal-banner-section help-center-banner">
    <div class="container">
        <div class="row">
            <div class="col-12 text-center">
                <h1>{$LANG.ch247_helpcenter_f97bfa12ae}</h1>
                <p class="legal-effective-date">{$LANG.ch247_helpcenter_35986808e7}</p>
            </div>
        </div>
    </div>
</div>

{* Search Section *}
<div class="help-search-section">
    <div class="container">
        <div class="row">
            <div class="col-12 col-md-8 offset-md-2">
                <div class="search-box-wrapper text-center">
                    <p class="search-intro">{$LANG.ch247_helpcenter_597e4efe88}</p>
                    <form class="input-group help-search-group" method="post" action="{routePath('knowledgebase-search')}">
                        <input type="search" name="search" class="form-control" placeholder="{$LANG.ch247_helpcenter_f9bb0e70e2|escape:'html'}" aria-label="{$LANG.ch247_helpcenter_dbbaee2ceb|escape:'html'}">
                        <div class="input-group-append">
                            <button class="btn btn-primary" type="submit">{$LANG.ch247_helpcenter_bade282a77}</button>
                        </div>
                    </form>
                    <p class="search-hint">{$LANG.ch247_helpcenter_13f5c985bf}</p>
                </div>
            </div>
        </div>
    </div>
</div>

{* Main Content Container *}
<div class="container inner_term_container help-center-container">
    <div class="row">
        <div class="col-12">

            {* Introductory Statement *}
            <div class="legal-intro">
                <p>{$LANG.ch247_helpcenter_0adc1eb2cf}</p>
            </div>

            {* Section 1: Getting Started *}
            <div class="help-section" id="getting-started">
                <h3>{$LANG.ch247_helpcenter_359ee747a6}</h3>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_99935c067c}</h4>
                    <div class="faq-answer">
                        <ol>
                            <li>{$LANG.ch247_helpcenter_bfa3d325c8}</li>
                            <li>{$LANG.ch247_helpcenter_0803b99a20}</li>
                            <li>{$LANG.ch247_helpcenter_73abe96d2f}</li>
                            <li>{$LANG.ch247_helpcenter_e4b357d6d9}</li>
                            <li>{$LANG.ch247_helpcenter_cc77c6d2b1}</li>
                        </ol>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_bfdbdd1a5a}</h4>
                    <div class="faq-answer">
                        <ul>
                            <li>{$LANG.ch247_helpcenter_cc77c6d2b1}</li>
                            <li>{$LANG.ch247_helpcenter_969031584f}</li>
                            <li>{$LANG.ch247_helpcenter_1904c1b002}</li>
                        </ul>
                    </div>
                </div>
            </div>

            {* Section 2: Account & Billing *}
            <div class="help-section" id="account-billing">
                <h3>{$LANG.ch247_helpcenter_c852a770fc}</h3>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_8f0ac6e92d}</h4>
                    <div class="faq-answer">
                        <ol>
                            <li>{$LANG.ch247_helpcenter_cc77c6d2b1}</li>
                            <li>{$LANG.ch247_helpcenter_4e40a4ee1d}</li>
                            <li>{$LANG.ch247_helpcenter_391728422d}</li>
                        </ol>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_9e20a78a93}</h4>
                    <div class="faq-answer">
                        <ul>
                            <li>{$LANG.ch247_helpcenter_5deeafa246}</li>
                            <li>{$LANG.ch247_helpcenter_e0da620e9a}</li>
                            <li>{$LANG.ch247_helpcenter_534ea62051}</li>
                        </ul>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_3033519f42}</h4>
                    <div class="faq-answer">
                        <ul>
                            <li>{$LANG.ch247_helpcenter_2dec3d89f9}</li>
                            <li>{$LANG.ch247_helpcenter_ab8a4f6bb8}</li>
                            <li>{$LANG.ch247_helpcenter_ee82ae2801}</li>
                            <li>{$LANG.ch247_helpcenter_d458ac677e}</li>
                        </ul>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_4a9aca4309}</h4>
                    <div class="faq-answer">
                        <ul>
                            <li>{$LANG.ch247_helpcenter_cc77c6d2b1}</li>
                            <li>{$LANG.ch247_helpcenter_65fffedc06}</li>
                            <li>{$LANG.ch247_helpcenter_d07417bd13}</li>
                        </ul>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_5715eaf15e}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_f689833c48}</p>
                    </div>
                </div>
            </div>

            {* Section 3: Services & Features *}
            <div class="help-section" id="services-features">
                <h3>{$LANG.ch247_helpcenter_f2092cb96f}</h3>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_6b5f700cf3}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_0312e9bdd5}</p>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_fba766779e}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_57dff2347f}</p>
                        <ol>
                            <li>{$LANG.ch247_helpcenter_b3d322ed00}</li>
                            <li>{$LANG.ch247_helpcenter_3aff3fe57c}</li>
                            <li>{$LANG.ch247_helpcenter_5adc040ca0}</li>
                        </ol>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_9c31193f66}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_17a0c4d3ec}</p>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_d1abb4a009}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_76b1c51ad1}</p>
                    </div>
                </div>
            </div>

            {* Section 4: Technical Support *}
            <div class="help-section" id="technical-support">
                <h3>{$LANG.ch247_helpcenter_958a52294f}</h3>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_d0a1cbde97}</h4>
                    <div class="faq-answer">
                        <ul>
                            <li>{$LANG.ch247_helpcenter_73060b0e0b}</li>
                            <li>{$LANG.ch247_helpcenter_4fcc2f3a6d}</li>
                            <li>{$LANG.ch247_helpcenter_55f8645a6f}</li>
                        </ul>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_02f36b7ae2}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_093320cc31}</p>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_0992b1c4ee}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_3d920f9a85}</p>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_a6566cdf06}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_d96b9db7e1}</p>
                    </div>
                </div>
            </div>

            {* Section 5: FAQs *}
            <div class="help-section" id="faqs">
                <h3>{$LANG.ch247_helpcenter_073437bd61}</h3>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_f41b54b52e}</h4>
                    <div class="faq-answer">
                        <ul>
                            <li>{$LANG.ch247_helpcenter_624b158104}</li>
                            <li>{$LANG.ch247_helpcenter_67c9f725ce}</li>
                            <li>{$LANG.ch247_helpcenter_4f07c1f419}</li>
                        </ul>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_48476a7ce6}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_5452c0ccfd}</p>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_73aa476d89}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_95e29f7526}</p>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_92882468a5}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_c002dbdbd9}</p>
                        <ul>
                            <li>{$LANG.ch247_helpcenter_93ccee0b90}</li>
                            <li>{$LANG.ch247_helpcenter_75e92f47a3}</li>
                            <li>{$LANG.ch247_helpcenter_fd900d0159}</li>
                            <li>{$LANG.ch247_helpcenter_05b93d5aaa}</li>
                            <li>{$LANG.ch247_helpcenter_2638637d2d}</li>
                        </ul>
                    </div>
                </div>
                <div class="faq-item">
                    <h4 class="faq-question">{$LANG.ch247_helpcenter_efcc4ff9bd}</h4>
                    <div class="faq-answer">
                        <p>{$LANG.ch247_helpcenter_d2a53c2f3f}</p>
                    </div>
                </div>
            </div>

            {* Section 6: Contact Us *}
            <div class="help-section contact-section" id="contact-us">
                <h3>{$LANG.ch247_helpcenter_f9fd576486}</h3>
                <div class="contact-grid">
                    <div class="contact-card">
                        <h5>{$LANG.ch247_helpcenter_4c1f0f7440}</h5>
                        <p><a href="mailto:info@cloudhost247.com">info@cloudhost247.com</a></p>
                    </div>
                    <div class="contact-card">
                        <h5>{$LANG.ch247_helpcenter_b23a1425e7}</h5>
                        <p><a href="mailto:support@cloudhost247.com">support@cloudhost247.com</a></p>
                    </div>
                    <div class="contact-card">
                        <h5>{$LANG.ch247_helpcenter_3ac8bbca9a}</h5>
                        <p><a href="mailto:billing@cloudhost247.com">billing@cloudhost247.com</a></p>
                    </div>
                    <div class="contact-card">
                        <h5>{$LANG.ch247_helpcenter_b5a229ac8b}</h5>
                        <p><a href="https://www.cloudhost247.com" target="_blank" rel="noopener">www.cloudhost247.com</a></p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</div>
