<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\JobApplicationTargetRepository;
use Throwable;

final class JobApplicationAutomation
{
    public function __construct(
        private JobApplicationTargetRepository $targets,
        private GooglePlacesService $places,
        private PhoneFormatter $phoneFormatter,
        private WebsiteEmailFinder $emailFinder,
        private WhatsAppGateway $whatsAppGateway,
        private ColdEmailSender $emailSender
    ) {
    }

    /**
     * @param array<int, string> $terms
     * @return array{found:int, saved:int}
     */
    public function scanLefkosaTargets(array $terms, int $maxPerTerm = 10): array
    {
        $found = 0;
        $saved = 0;

        foreach ($terms as $term) {
            $term = trim($term);

            if ($term === '') {
                continue;
            }

            foreach ($this->places->search($term, $maxPerTerm) as $place) {
                $found++;
                $address = (string)($place['open_address'] ?? '');

                if (!$this->isTrncLefkosaTarget($address, (string)($place['phone_number'] ?? ''), (string)($place['whatsapp_phone'] ?? ''))) {
                    continue;
                }

                $this->targets->upsert([
                    'google_place_id' => $place['google_place_id'] ?? null,
                    'organization_name' => $place['business_name'] ?? '',
                    'category' => $this->categoryFromTerm($term),
                    'phone_number' => $place['phone_number'] ?? null,
                    'whatsapp_phone' => $place['whatsapp_phone'] ?? null,
                    'email' => null,
                    'website_url' => $place['website_url'] ?? null,
                    'open_address' => $address,
                    'city' => 'Lefkoşa',
                    'source' => 'google_places',
                    'notes' => 'Lefkoşa iş başvurusu hedefi; mail için "Maili Olmayanlarda Webden Mail Ara" çalıştır',
                ]);
                $saved++;
            }
        }

        return [
            'found' => $found,
            'saved' => $saved,
        ];
    }

    /**
     * @param array<string, string> $profile
     * @return array{processed:int, sent:int}
     */
    public function sendWhatsAppApplications(array $profile, int $limit): array
    {
        $rows = $this->targets->pendingWhatsApp($limit);
        $sent = 0;

        foreach ($rows as $row) {
            try {
                $message = $this->messageForTarget($profile, (string)$row['organization_name']);
                $this->whatsAppGateway->sendDocument(
                    (string)$row['whatsapp_phone'],
                    (string)$profile['cv_path'],
                    $message,
                    'Demo-Applicant-CV.pdf'
                );
                $this->targets->markWhatsAppSent((int)$row['id']);
                $sent++;
            } catch (Throwable $exception) {
                $this->targets->saveWhatsAppError((int)$row['id'], $exception->getMessage());
            }

            sleep(max(0, (int)($profile['delay_seconds'] ?? 5)));
        }

        return [
            'processed' => count($rows),
            'sent' => $sent,
        ];
    }

    /**
     * @param array<string, string> $profile
     * @return array{processed:int, sent:int}
     */
    public function sendWhatsAppTextRecovery(array $profile, int $limit): array
    {
        $rows = $this->targets->pendingWhatsAppConnectionError($limit);
        $sent = 0;

        foreach ($rows as $row) {
            try {
                $message = $this->recoveryMessageForTarget($profile, (string)$row['organization_name']);
                $this->whatsAppGateway->send((string)$row['whatsapp_phone'], $message);
                $this->targets->markWhatsAppSent((int)$row['id']);
                $sent++;
            } catch (Throwable $exception) {
                $this->targets->saveWhatsAppError((int)$row['id'], $exception->getMessage());
            }

            sleep(max(0, (int)($profile['delay_seconds'] ?? 5)));
        }

        return [
            'processed' => count($rows),
            'sent' => $sent,
        ];
    }

    /**
     * @param array<string, string> $profile
     * @return array{processed:int, sent:int}
     */
    public function sendWhatsAppFollowups(array $profile, int $limit): array
    {
        $rows = $this->targets->pendingWhatsAppFollowup($limit);
        $sent = 0;

        foreach ($rows as $row) {
            try {
                $message = $this->followupMessageForTarget($profile, (string)$row['organization_name']);
                $this->whatsAppGateway->send((string)$row['whatsapp_phone'], $message);
                $this->targets->markWhatsAppFollowupSent((int)$row['id']);
                $sent++;
            } catch (Throwable $exception) {
                $this->targets->saveWhatsAppError((int)$row['id'], $exception->getMessage());
            }

            sleep(max(0, (int)($profile['delay_seconds'] ?? 5)));
        }

        return [
            'processed' => count($rows),
            'sent' => $sent,
        ];
    }

    /**
     * @param array<string, string> $profile
     * @return array{processed:int, sent:int}
     */
    public function sendEmailApplications(array $profile, int $limit): array
    {
        $rows = $this->targets->pendingEmail($limit);
        $sent = 0;

        foreach ($rows as $row) {
            try {
                $message = $this->messageForTarget($profile, (string)$row['organization_name']);
                $this->emailSender->sendJobApplication(
                    (string)$row['email'],
                    (string)$row['organization_name'],
                    (string)$profile['email_subject'],
                    $message,
                    (string)$profile['cv_path'],
                    (string)$profile['applicant_name']
                );
                $this->targets->markEmailSent((int)$row['id']);
                $sent++;
            } catch (Throwable $exception) {
                $this->targets->saveEmailError((int)$row['id'], $exception->getMessage());
            }
        }

        return [
            'processed' => count($rows),
            'sent' => $sent,
        ];
    }

    /**
     * @param array<string, string> $profile
     */
    public function sendTestWhatsApp(array $profile, string $phoneNumber): bool
    {
        $formatted = $this->phoneFormatter->format($phoneNumber);

        if ($formatted === null || $formatted === '') {
            throw new \RuntimeException('Test WhatsApp numarası boş veya geçersiz.');
        }

        $message = "[TEST GÖNDERİMİ]\n\n" . $this->messageForTarget($profile, 'Deneme Kurumu');
        return $this->whatsAppGateway->sendDocument($formatted, (string)$profile['cv_path'], $message, 'Demo-Applicant-CV.pdf');
    }

    /**
     * @param array<string, string> $profile
     */
    public function sendTestEmail(array $profile, string $email): bool
    {
        $email = trim($email);

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \RuntimeException('Test e-posta adresi boş veya geçersiz.');
        }

        $message = "[TEST GÖNDERİMİ]\n\n" . $this->messageForTarget($profile, 'Deneme Kurumu');
        return $this->emailSender->sendJobApplication(
            $email,
            'Deneme Alıcısı',
            '[TEST] ' . (string)$profile['email_subject'],
            $message,
            (string)$profile['cv_path'],
            (string)$profile['applicant_name']
        );
    }

    /**
     * @return array{checked:int, found:int}
     */
    public function fillMissingEmails(int $limit = 100): array
    {
        $rows = $this->targets->targetsMissingEmailWithWebsite($limit);
        $found = 0;

        foreach ($rows as $row) {
            $email = $this->emailFinder->findFirst((string)$row['website_url']);

            if ($email === null) {
                continue;
            }

            $this->targets->updateEmail((int)$row['id'], $email, 'Web sitesinden otomatik mail bulundu: ' . $email);
            $found++;
        }

        return [
            'checked' => count($rows),
            'found' => $found,
        ];
    }

    /**
     * @param array<string, string> $profile
     */
    private function messageForTarget(array $profile, string $organizationName): string
    {
        return strtr((string)$profile['cover_letter'], [
            '[KURUM]' => $organizationName,
            '[AD_SOYAD]' => (string)$profile['applicant_name'],
            '[TELEFON]' => (string)$profile['applicant_phone'],
            '[EMAIL]' => (string)$profile['applicant_email'],
        ]);
    }

    /**
     * @param array<string, string> $profile
     */
    private function recoveryMessageForTarget(array $profile, string $organizationName): string
    {
        return "Merhaba {$organizationName} yetkilisi,\n\n"
            . (string)$profile['applicant_name'] . " adına IVF / klinik hemşireliği başvurusunu e-posta üzerinden ilettik. "
            . "CV ve ön yazı mail ekinde yer alıyor.\n\n"
            . "Uygun görürseniz insan kaynakları veya ilgili birime yönlendirebilir misiniz?\n\n"
            . "Teşekkür ederiz.\n"
            . (string)$profile['applicant_name'] . "\n"
            . (string)$profile['applicant_phone'] . "\n"
            . (string)$profile['applicant_email'];
    }

    /**
     * @param array<string, string> $profile
     */
    private function followupMessageForTarget(array $profile, string $organizationName): string
    {
        return "Merhaba, geri dönüşünüz için teşekkür ederiz.\n\n"
            . (string)$profile['applicant_name'] . " IVF, üreme sağlığı, kadın sağlığı polikliniği ve klinik hemşireliği alanlarında değerlendirilmek istiyor. "
            . "CV'sini e-posta ile de ilettik.\n\n"
            . "Başvuruyu insan kaynakları veya ilgili birime yönlendirebilirseniz çok memnun oluruz.\n\n"
            . "İletişim: " . (string)$profile['applicant_phone'] . "\n"
            . "E-posta: " . (string)$profile['applicant_email'];
    }

    private function isTrncLefkosaTarget(string $address, string $phoneNumber, string $whatsappPhone): bool
    {
        $normalized = mb_strtolower($address, 'UTF-8');
        $phone = preg_replace('/\D+/', '', $phoneNumber . ' ' . $whatsappPhone) ?? '';

        if (str_starts_with($phone, '357')) {
            return false;
        }

        if (preg_match('/nicosia\s+(10|20)\d{2}/i', $address) === 1) {
            return false;
        }

        $isNorthCyprusAddress = str_contains($normalized, 'kktc')
            || str_contains($normalized, 'north cyprus')
            || str_contains($normalized, 'northern cyprus')
            || str_contains($normalized, 'lefkoşa 99')
            || str_contains($normalized, 'lefkosa 99')
            || str_contains($normalized, 'gönyeli')
            || str_contains($normalized, 'gonyeli')
            || str_contains($normalized, 'ortaköy')
            || str_contains($normalized, 'ortakoy')
            || str_contains($normalized, 'kızılbaş')
            || str_contains($normalized, 'kizilbas')
            || str_contains($normalized, 'küçük kaymaklı')
            || str_contains($normalized, 'kucuk kaymakli')
            || str_contains($normalized, 'hamitköy')
            || str_contains($normalized, 'hamitkoy')
            || str_contains($normalized, 'yenişehir')
            || str_contains($normalized, 'yenisehir');

        $hasTrncPhone = str_starts_with($phone, '90') || str_starts_with($phone, '0392') || str_starts_with($phone, '392');

        return $isNorthCyprusAddress || $hasTrncPhone;
    }

    private function categoryFromTerm(string $term): string
    {
        $normalized = mb_strtolower($term, 'UTF-8');

        if (str_contains($normalized, 'ivf') || str_contains($normalized, 'tüp') || str_contains($normalized, 'fertility')) {
            return 'IVF / Üreme Sağlığı';
        }

        if (str_contains($normalized, 'kadın') || str_contains($normalized, 'doğum')) {
            return 'Kadın Doğum';
        }

        return 'Hastane / Klinik';
    }
}
