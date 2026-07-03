<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

final class JobApplicationTargetRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    public function createSchema(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS job_application_targets (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                google_place_id VARCHAR(191) NULL,
                organization_name VARCHAR(255) NOT NULL,
                category VARCHAR(100) NULL,
                phone_number VARCHAR(80) NULL,
                whatsapp_phone VARCHAR(80) NULL,
                email VARCHAR(255) NULL,
                website_url VARCHAR(500) NULL,
                open_address TEXT NULL,
                city VARCHAR(100) NOT NULL DEFAULT 'Lefkoşa',
                source VARCHAR(100) NOT NULL DEFAULT 'google_places',
                notes TEXT NULL,
                whatsapp_sent TINYINT(1) NOT NULL DEFAULT 0,
                whatsapp_sent_at DATETIME NULL,
                email_sent TINYINT(1) NOT NULL DEFAULT 0,
                email_sent_at DATETIME NULL,
                last_whatsapp_error TEXT NULL,
                last_email_error TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_job_google_place_id (google_place_id),
                INDEX idx_job_whatsapp_pending (whatsapp_sent, whatsapp_phone),
                INDEX idx_job_email_pending (email_sent, email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        $this->pdo->exec("ALTER TABLE job_application_targets ADD COLUMN IF NOT EXISTS whatsapp_followup_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER whatsapp_sent_at");
        $this->pdo->exec("ALTER TABLE job_application_targets ADD COLUMN IF NOT EXISTS whatsapp_followup_sent_at DATETIME NULL AFTER whatsapp_followup_sent");
    }

    /**
     * @param array<string, mixed> $target
     */
    public function upsert(array $target): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO job_application_targets (
                google_place_id,
                organization_name,
                category,
                phone_number,
                whatsapp_phone,
                email,
                website_url,
                open_address,
                city,
                source,
                notes
            ) VALUES (
                :google_place_id,
                :organization_name,
                :category,
                :phone_number,
                :whatsapp_phone,
                :email,
                :website_url,
                :open_address,
                :city,
                :source,
                :notes
            )
            ON DUPLICATE KEY UPDATE
                organization_name = VALUES(organization_name),
                category = VALUES(category),
                phone_number = VALUES(phone_number),
                whatsapp_phone = VALUES(whatsapp_phone),
                email = COALESCE(NULLIF(VALUES(email), ''), email),
                website_url = VALUES(website_url),
                open_address = VALUES(open_address),
                city = VALUES(city),
                notes = COALESCE(NULLIF(VALUES(notes), ''), notes),
                updated_at = CURRENT_TIMESTAMP"
        );

        $stmt->execute([
            ':google_place_id' => $target['google_place_id'] ?? null,
            ':organization_name' => $target['organization_name'],
            ':category' => $target['category'] ?? null,
            ':phone_number' => $target['phone_number'] ?? null,
            ':whatsapp_phone' => $target['whatsapp_phone'] ?? null,
            ':email' => $target['email'] ?? null,
            ':website_url' => $target['website_url'] ?? null,
            ':open_address' => $target['open_address'] ?? null,
            ':city' => $target['city'] ?? 'Lefkoşa',
            ':source' => $target['source'] ?? 'google_places',
            ':notes' => $target['notes'] ?? null,
        ]);
    }

    public function addManual(
        string $organizationName,
        ?string $phoneNumber,
        ?string $whatsappPhone,
        ?string $email,
        ?string $websiteUrl,
        ?string $address,
        ?string $notes
    ): void {
        $stmt = $this->pdo->prepare(
            "INSERT INTO job_application_targets (
                organization_name,
                category,
                phone_number,
                whatsapp_phone,
                email,
                website_url,
                open_address,
                city,
                source,
                notes
            ) VALUES (
                :organization_name,
                'Manuel',
                :phone_number,
                :whatsapp_phone,
                :email,
                :website_url,
                :open_address,
                'Lefkoşa',
                'manual',
                :notes
            )"
        );

        $stmt->execute([
            ':organization_name' => $organizationName,
            ':phone_number' => $phoneNumber,
            ':whatsapp_phone' => $whatsappPhone,
            ':email' => $email,
            ':website_url' => $websiteUrl,
            ':open_address' => $address,
            ':notes' => $notes,
        ]);
    }

    public function addOrUpdateManual(
        string $organizationName,
        ?string $phoneNumber,
        ?string $whatsappPhone,
        ?string $email,
        ?string $websiteUrl,
        ?string $address,
        ?string $notes,
        string $source = 'manual'
    ): void {
        $this->upsert([
            'google_place_id' => 'manual_' . md5(mb_strtolower($organizationName . '|' . ($websiteUrl ?? '') . '|' . ($address ?? ''), 'UTF-8')),
            'organization_name' => $organizationName,
            'category' => 'Hastane / Klinik',
            'phone_number' => $phoneNumber,
            'whatsapp_phone' => $whatsappPhone,
            'email' => $email,
            'website_url' => $websiteUrl,
            'open_address' => $address,
            'city' => 'Lefkoşa',
            'source' => $source,
            'notes' => $notes,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->pdo->query(
            "SELECT *
             FROM job_application_targets
             ORDER BY updated_at DESC, id DESC"
        )->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingWhatsApp(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM job_application_targets
             WHERE whatsapp_sent = 0
               AND whatsapp_phone IS NOT NULL
               AND whatsapp_phone <> ''
               AND (last_whatsapp_error IS NULL OR last_whatsapp_error = '')
             ORDER BY id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingWhatsAppConnectionError(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM job_application_targets
             WHERE whatsapp_sent = 0
               AND whatsapp_phone IS NOT NULL
               AND whatsapp_phone <> ''
               AND last_whatsapp_error LIKE '%Connection Closed%'
             ORDER BY id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingWhatsAppFollowup(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM job_application_targets
             WHERE whatsapp_sent = 1
               AND whatsapp_phone IS NOT NULL
               AND whatsapp_phone <> ''
               AND whatsapp_followup_sent = 0
             ORDER BY whatsapp_sent_at ASC, id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function pendingEmail(int $limit): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM job_application_targets
             WHERE email_sent = 0
               AND email IS NOT NULL
               AND email <> ''
               AND (last_email_error IS NULL OR last_email_error = '')
             ORDER BY id ASC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(50, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function markWhatsAppSent(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_application_targets
             SET whatsapp_sent = 1,
                 whatsapp_sent_at = CURRENT_TIMESTAMP,
                 last_whatsapp_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    public function markWhatsAppFollowupSent(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_application_targets
             SET whatsapp_followup_sent = 1,
                 whatsapp_followup_sent_at = CURRENT_TIMESTAMP,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    public function markEmailSent(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_application_targets
             SET email_sent = 1,
                 email_sent_at = CURRENT_TIMESTAMP,
                 last_email_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([':id' => $id]);
    }

    public function saveWhatsAppError(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_application_targets
             SET last_whatsapp_error = :error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            ':error' => mb_substr($error, 0, 1000, 'UTF-8'),
            ':id' => $id,
        ]);
    }

    public function clearWhatsAppConnectionClosedErrors(): int
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_application_targets
             SET last_whatsapp_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE whatsapp_sent = 0
               AND last_whatsapp_error LIKE '%Connection Closed%'"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    public function saveEmailError(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_application_targets
             SET last_email_error = :error,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            ':error' => mb_substr($error, 0, 1000, 'UTF-8'),
            ':id' => $id,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function targetsMissingEmailWithWebsite(int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT *
             FROM job_application_targets
             WHERE (email IS NULL OR email = '')
               AND website_url IS NOT NULL
               AND website_url <> ''
             ORDER BY source = 'trusted_web' DESC, updated_at DESC, id DESC
             LIMIT :limit"
        );
        $stmt->bindValue(':limit', max(1, min(200, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    public function updateEmail(int $id, string $email, ?string $note = null): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE job_application_targets
             SET email = :email,
                 notes = CASE
                     WHEN :note_empty = '' THEN notes
                     ELSE TRIM(CONCAT(COALESCE(notes, ''), '\n', :note))
                 END,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id"
        );
        $stmt->execute([
            ':email' => $email,
            ':note_empty' => $note ?? '',
            ':note' => $note ?? '',
            ':id' => $id,
        ]);
    }

    public function deleteNonTrncTargets(): int
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM job_application_targets
             WHERE COALESCE(whatsapp_phone, phone_number, '') LIKE '+357%'
                OR COALESCE(phone_number, '') LIKE '+357%'
                OR COALESCE(open_address, '') REGEXP 'Nicosia 10[0-9]{2}|Nicosia 20[0-9]{2}'
                OR COALESCE(open_address, '') REGEXP 'Lefkoşa 10[0-9]{2}|Lefkosa 10[0-9]{2}'
                OR COALESCE(open_address, '') REGEXP 'Girne 99[0-9]{3}|Kyrenia 99[0-9]{3}'
                OR (
                    COALESCE(open_address, '') LIKE '%Nicosia%'
                    AND COALESCE(open_address, '') NOT LIKE '%North Cyprus%'
                    AND COALESCE(open_address, '') NOT LIKE '%KKTC%'
                    AND COALESCE(open_address, '') NOT LIKE '%Lefkoşa 99%'
                    AND COALESCE(open_address, '') NOT LIKE '%Lefkosa 99%'
                )"
        );
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * @param array<int, array<string, mixed>> $targets
     */
    public function seedTrustedTargets(array $targets): int
    {
        $saved = 0;

        foreach ($targets as $target) {
            $this->upsert($target);
            $saved++;
        }

        return $saved;
    }

    public function deleteDuplicateContacts(): int
    {
        $rows = $this->pdo->query(
            "SELECT id, source, organization_name, whatsapp_phone, email
             FROM job_application_targets
             ORDER BY id ASC"
        )->fetchAll();
        $groups = [];

        foreach ($rows as $row) {
            $keys = [];
            $phone = preg_replace('/\D+/', '', (string)($row['whatsapp_phone'] ?? '')) ?? '';
            $email = mb_strtolower((string)($row['email'] ?? ''), 'UTF-8');

            if ($phone !== '') {
                $keys[] = 'wa:' . $phone;
            }

            if ($email !== '') {
                $keys[] = 'mail:' . $email;
            }

            foreach ($keys as $key) {
                $groups[$key][] = $row;
            }
        }

        $deleteIds = [];

        foreach ($groups as $groupRows) {
            if (count($groupRows) < 2) {
                continue;
            }

            usort($groupRows, static function (array $a, array $b): int {
                $scoreA = (($a['source'] ?? '') === 'trusted_web' ? 100 : 0) + (!empty($a['email']) ? 10 : 0);
                $scoreB = (($b['source'] ?? '') === 'trusted_web' ? 100 : 0) + (!empty($b['email']) ? 10 : 0);

                if ($scoreA === $scoreB) {
                    return (int)$a['id'] <=> (int)$b['id'];
                }

                return $scoreB <=> $scoreA;
            });

            array_shift($groupRows);

            foreach ($groupRows as $duplicate) {
                $deleteIds[(int)$duplicate['id']] = true;
            }
        }

        if ($deleteIds === []) {
            return 0;
        }

        $ids = array_keys($deleteIds);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("DELETE FROM job_application_targets WHERE id IN ({$placeholders})");
        $stmt->execute($ids);

        return $stmt->rowCount();
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array
    {
        $row = $this->pdo->query(
            "SELECT
                COUNT(*) AS total,
                SUM(CASE WHEN whatsapp_sent = 1 THEN 1 ELSE 0 END) AS whatsapp_sent,
                SUM(CASE WHEN whatsapp_sent = 0 AND whatsapp_phone IS NOT NULL AND whatsapp_phone <> '' AND (last_whatsapp_error IS NULL OR last_whatsapp_error = '') THEN 1 ELSE 0 END) AS whatsapp_pending,
                SUM(CASE WHEN whatsapp_phone IS NULL OR whatsapp_phone = '' THEN 1 ELSE 0 END) AS whatsapp_no_phone,
                SUM(CASE WHEN last_whatsapp_error IS NOT NULL AND last_whatsapp_error <> '' THEN 1 ELSE 0 END) AS whatsapp_error,
                SUM(CASE WHEN last_whatsapp_error LIKE '%Connection Closed%' THEN 1 ELSE 0 END) AS whatsapp_connection_closed,
                SUM(CASE WHEN whatsapp_sent = 1 AND whatsapp_followup_sent = 0 THEN 1 ELSE 0 END) AS whatsapp_followup_pending,
                SUM(CASE WHEN whatsapp_followup_sent = 1 THEN 1 ELSE 0 END) AS whatsapp_followup_sent,
                SUM(CASE WHEN email_sent = 1 THEN 1 ELSE 0 END) AS email_sent,
                SUM(CASE WHEN email_sent = 0 AND email IS NOT NULL AND email <> '' AND (last_email_error IS NULL OR last_email_error = '') THEN 1 ELSE 0 END) AS email_pending,
                SUM(CASE WHEN email IS NULL OR email = '' THEN 1 ELSE 0 END) AS email_no_address,
                SUM(CASE WHEN last_email_error IS NOT NULL AND last_email_error <> '' THEN 1 ELSE 0 END) AS email_error
             FROM job_application_targets"
        )->fetch();

        return [
            'total' => (int)($row['total'] ?? 0),
            'whatsapp_sent' => (int)($row['whatsapp_sent'] ?? 0),
            'whatsapp_pending' => (int)($row['whatsapp_pending'] ?? 0),
            'whatsapp_no_phone' => (int)($row['whatsapp_no_phone'] ?? 0),
            'whatsapp_error' => (int)($row['whatsapp_error'] ?? 0),
            'whatsapp_connection_closed' => (int)($row['whatsapp_connection_closed'] ?? 0),
            'whatsapp_followup_pending' => (int)($row['whatsapp_followup_pending'] ?? 0),
            'whatsapp_followup_sent' => (int)($row['whatsapp_followup_sent'] ?? 0),
            'email_sent' => (int)($row['email_sent'] ?? 0),
            'email_pending' => (int)($row['email_pending'] ?? 0),
            'email_no_address' => (int)($row['email_no_address'] ?? 0),
            'email_error' => (int)($row['email_error'] ?? 0),
        ];
    }
}
