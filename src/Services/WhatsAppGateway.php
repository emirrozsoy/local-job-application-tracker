<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class WhatsAppGateway
{
    /**
     * @param array{provider:string, endpoint:string, token:string, instance_id:string, delay_seconds:int, limit:int} $config
     */
    public function __construct(private array $config)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is not enabled.');
        }
    }

    public function send(string $phoneNumber, string $message): bool
    {
        if ($this->isPlaceholderConfig()) {
            throw new RuntimeException('WhatsApp gateway config.php içinde gerçek endpoint/token ile yapılandırılmadı.');
        }

        $provider = mb_strtolower($this->config['provider'], 'UTF-8');
        $endpoint = $this->config['endpoint'];
        $headers = ['Content-Type: application/json'];
        $payload = [];

        if ($provider === 'evolution') {
            if (!str_contains($endpoint, '/message/sendText/')) {
                $endpoint = rtrim($endpoint, '/') . '/message/sendText/' . rawurlencode($this->config['instance_id']);
            }

            $headers[] = 'apikey: ' . $this->config['token'];
            $payload = [
                'number' => ltrim($phoneNumber, '+'),
                'text' => $message,
            ];
        } elseif ($provider === 'ultramsg') {
            $endpoint = rtrim($endpoint, '/') . '/' . rawurlencode($this->config['instance_id']) . '/messages/chat';
            $payload = [
                'token' => $this->config['token'],
                'to' => $phoneNumber,
                'body' => $message,
            ];
        } else {
            $headers[] = 'Authorization: Bearer ' . $this->config['token'];
            $payload = [
                'phone' => $phoneNumber,
                'message' => $message,
            ];
        }

        $ch = curl_init($endpoint);

        if ($ch === false) {
            throw new RuntimeException('Could not initialize WhatsApp cURL request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ]);

        $rawResponse = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException('WhatsApp cURL error: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('WhatsApp API HTTP %d: %s', $httpCode, mb_substr($rawResponse, 0, 500, 'UTF-8')));
        }

        $this->assertNotPending($rawResponse);

        return true;
    }

    public function sendDocument(string $phoneNumber, string $filePath, string $caption, ?string $fileName = null): bool
    {
        if ($this->isPlaceholderConfig()) {
            throw new RuntimeException('WhatsApp gateway config.php içinde gerçek endpoint/token ile yapılandırılmadı.');
        }

        if (!is_file($filePath) || !is_readable($filePath)) {
            throw new RuntimeException('WhatsApp ile gönderilecek dosya okunamıyor: ' . $filePath);
        }

        $provider = mb_strtolower($this->config['provider'], 'UTF-8');

        if ($provider !== 'evolution') {
            throw new RuntimeException('PDF dosya gönderimi şu an sadece Evolution API provider için destekleniyor.');
        }

        $endpoint = rtrim((string)$this->config['endpoint'], '/') . '/message/sendMedia/' . rawurlencode($this->config['instance_id']);
        $payload = [
            'number' => ltrim($phoneNumber, '+'),
            'mediatype' => 'document',
            'mimetype' => 'application/pdf',
            'caption' => $caption,
            'media' => base64_encode((string)file_get_contents($filePath)),
            'fileName' => $fileName ?? basename($filePath),
        ];

        $ch = curl_init($endpoint);

        if ($ch === false) {
            throw new RuntimeException('Could not initialize WhatsApp document cURL request.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $this->config['token'],
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);

        $rawResponse = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException('WhatsApp document cURL error: ' . $error);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(sprintf('WhatsApp Document API HTTP %d: %s', $httpCode, mb_substr($rawResponse, 0, 500, 'UTF-8')));
        }

        $this->assertNotPending($rawResponse, 'WhatsApp Document API');

        return true;
    }

    private function assertNotPending(string $rawResponse, string $label = 'WhatsApp API'): void
    {
        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            return;
        }

        $status = strtoupper((string)($decoded['status'] ?? ''));

        if ($status === 'PENDING') {
            $messageId = (string)($decoded['key']['id'] ?? '');
            throw new RuntimeException($label . ' mesajı sadece kuyruğa aldı (PENDING). Telefon uygulamasında görünmeden gönderildi sayılmadı. Mesaj ID: ' . $messageId);
        }
    }

    private function isPlaceholderConfig(): bool
    {
        return $this->config['endpoint'] === ''
            || str_contains($this->config['endpoint'], 'example-whatsapp-gateway.local')
            || $this->config['token'] === ''
            || $this->config['token'] === 'YOUR_WHATSAPP_GATEWAY_TOKEN';
    }
}
