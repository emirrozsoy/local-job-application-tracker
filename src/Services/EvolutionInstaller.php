<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class EvolutionInstaller
{
    public const AUTH_API_KEY = 'CHANGE_ME_EVOLUTION_API_KEY';
    public const INSTANCE_NAME = 'job_application_whatsapp';
    public const INSTANCE_TOKEN = 'CHANGE_ME_INSTANCE_TOKEN';
    public const BASE_URL = 'http://localhost:8080';

    /**
     * @return array<string, mixed>
     */
    public function setup(bool $forceRecreate = false): array
    {
        $dockerVersion = 'Docker kullanılmadı; Evolution API zaten çalışıyor.';

        if (!$this->isApiReady()) {
            $docker = $this->dockerBinary();

            if ($docker === null) {
                throw new RuntimeException('Evolution API çalışmıyor ve Docker Desktop terminal PATH içinde görünmüyor. Docker Desktop açıkken tekrar dene.');
            }

            $dockerVersion = $this->run($this->dockerCommand($docker, '--version'));
            $composeDir = dirname(__DIR__, 2) . '/docker/evolution-api';

            $this->run('cd ' . escapeshellarg($composeDir) . ' && ' . $this->dockerCommand($docker, 'compose up -d'));
            $this->waitUntilReady();
        }

        $instanceResponse = $forceRecreate ? $this->recreateInstance() : $this->ensureInstance();

        return [
            'docker_version' => trim($dockerVersion),
            'container' => 'evolution-api',
            'base_url' => self::BASE_URL,
            'instance_name' => self::INSTANCE_NAME,
            'auth_api_key' => self::AUTH_API_KEY,
            'instance_token' => self::INSTANCE_TOKEN,
            'instance_response' => $instanceResponse,
            'qr_text' => $this->extractQrText($instanceResponse),
            'qr_base64' => $this->extractQrBase64($instanceResponse),
        ];
    }

    public function settings(): array
    {
        return [
            'whatsapp.provider' => 'evolution',
            'whatsapp.endpoint' => self::BASE_URL,
            'whatsapp.token' => self::AUTH_API_KEY,
            'whatsapp.instance_id' => self::INSTANCE_NAME,
            'whatsapp.delay_seconds' => 5,
            'whatsapp.limit' => 20,
        ];
    }

    private function dockerBinary(): ?string
    {
        $fromPath = trim((string)shell_exec('command -v docker 2>/dev/null'));

        $candidates = array_filter([
            '/Applications/Docker.app/Contents/Resources/bin/docker',
            $fromPath !== '' ? $fromPath : null,
            '/usr/local/bin/docker',
            '/opt/homebrew/bin/docker',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function run(string $command): string
    {
        $output = [];
        $exitCode = 0;
        exec($command . ' 2>&1', $output, $exitCode);

        if ($exitCode !== 0) {
            throw new RuntimeException(implode("\n", $output));
        }

        return implode("\n", $output);
    }

    private function dockerCommand(string $docker, string $arguments): string
    {
        $dockerConfigDir = dirname(__DIR__, 2) . '/storage/docker-config';

        if (!is_dir($dockerConfigDir)) {
            mkdir($dockerConfigDir, 0775, true);
        }

        $pluginDir = $dockerConfigDir . '/cli-plugins';
        $composePlugin = $pluginDir . '/docker-compose';
        $desktopComposePlugin = '/Applications/Docker.app/Contents/Resources/cli-plugins/docker-compose';

        if (!is_dir($pluginDir)) {
            mkdir($pluginDir, 0775, true);
        }

        if (!is_file($composePlugin) && is_file($desktopComposePlugin)) {
            symlink($desktopComposePlugin, $composePlugin);
        }

        return 'DOCKER_CONFIG=' . escapeshellarg($dockerConfigDir)
            . ' ' . escapeshellarg($docker)
            . ' ' . $arguments;
    }

    private function isApiReady(): bool
    {
        $ch = curl_init(self::BASE_URL);

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

    private function waitUntilReady(): void
    {
        $deadline = time() + 45;

        while (time() < $deadline) {
            $ch = curl_init(self::BASE_URL);

            if ($ch === false) {
                sleep(2);
                continue;
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_TIMEOUT => 4,
            ]);

            curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($httpCode > 0) {
                return;
            }

            sleep(2);
        }
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
            $this->request('DELETE', '/instance/logout/' . rawurlencode(self::INSTANCE_NAME), self::INSTANCE_TOKEN);
        } catch (RuntimeException) {
        }

        try {
            $this->request('DELETE', '/instance/delete/' . rawurlencode(self::INSTANCE_NAME), self::AUTH_API_KEY);
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
        $decoded = $this->request('GET', '/instance/fetchInstances', self::AUTH_API_KEY);

        if (!is_array($decoded)) {
            return null;
        }

        foreach ($decoded as $instance) {
            if (is_array($instance) && ($instance['name'] ?? null) === self::INSTANCE_NAME) {
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
        return $this->request('GET', '/instance/connect/' . rawurlencode(self::INSTANCE_NAME), self::INSTANCE_TOKEN);
    }

    /**
     * @return array<string, mixed>
     */
    private function createInstance(): array
    {
        $payload = [
            'instanceName' => self::INSTANCE_NAME,
            'integration' => 'WHATSAPP-BAILEYS',
            'token' => self::INSTANCE_TOKEN,
            'qrcode' => true,
        ];

        return $this->request('POST', '/instance/create', self::AUTH_API_KEY, $payload);
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, string $apiKey, ?array $payload = null): array
    {
        $ch = curl_init(self::BASE_URL . $path);

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

    /**
     * @param array<string, mixed> $response
     */
    private function extractQrText(array $response): ?string
    {
        $candidates = [
            $response['qrcode']['code'] ?? null,
            $response['qrcode']['pairingCode'] ?? null,
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
     * @param array<string, mixed> $response
     */
    private function extractQrBase64(array $response): ?string
    {
        $candidates = [
            $response['qrcode']['base64'] ?? null,
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
