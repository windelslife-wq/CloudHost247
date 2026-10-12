{if $hostx_blocks['footer_block']}
<footer class="footer {if $filename == 'clientarea' || $filename == 'submitticket' || $filename == 'affiliates' || $filename == 'supporttickets' || $filename == 'serverstatus' || $filename == 'viewticket' || $templatefile == 'account-user-management' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-paymentmethods' || $templatefile == 'account-paymentmethods-manage' || $templatefile == 'announcements' || $templatefile == 'knowledgebase' || $templatefile == 'downloads' || $templatefile == 'viewannouncement' || $templatefile == 'knowledgebasecat' || $templatefile == 'knowledgebasearticle' || $templatefile == 'user-password' || $templatefile == 'user-profile' || $templatefile == 'user-switch-account' || $templatefile == 'user-security' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-contacts-new'}clientarea-footer-entered{/if}" id="mainfooterhostx">
    <div class="container">
    	<div class="row">
	        {foreach $hostx_blocks['footer_block']->widgets as $widget}
	          {eval var=$widget->widget_description|html_entity_decode}
	        {/foreach}
        </div>
    </div>
</footer>
{else}
<footer class="footer {if $filename == 'clientarea' || $filename == 'submitticket' || $filename == 'affiliates' || $filename == 'supporttickets' || $filename == 'serverstatus' || $filename == 'viewticket' || $templatefile == 'account-user-management' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-paymentmethods' || $templatefile == 'account-paymentmethods-manage' || $templatefile == 'announcements' || $templatefile == 'knowledgebase' || $templatefile == 'downloads' || $templatefile == 'viewannouncement' || $templatefile == 'knowledgebasecat' || $templatefile == 'knowledgebasearticle' || $templatefile == 'user-password' || $templatefile == 'user-profile' || $templatefile == 'user-switch-account' || $templatefile == 'user-security' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-contacts-new'}clientarea-footer-entered{/if}" id="mainfooterhostx">
    <div class="container">
        <div class="row">
            <div class="col-sm-4">
                <div class="footer_col">
                    <h4>{$LANG.footeraboutus}</h4>
                    <p>{$LANG.footeraboutustext}.</p>
                    <a href="{$WEB_ROOT}/submitticket.php" class="button03">{$LANG.footergettouch}</a>
                </div>
            </div>
            <div class="col-sm-4">
                <div class="footer_col">
                    <h4>{$LANG.footercontactinfo}</h4>
                    <a href="{$WEB_ROOT}/supporttickets.php">{$LANG.footer24support}</a>
                    <h4 class="mt-2">+{$hostx_theme_settings.country_calling_code_phone} {$hostx_theme_settings.phone}</h4>
                    <p class="mt-3">{$LANG.footeremail}</p>
                    <a href="mailto:{$companyEmailAddress}" class="color_white">{$companyEmailAddress}</a>
                    <div class="clearfix"></div>
					{if !empty($hostx_theme_settings.facebook_handle_code) || !empty($hostx_theme_settings.instagram_handle_code) || 
					!empty($hostx_theme_settings.pinrest_handle_code) || 
					!empty($hostx_theme_settings.linkedin_handle_code) || 
					!empty($hostx_theme_settings.twitter_handle_code)}
					    <span class="mt-3 pull-left color_white">{$LANG.footerfollowus}</span>
						<ul class="socil_icon">
						{if !empty($hostx_theme_settings.facebook_handle_code)}
							<li>
								<a href="{$hostx_theme_settings.facebook_handle_code}" target="_blank"><i class="fab fa-facebook"></i></a>
							</li>
						{/if}
						{if !empty($hostx_theme_settings.instagram_handle_code)}
							<li>
								<a href="{$hostx_theme_settings.instagram_handle_code}" target="_blank">
								<i class="fab fa-instagram"></i></a>
							</li>
						{/if}
						{if !empty($hostx_theme_settings.pinrest_handle_code)}
							<li>
								<a href="{$hostx_theme_settings.pinrest_handle_code}" target="_blank">
								<i class="fab fa-pinterest"></i></a>
							</li>
						{/if}
						{if !empty($hostx_theme_settings.linkedin_handle_code)}
							<li>
								<a href="{$hostx_theme_settings.linkedin_handle_code}" target="_blank">
								<i class="fab fa-linkedin"></i>
								</a>
							</li>
						{/if}
						{if !empty($hostx_theme_settings.twitter_handle_code)}
							<li>
								<a href="{$hostx_theme_settings.twitter_handle_code}" target="_blank">
								<i class="fab fa-twitter"></i></a>
							</li>
						{/if}
						</ul>
					{/if}
                </div>
            </div>
            <div class="col-sm-4">
                <div class="footer_col">
                    <h4>{$LANG.footerusefullinks}</h4>
                    <ul class="footer_links">
                        <li><a href="{$WEB_ROOT}/dedicated-server.php">{$LANG.headerdedicatedservers}</a></li>
                        <li><a href="{$WEB_ROOT}/windows-hosting.php">{$LANG.headerwindowhosting} </a></li>
                        <li><a href="{$WEB_ROOT}/vps-hosting.php">{$LANG.homecloudhosting} </a></li>
                        <li><a href="{$WEB_ROOT}/cpanel-hosting.php">{$LANG.footerlinuxservers} </a></li>
                        <li><a href="{$WEB_ROOT}/domain-broker.php">{if $LANG.domainbrokerservice}{$LANG.domainbrokerservice}{else}Domain Broker Service{/if}</a></li>
                    </ul>
                    <h4>{if $LANG.footerdomainservices}{$LANG.footerdomainservices}{else}Domain Services{/if}</h4>
                    <ul class="footer_links">
                        <li><a href="{$WEB_ROOT}/domain-search.php">{$LANG.footer_domain_search}</a></li>
                        <li><a href="{$WEB_ROOT}/bulk-domain-search.php">{$LANG.footer_bulk_domain_search}</a></li>
                        <li><a href="{$WEB_ROOT}/domain-transfer.php">{$LANG.footer_transfer_a_domain}</a></li>
                        <li><a href="{$WEB_ROOT}/tld-directory.php">{$LANG.footer_domain_extensions}</a></li>
                        <li><a href="{$WEB_ROOT}/domain-valuation.php">{$LANG.footer_domain_valuation}</a></li>
                        <li><a href="{$WEB_ROOT}/domain-auctions.php">{$LANG.footer_domain_auctions}</a></li>
                        <li><a href="{$WEB_ROOT}/discount-domain-club.php">{$LANG.footer_discount_domain_club}</a></li>
                        <li><a href="{$WEB_ROOT}/whois-lookup.php">{$LANG.footer_whois_lookup}</a></li>
                    </ul>
                    <h4>{if $LANG.footergrowthservices}{$LANG.footergrowthservices}{else}Grow Online{/if}</h4>
                    <ul class="footer_links">
                        <li><a href="{$WEB_ROOT}/website-builder.php">{$LANG.footer_build_your_website}</a></li>
                        <li><a href="{$WEB_ROOT}/ai-website-builder.php">{$LANG.footer_ai_website_builder}</a></li>
                        <li><a href="{$WEB_ROOT}/logo-maker.php">{$LANG.footer_logo_maker}</a></li>
                        <li><a href="{$WEB_ROOT}/digital-marketing.php">{$LANG.footer_digital_marketing}</a></li>
                        <li><a href="{$WEB_ROOT}/hire-an-expert.php">{$LANG.footer_hire_an_expert}</a></li>
                    </ul> 
                </div>
            </div>
        </div>
    </div>
</footer>
{/if}