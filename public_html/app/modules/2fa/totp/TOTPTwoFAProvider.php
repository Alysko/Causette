<?php
require_once __DIR__ . '/TOTP.php';

class TOTPTwoFAProvider implements TwoFAProviderInterface {
    public function getName(): string {
        return 'totp';
    }

    public function getLabel(): string {
        return 'Authenticator App';
    }

    public function isAvailable(int $userId): bool {
        $row = Database::query(
            "SELECT id FROM totp_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL",
            [$userId]
        )->fetch();
        return (bool)$row;
    }

    public function sendChallenge(int $userId, ?string $deliveryCode): bool {
        // No-op: TOTP codes are generated client-side by the authenticator app
        return true;
    }

    public function verifyCode(int $userId, string $code, array $challenge): bool {
        $row = Database::query(
            "SELECT secret_encrypted FROM totp_secrets WHERE user_id = ? AND confirmed_at IS NOT NULL",
            [$userId]
        )->fetch();

        if (!$row) {
            return false;
        }

        $secret = TOTP::decrypt($row->secret_encrypted);
        if ($secret === null) {
            return false;
        }

        if (preg_match('/\A[0-9]{6}\z/', $code) === 1) {
            $step = TOTP::matchingStep($secret, $code);
            return $step !== null && TOTP::consumeStep($userId, $step);
        }

        if (preg_match('/\A[a-zA-Z0-9]{8}\z/', $code) !== 1) {
            return false;
        }

        return Database::query('UPDATE totp_recovery_codes SET used_at = NOW()
            WHERE user_id = ? AND code_hash = ? AND used_at IS NULL',
            [$userId, hash('sha256', strtolower($code))])->rowCount() > 0;
    }
}
