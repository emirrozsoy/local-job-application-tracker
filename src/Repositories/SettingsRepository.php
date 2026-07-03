<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS app_settings (
                setting_key VARCHAR(191) PRIMARY KEY,
                setting_value TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @return array<string, string>
     */
    public function all(): array
    {
        $this->createSchema();
        $stmt = $this->pdo->query('SELECT setting_key, setting_value FROM app_settings');
        $settings = [];

        foreach ($stmt->fetchAll() as $row) {
            $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
        }

        return $settings;
    }

    /**
     * @param array<string, string|int|null> $settings
     */
    public function saveMany(array $settings): void
    {
        $this->createSchema();
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings (setting_key, setting_value)
             VALUES (:setting_key, :setting_value)
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_at = CURRENT_TIMESTAMP"
        );

        foreach ($settings as $key => $value) {
            $stmt->execute([
                ':setting_key' => $key,
                ':setting_value' => $value === null ? '' : (string)$value,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $config
     * @param array<string, string> $settings
     * @return array<string, mixed>
     */
    public function applyToConfig(array $config, array $settings): array
    {
        foreach ($settings as $key => $value) {
            $this->setByDotKey($config, $key, $this->castValue($key, $value));
        }

        if (isset($config['google']['places_api_key'])) {
            $config['google_api_key'] = $config['google']['places_api_key'];
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function setByDotKey(array &$config, string $key, mixed $value): void
    {
        $parts = explode('.', $key);
        $current = &$config;

        foreach ($parts as $index => $part) {
            if ($index === count($parts) - 1) {
                $current[$part] = $value;
                return;
            }

            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }

            $current = &$current[$part];
        }
    }

    private function castValue(string $key, string $value): string|int
    {
        $intKeys = [
            'whatsapp.delay_seconds',
            'whatsapp.limit',
            'smtp.port',
            'smtp.limit',
            'job.limit',
            'job.delay_seconds',
            'job.whatsapp.delay_seconds',
            'job.whatsapp.limit',
            'job.smtp.port',
            'job.smtp.limit',
        ];

        if (in_array($key, $intKeys, true)) {
            return (int)$value;
        }

        return $value;
    }
}
