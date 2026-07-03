<?php

declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use RuntimeException;

final class ColdEmailSender
{
    /**
     * @param array{host:string, port:int, username:string, password:string, encryption:string, from_email:string, from_name:string, reply_to:string, limit:int} $config
     */
    public function __construct(private array $config)
    {
        if (!class_exists(PHPMailer::class)) {
            throw new RuntimeException('PHPMailer is not installed. Run: /Applications/XAMPP/xamppfiles/bin/php composer.phar install');
        }
    }

    public function send(string $toEmail, string $businessName, string $pitchMessage): bool
    {
        if ($this->isPlaceholderConfig()) {
            throw new RuntimeException('SMTP config.php içinde gerçek sunucu bilgileri ile yapılandırılmadı.');
        }

        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];
        $mail->Port = $this->config['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        if ($this->config['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($this->config['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($this->config['from_email'], $this->config['from_name']);
        $mail->addReplyTo($this->config['reply_to'], $this->config['from_name']);
        $mail->addAddress($toEmail, $businessName);
        $mail->isHTML(true);
        $mail->Subject = 'Web siteniz ve dijital dönüşüm fırsatı';
        $mail->Body = nl2br(htmlspecialchars($pitchMessage, ENT_QUOTES, 'UTF-8'))
            . '<br><br><strong>YourAgency</strong><br>KKTC yerel işletmeleri için web, QR menü ve otomasyon çözümleri<br><a href="https://youragency.com">https://youragency.com</a>';
        $mail->AltBody = $pitchMessage . "\n\nYourAgency\nKKTC yerel işletmeleri için web, QR menü ve otomasyon çözümleri\nhttps://youragency.com";

        return $mail->send();
    }

    public function sendJobApplication(
        string $toEmail,
        string $organizationName,
        string $subject,
        string $message,
        string $cvPath,
        string $applicantName
    ): bool {
        if ($this->isPlaceholderConfig()) {
            throw new RuntimeException('SMTP config.php içinde gerçek sunucu bilgileri ile yapılandırılmadı.');
        }

        if (!is_file($cvPath) || !is_readable($cvPath)) {
            throw new RuntimeException('CV PDF dosyası okunamıyor: ' . $cvPath);
        }

        $mail = $this->makeConfiguredMailer();
        $mail->setFrom($this->config['from_email'], $this->config['from_name']);
        $mail->addReplyTo($this->config['reply_to'], $applicantName);
        $mail->addAddress($toEmail, $organizationName);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));
        $mail->AltBody = $message;
        $mail->addAttachment($cvPath, 'Demo-Applicant-CV.pdf');

        return $mail->send();
    }

    public function testConnection(): bool
    {
        if ($this->isPlaceholderConfig()) {
            throw new RuntimeException('SMTP ayarları gerçek sunucu bilgileri ile yapılandırılmadı.');
        }

        $mail = $this->makeConfiguredMailer();
        $smtp = $mail->getSMTPInstance();

        try {
            return $mail->smtpConnect();
        } finally {
            $smtp->quit(true);
            $mail->smtpClose();
        }
    }

    private function makeConfiguredMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $this->config['username'];
        $mail->Password = $this->config['password'];
        $mail->Port = $this->config['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = 'base64';

        if ($this->config['encryption'] === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($this->config['encryption'] === 'tls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = false;
            $mail->SMTPAutoTLS = false;
        }

        return $mail;
    }

    private function isPlaceholderConfig(): bool
    {
        return $this->config['host'] === ''
            || $this->config['host'] === 'smtp.example.com'
            || $this->config['password'] === ''
            || $this->config['password'] === 'YOUR_SMTP_PASSWORD';
    }
}
