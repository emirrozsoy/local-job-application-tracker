<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class EvolutionInstanceService
{
    public function __construct(
        private string $baseUrl,
        private string $authApiKey,
        private string $instanceName,
        private string $instanceToken
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function setup(bool $forceRecreate = false): array
    {
        if (!$this->isApiReady()) {
            (new EvolutionInstaller())->setup();
        }

        $instance = $forceRecreate ? $this->recreateInstance() : $this->ensureInstance();

        return [
            'base_url' => $this->baseUrl,
            'instance_name' => $this->instanceName,
            'auth_api_key' => $this->authApiKey,
            'instance_token' => $this->instanceToken,
            'instance_response' => $instance,
            'qr_text' => $this->extractQrText($instance),
            'qr_base64' => $this->extractQrBase64($instance),
        ];
    }

    /**
     * @return array<string, string|int>
     */
    public function settings(): array
    {
        return [
            'job.whatsapp.provider' => 'evolution',
            'job.whatsapp.endpoint' => $this->baseUrl,
            'job.whatsapp.token' => $this->authApiKey,
            'job.whatsapp.instance_id' => $this->instanceName,
            'job.whatsapp.delay_seconds' => 5,
            'job.whatsapp.limit' => 20,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureInstance(): array
    {
        $existing = $this->findExistingInstance();

        if ($existing !== null) {
            $connectResponse = $this->connectInstance();

            return array_merge($existing, [
                'qrcode' => is_array($connectResponse) ? $connectResponse : null,
                'connect_response' => $connectResponse,
                'already_exists' => true,
            ]);
        }

        return $this->createInstance();
    }

    /**
     * @return array<string, mixed>
     */
    private function recreateInstance(): array
    {
        try {
            $this->request('DELETE', '/instance/logout/' . rawurlencode($this->instanceName), $this->instanceToken);
        } catch (RuntimeException) {
        }

        try {
            $this->request('DELETE', '/instance/delete/' . rawurlencode($this->instanceName), $this->authApiKey);
        } catch (RuntimeException) {
        }

        sleep(2);

        return $this->createInstance();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findExistingInstance(): ?array
    {
        $decoded = $this->request('GET', '/instance/fetchInstances', $this->authApiKey);

        foreach ($decoded as $instance) {
            if (is_array($instance) && ($instance['name'] ?? null) === $this->instanceName) {
                return $instance;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function connectInstance(): array
    {
        return $this->request('GET', '/instance/connect/' . rawurlencode($this->instanceName), $this->instanceToken);
    }

    /**
     * @return array<string, mixed>
     */
    private function createInstance(): array
    {
        return $this->request('POST', '/instance/create', $this->authApiKey, [
            'instanceName' => $this->instanceName,
            'integration' => 'WHATSAPP-BAILEYS',
            'token' => $this->instanceToken,
            'qrcode' => true,
        ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>|array<int, mixed>
     */
    private function request(string $method, string $path, string $apiKey, ?array $payload = null): array
    {
        $ch = curl_init(rtrim($this->baseUrl, '/') . $path);

        if ($ch === false) {
            throw new RuntimeException('Evolution API cURL başlatılamadı.');
        }

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'apikey: ' . $apiKey,
            ],
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
        ];

        if ($method === 'POST') {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload ?? [], JSON_THROW_ON_ERROR);
        } elseif ($method !== 'GET') {
            $options[CURLOPT_CUSTOMREQUEST] = $method;
        }

        curl_setopt_array($ch, $options);

        $rawResponse = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException('Evolution API cURL hatası: ' . $error);
        }

        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Evolution API geçersiz JSON döndürdü: ' . $rawResponse);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $message = $decoded['message'] ?? $decoded['error'] ?? $rawResponse;
            throw new RuntimeException('Evolution API isteği başarısız oldu: ' . (is_string($message) ? $message : json_encode($message)));
        }

        return $decoded;
    }

    private function isApiReady(): bool
    {
        $ch = curl_init($this->baseUrl);

        if ($ch === false) {
            return false;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 4,
        ]);

        curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return $httpCode >= 200 && $httpCode < 500;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $response
     */
    private function extractQrText(array $response): ?string
    {
        $candidates = [
            $response['qrcode']['code'] ?? null,
            $response['qrcode']['pairingCode'] ?? null,
            $response['connect_response']['code'] ?? null,
            $response['code'] ?? null,
            $response['pairingCode'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $response
     */
    private function extractQrBase64(array $response): ?string
    {
        $candidates = [
            $response['qrcode']['base64'] ?? null,
            $response['connect_response']['base64'] ?? null,
            $response['base64'] ?? null,
            $response['qr'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && str_contains($candidate, 'base64')) {
                return $candidate;
            }
        }

        return null;
    }
}
