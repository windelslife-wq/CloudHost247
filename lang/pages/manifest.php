<?php
/**
 * English references for page-array catalogs. CI reads these actual arrays,
 * not a copied fixture. Never execute a landing page to extract its content.
 * New page: wrap its assignment with ch247_content(), register it here, add
 * four locale catalogs and run ci/run-php.sh i18n.
 */
return array(
    'legal' => array('source' => 'legal.php', 'catalog' => 'legal', 'template' => 'legal'),
    'faqs' => array('source' => 'faqs.php', 'catalog' => 'faqs', 'template' => 'faqs'),
    'refund-policy' => array('source' => 'refund-policy.php', 'variable' => 'refundSections', 'template' => 'refundpolicy', 'assignment' => 'refundData'),
    'backup-policy' => array('source' => 'backup-policy.php', 'variable' => 'backupSections', 'template' => 'backuppolicy', 'assignment' => 'backupData'),
    'cybercrime-policy' => array('source' => 'cybercrime-policy.php', 'variable' => 'cybercrimeSections', 'template' => 'cybercrimepolicy', 'assignment' => 'cybercrimeData'),
    'trademark-policy' => array('source' => 'trademark-policy.php', 'variable' => 'trademarkSections', 'template' => 'trademarkpolicy', 'assignment' => 'trademarkData'),
);
