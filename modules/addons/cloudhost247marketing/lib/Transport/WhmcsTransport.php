<?php
/**
 * Reuse whatever WHMCS is already configured to send mail with.
 *
 * This is the zero-new-credentials option and the default. It reads WHMCS's
 * own mail configuration from tblconfiguration and delegates:
 *
 *   MailType = smtp  -> SmtpTransport using WHMCS's SMTP host/credentials
 *                       (password decrypted through the local API, which is
 *                       the only supported way to read it)
 *   MailType = mail  -> PHP mail()
 *
 * The upside is inheriting an already-warmed, already-SPF-aligned setup. The
 * downside is no bounce webhooks, which is exactly why the settings page
 * recommends a dedicated provider once volume grows.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

use Ch247Mkt\Core\Db;
use Ch247Mkt\Core\Logger;
use Ch247Mkt\Core\Whmcs;

class WhmcsTransport implements TransportInterface
{
    /** @var array<string,string>|null */
    protected $config;
    /** @var SmtpTransport|null */
    protected $smtp;

    public function name()
    {
        return 'whmcs:' . ($this->mailType() === 'smtp' ? 'smtp' : 'mail');
    }

    public function isConfigured()
    {
        if ($this->mailType() === 'smtp') {
            $config = $this->whmcsConfig();
            return trim((string) ($config['SMTPHost'] ?? '')) !== '';
        }
        return function_exists('mail');
    }

    public function configurationProblem()
    {
        if ($this->mailType() === 'smtp' && trim((string) ($this->whmcsConfig()['SMTPHost'] ?? '')) === '') {
            return 'WHMCS is set to SMTP but has no SMTP host configured (Setup → General Settings → Mail).';
        }
        if ($this->mailType() !== 'smtp' && !function_exists('mail')) {
            return 'WHMCS is set to use PHP mail() but the mail() function is disabled on this server.';
        }
        return '';
    }

    public function send(Message $message)
    {
        if (!$this->isConfigured()) {
            return Result::permanent($this->configurationProblem());
        }
        if ($this->mailType() === 'smtp') {
            return $this->smtp()->send($message);
        }
        return $this->sendWithPhpMail($message);
    }

    /** @return array{ok:bool,message:string} */
    public function verify()
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => $this->configurationProblem()];
        }
        if ($this->mailType() === 'smtp') {
            return $this->smtp()->verify();
        }
        return ['ok' => true, 'message' => 'WHMCS is configured to use PHP mail(); nothing to connect to.'];
    }

    public function disconnect()
    {
        if ($this->smtp !== null) {
            $this->smtp->disconnect();
        }
    }

    /* -------------------------------------------------------- internals -- */

    protected function sendWithPhpMail(Message $message)
    {
        $mime = $message->mime();
        $headers = [
            'From: ' . $message->fromHeader(),
            'MIME-Version: 1.0',
            'Content-Type: ' . $mime['content_type'],
        ];
        if ($mime['encoding'] !== '') {
            $headers[] = 'Content-Transfer-Encoding: ' . $mime['encoding'];
        }
        if ($message->replyTo !== '') {
            $headers[] = 'Reply-To: ' . $message->replyTo;
        }
        foreach ($message->headers as $name => $value) {
            $headers[] = $name . ': ' . $value;
        }

        $ok = @mail(
            $message->toHeader(),
            Message::encodeHeader($message->subject),
            $mime['body'],
            implode("\r\n", $headers),
            $message->fromEmail !== '' ? '-f' . escapeshellarg($message->fromEmail) : ''
        );
        if ($ok) {
            return Result::success();
        }
        // mail() gives us no reason, so treat it as transient and let the
        // attempt counter decide when to give up.
        return Result::transient('PHP mail() returned false (check the server mail log).');
    }

    protected function smtp()
    {
        if ($this->smtp !== null) {
            return $this->smtp;
        }
        $config = $this->whmcsConfig();
        $ssl = strtolower(trim((string) ($config['SMTPSSL'] ?? '')));
        $this->smtp = new SmtpTransport([
            'host'       => (string) ($config['SMTPHost'] ?? ''),
            'port'       => (int) ($config['SMTPPort'] ?? 587),
            'encryption' => $ssl === 'ssl' ? 'ssl' : ($ssl === 'tls' ? 'tls' : 'none'),
            'username'   => (string) ($config['SMTPUsername'] ?? ''),
            'password'   => $this->decrypt((string) ($config['SMTPPassword'] ?? '')),
            'timeout'    => 20,
            'label'      => 'whmcs:smtp',
        ]);
        return $this->smtp;
    }

    protected function mailType()
    {
        $type = strtolower(trim((string) ($this->whmcsConfig()['MailType'] ?? '')));
        return $type === 'smtp' ? 'smtp' : 'mail';
    }

    protected function whmcsConfig()
    {
        if ($this->config !== null) {
            return $this->config;
        }
        $this->config = [];
        try {
            if (Db::whmcsTableExists('tblconfiguration')) {
                $keys = ['MailType', 'SMTPHost', 'SMTPPort', 'SMTPUsername', 'SMTPPassword', 'SMTPSSL', 'SystemEmailsFromName', 'SystemEmailsFromEmail'];
                $placeholders = implode(', ', array_fill(0, count($keys), '?'));
                foreach (Db::query('SELECT setting, value FROM tblconfiguration WHERE setting IN (' . $placeholders . ')', $keys) as $row) {
                    $this->config[(string) $row['setting']] = (string) $row['value'];
                }
            }
        } catch (\Throwable $e) {
            Logger::warning('Could not read WHMCS mail configuration', ['reason' => get_class($e)]);
        }
        return $this->config;
    }

    /** WHMCS stores SMTPPassword encrypted; only the local API can read it. */
    protected function decrypt($value)
    {
        if (trim((string) $value) === '') {
            return '';
        }
        try {
            $response = Whmcs::api('DecryptPassword', ['password2' => $value]);
            if (is_array($response) && isset($response['password'])) {
                return (string) $response['password'];
            }
        } catch (\Throwable $e) {
            Logger::warning('Could not decrypt the WHMCS SMTP password', ['reason' => $e->getMessage()]);
        }
        return '';
    }

    /** WHMCS's own default sender, used when the module has none set. */
    public function defaultSender()
    {
        $config = $this->whmcsConfig();
        return [
            'name'  => (string) ($config['SystemEmailsFromName'] ?? ''),
            'email' => (string) ($config['SystemEmailsFromEmail'] ?? ''),
        ];
    }
}
