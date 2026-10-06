<?php
/**
 * Domain Broker — shared behaviour for the HTML surfaces.
 *
 * Every browser-facing page goes through this base class: it resolves the
 * actor from server-side state, verifies CSRF on writes, turns the module's
 * typed exceptions into human messages, and implements post/redirect/get so a
 * refresh can never replay a financial action.
 *
 * @package DomainBroker
 */

namespace DomainBroker\Http;

use DomainBroker\Core\Actor;
use DomainBroker\Core\Csrf;
use DomainBroker\Core\DomainBrokerException;
use DomainBroker\Core\Http;
use DomainBroker\Core\Identity;
use DomainBroker\Core\Logger;
use DomainBroker\Core\Money;
use DomainBroker\Core\Settings;
use DomainBroker\Core\Str;
use DomainBroker\Core\ValidationException;
use DomainBroker\Services\AssignmentService;
use DomainBroker\Services\BrokerDirectoryService;
use DomainBroker\Services\DisputeService;
use DomainBroker\Services\DocumentService;
use DomainBroker\Services\DomainIntelService;
use DomainBroker\Services\FeeService;
use DomainBroker\Services\MessageService;
use DomainBroker\Services\NegotiationService;
use DomainBroker\Services\PaymentService;
use DomainBroker\Services\ReportService;
use DomainBroker\Services\RequestService;
use DomainBroker\Services\RiskService;
use DomainBroker\Services\TransferService;
use DomainBroker\Services\VerificationService;

abstract class Controller
{
    const FLASH_KEY = 'domainbroker_flash';

    /** @var Actor */
    protected $actor;

    /** @var array<string,object> */
    protected $services = [];

    /** @var bool set by tests so redirect() returns instead of exiting */
    public static $testMode = false;

    /** @var array<int,array{url:string}> redirects captured in test mode */
    public static $redirects = [];

    public function __construct(Actor $actor = null)
    {
        $this->actor = $actor ?: Identity::current();
    }

    public function actor()
    {
        return $this->actor;
    }

    /* ----------------------------------------------------------- services */

    protected function service($class)
    {
        if (!isset($this->services[$class])) {
            $this->services[$class] = new $class();
        }
        return $this->services[$class];
    }

    /** @return RequestService */
    protected function requestService()
    {
        return $this->service(RequestService::class);
    }

    /** @return NegotiationService */
    protected function negotiationService()
    {
        return $this->service(NegotiationService::class);
    }

    /** @return AssignmentService */
    protected function assignmentService()
    {
        return $this->service(AssignmentService::class);
    }

    /** @return PaymentService */
    protected function paymentService()
    {
        return $this->service(PaymentService::class);
    }

    /** @return TransferService */
    protected function transferService()
    {
        return $this->service(TransferService::class);
    }

    /** @return VerificationService */
    protected function verificationService()
    {
        return $this->service(VerificationService::class);
    }

    /** @return MessageService */
    protected function messageService()
    {
        return $this->service(MessageService::class);
    }

    /** @return DocumentService */
    protected function documentService()
    {
        return $this->service(DocumentService::class);
    }

    /** @return DisputeService */
    protected function disputeService()
    {
        return $this->service(DisputeService::class);
    }

    /** @return BrokerDirectoryService */
    protected function brokerService()
    {
        return $this->service(BrokerDirectoryService::class);
    }

    /** @return FeeService */
    protected function feeService()
    {
        return $this->service(FeeService::class);
    }

    /** @return ReportService */
    protected function reportService()
    {
        return $this->service(ReportService::class);
    }

    /** @return RiskService */
    protected function riskService()
    {
        return $this->service(RiskService::class);
    }

    /** @return DomainIntelService */
    protected function domainService()
    {
        return $this->service(DomainIntelService::class);
    }

    /* -------------------------------------------------------------- input */

    protected function isPost()
    {
        return strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET') === 'POST';
    }

    protected function input($key, $default = null)
    {
        if (isset($_POST[$key])) {
            return is_string($_POST[$key]) ? trim($_POST[$key]) : $_POST[$key];
        }
        if (isset($_GET[$key])) {
            return is_string($_GET[$key]) ? trim($_GET[$key]) : $_GET[$key];
        }
        return $default;
    }

    protected function intInput($key, $default = 0)
    {
        $value = $this->input($key, null);
        return $value === null || $value === '' ? (int) $default : (int) $value;
    }

    protected function boolInput($key)
    {
        $value = $this->input($key, null);
        if ($value === null) {
            return false;
        }
        return in_array(strtolower((string) $value), ['1', 'on', 'yes', 'true'], true);
    }

    /** Collect only the fields a form is allowed to submit. */
    protected function only(array $keys)
    {
        $out = [];
        foreach ($keys as $key) {
            $value = $this->input($key, null);
            if ($value !== null) {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /** Verify CSRF on any state-changing submission. */
    protected function guardCsrf()
    {
        Csrf::verify();
    }

    /* -------------------------------------------------------------- flash */

    protected function flash($type, $message)
    {
        $this->startSession();
        if (!isset($_SESSION[self::FLASH_KEY]) || !is_array($_SESSION[self::FLASH_KEY])) {
            $_SESSION[self::FLASH_KEY] = [];
        }
        $_SESSION[self::FLASH_KEY][] = ['type' => (string) $type, 'message' => (string) $message];
    }

    protected function success($message)
    {
        $this->flash('success', $message);
    }

    protected function error($message)
    {
        $this->flash('danger', $message);
    }

    /** Read and clear the queued messages. */
    protected function takeFlash()
    {
        $this->startSession();
        $messages = isset($_SESSION[self::FLASH_KEY]) && is_array($_SESSION[self::FLASH_KEY])
            ? $_SESSION[self::FLASH_KEY]
            : [];
        $_SESSION[self::FLASH_KEY] = [];
        return $messages;
    }

    protected function startSession()
    {
        if (php_sapi_name() === 'cli' || self::$testMode) {
            if (!isset($_SESSION) || !is_array($_SESSION)) {
                $_SESSION = [];
            }
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /* ----------------------------------------------------------- redirect */

    /**
     * Post/redirect/get. Financial posts must never be replayable by a browser
     * refresh, so every write path ends here.
     */
    protected function redirect($url)
    {
        self::$redirects[] = ['url' => $url];
        if (self::$testMode || php_sapi_name() === 'cli') {
            return $url;
        }
        if (!headers_sent()) {
            header('Location: ' . $url, true, 303);
        }
        echo '<p>Redirecting to <a href="' . View::attr($url) . '">' . View::e($url) . '</a>.</p>';
        exit;
    }

    /* ---------------------------------------------------------- exceptions */

    /**
     * Run a write action, converting the module's typed exceptions into a
     * flash message. Internal details are logged, not shown.
     *
     * @return bool true when the action completed
     */
    protected function attempt(callable $action, $successMessage = null)
    {
        try {
            $action();
            if ($successMessage !== null) {
                $this->success($successMessage);
            }
            return true;
        } catch (ValidationException $e) {
            $details = [];
            foreach ($e->errors() as $field => $message) {
                $details[] = is_string($field) && !is_numeric($field)
                    ? (Str::label($field) . ': ' . $message)
                    : $message;
            }
            $this->error($e->getMessage() . ($details ? ' (' . implode('; ', $details) . ')' : ''));
            return false;
        } catch (DomainBrokerException $e) {
            $this->error($e->getMessage());
            return false;
        } catch (\Throwable $e) {
            Logger::error('Unhandled error in a Domain Broker page.', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'actor' => $this->actor->identity(),
            ]);
            $this->error('Something went wrong while processing that request. Our team has been notified.');
            return false;
        }
    }

    /* -------------------------------------------------------------- shared */

    /** Currencies this deployment accepts, as code => label. */
    protected function currencyOptions()
    {
        $out = [];
        foreach ($this->requestService()->allowedCurrencies() as $code) {
            $out[$code] = $code;
        }
        if (!$out) {
            $out[Settings::string('default_currency', 'USD')] = Settings::string('default_currency', 'USD');
        }
        return $out;
    }

    /** Context every template needs. */
    protected function sharedViewData(array $extra = [])
    {
        return array_merge([
            'actor' => [
                'type' => $this->actor->type,
                'role' => $this->actor->role,
                'name' => $this->actor->name,
                'id' => $this->actor->actorId(),
                'is_customer' => $this->actor->isCustomer(),
                'is_broker' => $this->actor->isBroker(),
                'is_admin' => $this->actor->isAdmin(),
            ],
            'csrf_field' => Csrf::field(),
            'csrf_token' => Csrf::token(),
            'csrf_name' => Csrf::FIELD,
            'flash' => $this->takeFlash(),
            'module_url' => View::url(''),
            'urls' => [
                'dashboard' => View::url('dashboard'),
                'requests' => View::url('requests'),
                'new' => View::url('new'),
                'transactions' => View::url('transactions'),
                'notifications' => View::url('notifications'),
                'help' => View::url('help'),
            ],
            'assets_url' => 'modules/addons/domainbroker/assets',
            'default_currency' => Settings::string('default_currency', 'USD'),
            'currencies' => $this->currencyOptions(),
            'support_email' => Settings::string('support_email', ''),
            'brand' => Settings::string('service_name', 'Domain Broker Service'),
        ], $extra);
    }

    /** Minor units from a decimal form field, with a friendly failure. */
    protected function moneyInput($key, $currency, $required = true)
    {
        $raw = (string) $this->input($key, '');
        if ($raw === '') {
            if ($required) {
                throw new ValidationException('Please enter an amount.', [$key => 'An amount is required.']);
            }
            return null;
        }
        return Money::toMinor($raw, $currency);
    }

    /** The IP/user-agent pair recorded against a write. */
    protected function requestContext()
    {
        return ['ip' => Http::clientIp(), 'user_agent' => Http::userAgent()];
    }
}
