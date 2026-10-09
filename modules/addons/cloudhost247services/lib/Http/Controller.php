<?php
/**
 * Shared behaviour for the customer portal module pages.
 *
 * @package Chs\Http
 */

namespace Chs\Http;

use Chs\Core\ChsException;
use Chs\Core\I18n;
use Chs\Core\Csrf;
use Chs\Core\DuplicateOperationException;
use Chs\Core\ForbiddenException;
use Chs\Core\Http;
use Chs\Core\Identity;
use Chs\Core\InvalidTransitionException;
use Chs\Core\NotFoundException;
use Chs\Core\ProviderNotConfiguredException;
use Chs\Core\RateLimitException;
use Chs\Core\ServiceUnavailableException;
use Chs\Core\ValidationException;

class Controller
{
    /** @var array<string,string> translated titles for module-owned screens */
    protected static $titleKeys = [
        'login_required' => 'sign_in_required',
        'dashboard' => 'my_digital_services',
        'search' => 'domain_search',
        'bulk' => 'bulk_domain_search',
        'bulkview' => 'bulk_domain_search',
        'domains' => 'my_domains',
        'domain' => 'my_domains',
        'transfers' => 'domain_transfers',
        'transfer' => 'domain_transfers',
        'servers' => 'my_servers',
        'server' => 'my_servers',
        'order' => 'order_server',
        'orderconfig' => 'order_server',
        'valuation' => 'domain_valuation',
        'auctions' => 'domain_auctions',
        'watchlist' => 'my_watchlist',
        'sell' => 'sell_a_domain',
        'club' => 'discount_domain_club',
        'requests' => 'my_service_requests',
        'request_new' => 'new_service_request',
        'logos' => 'my_logo_projects',
        'logo_studio' => 'logo_studio',
        'ai_builder' => 'ai_website_builder',
        'inbox' => 'unified_inbox',
        'thread' => 'conversation',
        'notifications' => 'notifications',
    ];

    /**
     * Standard page envelope for a module clientarea response.
     */
    protected function page($pagetitle, $template, array $vars = [])
    {
        $vars['modulelink'] = 'index.php?m=cloudhost247services';
        $vars['csrf_field'] = Csrf::field();
        $vars['csrf_token'] = Csrf::token();
        $vars['chs_language'] = I18n::language();
        $titleKey = isset(self::$titleKeys[$template]) ? self::$titleKeys[$template] : '';
        $translatedTitle = $titleKey !== '' ? I18n::text($titleKey, $pagetitle) : $pagetitle;

        return [
            'pagetitle'    => $translatedTitle,
            'breadcrumb'   => ['index.php?m=cloudhost247services' => 'CloudHost247 Services'],
            'templatefile' => 'templates/client/' . $template,
            'templatevariables' => $vars,
        ];
    }

    /** Error page (template renders $error_message and optional $error_fields). */
    protected function errorPage($title, $message, array $fields = [], $httpTemplate = 'error')
    {
        return $this->page($title, $httpTemplate, [
            'error_message' => $message,
            'error_fields'  => $fields,
        ]);
    }

    /**
     * Run an action with the full exception → honest screen mapping.
     */
    protected function guard(callable $fn)
    {
        try {
            return $fn();
        } catch (ValidationException $e) {
            return $this->errorPage('Check the highlighted fields', $e->getMessage(), $e->fieldErrors());
        } catch (ForbiddenException $e) {
            return $this->errorPage('Access restricted', $e->getMessage(), [], 'login_required');
        } catch (NotFoundException $e) {
            return $this->errorPage('Not found', $e->getMessage());
        } catch (InvalidTransitionException $e) {
            return $this->errorPage('That just changed', $e->getMessage() . ' Reload the page to see its current state.');
        } catch (RateLimitException $e) {
            return $this->errorPage('Slow down a moment', $e->getMessage());
        } catch (ProviderNotConfiguredException $e) {
            return $this->errorPage('Not configured yet', $e->getMessage(), [], 'config_required');
        } catch (ServiceUnavailableException $e) {
            return $this->errorPage('Temporarily unavailable', $e->getMessage());
        } catch (DuplicateOperationException $e) {
            return $this->errorPage('Already recorded', $e->getMessage() . ' No duplicate was created.');
        } catch (ChsException $e) {
            return $this->errorPage('Something went wrong', $e->getMessage());
        } catch (\Throwable $e) {
            \Chs\Core\Logger::error('Unhandled portal error', ['message' => $e->getMessage(), 'class' => get_class($e)]);
            return $this->errorPage('Something went wrong', 'An unexpected error occurred. The team has been notified.');
        }
    }

    /** Flash helper: survive one redirect via the session. */
    protected function flash($key, $value = null)
    {
        if (session_status() === PHP_SESSION_NONE && php_sapi_name() !== 'cli') {
            @session_start();
        }
        if (!isset($_SESSION['chs_flash']) || !is_array($_SESSION['chs_flash'])) {
            $_SESSION['chs_flash'] = [];
        }
        if ($value === null) {
            $v = isset($_SESSION['chs_flash'][$key]) ? $_SESSION['chs_flash'][$key] : null;
            unset($_SESSION['chs_flash'][$key]);
            return $v;
        }
        $_SESSION['chs_flash'][$key] = $value;
    }
}
