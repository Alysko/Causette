<?php
require_once __DIR__ . '/TwoFAProviderInterface.php';

class TwoFAManager {
    private static ?array $providers = null;

    public static function getProviders(): array {
        if (self::$providers !== null) {
            return self::$providers;
        }

        require_once __DIR__ . '/totp/TOTPTwoFAProvider.php';
        require_once __DIR__ . '/email/EmailTwoFAProvider.php';
        self::$providers = [new TOTPTwoFAProvider(), new EmailTwoFAProvider()];

        return self::$providers;
    }

    public static function getAvailableProvider(int $userId): ?TwoFAProviderInterface {
        foreach (self::getProviders() as $provider) {
            if ($provider->isAvailable($userId)) {
                return $provider;
            }
        }
        return null;
    }

    public static function getProviderByName(string $name): ?TwoFAProviderInterface {
        foreach (self::getProviders() as $provider) {
            if ($provider->getName() === $name) {
                return $provider;
            }
        }
        return null;
    }
}
