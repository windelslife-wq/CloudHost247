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
                  <h2>We love helping you build your brand.</h2>
               </div>
            </div>
         </div>
         <div class="clearfix"></div>
         <div class="for-desktop">
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>Domains</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/domain-search.php">Register New Domain</a></li>
                     <li><a href="{$WEB_ROOT}/domain-transfer.php">Transfer Domain</a></li>
                     <li><a href="{$WEB_ROOT}/tld-directory.php">Domain Extensions</a></li>
                     <li><a href="{$WEB_ROOT}/domain-broker.php">Domain Broker Service</a></li>
                     <li><a href="{$WEB_ROOT}/ssl-certificate.php">SSL Security</a></li>
                  </ul>
                  <h4>Hosting</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/wordpress-hosting.php">Wordpress Hosting</a></li>
                     <li><a href="{$WEB_ROOT}/windows-hosting.php">Window Hosting</a></li>
                     <li><a href="{$WEB_ROOT}/plesk-hosting.php">Plesk Hosting</a></li>
                     <li><a href="{$WEB_ROOT}/cpanel-hosting.php">cPanel Hosting</a></li>
                  </ul>
               </div>
            </div>
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>Websites</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/website-builder.php">Website Builder</a></li>
                     <li><a href="{$WEB_ROOT}/website-design.php">Website Design</a></li>
                     <li><a href="{$WEB_ROOT}/future-element.php">Future Element</a></li>
                     <li><a href="{$WEB_ROOT}/web-hosting.php">Web Hosting</a></li>
                  </ul>
                  <h4>Servers</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/vps-hosting.php">VPS Hosting</a></li>
                     <li><a href="{$WEB_ROOT}/vps-publiccloud.php">Public Cloud</a></li>
                     <li><a href="{$WEB_ROOT}/vps-privatecloud.php">Private Cloud</a></li>
                     <li><a href="{$WEB_ROOT}/enterprise-servers.php">Enterprise Server</a></li>
                  </ul>
               </div>
            </div>
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>Domain Services</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/domain-valuation.php">Domain Valuation</a></li>
                     <li><a href="{$WEB_ROOT}/domain-auctions.php">Domain Auctions</a></li>
                     <li><a href="{$WEB_ROOT}/discount-domain-club.php">Discount Domain Club</a></li>
                     <li><a href="{$WEB_ROOT}/whois-lookup.php">WHOIS Lookup</a></li>
                  </ul>
                  <h4>Security &amp; Trust</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/ssl-certificate.php">SSL Certificates</a></li>
                     <li><a href="{$WEB_ROOT}/backup-policy.php">Website Backup</a></li>
                     <li><a href="{$WEB_ROOT}/data-protection-standards.php">Website Security</a></li>
                  </ul>
               </div>
            </div>
            <div class="col-md-3 col-sm-6 col-xs-6 ">
               <div class="footer_col">
                  <h4>CloudHost247</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/aboutus.php">Why CloudHost247</a></li>
                     <li><a href="{$WEB_ROOT}/faqs.php">Frequent Questions</a></li>
                     <li><a href="{$WEB_ROOT}/affiliates.php">Affiliates Program</a></li>
                     <li><a href="{$WEB_ROOT}/terms-of-service.php">Terms of services</a></li>
                  </ul>
                  <h4>Support</h4>
                  <ul class="footer_links">
                     <li><a href="{$WEB_ROOT}/submitticket.php">Open Ticket</a></li>
                     <li><a href="{$WEB_ROOT}/knowledgebase.php">Knowledgebase</a></li>
                     <li><a href="{$WEB_ROOT}/announcements.php">News</a></li>
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