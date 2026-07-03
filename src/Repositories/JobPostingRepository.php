<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class JobPostingRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS job_postings (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                source VARCHAR(100) NOT NULL,
                source_url VARCHAR(700) NOT NULL,
                organization_name VARCHAR(255) NOT NULL,
                title VARCHAR(255) NOT NULL,
                location VARCHAR(150) NOT NULL DEFAULT 'Lefkoşa',
                category VARCHAR(120) NULL,
                application_channel VARCHAR(255) NULL,
                status VARCHAR(60) NOT NULL DEFAULT 'bulundu',
                priority TINYINT UNSIGNED NOT NULL DEFAULT 3,
                notes TEXT NULL,
                discovered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                applied_at DATETIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_job_posting_source_url (source_url),
                INDEX idx_job_posting_status (status),
                INDEX idx_job_posting_location (location)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }

    /**
     * @param array<int, array<string, mixed>> $postings
     */
    public function seed(array $postings): int
    {
        $saved = 0;

        foreach ($postings as $posting) {
            if (!$this->isTrncLefkosaPosting((string)($posting['location'] ?? ''), (string)($posting['notes'] ?? ''))) {
                continue;
            }

            $this->upsert($posting);
            $saved++;
        }

        return $saved;
    }

    /**
     * @param array<string, mixed> $posting
     */
    public function upsert(array $posting): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO job_postings (
                source,
                source_url,
                organization_name,
                title,
                location,
                category,
                application_channel,
                status,
                priority,
                notes
            ) VALUES (
                :source,
                :source_url,
                :organization_name,
                :title,
                :location,
                :category,
                :application_channel,
                :status,
                :priority,
                :notes
            )
            ON DUPLICATE KEY UPDATE
                organization_name = VALUES(organization_name),
                title = VALUES(title),
                location = VALUES(location),
                category = VALUES(category),
                application_channel = COALESCE(NULLIF(VALUES(application_channel), ''), application_channel),
                notes = COALESCE(NULLIF(VALUES(notes), ''), notes),
                priority = VALUES(priority),
                updated_at = CURRENT_TIMESTAMP"
        );

        $stmt->execute([
            ':source' => $posting['source'],
            ':source_url' => $posting['source_url'],
            ':organization_name' => $posting['organization_name'],
            ':title' => $posting['title'],
            ':location' => $posting['location'] ?? 'Lefkoşa',
            ':category' => $posting['category'] ?? null,
            ':application_channel' => $posting['application_channel'] ?? null,
            ':status' => $posting['status'] ?? 'bulundu',
            ':priority' => $posting['priority'] ?? 3,
            ':notes' => $posting['notes'] ?? null,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query(
            "SELECT *
             FROM job_postings
             ORDER BY priority ASC, updated_at DESC, id DESC"
        )->fetchAll();
    }

    public function updateStatus(int $id, string $status): void
    {
        $allowed = ['bulundu', 'basvuruldu', 'takip', 'gorusme', 'olumsuz'];

        if (!in_array($status, $allowed, true)) {
            return;
        }

        $stmt = $this->pdo->prepare(
            "UPDATE job_postings
             SET status = :status,
                 applied_at = CASE WHEN :status_applied = 'basvuruldu' AND applied_at IS NULL THEN CURRENT_TIMESTAMP ELSE applied_at END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            ':status' => $status,
            ':status_applied' => $status,
            ':id' => $id,
        ]);
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $row = $this->pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN status = 'bulundu' THEN 1 ELSE 0 END) AS found,
                SUM(CASE WHEN status = 'basvuruldu' THEN 1 ELSE 0 END) AS applied,
                SUM(CASE WHEN status = 'takip' THEN 1 ELSE 0 END) AS followup,
                SUM(CASE WHEN status = 'gorusme' THEN 1 ELSE 0 END) AS interview,
                SUM(CASE WHEN status = 'olumsuz' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN priority <= 2 THEN 1 ELSE 0 END) AS high_priority_count
             FROM job_postings"
        )->fetch();

        return [
            'total' => (int)($row['total'] ?? 0),
            'found' => (int)($row['found'] ?? 0),
            'applied' => (int)($row['applied'] ?? 0),
            'followup' => (int)($row['followup'] ?? 0),
            'interview' => (int)($row['interview'] ?? 0),
            'rejected' => (int)($row['rejected'] ?? 0),
            'high_priority' => (int)($row['high_priority_count'] ?? 0),
        ];
    }

    private function isTrncLefkosaPosting(string $location, string $notes): bool
    {
        $text = mb_strtolower($location . ' ' . $notes, 'UTF-8');

        if (str_contains($text, 'south nicosia') || str_contains($text, 'cyprus republic') || str_contains($text, 'güney kıbrıs')) {
            return false;
        }

        return str_contains($text, 'lefkoşa')
            || str_contains($text, 'lefkosa')
            || str_contains($text, 'nicosia')
            || str_contains($text, 'kktc')
            || str_contains($text, 'north cyprus')
            || str_contains($text, 'kuzey kıbrıs');
    }
}
