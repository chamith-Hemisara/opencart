<?php
namespace Opencart\System\Library\Mail;
class Smtp {
	protected string $to = '';
	protected string $from = '';
	protected string $sender = '';
	protected string $reply_to = '';
	protected string $subject = '';
	protected string $text = '';
	protected string $html = '';
	protected array $attachments = [];
	protected string $smtp_hostname = '';
	protected string $smtp_username = '';
	protected string $smtp_password = '';
	protected int $smtp_port = 25;
	protected int $smtp_timeout = 5;
	protected int $max_attempts = 3;
	protected bool $verp = false;

	public function __construct(array $args) {
		foreach ($args as $key => $value) {
			if (property_exists($this, $key)) {
				$this->{$key} = $value;
			}
		}
	}

	public function send(): bool {
		if (is_array($this->to)) {
			$to = implode(',', $this->to);
		} else {
			$to = $this->to;
		}

		if (version_compare(phpversion(), '8.0', '>=') || substr(PHP_OS, 0, 3) == 'WIN') {
			$eol = "\r\n";
		} else {
			$eol = PHP_EOL;
		}

		$boundary = '----=_NextPart_' . md5(time());

		$header = 'MIME-Version: 1.0' . $eol;
		$header .= 'To: <' . $to . '>' . $eol;
		$header .= 'Subject: =?UTF-8?B?' . base64_encode($this->subject) . '?=' . $eol;
		$header .= 'Date: ' . date('D, d M Y H:i:s O') . $eol;
		$header .= 'From: =?UTF-8?B?' . base64_encode($this->sender) . '?= <' . $this->from . '>' . $eol;

		if (!$this->reply_to) {
			$header .= 'Reply-To: =?UTF-8?B?' . base64_encode($this->sender) . '?= <' . $this->from . '>' . $eol;
		} else {
			$header .= 'Reply-To: =?UTF-8?B?' . base64_encode($this->reply_to) . '?= <' . $this->reply_to . '>' . $eol;
		}

		$header .= 'Return-Path: ' . $this->from . $eol;
		$header .= 'X-Mailer: PHP/' . phpversion() . $eol;
		$header .= 'Content-Type: multipart/mixed; boundary="' . $boundary . '"' . $eol . $eol;

		if (!$this->html) {
			$message = '--' . $boundary . $eol;
			$message .= 'Content-Type: text/plain; charset="utf-8"' . $eol;
			$message .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
			$message .= base64_encode($this->text) . $eol;
		} else {
			$message = '--' . $boundary . $eol;
			$message .= 'Content-Type: multipart/alternative; boundary="' . $boundary . '_alt"' . $eol . $eol;
			$message .= '--' . $boundary . '_alt' . $eol;
			$message .= 'Content-Type: text/plain; charset="utf-8"' . $eol;
			$message .= 'Content-Transfer-Encoding: base64' . $eol . $eol;

			if ($this->text) {
				$message .= base64_encode($this->text) . $eol;
			} else {
				$message .= base64_encode('This is a HTML email and your email client software does not support HTML email!') . $eol;
			}

			$message .= '--' . $boundary . '_alt' . $eol;
			$message .= 'Content-Type: text/html; charset="utf-8"' . $eol;
			$message .= 'Content-Transfer-Encoding: base64' . $eol . $eol;
			$message .= base64_encode($this->html) . $eol;
			$message .= '--' . $boundary . '_alt--' . $eol;
		}

		foreach ($this->attachments as $attachment) {
			if (file_exists($attachment)) {
				$handle = fopen($attachment, 'r');

				$content = fread($handle, filesize($attachment));

				fclose($handle);

				$message .= '--' . $boundary . $eol;
				$message .= 'Content-Type: application/octet-stream; name="' . basename($attachment) . '"' . $eol;
				$message .= 'Content-Transfer-Encoding: base64' . $eol;
				$message .= 'Content-Disposition: attachment; filename="' . basename($attachment) . '"' . $eol;
				$message .= 'Content-ID: <' . urlencode(basename($attachment)) . '>' . $eol;
				$message .= 'X-Attachment-Id: ' . urlencode(basename($attachment)) . $eol . $eol;
				$message .= chunk_split(base64_encode($content));
			}
		}

		$message .= '--' . $boundary . '--' . $eol;

		if (substr($this->smtp_hostname, 0, 3) == 'tls') {
			$hostname = substr($this->smtp_hostname, 6);
		} else {
			$hostname = $this->smtp_hostname;
		}

		$handle = fsockopen($hostname, $this->smtp_port, $errno, $errstr, $this->smtp_timeout);

		if (!$handle) {
			throw new \Exception('Error: ' . $errstr . ' (' . $errno . ')');
		} else {
			if (substr(PHP_OS, 0, 3) != 'WIN') {
				socket_set_timeout($handle, $this->smtp_timeout, 0);
			}

			while ($line = fgets($handle, 515)) {
				if (substr($line, 3, 1) == ' ') {
					break;
				}
			}

			fputs($handle, 'EHLO ' . getenv('SERVER_NAME') . "\r\n");

			$reply = '';

			while ($line = fgets($handle, 515)) {
				$reply .= $line;

				//some SMTP servers respond with 220 code before responding with 250. hence, we need to ignore 220 response string
				if (substr($reply, 0, 3) == 220 && substr($line, 3, 1) == ' ') {
					$reply = '';

					continue;
				} else if (substr($line, 3, 1) == ' ') {
					break;
				}
			}

			if (substr($reply, 0, 3) != 250) {
				throw new \Exception('Error: EHLO not accepted from server!');
			}

			if (substr($this->smtp_hostname, 0, 3) == 'tls') {
				fputs($handle, 'STARTTLS' . "\r\n");

				$this->handleReply($handle, 220, 'Error: STARTTLS not accepted from server!');

				stream_socket_enable_crypto($handle, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
			}

			if (!empty($this->smtp_username) && !empty($this->smtp_password)) {
				fputs($handle, 'EHLO ' . getenv('SERVER_NAME') . "\r\n");

				$this->handleReply($handle, 250, 'Error: EHLO not accepted from server!');

				fputs($handle, 'AUTH LOGIN' . "\r\n");

				$this->handleReply($handle, 334, 'Error: AUTH LOGIN not accepted from server!');

				fputs($handle, base64_encode($this->smtp_username) . "\r\n");

				$this->handleReply($handle, 334, 'Error: Username not accepted from server!');

				fputs($handle, base64_encode($this->smtp_password) . "\r\n");

				$this->handleReply($handle, 235, 'Error: Password not accepted from server!');

			} else {
				fputs($handle, 'HELO ' . getenv('SERVER_NAME') . "\r\n");

				$this->handleReply($handle, 250, 'Error: HELO not accepted from server!');
			}

			if ($this->verp) {
				fputs($handle, 'MAIL FROM: <' . $this->from . '>XVERP' . "\r\n");
			} else {
				fputs($handle, 'MAIL FROM: <' . $this->from . '>' . "\r\n");
			}

			$this->handleReply($handle, 250, 'Error: MAIL FROM not accepted from server!');

			if (!is_array($this->to)) {
				fputs($handle, 'RCPT TO: <' . $this->to . '>' . "\r\n");

				$reply = $this->handleReply($handle, false, 'RCPT TO [!array]');

				if ((substr($reply, 0, 3) != 250) && (substr($reply, 0, 3) != 251)) {
					throw new \Exception('Error: RCPT TO not accepted from server!');
				}
			} else {
				foreach ($this->to as $recipient) {
					fputs($handle, 'RCPT TO: <' . $recipient . '>' . "\r\n");

					$reply = $this->handleReply($handle, false, 'RCPT TO [array]');

					if ((substr($reply, 0, 3) != 250) && (substr($reply, 0, 3) != 251)) {
						throw new \Exception('Error: RCPT TO not accepted from server!');
					}
				}
			}

			fputs($handle, 'DATA' . "\r\n");

			$this->handleReply($handle, 354, 'Error: DATA not accepted from server!');

			// According to rfc 821 we should not send more than 1000 including the CRLF
			$message = str_replace("\r\n", "\n", $header . $message);
			$message = str_replace("\r", "\n", $message);

			$length = (mb_detect_encoding($message, mb_detect_order(), true) == 'ASCII') ? 998 : 249;

			$lines = explode("\n", $message);

			foreach ($lines as $line) {
				$results = str_split($line, $length);

				foreach ($results as $result) {
					if (substr(PHP_OS, 0, 3) != 'WIN') {
						fputs($handle, $result . "\r\n");
					} else {
						fputs($handle, str_replace("\n", "\r\n", $result) . "\r\n");
					}
				}
			}

			fputs($handle, '.' . "\r\n");

			$this->handleReply($handle, 250, 'Error: DATA not accepted from server!');

			fputs($handle, 'QUIT' . "\r\n");

			$this->handleReply($handle, 221, 'Error: QUIT not accepted from server!');

			fclose($handle);
		}

		return true;
	}

	private function handleReply($handle, $status_code = false, $error_text = false, $counter = 0) {
		$reply = '';

		while (($line = fgets($handle, 515)) !== false) {
			$reply .= $line;

			if (substr($line, 3, 1) == ' ') {
				break;
			}
		}

		// Handle slowish server responses (generally due to policy servers)
		if (!$line && empty($reply) && $counter < $this->max_attempts) {
			sleep(1);

			$counter++;

			return $this->handleReply($handle, $status_code, $error_text, $counter);
		}

		if ($status_code) {
			if (substr($reply, 0, 3) != $status_code) {
				throw new \Exception($error_text);
			}
		}

		return $reply;
	}
}
