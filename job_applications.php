<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Repositories\JobPostingRepository;
use App\Repositories\JobApplicationTargetRepository;
use App\Repositories\SettingsRepository;
use App\Services\ColdEmailSender;
use App\Services\EvolutionInstanceService;
use App\Services\GooglePlacesService;
use App\Services\JobApplicationAutomation;
use App\Services\PhoneFormatter;
use App\Services\WebsiteEmailFinder;
use App\Services\WhatsAppGateway;

require_once __DIR__ . '/bootstrap.php';

$baseConfig = require __DIR__ . '/config.php';
$pdo = Connection::make($baseConfig['db']);
$settingsRepository = new SettingsRepository($pdo);
$config = $settingsRepository->applyToConfig($baseConfig, $settingsRepository->all());
$targetRepository = new JobApplicationTargetRepository($pdo);
$targetRepository->createSchema();
$jobPostingRepository = new JobPostingRepository($pdo);
$jobPostingRepository->createSchema();

$message = null;
$error = null;
$jobEvolutionResult = null;
$action = (string)($_POST['action'] ?? '');
$settings = $settingsRepository->all();

const JOB_EVOLUTION_BASE_URL = 'http://localhost:8080';
const JOB_EVOLUTION_AUTH_KEY = 'CHANGE_ME_EVOLUTION_API_KEY';
const JOB_EVOLUTION_INSTANCE = 'job_application_whatsapp';
const JOB_EVOLUTION_INSTANCE_TOKEN = 'CHANGE_ME_INSTANCE_TOKEN';

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function defaultCoverLetter(): string
{
    return "Merhaba [KURUM] İnsan Kaynakları / Yetkili Birimi,\n\n"
        . "Ben [AD_SOYAD]. Sağlık bilimleri alanında mezuniyetim ve klinik staj deneyimimle kariyerimi IVF, üreme sağlığı, kadın sağlığı polikliniği ve klinik hemşireliği alanlarında geliştirmeyi hedefliyorum.\n\n"
        . "Eğitimim ve klinik stajlarım boyunca kadın hastalıkları, jinekoloji, poliklinik, yenidoğan yoğun bakım ve doğumhane süreçlerinde aktif saha deneyimi edindim. Kan alma, damar yolu açma, NST takibi, hasta kabulü, anamnez alma, hasta dosyası hazırlama ve HBYS kullanımı konularında pratik deneyime sahibim.\n\n"
        . "IVF ve üreme sağlığı alanında hasta iletişimi güçlü, düzenli, öğrenmeye açık ve klinik süreçlere hızlı uyum sağlayabilecek bir ekip üyesi olarak değerlendirilmek isterim. CV'mi ekte paylaşıyorum; uygun görmeniz halinde görüşme fırsatı bulmaktan memnuniyet duyarım.\n\n"
        . "Saygılarımla,\n"
        . "[AD_SOYAD]\n"
        . "[TELEFON]\n"
        . "[EMAIL]";
}

/**
 * @param array<string, string> $profile
 */
function manualWhatsAppText(array $profile, string $organizationName): string
{
    return "Merhaba {$organizationName} yetkilisi,\n\n"
        . (string)$profile['applicant_name'] . " adına IVF / klinik hemşireliği başvurusunu e-posta üzerinden ilettik. "
        . "CV ve ön yazı mail ekinde yer alıyor.\n\n"
        . "Uygun görürseniz başvuruyu insan kaynakları veya ilgili birime yönlendirebilir misiniz?\n\n"
        . "Teşekkür ederiz.\n"
        . (string)$profile['applicant_name'] . "\n"
        . (string)$profile['applicant_phone'] . "\n"
        . (string)$profile['applicant_email'];
}

function webWhatsAppUrl(?string $phone, string $message): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone) ?? '';

    return 'https://web.whatsapp.com/send?phone=' . rawurlencode($digits) . '&text=' . rawurlencode($message);
}

function mobileWhatsAppUrl(?string $phone, string $message): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone) ?? '';

    return 'https://wa.me/' . rawurlencode($digits) . '?text=' . rawurlencode($message);
}

/**
 * @param array<string, string> $settings
 * @return array<string, string>
 */
function jobProfile(array $settings): array
{
    return [
        'applicant_name' => $settings['job.applicant_name'] ?? 'Demo Applicant',
        'applicant_phone' => $settings['job.applicant_phone'] ?? '+905551112233',
        'applicant_email' => $settings['job.applicant_email'] ?? 'applicant@example.com',
        'cv_path' => $settings['job.cv_path'] ?? __DIR__ . '/storage/cv/sample-cv.pdf',
        'email_subject' => $settings['job.email_subject'] ?? 'Healthcare / Clinic Nurse Application - Demo Applicant',
        'cover_letter' => $settings['job.cover_letter'] ?? defaultCoverLetter(),
        'search_terms' => $settings['job.search_terms'] ?? "Nicosia IVF clinic\nNicosia fertility clinic\nNicosia women health clinic\nNicosia private hospital nurse\nNicosia clinic nurse",
        'limit' => $settings['job.limit'] ?? '10',
        'delay_seconds' => $settings['job.delay_seconds'] ?? '5',
        'test_phone' => $settings['job.test_phone'] ?? '+905551112233',
        'test_email' => $settings['job.test_email'] ?? 'test@example.com',
    ];
}

/**
 * @param array<string, mixed> $baseConfig
 * @param array<string, string> $settings
 * @return array<string, mixed>
 */
function jobWhatsAppConfig(array $baseConfig, array $settings): array
{
    return [
        'provider' => $settings['job.whatsapp.provider'] ?? 'evolution',
        'endpoint' => $settings['job.whatsapp.endpoint'] ?? JOB_EVOLUTION_BASE_URL,
        'token' => $settings['job.whatsapp.token'] ?? JOB_EVOLUTION_AUTH_KEY,
        'instance_id' => $settings['job.whatsapp.instance_id'] ?? JOB_EVOLUTION_INSTANCE,
        'delay_seconds' => (int)($settings['job.whatsapp.delay_seconds'] ?? $settings['job.delay_seconds'] ?? 5),
        'limit' => (int)($settings['job.whatsapp.limit'] ?? $settings['job.limit'] ?? 20),
    ];
}

/**
 * @param array<string, mixed> $baseConfig
 * @param array<string, string> $settings
 * @return array<string, mixed>
 */
function jobSmtpConfig(array $baseConfig, array $settings): array
{
    return [
        'host' => $settings['job.smtp.host'] ?? (string)$baseConfig['smtp']['host'],
        'port' => (int)($settings['job.smtp.port'] ?? $baseConfig['smtp']['port']),
        'username' => $settings['job.smtp.username'] ?? (string)$baseConfig['smtp']['username'],
        'password' => $settings['job.smtp.password'] ?? (string)$baseConfig['smtp']['password'],
        'encryption' => $settings['job.smtp.encryption'] ?? (string)$baseConfig['smtp']['encryption'],
        'from_email' => $settings['job.smtp.from_email'] ?? (string)$baseConfig['smtp']['from_email'],
        'from_name' => $settings['job.smtp.from_name'] ?? 'Demo Applicant',
        'reply_to' => $settings['job.smtp.reply_to'] ?? 'applicant@example.com',
        'limit' => (int)($settings['job.smtp.limit'] ?? $settings['job.limit'] ?? 20),
    ];
}

/**
 * @param array<string, mixed> $smtp
 */
function isJobSmtpConfigured(array $smtp): bool
{
    return trim((string)$smtp['host']) !== ''
        && (string)$smtp['host'] !== 'smtp.example.com'
        && trim((string)$smtp['username']) !== ''
        && trim((string)$smtp['password']) !== ''
        && (string)$smtp['password'] !== 'YOUR_SMTP_PASSWORD'
        && trim((string)$smtp['from_email']) !== ''
        && filter_var((string)$smtp['from_email'], FILTER_VALIDATE_EMAIL) !== false;
}

function automation(array $config, array $settings, JobApplicationTargetRepository $targets): JobApplicationAutomation
{
    $phoneFormatter = new PhoneFormatter();
    $jobWhatsapp = jobWhatsAppConfig($config, $settings);
    $jobSmtp = jobSmtpConfig($config, $settings);

    return new JobApplicationAutomation(
        $targets,
        new GooglePlacesService(
            (string)$config['google']['places_api_key'],
            $phoneFormatter,
            (string)$config['google']['default_language']
        ),
        $phoneFormatter,
        new WebsiteEmailFinder(),
        new WhatsAppGateway($jobWhatsapp),
        new ColdEmailSender($jobSmtp)
    );
}

function trustedKktcIvfTargets(): array
{
    return [
        [
            'google_place_id' => 'demo_healthcare_center_1',
            'organization_name' => 'Demo Healthcare Center',
            'category' => 'Clinic / Women Health',
            'phone_number' => '+90 555 111 22 33',
            'whatsapp_phone' => '+905551112233',
            'email' => 'hr@example-clinic.test',
            'website_url' => 'https://example-clinic.test/',
            'open_address' => 'Demo District, Nicosia / TRNC',
            'city' => 'Lefkoşa',
            'source' => 'demo',
            'notes' => 'Demo record. Replace with your own verified targets before sending.',
        ],
        [
            'google_place_id' => 'demo_ivf_center_2',
            'organization_name' => 'Demo IVF Center',
            'category' => 'IVF / Clinic Nurse',
            'phone_number' => '+90 555 222 33 44',
            'whatsapp_phone' => '+905552223344',
            'email' => 'careers@example-ivf.test',
            'website_url' => 'https://example-ivf.test/',
            'open_address' => 'Demo Avenue, Nicosia / TRNC',
            'city' => 'Lefkoşa',
            'source' => 'demo',
            'notes' => 'Demo record. Replace with your own verified targets before sending.',
        ],
    ];
}

function trustedKktcJobPostings(): array
{
    return [
        [
            'source' => 'demo_job_board',
            'source_url' => 'https://example-job-board.test/healthcare',
            'organization_name' => 'Demo Job Board',
            'title' => 'Healthcare job feed',
            'location' => 'Nicosia / TRNC',
            'category' => 'Sağlık İlan Havuzu',
            'application_channel' => 'Job board / direct email',
            'status' => 'takip',
            'priority' => 2,
            'notes' => 'Demo follow-up source. Replace with trusted local job boards.',
        ],
        [
            'source' => 'demo_job_board',
            'source_url' => 'https://example-job-board.test/jobs/clinic-nurse',
            'organization_name' => 'Demo IVF Center',
            'title' => 'Clinic Nurse',
            'location' => 'Nicosia / TRNC',
            'category' => 'IVF / Clinic Nurse',
            'application_channel' => 'Job board + direct email',
            'status' => 'bulundu',
            'priority' => 1,
            'notes' => 'Demo posting. Replace with real posting details after verification.',
        ],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'save_profile') {
            $settingsRepository->saveMany([
                'job.applicant_name' => trim((string)($_POST['applicant_name'] ?? '')),
                'job.applicant_phone' => trim((string)($_POST['applicant_phone'] ?? '')),
                'job.applicant_email' => trim((string)($_POST['applicant_email'] ?? '')),
                'job.cv_path' => trim((string)($_POST['cv_path'] ?? '')),
                'job.email_subject' => trim((string)($_POST['email_subject'] ?? '')),
                'job.cover_letter' => trim((string)($_POST['cover_letter'] ?? '')),
                'job.search_terms' => trim((string)($_POST['search_terms'] ?? '')),
                'job.limit' => (int)($_POST['limit'] ?? 10),
                'job.delay_seconds' => (int)($_POST['delay_seconds'] ?? 5),
                'job.test_phone' => trim((string)($_POST['test_phone'] ?? '')),
                'job.test_email' => trim((string)($_POST['test_email'] ?? '')),
            ]);
            $settings = $settingsRepository->all();
            $message = 'Başvuru profili kaydedildi.';
        } elseif ($action === 'save_job_settings') {
            $jobSettingsToSave = [
                'job.whatsapp.provider' => trim((string)($_POST['job_whatsapp_provider'] ?? 'evolution')),
                'job.whatsapp.endpoint' => trim((string)($_POST['job_whatsapp_endpoint'] ?? JOB_EVOLUTION_BASE_URL)),
                'job.whatsapp.instance_id' => trim((string)($_POST['job_whatsapp_instance_id'] ?? JOB_EVOLUTION_INSTANCE)),
                'job.whatsapp.delay_seconds' => (int)($_POST['job_whatsapp_delay_seconds'] ?? 5),
                'job.whatsapp.limit' => (int)($_POST['job_whatsapp_limit'] ?? 20),
                'job.smtp.host' => trim((string)($_POST['job_smtp_host'] ?? '')),
                'job.smtp.port' => (int)($_POST['job_smtp_port'] ?? 587),
                'job.smtp.username' => trim((string)($_POST['job_smtp_username'] ?? '')),
                'job.smtp.encryption' => trim((string)($_POST['job_smtp_encryption'] ?? 'tls')),
                'job.smtp.from_email' => trim((string)($_POST['job_smtp_from_email'] ?? '')),
                'job.smtp.from_name' => trim((string)($_POST['job_smtp_from_name'] ?? 'Demo Applicant')),
                'job.smtp.reply_to' => trim((string)($_POST['job_smtp_reply_to'] ?? '')),
                'job.smtp.limit' => (int)($_POST['job_smtp_limit'] ?? 20),
            ];
            $postedWhatsappToken = trim((string)($_POST['job_whatsapp_token'] ?? ''));
            $postedSmtpPassword = trim((string)($_POST['job_smtp_password'] ?? ''));

            if ($postedWhatsappToken !== '') {
                $jobSettingsToSave['job.whatsapp.token'] = $postedWhatsappToken;
            }

            if ($postedSmtpPassword !== '') {
                $jobSettingsToSave['job.smtp.password'] = $postedSmtpPassword;
            }

            $settingsRepository->saveMany($jobSettingsToSave);
            $settings = $settingsRepository->all();
            $message = 'İş başvurusu WhatsApp/mail ayarları kaydedildi.';
        } elseif ($action === 'setup_job_whatsapp') {
            $instance = new EvolutionInstanceService(
                JOB_EVOLUTION_BASE_URL,
                JOB_EVOLUTION_AUTH_KEY,
                JOB_EVOLUTION_INSTANCE,
                JOB_EVOLUTION_INSTANCE_TOKEN
            );
            $settingsRepository->saveMany($instance->settings());
            $settings = $settingsRepository->all();
            $jobEvolutionResult = $instance->setup(false);
            $message = !empty($jobEvolutionResult['qr_base64'])
                ? 'İş başvurusu WhatsApp QR hazır. Telefonundan Bağlı Cihazlar ile okut.'
                : 'İş başvurusu WhatsApp instance hazır. Zaten bağlıysa QR görünmeyebilir.';
        } elseif ($action === 'reset_job_whatsapp') {
            $instance = new EvolutionInstanceService(
                JOB_EVOLUTION_BASE_URL,
                JOB_EVOLUTION_AUTH_KEY,
                JOB_EVOLUTION_INSTANCE,
                JOB_EVOLUTION_INSTANCE_TOKEN
            );
            $settingsRepository->saveMany($instance->settings());
            $settings = $settingsRepository->all();
            $jobEvolutionResult = $instance->setup(true);
            $message = 'İş başvurusu WhatsApp oturumu sıfırlandı. Yeni QR kodu okut.';
        } elseif ($action === 'test_job_smtp') {
            $jobSmtpForTest = jobSmtpConfig($config, $settings);

            if (!isJobSmtpConfigured($jobSmtpForTest)) {
                throw new RuntimeException('SMTP ayarları eksik. Önce Host, Port, Kullanıcı, Şifre, Gönderen Mail ve Reply-To alanlarını kaydet.');
            }

            (new ColdEmailSender($jobSmtpForTest))->testConnection();
            $message = 'SMTP bağlantısı başarılı. Sunucu giriş bilgilerini kabul etti.';
        } elseif ($action === 'cleanup_non_trnc') {
            $deleted = $targetRepository->deleteNonTrncTargets();
            $message = $deleted . ' Rum tarafı / KKTC dışı hedef silindi.';
        } elseif ($action === 'dedupe_job_targets') {
            $deleted = $targetRepository->deleteDuplicateContacts();
            $message = $deleted . ' mükerrer WhatsApp/e-posta hedefi silindi.';
        } elseif ($action === 'seed_trusted_ivf') {
            $targetRepository->deleteNonTrncTargets();
            $saved = $targetRepository->seedTrustedTargets(trustedKktcIvfTargets());
            $message = $saved . ' demo sağlık/klinik hedefi eklendi/güncellendi.';
        } elseif ($action === 'seed_job_postings') {
            $saved = $jobPostingRepository->seed(trustedKktcJobPostings());
            $message = $saved . ' KKTC/Lefkoşa aktif ilanı takip listesine eklendi/güncellendi.';
        } elseif ($action === 'update_job_posting_status') {
            $jobPostingRepository->updateStatus(
                (int)($_POST['posting_id'] ?? 0),
                trim((string)($_POST['posting_status'] ?? 'bulundu'))
            );
            $message = 'İlan başvuru durumu güncellendi.';
        } elseif ($action === 'scan_targets') {
            $profile = jobProfile($settings);
            $terms = array_filter(array_map('trim', preg_split('/\R+/', (string)$profile['search_terms']) ?: []));
            $result = automation($config, $settings, $targetRepository)->scanLefkosaTargets($terms, (int)$profile['limit']);
            $message = $result['found'] . ' Google sonucu bulundu, Lefkoşa filtresinden geçen ' . $result['saved'] . ' hedef kaydedildi/güncellendi.';
        } elseif ($action === 'find_missing_emails') {
            $result = automation($config, $settings, $targetRepository)->fillMissingEmails(150);
            $message = $result['checked'] . ' web sitesi kontrol edildi, ' . $result['found'] . ' yeni mail adresi bulundu.';
        } elseif ($action === 'add_manual') {
            $formatter = new PhoneFormatter();
            $targetRepository->addManual(
                trim((string)($_POST['organization_name'] ?? '')),
                trim((string)($_POST['phone_number'] ?? '')) ?: null,
                $formatter->format((string)($_POST['whatsapp_phone'] ?? '')),
                trim((string)($_POST['email'] ?? '')) ?: null,
                trim((string)($_POST['website_url'] ?? '')) ?: null,
                trim((string)($_POST['open_address'] ?? '')) ?: null,
                trim((string)($_POST['notes'] ?? '')) ?: null
            );
            $message = 'Manuel hedef eklendi.';
        } elseif ($action === 'send_whatsapp') {
            $profile = jobProfile($settings);
            $result = automation($config, $settings, $targetRepository)->sendWhatsAppApplications($profile, (int)$profile['limit']);
            $message = $result['sent'] . ' WhatsApp başvurusu gönderildi. İşlenen hedef: ' . $result['processed'];
        } elseif ($action === 'clear_wa_connection_errors') {
            $cleared = $targetRepository->clearWhatsAppConnectionClosedErrors();
            $message = $cleared . ' Connection Closed WhatsApp hatası tekrar denemeye açıldı.';
        } elseif ($action === 'send_wa_text_recovery') {
            $profile = jobProfile($settings);
            $result = automation($config, $settings, $targetRepository)->sendWhatsAppTextRecovery($profile, (int)$profile['limit']);
            $message = $result['sent'] . ' PDF’siz WhatsApp kurtarma mesajı gönderildi. İşlenen hedef: ' . $result['processed'];
        } elseif ($action === 'send_wa_followups') {
            $profile = jobProfile($settings);
            $result = automation($config, $settings, $targetRepository)->sendWhatsAppFollowups($profile, (int)$profile['limit']);
            $message = $result['sent'] . ' WhatsApp takip cevabı gönderildi. İşlenen hedef: ' . $result['processed'];
        } elseif ($action === 'send_email') {
            $profile = jobProfile($settings);
            $jobSmtpForSend = jobSmtpConfig($config, $settings);

            if (!isJobSmtpConfigured($jobSmtpForSend)) {
                throw new RuntimeException('SMTP ayarları eksik olduğu için mail gönderimi başlatılmadı.');
            }

            $result = automation($config, $settings, $targetRepository)->sendEmailApplications($profile, (int)$profile['limit']);
            $message = $result['sent'] . ' e-posta başvurusu gönderildi. İşlenen hedef: ' . $result['processed'];
        } elseif ($action === 'send_test_whatsapp') {
            $settingsRepository->saveMany([
                'job.test_phone' => trim((string)($_POST['test_phone'] ?? '')),
            ]);
            $settings = $settingsRepository->all();
            $profile = jobProfile($settings);
            automation($config, $settings, $targetRepository)->sendTestWhatsApp($profile, (string)$profile['test_phone']);
            $message = 'Test WhatsApp + CV gönderildi: ' . $profile['test_phone'];
        } elseif ($action === 'send_test_email') {
            $settingsRepository->saveMany([
                'job.test_email' => trim((string)($_POST['test_email'] ?? '')),
            ]);
            $settings = $settingsRepository->all();
            $profile = jobProfile($settings);
            $jobSmtpForSend = jobSmtpConfig($config, $settings);

            if (!isJobSmtpConfigured($jobSmtpForSend)) {
                throw new RuntimeException('SMTP ayarları eksik olduğu için test maili gönderilmedi.');
            }

            automation($config, $settings, $targetRepository)->sendTestEmail($profile, (string)$profile['test_email']);
            $message = 'Test e-posta SMTP sunucusuna teslim edildi: ' . $profile['test_email'] . '. Gelen kutusu, spam ve sunucu mail kuyruğunu kontrol et.';
        }
    } catch (Throwable $exception) {
        app_log_error($exception);
        $error = $exception->getMessage();
    }
}

$profile = jobProfile($settingsRepository->all());
$settings = $settingsRepository->all();
$jobWhatsapp = jobWhatsAppConfig($config, $settings);
$jobSmtp = jobSmtpConfig($config, $settings);
$jobSmtpConfigured = isJobSmtpConfigured($jobSmtp);
$targets = $targetRepository->all();
$stats = $targetRepository->stats();
$jobPostings = $jobPostingRepository->all();
$jobPostingStats = $jobPostingRepository->stats();
$cvExists = is_file((string)$profile['cv_path']);
$manualWhatsAppTargets = array_values(array_filter(
    $targets,
    static fn (array $target): bool => !empty($target['whatsapp_phone'])
        && (
            !empty($target['last_whatsapp_error'])
            || (int)($target['whatsapp_sent'] ?? 0) === 0
        )
));

?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>IVF Klinik Başvuru Otomasyonu</title>
    <style>
        :root {
            --bg: #f4f6f8;
            --surface: #ffffff;
            --text: #18202a;
            --muted: #687385;
            --line: #dbe1e8;
            --brand: #1667d8;
            --brand-dark: #0f4fa8;
            --good: #0f7b4f;
            --warn: #a26300;
            --bad: #b42318;
        }

        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.45 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        .wrap { width: min(1280px, calc(100% - 32px)); margin: 28px auto; }
        .topbar { display: flex; align-items: flex-end; justify-content: space-between; gap: 16px; margin-bottom: 18px; }
        h1, h2 { margin: 0 0 4px; letter-spacing: 0; }
        h1 { font-size: 24px; }
        h2 { font-size: 18px; }
        .subtitle, .muted { color: var(--muted); }
        .panel { background: var(--surface); border: 1px solid var(--line); border-radius: 8px; padding: 18px; margin-bottom: 18px; }
        .grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
        .settings-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 14px; }
        label { display: grid; gap: 6px; color: var(--muted); font-size: 13px; }
        input, textarea { width: 100%; border: 1px solid var(--line); border-radius: 8px; padding: 10px 11px; color: var(--text); font: inherit; background: #fff; }
        textarea { min-height: 130px; resize: vertical; }
        button, .btn { border: 0; border-radius: 8px; padding: 11px 15px; color: #fff; background: var(--brand); font: inherit; font-weight: 650; text-decoration: none; cursor: pointer; white-space: nowrap; }
        button:hover, .btn:hover { background: var(--brand-dark); }
        .btn.secondary, button.secondary { background: #eef3fa; color: var(--brand-dark); border: 1px solid #ccd9ea; }
        .btn.warn, button.warn { background: #fff8eb; color: var(--warn); border: 1px solid #f1d19a; }
        .actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
        .notice { border-radius: 8px; padding: 12px 14px; margin: 0 0 14px; border: 1px solid var(--line); background: #f8fafc; }
        .notice.good { border-color: #b8dfce; background: #eefbf5; color: var(--good); }
        .notice.warn { border-color: #f1d19a; background: #fff8eb; color: var(--warn); }
        .notice.bad { border-color: #f0b8b3; background: #fff1f0; color: var(--bad); }
        .stat { border: 1px solid var(--line); border-radius: 8px; padding: 12px; background: #fbfcfe; }
        .stat strong { display: block; font-size: 22px; }
        .status { display: inline-flex; align-items: center; border-radius: 999px; padding: 3px 8px; font-size: 12px; font-weight: 700; }
        .status.ok { color: var(--good); background: #e7f7ef; }
        .status.wait { color: var(--warn); background: #fff4dc; }
        .status.bad { color: var(--bad); background: #fff1f0; }
        .table-wrap { overflow: auto; border: 1px solid var(--line); border-radius: 8px; }
        table { width: 100%; min-width: 1480px; border-collapse: collapse; background: var(--surface); }
        th, td { padding: 11px 12px; border-bottom: 1px solid var(--line); text-align: left; vertical-align: top; }
        th { color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .04em; background: #f8fafc; }
        tr:last-child td { border-bottom: 0; }
        .message { max-width: 420px; white-space: pre-wrap; }
        .nowrap { white-space: nowrap; }
        a { color: var(--brand); }
        .mini-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
        .mini-btn { display: inline-flex; align-items: center; border-radius: 7px; padding: 6px 9px; background: #eef3fa; color: var(--brand-dark); border: 1px solid #ccd9ea; text-decoration: none; font-size: 12px; font-weight: 700; cursor: pointer; }
        .mini-btn.good { background: #e7f7ef; color: var(--good); border-color: #b8dfce; }
        .mini-btn.warn { background: #fff8eb; color: var(--warn); border-color: #f1d19a; }
        .mini-btn.bad { background: #fff1f0; color: var(--bad); border-color: #f0b8b3; }
        @media (max-width: 920px) { .topbar, .grid, .settings-grid { grid-template-columns: 1fr; display: grid; } }
    </style>
</head>
<body>
<main class="wrap">
    <div class="topbar">
        <div>
            <h1>IVF Klinik Başvuru Otomasyonu</h1>
            <div class="subtitle">Demo aday için sağlık, klinik ve IVF başvurularını lokal panelden takip et.</div>
        </div>
        <div class="actions">
            <a class="btn secondary" href="logs.php">Error Logları</a>
        </div>
    </div>

    <?php if ($message !== null): ?>
        <div class="notice good"><?= e($message) ?></div>
    <?php endif; ?>

    <?php if ($error !== null): ?>
        <div class="notice bad"><?= e($error) ?></div>
    <?php endif; ?>

    <?php if ($jobEvolutionResult !== null): ?>
        <div class="notice good">
            <strong>İş başvurusu WhatsApp hazır.</strong><br>
            Base URL: <?= e((string)$jobEvolutionResult['base_url']) ?><br>
            Instance: <?= e((string)$jobEvolutionResult['instance_name']) ?><br>
            API Key: <?= e((string)$jobEvolutionResult['auth_api_key']) ?><br>
            <?php if (!empty($jobEvolutionResult['qr_base64'])): ?>
                <div style="margin-top:10px">
                    <img src="<?= e((string)$jobEvolutionResult['qr_base64']) ?>" alt="İş başvurusu WhatsApp QR" style="max-width:260px;width:100%;height:auto;border:1px solid var(--line);border-radius:8px">
                </div>
            <?php else: ?>
                <span class="muted">QR dönmedi; instance zaten bağlı olabilir.</span>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if (!$cvExists): ?>
        <div class="notice bad">CV dosyası bulunamadı: <?= e((string)$profile['cv_path']) ?></div>
    <?php endif; ?>

    <?php if (!$jobSmtpConfigured): ?>
        <div class="notice warn">
            <strong>Mail gönderimi hazır değil.</strong><br>
            İş başvuru SMTP ayarları eksik görünüyor. SMTP bilgilerini kaydedip önce "SMTP Bağlantısını Test Et" butonunu çalıştır.
        </div>
    <?php endif; ?>

    <section class="panel">
        <div class="grid">
            <div class="stat"><span class="muted">Toplam Hedef</span><strong><?= e((string)$stats['total']) ?></strong></div>
            <div class="stat"><span class="muted">WhatsApp Gönderildi</span><strong><?= e((string)$stats['whatsapp_sent']) ?></strong></div>
            <div class="stat"><span class="muted">WhatsApp Bekleyen</span><strong><?= e((string)$stats['whatsapp_pending']) ?></strong></div>
            <div class="stat"><span class="muted">Numara Yok</span><strong><?= e((string)$stats['whatsapp_no_phone']) ?></strong></div>
        </div>
        <div class="grid" style="margin-top:12px">
            <div class="stat"><span class="muted">WA Bağlantı Hatası</span><strong><?= e((string)($stats['whatsapp_connection_closed'] ?? 0)) ?></strong></div>
            <div class="stat"><span class="muted">WA Takip Bekleyen</span><strong><?= e((string)($stats['whatsapp_followup_pending'] ?? 0)) ?></strong></div>
            <div class="stat"><span class="muted">WA Takip Gönderildi</span><strong><?= e((string)($stats['whatsapp_followup_sent'] ?? 0)) ?></strong></div>
            <div class="stat"><span class="muted">Hatalı Kayıt</span><strong><?= e((string)($stats['whatsapp_error'] + $stats['email_error'])) ?></strong></div>
        </div>
        <div class="grid" style="margin-top:12px">
            <div class="stat"><span class="muted">Mail Gönderildi</span><strong><?= e((string)$stats['email_sent']) ?></strong></div>
            <div class="stat"><span class="muted">Mail Bekleyen</span><strong><?= e((string)$stats['email_pending']) ?></strong></div>
            <div class="stat"><span class="muted">Mail Adresi Yok</span><strong><?= e((string)$stats['email_no_address']) ?></strong></div>
            <div class="stat"><span class="muted">Mail Hatası</span><strong><?= e((string)$stats['email_error']) ?></strong></div>
        </div>
    </section>

    <section class="panel">
        <h2>Başvuru Profili ve Ön Yazı</h2>
        <div class="subtitle">Köşeli parantezli alanlar gönderirken otomatik doldurulur: [KURUM], [AD_SOYAD], [TELEFON], [EMAIL].</div>
        <form method="post" action="job_applications.php" style="margin-top:14px">
            <input type="hidden" name="action" value="save_profile">
            <div class="settings-grid">
                <label>Ad Soyad <input name="applicant_name" value="<?= e((string)$profile['applicant_name']) ?>"></label>
                <label>Telefon <input name="applicant_phone" value="<?= e((string)$profile['applicant_phone']) ?>"></label>
                <label>E-posta <input name="applicant_email" value="<?= e((string)$profile['applicant_email']) ?>"></label>
                <label>CV PDF Yolu <input name="cv_path" value="<?= e((string)$profile['cv_path']) ?>"></label>
                <label>Mail Konusu <input name="email_subject" value="<?= e((string)$profile['email_subject']) ?>"></label>
                <label>Tek Sefer Limit <input type="number" min="1" max="50" name="limit" value="<?= e((string)$profile['limit']) ?>"></label>
                <label>WhatsApp Bekleme Sn. <input type="number" min="0" max="120" name="delay_seconds" value="<?= e((string)$profile['delay_seconds']) ?>"></label>
                <label>Test Telefon <input name="test_phone" value="<?= e((string)$profile['test_phone']) ?>"></label>
                <label>Test Mail <input type="email" name="test_email" value="<?= e((string)$profile['test_email']) ?>"></label>
            </div>
            <label style="margin-top:14px">Ön Yazı <textarea name="cover_letter"><?= e((string)$profile['cover_letter']) ?></textarea></label>
            <label style="margin-top:14px">Sadece Lefkoşa için Google arama terimleri <textarea name="search_terms" style="min-height:100px"><?= e((string)$profile['search_terms']) ?></textarea></label>
            <div class="actions" style="margin-top:14px">
                <button type="submit">Profili Kaydet</button>
            </div>
        </form>
    </section>

    <section class="panel">
        <h2>Bağımsız WhatsApp ve Mail Ayarları</h2>
        <div class="subtitle">Bu alan yalnızca iş başvuru sistemi için kullanılır.</div>
        <form method="post" action="job_applications.php" style="margin-top:14px">
            <input type="hidden" name="action" value="save_job_settings">
            <div class="settings-grid">
                <label>WhatsApp Provider <input name="job_whatsapp_provider" value="<?= e((string)$jobWhatsapp['provider']) ?>"></label>
                <label>WhatsApp Endpoint <input name="job_whatsapp_endpoint" value="<?= e((string)$jobWhatsapp['endpoint']) ?>"></label>
                <label>WhatsApp API Key <input type="password" name="job_whatsapp_token" value="" placeholder="Kayıtlı, değiştirmek için yaz"></label>
                <label>WhatsApp Instance <input name="job_whatsapp_instance_id" value="<?= e((string)$jobWhatsapp['instance_id']) ?>"></label>
                <label>WA Bekleme Sn. <input type="number" min="0" max="120" name="job_whatsapp_delay_seconds" value="<?= e((string)$jobWhatsapp['delay_seconds']) ?>"></label>
                <label>WA Limit <input type="number" min="1" max="50" name="job_whatsapp_limit" value="<?= e((string)$jobWhatsapp['limit']) ?>"></label>
                <label>SMTP Host <input name="job_smtp_host" value="<?= e((string)$jobSmtp['host']) ?>"></label>
                <label>SMTP Port <input type="number" name="job_smtp_port" value="<?= e((string)$jobSmtp['port']) ?>"></label>
                <label>SMTP Şifreleme <input name="job_smtp_encryption" value="<?= e((string)$jobSmtp['encryption']) ?>"></label>
                <label>SMTP Kullanıcı <input name="job_smtp_username" value="<?= e((string)$jobSmtp['username']) ?>"></label>
                <label>SMTP Şifre <input type="password" name="job_smtp_password" value="" placeholder="Kayıtlı, değiştirmek için yaz"></label>
                <label>Gönderen Mail <input name="job_smtp_from_email" value="<?= e((string)$jobSmtp['from_email']) ?>"></label>
                <label>Gönderen Adı <input name="job_smtp_from_name" value="<?= e((string)$jobSmtp['from_name']) ?>"></label>
                <label>Reply-To <input name="job_smtp_reply_to" value="<?= e((string)$jobSmtp['reply_to']) ?>"></label>
                <label>Mail Limit <input type="number" min="1" max="50" name="job_smtp_limit" value="<?= e((string)$jobSmtp['limit']) ?>"></label>
            </div>
            <div class="actions" style="margin-top:14px">
                <button type="submit">İş Başvuru Ayarlarını Kaydet</button>
            </div>
        </form>

        <div class="actions" style="margin-top:10px">
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="setup_job_whatsapp">
                <button type="submit" class="secondary">İş WhatsApp QR Oluştur / Bağlantıyı Kontrol Et</button>
            </form>
            <form method="post" action="job_applications.php" onsubmit="return confirm('İş başvurusu WhatsApp oturumu sıfırlanacak ve yeni QR üretilecek. Devam edilsin mi?');">
                <input type="hidden" name="action" value="reset_job_whatsapp">
                <button type="submit" class="warn">İş WhatsApp Oturumunu Sıfırla ve Yeni QR Oluştur</button>
            </form>
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="test_job_smtp">
                <button type="submit" class="secondary">SMTP Bağlantısını Test Et</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <h2>Test Gönderimi</h2>
        <div class="subtitle">Bu alan yalnızca kendi test numarana/mailine gönderir; hedef listesindeki kurumların gönderim durumunu değiştirmez.</div>
        <div class="actions" style="margin-top:14px">
            <form method="post" action="job_applications.php" class="actions" onsubmit="return confirm('Test WhatsApp mesajı ve CV PDF gönderilsin mi?');">
                <input type="hidden" name="action" value="send_test_whatsapp">
                <input name="test_phone" value="<?= e((string)$profile['test_phone']) ?>" style="width:180px" aria-label="Test telefon">
                <button type="submit" class="secondary">Bu Telefona Test WhatsApp + CV Gönder</button>
            </form>
            <form method="post" action="job_applications.php" class="actions" onsubmit="return confirm('Test e-posta ve CV PDF gönderilsin mi?');">
                <input type="hidden" name="action" value="send_test_email">
                <input type="email" name="test_email" value="<?= e((string)$profile['test_email']) ?>" style="width:240px" aria-label="Test mail">
                <button type="submit" class="secondary">Bu Maile Test Mail + CV Gönder</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <h2>Başvuru Hedeflerini Yönet</h2>
        <div class="subtitle">Önce hedefleri tara, listeyi kontrol et, sonra WhatsApp veya mail gönderimini başlat.</div>
        <div class="actions" style="margin-top:14px">
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="cleanup_non_trnc">
                <button type="submit" class="secondary">Bölge Dışı Kayıtları Temizle</button>
            </form>
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="dedupe_job_targets">
                <button type="submit" class="secondary">Mükerrer Telefon/Mail Kayıtlarını Temizle</button>
            </form>
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="seed_trusted_ivf">
                <button type="submit" class="secondary">Demo Sağlık Hedeflerini Ekle</button>
            </form>
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="seed_job_postings">
                <button type="submit" class="secondary">KKTC Aktif İlan Takibini Güncelle</button>
            </form>
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="scan_targets">
                <button type="submit" class="secondary">Sağlık / Klinik Hedeflerini Tara</button>
            </form>
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="find_missing_emails">
                <button type="submit" class="secondary">Maili Olmayanlarda Webden Mail Ara</button>
            </form>
            <button type="button" class="secondary" disabled title="Evolution API PENDING bıraktığı için otomatik WhatsApp geçici olarak kapalı. Manuel Web WhatsApp butonlarını kullan.">Otomatik WhatsApp Geçici Kapalı</button>
            <form method="post" action="job_applications.php">
                <input type="hidden" name="action" value="clear_wa_connection_errors">
                <button type="submit" class="secondary">Connection Closed Hatalarını Tekrar Denemeye Aç</button>
            </form>
            <form method="post" action="job_applications.php" onsubmit="return confirm('Bekleyen e-posta hedeflerine ön yazı ve CV PDF gönderilecek. Devam edilsin mi?');">
                <input type="hidden" name="action" value="send_email">
                <button type="submit" class="warn">Bekleyenlere Mail + CV Gönder (<?= e((string)$stats['email_pending']) ?>)</button>
            </form>
        </div>
    </section>

    <section class="panel">
        <div class="topbar">
            <div>
                <h2>Manuel Web WhatsApp Takibi</h2>
                <div class="subtitle">Otomasyon göndermeyecek. Hatalı veya bekleyen WhatsApp kayıtlarını hazır metinle Web WhatsApp’ta açar; gönderimi sen elle yaparsın.</div>
            </div>
            <strong><?= e((string)count($manualWhatsAppTargets)) ?> kayıt</strong>
        </div>
        <?php if ($manualWhatsAppTargets === []): ?>
            <div class="notice">Manuel WhatsApp takibi gerektiren kayıt yok.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table style="min-width:980px">
                    <thead>
                    <tr>
                        <th>Kurum</th>
                        <th>WhatsApp</th>
                        <th>Mail</th>
                        <th>Durum</th>
                        <th>Manuel Aksiyon</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($manualWhatsAppTargets as $target): ?>
                        <?php $manualText = manualWhatsAppText($profile, (string)$target['organization_name']); ?>
                        <tr>
                            <td><strong><?= e((string)$target['organization_name']) ?></strong></td>
                            <td><?= e((string)$target['whatsapp_phone']) ?></td>
                            <td><?= !empty($target['email']) ? e((string)$target['email']) : '<span class="muted">Yok</span>' ?></td>
                            <td class="message">
                                <?php if (!empty($target['last_whatsapp_error'])): ?>
                                    <span class="status bad">Otomasyon başarısız</span><br><?= e((string)$target['last_whatsapp_error']) ?>
                                <?php else: ?>
                                    <span class="status wait">Manuel takip bekliyor</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a class="btn secondary" href="<?= e(mobileWhatsAppUrl((string)$target['whatsapp_phone'], $manualText)) ?>" target="_blank" rel="noopener">Telefondan WhatsApp’ta Aç</a>
                                    <a class="btn secondary" href="<?= e(webWhatsAppUrl((string)$target['whatsapp_phone'], $manualText)) ?>" target="_blank" rel="noopener">Web WhatsApp’ta Aç</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <h2>Manuel Hedef Ekle</h2>
        <form method="post" action="job_applications.php" class="settings-grid" style="margin-top:14px">
            <input type="hidden" name="action" value="add_manual">
            <label>Kurum Adı <input name="organization_name" required></label>
            <label>Telefon <input name="phone_number"></label>
            <label>WhatsApp <input name="whatsapp_phone" placeholder="Örn: 0539..."></label>
            <label>E-posta <input type="email" name="email"></label>
            <label>Web Sitesi <input name="website_url"></label>
            <label>Adres <input name="open_address" placeholder="Lefkoşa"></label>
            <label style="grid-column:1/-1">Not <input name="notes"></label>
            <div class="actions"><button type="submit" class="secondary">Manuel Hedef Ekle</button></div>
        </form>
    </section>

    <section class="panel">
        <div class="topbar">
            <div>
                <h2>İlan Takip / Başvuru Takip</h2>
                <div class="subtitle">Sadece KKTC/Lefkoşa sağlık, IVF, klinik ve hemşirelik ilanları. Rum tarafı/güney ilanları filtre dışı tutulur.</div>
            </div>
        </div>
        <div class="grid">
            <div class="stat"><span class="muted">Toplam İlan</span><strong><?= e((string)$jobPostingStats['total']) ?></strong></div>
            <div class="stat"><span class="muted">Başvurulacak</span><strong><?= e((string)$jobPostingStats['found']) ?></strong></div>
            <div class="stat"><span class="muted">Başvuruldu</span><strong><?= e((string)$jobPostingStats['applied']) ?></strong></div>
            <div class="stat"><span class="muted">Görüşme</span><strong><?= e((string)$jobPostingStats['interview']) ?></strong></div>
            <div class="stat"><span class="muted">Takip Kaynağı</span><strong><?= e((string)$jobPostingStats['followup']) ?></strong></div>
            <div class="stat"><span class="muted">Olumsuz</span><strong><?= e((string)$jobPostingStats['rejected']) ?></strong></div>
            <div class="stat"><span class="muted">Yüksek Öncelik</span><strong><?= e((string)$jobPostingStats['high_priority']) ?></strong></div>
        </div>

        <?php if ($jobPostings === []): ?>
            <div class="notice" style="margin-top:14px">Henüz ilan yok. "KKTC Aktif İlan Takibini Güncelle" butonunu çalıştır.</div>
        <?php else: ?>
            <div class="table-wrap" style="margin-top:14px">
                <table style="min-width:1380px">
                    <thead>
                    <tr>
                        <th>Öncelik</th>
                        <th>Kurum</th>
                        <th>İlan</th>
                        <th>Lokasyon</th>
                        <th>Kanal</th>
                        <th>Durum</th>
                        <th>Aksiyon</th>
                        <th>Kaynak</th>
                        <th>Not</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($jobPostings as $posting): ?>
                        <tr>
                            <td><?= e((string)$posting['priority']) ?></td>
                            <td><strong><?= e((string)$posting['organization_name']) ?></strong></td>
                            <td><?= e((string)$posting['title']) ?><br><span class="muted"><?= e((string)($posting['category'] ?? '')) ?></span></td>
                            <td><?= e((string)$posting['location']) ?></td>
                            <td><?= e((string)($posting['application_channel'] ?? '')) ?></td>
                            <td><span class="status <?= $posting['status'] === 'bulundu' ? 'wait' : 'ok' ?>"><?= e((string)$posting['status']) ?></span></td>
                            <td>
                                <form method="post" action="job_applications.php" class="mini-actions">
                                    <input type="hidden" name="action" value="update_job_posting_status">
                                    <input type="hidden" name="posting_id" value="<?= e((string)$posting['id']) ?>">
                                    <button class="mini-btn good" type="submit" name="posting_status" value="basvuruldu">Başvuruldu</button>
                                    <button class="mini-btn warn" type="submit" name="posting_status" value="takip">Takipte</button>
                                    <button class="mini-btn good" type="submit" name="posting_status" value="gorusme">Görüşme</button>
                                    <button class="mini-btn bad" type="submit" name="posting_status" value="olumsuz">Olumsuz</button>
                                </form>
                                <?php if (!empty($posting['applied_at'])): ?>
                                    <div class="muted" style="margin-top:6px">Başvuru: <?= e((string)$posting['applied_at']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><a href="<?= e((string)$posting['source_url']) ?>" target="_blank" rel="noopener"><?= e((string)$posting['source']) ?></a></td>
                            <td class="message"><?= e((string)($posting['notes'] ?? '')) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="panel">
        <div class="topbar">
            <div>
                <h2>Lefkoşa Başvuru Hedefleri</h2>
                <div class="subtitle"><?= count($targets) ?> kayıt listeleniyor.</div>
            </div>
        </div>

        <?php if ($targets === []): ?>
            <div class="notice">Henüz hedef yok. Önce tarama yap veya manuel hedef ekle.</div>
        <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                    <tr>
                        <th>Kurum</th>
                        <th>Kategori</th>
                        <th>Telefon / WhatsApp</th>
                        <th>E-posta</th>
                        <th>Web</th>
                        <th>Adres</th>
                        <th>WhatsApp Durumu</th>
                        <th>Mail Durumu</th>
                        <th>Not / Hata</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($targets as $target): ?>
                        <tr>
                            <td><strong><?= e((string)$target['organization_name']) ?></strong></td>
                            <td><?= e((string)($target['category'] ?? '')) ?></td>
                            <td class="nowrap">
                                <?= e((string)($target['phone_number'] ?? '')) ?><br>
                                <?php if (!empty($target['whatsapp_phone'])): ?>
                                    <a href="https://wa.me/<?= e(ltrim((string)$target['whatsapp_phone'], '+')) ?>" target="_blank" rel="noopener"><?= e((string)$target['whatsapp_phone']) ?></a>
                                    <div class="mini-actions">
                                        <a class="mini-btn" href="<?= e(mobileWhatsAppUrl((string)$target['whatsapp_phone'], manualWhatsAppText($profile, (string)$target['organization_name']))) ?>" target="_blank" rel="noopener">Telefonda Aç</a>
                                        <a class="mini-btn" href="<?= e(webWhatsAppUrl((string)$target['whatsapp_phone'], manualWhatsAppText($profile, (string)$target['organization_name']))) ?>" target="_blank" rel="noopener">Web WhatsApp’ta Aç</a>
                                    </div>
                                <?php else: ?>
                                    <span class="muted">WhatsApp yok</span>
                                <?php endif; ?>
                            </td>
                            <td><?= !empty($target['email']) ? e((string)$target['email']) : '<span class="muted">Yok</span>' ?></td>
                            <td>
                                <?php if (!empty($target['website_url'])): ?>
                                    <a href="<?= e((string)$target['website_url']) ?>" target="_blank" rel="noopener">Site</a>
                                <?php else: ?>
                                    <span class="muted">Yok</span>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string)($target['open_address'] ?? '')) ?></td>
                            <td>
                                <?php if ((int)$target['whatsapp_sent'] === 1): ?>
                                    <span class="status ok">WhatsApp gönderildi</span>
                                    <?php if ((int)($target['whatsapp_followup_sent'] ?? 0) === 1): ?>
                                        <br><span class="status ok">Takip cevabı gönderildi</span>
                                    <?php elseif ((int)($target['whatsapp_followup_sent'] ?? 0) === 0): ?>
                                        <br><span class="status wait">Takip gerekebilir</span>
                                    <?php endif; ?>
                                <?php elseif (!empty($target['last_whatsapp_error'])): ?>
                                    <span class="status bad">WhatsApp hatalı, tekrar gönderilmeyecek</span>
                                <?php elseif (empty($target['whatsapp_phone'])): ?>
                                    <span class="status wait">Numara yok</span>
                                <?php else: ?>
                                    <span class="status wait">WhatsApp bekliyor</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ((int)$target['email_sent'] === 1): ?>
                                    <span class="status ok">Mail + CV gönderildi</span>
                                <?php elseif (!empty($target['last_email_error'])): ?>
                                    <span class="status bad">Mail hatalı, tekrar gönderilmeyecek</span>
                                <?php elseif (empty($target['email'])): ?>
                                    <span class="status wait">Mail yok</span>
                                <?php else: ?>
                                    <span class="status wait">Mail bekliyor</span>
                                <?php endif; ?>
                            </td>
                            <td class="message">
                                <?= e((string)($target['notes'] ?? '')) ?>
                                <?php if (!empty($target['last_whatsapp_error'])): ?>
                                    <br>WA Hata: <?= e((string)$target['last_whatsapp_error']) ?>
                                <?php endif; ?>
                                <?php if (!empty($target['last_email_error'])): ?>
                                    <br>Mail Hata: <?= e((string)$target['last_email_error']) ?>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
