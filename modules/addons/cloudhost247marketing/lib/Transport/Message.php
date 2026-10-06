<?php
/**
 * An outbound message, transport-agnostic.
 *
 * Header values are sanitised on the way in: a newline in a subject line or
 * an address is a header-injection attempt, and it is stripped here once
 * rather than in every transport.
 *
 * @package Ch247Mkt
 */

namespace Ch247Mkt\Transport;

class Message
{
    public $toEmail = '';
    public $toName = '';
    public $fromEmail = '';
    public $fromName = '';
    public $replyTo = '';
    public $subject = '';
    public $html = '';
    public $text = '';
    /** @var array<string,string> */
    public $headers = [];

    public static function make(array $data)
    {
        $m = new self();
        $m->toEmail   = self::clean($data['to_email'] ?? '');
        $m->toName    = self::clean($data['to_name'] ?? '');
        $m->fromEmail = self::clean($data['from_email'] ?? '');
        $m->fromName  = self::clean($data['from_name'] ?? '');
        $m->replyTo   = self::clean($data['reply_to'] ?? '');
        $m->subject   = self::clean($data['subject'] ?? '');
        $m->html      = (string) ($data['html'] ?? '');
        $m->text      = (string) ($data['text'] ?? '');
        foreach ((array) ($data['headers'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9-]/', '', (string) $name);
            if ($name === '') {
                continue;
            }
            $m->headers[$name] = self::clean($value);
        }
        return $m;
    }

    /** RFC 5322 display-name + address, encoded when non-ASCII. */
    public function fromHeader()
    {
        return self::address($this->fromEmail, $this->fromName);
    }

    public function toHeader()
    {
        return self::address($this->toEmail, $this->toName);
    }

    public static function address($email, $name = '')
    {
        $email = self::clean($email);
        $name = self::clean($name);
        if ($name === '') {
            return $email;
        }
        if (preg_match('/^[\x20-\x7E]*$/', $name) === 1) {
            return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $name) . '" <' . $email . '>';
        }
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $email . '>';
    }

    public static function encodeHeader($value)
    {
        $value = self::clean($value);
        if (preg_match('/^[\x20-\x7E]*$/', $value) === 1) {
            return $value;
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    /** Strip CR/LF — the only defence that matters against header injection. */
    public static function clean($value)
    {
        return trim(str_replace(["\r", "\n", "\0"], '', (string) $value));
    }

    /** Build the full MIME body (multipart/alternative when both parts exist). */
    public function mime($boundary = null)
    {
        $boundary = $boundary ?: 'ch247m_' . bin2hex(random_bytes(12));
        $html = $this->html;
        $text = $this->text !== '' ? $this->text : \Ch247Mkt\Core\Str::htmlToText($html);

        if ($html === '') {
            return [
                'content_type' => 'text/plain; charset=UTF-8',
                'encoding'     => 'base64',
                'body'         => chunk_split(base64_encode($text), 76, "\r\n"),
            ];
        }

        $body = "This is a multi-part message in MIME format.\r\n\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($text), 76, "\r\n") . "\r\n";
        $body .= '--' . $boundary . "\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n";
        $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
        $body .= chunk_split(base64_encode($html), 76, "\r\n") . "\r\n";
        $body .= '--' . $boundary . "--\r\n";

        return [
            'content_type' => 'multipart/alternative; boundary="' . $boundary . '"',
            'encoding'     => '',
            'body'         => $body,
        ];
    }

    /** Complete RFC 5322 header block + body, for SMTP DATA. */
    public function rfc822()
    {
        $mime = $this->mime();
        $headers = [
            'Date'         => gmdate('D, d M Y H:i:s') . ' +0000',
            'From'         => $this->fromHeader(),
            'To'           => $this->toHeader(),
            'Subject'      => self::encodeHeader($this->subject),
            'Message-ID'   => '<' . bin2hex(random_bytes(12)) . '@' . $this->senderDomain() . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => $mime['content_type'],
        ];
        if ($this->replyTo !== '') {
            $headers['Reply-To'] = $this->replyTo;
        }
        if ($mime['encoding'] !== '') {
            $headers['Content-Transfer-Encoding'] = $mime['encoding'];
        }
        foreach ($this->headers as $name => $value) {
            $headers[$name] = $value;
        }

        $out = '';
        foreach ($headers as $name => $value) {
            $out .= $name . ': ' . $value . "\r\n";
        }
        return $out . "\r\n" . $mime['body'];
    }

    public function senderDomain()
    {
        $at = strrpos($this->fromEmail, '@');
        $domain = $at === false ? '' : substr($this->fromEmail, $at + 1);
        return $domain !== '' ? $domain : 'localhost';
    }
}
