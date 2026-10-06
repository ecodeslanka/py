<?php
/**
 * includes/SmtpMailer.php
 * ------------------------------------------------------------------
 * Minimal, dependency-free SMTP client (no Composer / PHPMailer needed).
 * Talks straight to the mail server over sockets so the "Outgoing Email
 * (SMTP)" settings saved in Admin → Email Settings are actually used to
 * send mail, instead of being silently ignored.
 *
 * Supports: STARTTLS (port 587), implicit SSL (port 465), plain (port 25),
 * AUTH LOGIN, and returns a real error message on failure so the admin
 * screen can show *why* a send failed instead of a generic message.
 * ------------------------------------------------------------------
 */
declare(strict_types=1);

class SmtpMailer
{
    private string $host;
    private int    $port;
    private string $username;
    private string $password;
    private string $encryption; // 'tls' | 'ssl' | 'none'
    private int    $timeout;

    /** Human-readable reason the last send() failed, if any. */
    public string $lastError = '';

    public function __construct(string $host, int $port, string $username, string $password, string $encryption = 'tls', int $timeout = 15)
    {
        $this->host       = $host;
        $this->port       = $port;
        $this->username   = $username;
        $this->password   = $password;
        $this->encryption = in_array($encryption, ['tls', 'ssl', 'none'], true) ? $encryption : 'tls';
        $this->timeout    = $timeout;
    }

    /**
     * Sends one HTML email.
     *
     * @param string $fromEmail
     * @param string $fromName
     * @param string $toEmail
     * @param string $subject
     * @param string $htmlBody
     * @return bool  true on success; check ->lastError on false
     */
    public function send(string $fromEmail, string $fromName, string $toEmail, string $subject, string $htmlBody): bool
    {
        $this->lastError = '';

        if ($this->host === '') {
            $this->lastError = 'SMTP host is not set.';
            return false;
        }
        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $this->lastError = 'Recipient address is invalid.';
            return false;
        }

        $transport = $this->encryption === 'ssl' ? 'ssl://' : '';
        $address   = $transport . $this->host . ':' . $this->port;

        $errno = 0;
        $errstr = '';
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'       => true,
                'verify_peer_name'  => true,
                'allow_self_signed' => false,
            ],
        ]);

        $socket = @stream_socket_client($address, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$socket) {
            $this->lastError = "Could not connect to $this->host:$this->port" . ($errstr ? " ($errstr)" : '');
            return false;
        }
        stream_set_timeout($socket, $this->timeout);

        try {
            $this->expect($socket, 220, 'connect');
            $this->command($socket, 'EHLO ' . $this->clientDomain(), 250);

            if ($this->encryption === 'tls') {
                $this->command($socket, 'STARTTLS', 220);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('STARTTLS negotiation failed — server did not accept a TLS handshake.');
                }
                $this->command($socket, 'EHLO ' . $this->clientDomain(), 250);
            }

            if ($this->username !== '') {
                $this->command($socket, 'AUTH LOGIN', 334);
                $this->command($socket, base64_encode($this->username), 334);
                $this->command($socket, base64_encode($this->password), 235);
            }

            $this->command($socket, 'MAIL FROM:<' . $fromEmail . '>', 250);
            $this->command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251]);
            $this->command($socket, 'DATA', 354);

            $headers  = 'Date: ' . date('r') . "\r\n";
            $headers .= 'From: ' . $this->encodeHeader($fromName) . ' <' . $fromEmail . ">\r\n";
            $headers .= 'To: <' . $toEmail . ">\r\n";
            $headers .= 'Subject: ' . $this->encodeHeader($subject) . "\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
            $headers .= "Content-Transfer-Encoding: 8bit\r\n";

            // Dot-stuff lines that start with '.' per RFC 5321, normalise line endings.
            $body = str_replace("\r\n", "\n", $htmlBody);
            $body = str_replace("\n", "\r\n", $body);
            $body = preg_replace('/^\./m', '..', $body);

            $this->write($socket, $headers . "\r\n" . $body . "\r\n.");
            $this->expect($socket, 250, 'end-of-data');

            $this->command($socket, 'QUIT', 221);
            fclose($socket);
            return true;
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage();
            @fclose($socket);
            return false;
        }
    }

    private function clientDomain(): string
    {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        return preg_replace('/[^A-Za-z0-9\.\-]/', '', explode(':', $host)[0]) ?: 'localhost';
    }

    private function encodeHeader(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value) ? mb_encode_mimeheader($value, 'UTF-8', 'B') : $value;
    }

    private function write($socket, string $data): void
    {
        fwrite($socket, $data . "\r\n");
    }

    /** Sends a command and validates the response code. */
    private function command($socket, string $cmd, $expectCode): string
    {
        $this->write($socket, $cmd);
        return $this->expect($socket, $expectCode, $cmd);
    }

    /** Reads a (possibly multi-line) SMTP response and throws if the code doesn't match. */
    private function expect($socket, $expectCode, string $context): string
    {
        $expected = is_array($expectCode) ? $expectCode : [$expectCode];
        $full = '';
        do {
            $line = fgets($socket, 515);
            if ($line === false) {
                $meta = stream_get_meta_data($socket);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException("Timed out waiting for server response to: $context");
                }
                throw new RuntimeException("Connection closed unexpectedly during: $context");
            }
            $full .= $line;
        } while (isset($line[3]) && $line[3] === '-');

        $code = (int)substr($full, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException("Server rejected \"$context\": " . trim($full));
        }
        return $full;
    }
}
