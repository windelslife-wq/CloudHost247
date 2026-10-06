<?php
/**
 * Dependency-free ESMTP client.
 *
 * WHMCS ships PHPMailer, but an addon reaching into core's vendor tree breaks
 * on every WHMCS upgrade that bumps it. This is a focused implementation of
 * exactly what campaign delivery needs: EHLO, STARTTLS, AUTH (PLAIN/LOGIN),
 * MAIL FROM, RCPT TO, DATA — with connection reuse so a batch of 200 messages
 * is one TCP/TLS handshake, not 200.
 *
 * Reply codes are mapped to Result::permanent / Result::transient so the queue
 * retries greylisting but never retries "no such user".
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

use Ch247Mkt\Core\Logger;

class SmtpTransport implements TransportInterface
{
    protected $host;
    protected $port;
    protected $encryption; // tls | ssl | none
    protected $username;
    protected $password;
    protected $timeout;
    protected $heloName;
    protected $label;

    /** @var resource|null */
    protected $socket;
    /** @var string[] */
    protected $capabilities = [];
    protected $sentThisSession = 0;

    public function __construct(array $config)
    {
        $this->host       = trim((string) ($config['host'] ?? ''));
        $this->port       = (int) ($config['port'] ?? 587);
        $this->encryption = in_array($config['encryption'] ?? 'tls', ['tls', 'ssl', 'none'], true) ? $config['encryption'] : 'tls';
        $this->username   = (string) ($config['username'] ?? '');
        $this->password   = (string) ($config['password'] ?? '');
        $this->timeout    = max(5, (int) ($config['timeout'] ?? 15));
        $this->heloName   = (string) ($config['helo'] ?? '');
        $this->label      = (string) ($config['label'] ?? 'smtp');
    }

    public function name()
    {
        return $this->label;
    }

    public function isConfigured()
    {
        return $this->host !== '' && $this->port > 0;
    }

    public function configurationProblem()
    {
        if ($this->host === '') {
            return 'No SMTP host is set.';
        }
        if ($this->port <= 0) {
            return 'The SMTP port is not valid.';
        }
        return '';
    }

    public function send(Message $message)
    {
        if (!$this->isConfigured()) {
            return Result::permanent($this->configurationProblem());
        }
        try {
            if (!$this->ensureConnected()) {
                return Result::transient('Could not connect to ' . $this->host . ':' . $this->port . '.');
            }

            $from = $message->fromEmail !== '' ? $message->fromEmail : $this->username;
            list($code, $reply) = $this->command('MAIL FROM:<' . $from . '>');
            if ($code >= 400) {
                $this->reset();
                return Result::fromSmtpCode($code, $reply);
            }

            list($code, $reply) = $this->command('RCPT TO:<' . $message->toEmail . '>');
            if ($code >= 400) {
                $this->reset();
                return Result::fromSmtpCode($code, $reply);
            }

            list($code, $reply) = $this->command('DATA');
            if ($code !== 354) {
                $this->reset();
                return Result::fromSmtpCode($code, $reply);
            }

            $this->write($this->dotStuff($message->rfc822()) . "\r\n.");
            list($code, $reply) = $this->readReply();
            if ($code >= 400) {
                return Result::fromSmtpCode($code, $reply);
            }

            $this->sentThisSession++;
            // Many servers return the queue id in the 250 reply.
            $messageId = preg_match('/queued as ([A-Za-z0-9]+)/i', $reply, $m) === 1 ? $m[1] : '';
            return Result::success($messageId, ['reply' => trim($reply)]);
        } catch (\Throwable $e) {
            $this->disconnect();
            return Result::transient('SMTP error: ' . $e->getMessage());
        }
    }

    /** Verify credentials without sending anything. @return array{ok:bool,message:string} */
    public function verify()
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'message' => $this->configurationProblem()];
        }
        try {
            $this->disconnect();
            if (!$this->ensureConnected()) {
                return ['ok' => false, 'message' => 'Could not connect to ' . $this->host . ':' . $this->port . '.'];
            }
            $banner = $this->capabilities === [] ? 'connected' : 'connected; server offers ' . implode(', ', array_slice($this->capabilities, 0, 6));
            $this->disconnect();
            return ['ok' => true, 'message' => ucfirst($banner) . '.'];
        } catch (\Throwable $e) {
            $this->disconnect();
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    public function disconnect()
    {
        if (is_resource($this->socket)) {
            @fwrite($this->socket, "QUIT\r\n");
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->capabilities = [];
    }

    public function __destruct()
    {
        $this->disconnect();
    }

    /* -------------------------------------------------------- internals -- */

    protected function ensureConnected()
    {
        if (is_resource($this->socket) && !feof($this->socket)) {
            return true;
        }
        $this->socket = null;

        $scheme = $this->encryption === 'ssl' ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => ['SNI_enabled' => true, 'peer_name' => $this->host],
        ]);
        $errno = 0;
        $errstr = '';
        $socket = @stream_socket_client(
            $scheme . $this->host . ':' . $this->port,
            $errno,
            $errstr,
            $this->timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if ($socket === false) {
            Logger::warning('SMTP connect failed', ['host' => $this->host, 'port' => $this->port, 'error' => $errstr]);
            return false;
        }
        stream_set_timeout($socket, $this->timeout);
        $this->socket = $socket;

        list($code) = $this->readReply();
        if ($code !== 220) {
            $this->disconnect();
            return false;
        }

        $this->ehlo();

        if ($this->encryption === 'tls') {
            if (!$this->supports('STARTTLS')) {
                $this->disconnect();
                throw new \RuntimeException('The server does not offer STARTTLS but encryption is set to TLS.');
            }
            list($code) = $this->command('STARTTLS');
            if ($code !== 220) {
                $this->disconnect();
                throw new \RuntimeException('STARTTLS was refused by the server.');
            }
            $crypto = STREAM_CRYPTO_METHOD_TLS_CLIENT;
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
            }
            if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
                $crypto |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
            }
            if (!@stream_socket_enable_crypto($this->socket, true, $crypto)) {
                $this->disconnect();
                throw new \RuntimeException('TLS negotiation failed.');
            }
            $this->ehlo(); // capabilities change after STARTTLS
        }

        if ($this->username !== '') {
            $this->authenticate();
        }
        return true;
    }

    protected function ehlo()
    {
        $helo = $this->heloName !== '' ? $this->heloName : $this->localName();
        list($code, $reply) = $this->command('EHLO ' . $helo);
        if ($code >= 400) {
            list($code, $reply) = $this->command('HELO ' . $helo);
            if ($code >= 400) {
                throw new \RuntimeException('Server rejected EHLO/HELO: ' . trim($reply));
            }
            $this->capabilities = [];
            return;
        }
        $this->capabilities = [];
        foreach (preg_split('/\r?\n/', $reply) as $line) {
            $line = trim(preg_replace('/^\d{3}[\s-]/', '', $line));
            if ($line !== '') {
                $this->capabilities[] = strtoupper($line);
            }
        }
    }

    protected function authenticate()
    {
        if ($this->supports('AUTH') && $this->authMechanism('PLAIN')) {
            list($code, $reply) = $this->command('AUTH PLAIN ' . base64_encode("\0" . $this->username . "\0" . $this->password));
            if ($code === 235) {
                return;
            }
            if ($code >= 500 && !$this->authMechanism('LOGIN')) {
                throw new \RuntimeException('SMTP authentication failed: ' . trim($reply));
            }
        }
        list($code, $reply) = $this->command('AUTH LOGIN');
        if ($code !== 334) {
            throw new \RuntimeException('SMTP server did not accept AUTH LOGIN: ' . trim($reply));
        }
        list($code, $reply) = $this->command(base64_encode($this->username));
        if ($code !== 334) {
            throw new \RuntimeException('SMTP username was rejected: ' . trim($reply));
        }
        list($code, $reply) = $this->command(base64_encode($this->password));
        if ($code !== 235) {
            throw new \RuntimeException('SMTP authentication failed: ' . trim($reply));
        }
    }

    protected function authMechanism($mechanism)
    {
        foreach ($this->capabilities as $capability) {
            if (strpos($capability, 'AUTH') === 0 && strpos($capability, strtoupper($mechanism)) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function supports($capability)
    {
        foreach ($this->capabilities as $line) {
            if (strpos($line, strtoupper($capability)) === 0) {
                return true;
            }
        }
        return false;
    }

    protected function reset()
    {
        try {
            $this->command('RSET');
        } catch (\Throwable $e) {
            $this->disconnect();
        }
    }

    protected function command($command)
    {
        $this->write($command);
        return $this->readReply();
    }

    protected function write($data)
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP connection is not open.');
        }
        if (@fwrite($this->socket, $data . "\r\n") === false) {
            throw new \RuntimeException('Writing to the SMTP socket failed.');
        }
    }

    /** @return array{0:int,1:string} */
    protected function readReply()
    {
        if (!is_resource($this->socket)) {
            throw new \RuntimeException('SMTP connection is not open.');
        }
        $reply = '';
        $code = 0;
        while (($line = fgets($this->socket, 1024)) !== false) {
            $reply .= $line;
            // Final line is "250 text"; continuations are "250-text".
            if (preg_match('/^(\d{3})(\s|$)/', $line, $m) === 1) {
                $code = (int) $m[1];
                break;
            }
            $meta = stream_get_meta_data($this->socket);
            if (!empty($meta['timed_out'])) {
                throw new \RuntimeException('SMTP read timed out.');
            }
        }
        if ($reply === '') {
            throw new \RuntimeException('SMTP connection closed unexpectedly.');
        }
        return [$code, $reply];
    }

    /** RFC 5321 §4.5.2 — a line starting with "." must be doubled. */
    protected function dotStuff($data)
    {
        return preg_replace('/^\./m', '..', (string) $data);
    }

    protected function localName()
    {
        $host = isset($_SERVER['SERVER_NAME']) ? (string) $_SERVER['SERVER_NAME'] : '';
        if ($host === '' && function_exists('gethostname')) {
            $host = (string) gethostname();
        }
        $host = preg_replace('/[^A-Za-z0-9.\-]/', '', $host);
        return $host !== '' ? $host : 'localhost';
    }
}
