{if $hostx_blocks['footer_block_latest']}
<footer class="footer {if $filename == 'clientarea' || $filename == 'submitticket' || $filename == 'affiliates' || $filename == 'supporttickets' || $filename == 'serverstatus' || $filename == 'viewticket' || $templatefile == 'account-user-management' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-paymentmethods' || $templatefile == 'account-paymentmethods-manage' || $templatefile == 'announcements' || $templatefile == 'knowledgebase' || $templatefile == 'downloads' || $templatefile == 'viewannouncement' || $templatefile == 'knowledgebasecat' || $templatefile == 'knowledgebasearticle' || $templatefile == 'user-password' || $templatefile == 'user-profile' || $templatefile == 'user-switch-account' || $templatefile == 'user-security' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-contacts-new'}clientarea-footer-entered{/if}" id="mainfooterhostx">
   <div class="container">
      <div class="row">
          {foreach $hostx_blocks['footer_block_latest']->widgets as $widget}
            {eval var=$widget->widget_description|html_entity_decode}
          {/foreach}
       </div>
   </div>
</footer>
{else}
<footer class="footer footer-block-latest {if $filename == 'clientarea' || $filename == 'submitticket' || $filename == 'affiliates' || $filename == 'supporttickets' || $filename == 'serverstatus' || $filename == 'viewticket' || $templatefile == 'account-user-management' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-paymentmethods' || $templatefile == 'account-paymentmethods-manage' || $templatefile == 'announcements' || $templatefile == 'knowledgebase' || $templatefile == 'downloads' || $templatefile == 'viewannouncement' || $templatefile == 'knowledgebasecat' || $templatefile == 'knowledgebasearticle' || $templatefile == 'user-password' || $templatefile == 'user-profile' || $templatefile == 'user-switch-account' || $templatefile == 'user-security' || $templatefile == 'account-contacts-manage' || $templatefile == 'account-contacts-new'}clientarea-footer-entered{/if}" id="mainfooterhostx">
   <div class="container">
      <div class="row">
         <div class="footer-logo-container ">
            <div class="footer_col">
               <div class="col-md-3 col-sm-12 ">
                  <img src="{$WEB_ROOT}/templates/{$template}/images/logo-white.png" alt="logo-footer" class="logo-dark img-responsive">
               </div>
               <div class="col-md-9 col-sm-12 ">
                  <h2>{$LANG.footer_latest_tagline}</h2>
               </div>
            </div>
         </div>
         <div class="clearfix"></div>
         <div class="for-desktop">
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>{$LANG.nav_domains}</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/domain-search.php">{$LANG.footer_register_new_domain}</a></li>
                     <li><a href="{$WEB_ROOT}/domain-transfer.php">{$LANG.footer_transfer_domain}</a></li>
                     <li><a href="{$WEB_ROOT}/tld-directory.php">{$LANG.footer_domain_extensions}</a></li>
                     <li><a href="{$WEB_ROOT}/domain-broker.php">{$LANG.footer_domain_broker_service}</a></li>
                     <li><a href="{$WEB_ROOT}/ssl-certificate.php">{$LANG.footer_ssl_security}</a></li>
                  </ul>
                  <h4>{$LANG.nav_hosting}</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/wordpress-hosting.php">{$LANG.footer_wordpress_hosting}</a></li>
                     <li><a href="{$WEB_ROOT}/windows-hosting.php">{$LANG.footer_windows_hosting}</a></li>
                     <li><a href="{$WEB_ROOT}/plesk-hosting.php">{$LANG.footer_plesk_hosting}</a></li>
                     <li><a href="{$WEB_ROOT}/cpanel-hosting.php">{$LANG.footer_cpanel_hosting}</a></li>
                  </ul>
               </div>
            </div>
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>{$LANG.footer_websites}</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/website-builder.php">{$LANG.footer_website_builder}</a></li>
                     <li><a href="{$WEB_ROOT}/website-design.php">{$LANG.footer_website_design}</a></li>
                     <li><a href="{$WEB_ROOT}/future-element.php">{$LANG.footer_future_element}</a></li>
                     <li><a href="{$WEB_ROOT}/web-hosting.php">{$LANG.footer_web_hosting}</a></li>
                  </ul>
                  <h4>{$LANG.nav_servers}</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/vps-hosting.php">{$LANG.footer_vps_hosting}</a></li>
                     <li><a href="{$WEB_ROOT}/vps-publiccloud.php">{$LANG.footer_public_cloud}</a></li>
                     <li><a href="{$WEB_ROOT}/vps-privatecloud.php">{$LANG.footer_private_cloud}</a></li>
                     <li><a href="{$WEB_ROOT}/enterprise-servers.php">{$LANG.footer_enterprise_server}</a></li>
                  </ul>
               </div>
            </div>
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>{$LANG.footer_domain_services}</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/domain-valuation.php">{$LANG.footer_domain_valuation}</a></li>
                     <li><a href="{$WEB_ROOT}/domain-auctions.php">{$LANG.footer_domain_auctions}</a></li>
                     <li><a href="{$WEB_ROOT}/discount-domain-club.php">{$LANG.footer_discount_domain_club}</a></li>
                     <li><a href="{$WEB_ROOT}/whois-lookup.php">{$LANG.footer_whois_lookup}</a></li>
                  </ul>
                  <h4>{$LANG.footer_security_trust}</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/ssl-certificate.php">{$LANG.footer_ssl_certificates}</a></li>
                     <li><a href="{$WEB_ROOT}/backup-policy.php">{$LANG.footer_website_backup}</a></li>
                     <li><a href="{$WEB_ROOT}/data-protection-standards.php">{$LANG.footer_website_security}</a></li>
                  </ul>
               </div>
            </div>
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>CloudHost247</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/aboutus.php">{$LANG.footer_why_cloudhost247}</a></li>
                     <li><a href="{$WEB_ROOT}/faqs.php">{$LANG.footer_frequent_questions}</a></li>
                     <li><a href="{$WEB_ROOT}/affiliates.php">{$LANG.footer_affiliates_program}</a></li>
                     <li><a href="{$WEB_ROOT}/terms-of-service.php">{$LANG.footer_terms_of_services}</a></li>
                  </ul>
                  <h4>{$LANG.nav_support}</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/submitticket.php">{$LANG.footer_open_ticket}</a></li>
                     <li><a href="{$WEB_ROOT}/knowledgebase.php">{$LANG.footer_knowledgebase}</a></li>
                     <li><a href="{$WEB_ROOT}/announcements.php">{$LANG.footer_news}</a></li>
                  </ul>
                  <div class="clearfix"></div>
                  <ul class="socil_icon">
                     <li><a target="_blank" href="{$hostx_theme_settings.linkedin_handle_code}" rel="noopener"><i class="fab fa-linkedin"></i></a></li>
                     <li><a target="_blank" href="{$hostx_theme_settings.twitter_handle_code}" rel="noopener"><i class="fab fa-twitter"></i></a></li>
                     <li><a target="_blank" href="{$hostx_theme_settings.facebook_handle_code}" rel="noopener"><i class="fab fa-facebook"></i></a></li>
                     <li><a target="_blank" href="{$hostx_theme_settings.instagram_handle_code}" rel="noopener"><i class="fab fa-instagram"></i></a></li>
                     <li><a target="_blank" href="{$hostx_theme_settings.pinrest_handle_code}" rel="noopener"><i class="fab fa-pinterest"></i></a></li>
                     
                  </ul>
               </div>
            </div>
         </div>
      </div>
   </div>
</footer>
{/if}
<script>
   jQuery(document).ready(function(){
   jQuery(".footer_col h4").on('click',function(){
   if(jQuery(this).hasClass("active")){
   jQuery(".footer_col h4").removeClass("active");
   jQuery(".footer_links").removeClass("active");
   }else{
   jQuery(".footer_col h4").removeClass("active");
   jQuery(".footer_links").removeClass("active");
   jQuery(this).addClass("active");
   jQuery(this).next(".footer_links").addClass("active");
   }
   });
   });
   </script>