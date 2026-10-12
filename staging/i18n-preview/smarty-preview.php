<?php
/**
 * Test/preview adapter for the real Smarty compiler. No homemade Smarty parser:
 * the same foreach/if/assign/escaping semantics that ship with WHMCS are used.
 * Source is a hash-pinned dev download in .cache, never a production dependency.
 */
declare(strict_types=1);

final class SmartyPreview
{
    private $smarty;

    public function __construct(string $templateDir, array $vars = array())
    {
        $library = __DIR__ . '/.cache/smarty-4.5.6/libs/Smarty.class.php';
        if (!is_file($library)) {
            throw new RuntimeException('Run node staging/i18n-preview/prepare.mjs first');
        }
        require_once $library;
        $compile = __DIR__ . '/.cache/templates_c';
        if (!is_dir($compile)) {
            mkdir($compile, 0775, true);
        }
        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir(array($templateDir, dirname($templateDir)));
        $this->smarty->setCompileDir($compile);
        $this->smarty->caching = Smarty::CACHING_OFF;
        $this->smarty->error_reporting = E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED;
        // The only WHMCS-specific function used by these public templates.
        // It is a fixture route, NOT a stub checkout or a replacement for WHMCS.
        $this->smarty->registerPlugin('modifier', 'routePath', function ($route) {
            return '/index.php?rp=/knowledgebase/search';
        });
        $this->smarty->registerPlugin('modifier', 'html_entity_decode', 'html_entity_decode');
        $this->smarty->assign($vars);
    }

    public function render(string $file, array $vars = array()): string
    {
        $this->smarty->assign($vars);
        return $this->smarty->fetch($file);
    }
}
