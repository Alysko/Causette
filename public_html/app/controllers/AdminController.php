<?php
require_once __DIR__ . '/../core/SecretSettings.php';
require_once __DIR__ . '/../core/MailDelivery.php';
require_once __DIR__ . '/../core/PushEndpoint.php';

use Causette\Localization\DisplayPreferences;
use Causette\Mail\MailDelivery;
use Causette\Push\PushEndpoint;
use Causette\Security\SecretSettings;
use PHPMailer\PHPMailer\PHPMailer;

class AdminController extends Controller {
    private function requireAdminUser() {
        Auth::requireAuth();
        $user = Auth::user();
        if (!$user || strtolower((string)($user->role ?? '')) !== 'admin') {
            ErrorHandler::abort(403, 'Access denied');
        }

        return $user;
    }

    public function saveDisplayDefaults(): void {
        $this->requireAdminUser();
        Auth::csrfValidate(403);
        if (!DisplayPreferences::save($_POST)) {
            ErrorHandler::abort(400, 'Invalid display defaults');
        }

        $this->flash('success', 'preferences_saved');
        $this->redirect('/settings');
    }

    public function saveAccountSettings() {
        $this->requireAdminUser();
        Auth::csrfValidate();

        Setting::set('invite_code_required', isset($_POST['invite_code_required']) ? '1' : '0');
        Setting::set('invites_enabled', isset($_POST['invites_enabled']) ? '1' : '0');
        Setting::set('invite_codes_per_user', (string)max(0, (int)($_POST['invite_codes_per_user'] ?? 3)));
        Setting::set('email_verification_required', isset($_POST['email_verification_required']) ? '1' : '0');
        Setting::set('new_user_notification', isset($_POST['new_user_notification']) ? '1' : '0');

        $autoJoinGroup = trim((string)($_POST['new_user_auto_join_group'] ?? ''));
        if ($autoJoinGroup !== '') {
            $validChat = Database::query(
                "SELECT id FROM chats WHERE id = ? AND type = 'group'",
                [(int)$autoJoinGroup]
            )->fetch();
            Setting::set('new_user_auto_join_group', $validChat ? (string)$validChat->id : '');
        } else {
            Setting::set('new_user_auto_join_group', '');
        }

        $this->flash('success', 'accounts_saved');
        $this->redirect('/settings');
    }

    public function saveAnnouncementSettings() {
        $this->requireAdminUser();
        Auth::csrfValidate();

        $rawAnnouncement = (string)($_POST['announcement_message'] ?? '');
        $announcement = strip_tags(trim($rawAnnouncement));
        if (function_exists('mb_substr')) {
            $announcement = mb_substr($announcement, 0, 1000);
        } else {
            $announcement = substr($announcement, 0, 1000);
        }

        $announcementStyle = trim((string)($_POST['announcement_style'] ?? 'orange'));
        $validAnnouncementStyles = ['orange', 'green', 'blue'];
        if (!in_array($announcementStyle, $validAnnouncementStyles, true)) {
            $announcementStyle = 'orange';
        }

        Setting::set('announcement_message', $announcement);
        Setting::set('announcement_style', $announcementStyle);

        $this->flash('success', 'announcement_saved');
        $this->redirect('/settings');
    }

    public function saveMoreSettings() {
        $this->requireAdminUser();
        Auth::csrfValidate();

        Setting::set('error_display', isset($_POST['error_display']) ? '1' : '0');
        ErrorHandler::setDebugMode(isset($_POST['error_display']));
        Setting::set('attachment_logging', isset($_POST['attachment_logging']) ? '1' : '0');

        $this->flash('success', 'more_saved');
        $this->redirect('/settings');
    }

    public function saveCaptchaSettings() {
        $this->requireAdminUser();
        Auth::csrfValidate();

        $provider = trim((string)($_POST['captcha_provider'] ?? ''));
        $validProviders = ['', 'cloudflare-turnstile', 'google-recaptcha-v2'];
        if (!in_array($provider, $validProviders, true)) {
            $provider = '';
        }

        Setting::set('captcha_provider', $provider);

        if ($provider !== '') {
            $siteKey = trim((string)($_POST['captcha_site_key'] ?? ''));
            Setting::set('captcha_site_key', $siteKey);

            $secretKey = trim((string)($_POST['captcha_secret_key'] ?? ''));
            if ($secretKey !== '') {
                Setting::set('captcha_secret_key', $secretKey);
            }
        }

        $this->flash('success', 'captcha_saved');
        $this->redirect('/settings');
    }

    public function saveAttachmentSettings() {
        $this->requireAdminUser();
        Auth::csrfValidate();

        if (array_key_exists('attachments_expiry_hours', $_POST)) {
            $hours = Attachment::parseExpiryHours($_POST['attachments_expiry_hours']);
            if ($hours === null) {
                $this->flash('error', 'attachments_invalid_expiry');
                $this->redirect('/settings');
                return;
            }
            Setting::set('attachments_expiry_hours', implode(',', $hours));
            Setting::set('attachments_expiry_enabled', isset($_POST['attachments_expiry_enabled']) ? '1' : '0');
        }

        $types = [];
        foreach (Attachment::supportedExtensions() as $type) {
            if (isset($_POST['type_' . $type])) $types[] = $type;
        }
        Setting::set('attachments_accepted_file_types', implode(',', $types));

        $maxMb = (int)($_POST['attachments_maximum_file_size_mb'] ?? 10);
        if ($maxMb < 1) $maxMb = 1;
        Setting::set('attachments_maximum_file_size_mb', (string)$maxMb);

        $this->flash('success', 'attachments_saved');
        $this->redirect('/settings');
    }

    public function saveMailSettings() {
        $this->requireAdminUser();
        Auth::csrfValidate();

        $transport = $_POST['mail_transport'] ?? MailDelivery::transport();
        $from = $_POST['mail_from'] ?? '';
        $fromName = $_POST['mail_from_name'] ?? '';
        if (!in_array($transport, [MailDelivery::PHP, MailDelivery::SMTP], true)
            || !is_string($from) || !is_string($fromName)
            || ($transport === MailDelivery::PHP && !filter_var(trim($from), FILTER_VALIDATE_EMAIL))
        ) {
            $this->flash('error', 'mail_settings_invalid');
            $this->redirect('/settings');
            return;
        }

        $settings = ['mail_transport' => $transport, 'mail_from' => trim($from), 'mail_from_name' => trim($fromName)];
        $password = '';
        if ($transport === MailDelivery::SMTP) {
            $host = $_POST['mail_host'] ?? '';
            $port = $_POST['mail_port'] ?? '';
            $user = $_POST['mail_user'] ?? '';
            $password = $_POST['mail_pass'] ?? '';
            if (!is_string($host) || !is_string($port) || !is_string($user) || !is_string($password)
                || (trim($port) !== '' && filter_var(trim($port), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]) === false)
            ) {
                $this->flash('error', 'mail_settings_invalid');
                $this->redirect('/settings');
                return;
            }
            $settings += ['mail_host' => trim($host), 'mail_port' => trim($port) ?: '587', 'mail_user' => trim($user)];
        }

        try {
            SecretSettings::save($settings, SecretSettings::SMTP, $password);
        } catch (RuntimeException) {
            $this->flash('error', 'integration_storage_error');
            $this->redirect('/settings');
            return;
        }

        $this->flash('success', 'mail_saved');
        $this->redirect('/settings');
    }

    public function savePushSettings() {
        $this->requireAdminUser();
        Auth::csrfValidate();

        $publicKey = $_POST['push_vapid_public_key'] ?? '';
        $privateKey = $_POST['push_vapid_private_key'] ?? '';

        try {
            if (!is_string($publicKey) || !is_string($privateKey)) {
                throw new RuntimeException('Invalid key field.');
            }
            $settings = ['push_vapid_public_key' => trim($publicKey)];
            if (($_POST['push_provider_policy_present'] ?? null) === '1') {
                $requested = $_POST['push_allowed_providers'] ?? [];
                if (!is_array($requested) || count($requested) > count(PushEndpoint::PROVIDERS)) {
                    throw new RuntimeException('Invalid push provider selection.');
                }
                $selected = array_filter(PushEndpoint::PROVIDERS, static fn(string $provider): bool => in_array($provider, $requested, true));
                if (count($selected) !== count($requested)) {
                    throw new RuntimeException('Invalid push provider selection.');
                }
                $settings['push_allowed_providers'] = implode(',', $selected);
            }
            SecretSettings::save($settings, SecretSettings::VAPID, trim($privateKey));
        } catch (RuntimeException) {
            $this->flash('error', 'integration_storage_error');
            $this->redirect('/settings');
            return;
        }
        $this->flash('success', 'push_saved');
        $this->redirect('/settings');
    }

    public function sendTestMail() {
        $user = $this->requireAdminUser();
        Auth::csrfValidate();

        if (!MailDelivery::isConfigured()) {
            $this->flash('mail_test_error', 'mail_test_missing_settings');
            $this->redirect('/settings');
            return;
        }

        $locale = DisplayPreferences::localeForRecipient((int) $user->id);
        if (MailDelivery::send($user->email, $locale->text('mail.smtp_test_subject'), $locale->text('mail.smtp_test_body'),
            false, $this->createTestMailer())) {
            $this->flash('success', 'mail_test_sent');
        } else {
            $this->flash('mail_test_error', 'mail_test_delivery_failed');
        }

        $this->redirect('/settings');
    }

    protected function createTestMailer(): PHPMailer {
        return new PHPMailer(true);
    }

        public function recalculateStorageStats() {
            $this->requireAdminUser();
            Auth::csrfValidate();

            try {
                $stats = $this->calculateStorageStats();

                Setting::set('storage_total_bytes_cached', (string)$stats['total_storage_bytes']);
                Setting::set('storage_dedup_saved_bytes_cached', (string)$stats['dedup_saved_bytes']);
                Setting::set('storage_stats_last_recalculated_at', gmdate('Y-m-d H:i:s'));

                $this->flash('success', 'storage_recalculated');
            } catch (Throwable $exception) {
                $this->flash('error', 'storage_recalculate_failed');
            }

            $this->redirect('/settings');
        }

    public function checkForUpdatesNow() {
        $this->requireAdminUser();
        Auth::csrfValidate();
        $this->flash('error', 'update_check_unconfigured');
        $this->redirect('/settings');
    }

    private function storageRoot(): string {
        return rtrim((string)(defined('STORAGE_FILESYSTEM_ROOT') ? STORAGE_FILESYSTEM_ROOT : (dirname(__DIR__, 3) . '/storage')), '/');
    }

    private function calculateStorageStats(): array {
        $storageSizeBytes = $this->calculateDirectorySizeBytes($this->storageRoot());

        $dedupSavedBytes = (int)Database::query(
            "SELECT COALESCE(SUM(file_size), 0) FROM attachments WHERE dedup_source_id IS NOT NULL"
        )->fetchColumn();

        if ($dedupSavedBytes < 0) {
            $dedupSavedBytes = 0;
        }

        return [
            'total_storage_bytes' => $storageSizeBytes,
            'dedup_saved_bytes' => $dedupSavedBytes,
        ];
    }

    private function calculateDirectorySizeBytes(string $path): int {
        if (!is_dir($path)) {
            return 0;
        }

        $size = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile()) {
                    continue;
                }

                $fileSize = (int)$fileInfo->getSize();
                if ($fileSize > 0) {
                    $size += $fileSize;
                }
            }
        } catch (Throwable $exception) {
            return max(0, $size);
        }

        return max(0, $size);
    }

    private function formatBytes(int $bytes): string {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = (float)$bytes;
        foreach ($units as $unit) {
            $value /= 1024;
            if ($value < 1024 || $unit === 'TB') {
                return number_format($value, 2) . ' ' . $unit;
            }
        }

        return number_format($bytes) . ' B';
    }

    private function formatLastRecalculatedLabel(string $raw): string {
        if ($raw === '') {
            return 'N/A';
        }

        try {
            $dateTime = new DateTimeImmutable($raw, new DateTimeZone('UTC'));
            return 'Last recalculated ' . $dateTime->format('Y-m-d H:i:s') . ' UTC';
        } catch (Throwable $exception) {
            return 'Last recalculated ' . $raw;
        }
    }

    private function getBruteForceProtectionData() {
        $result = [
            'failed_login_attempts_24h' => 0,
            'failed_registration_attempts_24h' => 0,
            'active_banned_ips' => [],
        ];

        try {
            $result['failed_login_attempts_24h'] = (int)Database::query(
                "SELECT COUNT(*) FROM auth_attempt_limits WHERE action_type = 'login_failed' AND created_at >= (NOW() - INTERVAL 24 HOUR)"
            )->fetchColumn();

            $result['failed_registration_attempts_24h'] = (int)Database::query(
                "SELECT COUNT(*) FROM auth_attempt_limits WHERE action_type = 'register_invite_failed' AND created_at >= (NOW() - INTERVAL 24 HOUR)"
            )->fetchColumn();

            $result['active_banned_ips'] = Database::query(
                "WITH ranked_attempts AS (
                    SELECT
                        ip_address,
                        action_type,
                        created_at,
                        ROW_NUMBER() OVER (
                            PARTITION BY ip_address, action_type
                            ORDER BY created_at DESC
                        ) AS row_num
                    FROM auth_attempt_limits
                    WHERE created_at >= (NOW() - INTERVAL 1 MINUTE)
                ),
                active_action_bans AS (
                    SELECT
                        ip_address,
                        action_type,
                        DATE_ADD(MIN(created_at), INTERVAL 1 MINUTE) AS ban_expires_at
                    FROM ranked_attempts
                    WHERE row_num <= 5
                    GROUP BY ip_address, action_type
                    HAVING COUNT(*) = 5 AND DATE_ADD(MIN(created_at), INTERVAL 1 MINUTE) > NOW()
                )
                SELECT
                    ip_address,
                    CEIL(GREATEST(TIMESTAMPDIFF(SECOND, NOW(), MAX(ban_expires_at)), 0) / 60) AS minutes_left
                FROM active_action_bans
                GROUP BY ip_address
                ORDER BY minutes_left DESC, ip_address ASC"
            )->fetchAll();
        } catch (Throwable $exception) {
            return $result;
        }

        return $result;
    }
}
