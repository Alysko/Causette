<?php

declare(strict_types=1);

namespace Causette\Security;

use Database;
use Setting;
use Throwable;

final class AuthBudget
{
    public static function allow(string $action, int $userId, string $address, int $accountLimit): bool
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP) === false ? '' : inet_pton($address);
        if (str_starts_with($packed, str_repeat("\0", 10) . "\xff\xff")) {
            $packed = substr($packed, 12);
        }
        $network = substr($packed, 0, 8);
        $shard = substr(hash('sha256', $network), 0, 2);
        $now = time();
        $connection = Database::getInstance();
        $connection->beginTransaction();

        try {
            // A fixed set of network buckets bounds storage even as addresses change.
            if (!self::take('auth_limit_global', intdiv($now, 60), 480)
                || !self::take('auth_limit_net_' . $shard, intdiv($now, 60), 48)
                || !self::take('auth_limit_' . $action . '_' . $userId, intdiv($now, 600), $accountLimit)
            ) {
                $connection->rollBack();
                return false;
            }

            $connection->commit();
            return true;
        } catch (Throwable $failure) {
            if ($connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $failure;
        }
    }

    private static function take(string $key, int $window, int $limit): bool
    {
        Database::query("INSERT INTO settings (`key`, `value`) VALUES (?, '0:0')
            ON DUPLICATE KEY UPDATE `key` = `key`", [$key]);
        $value = Database::query('SELECT `value` FROM settings WHERE `key` = ? FOR UPDATE', [$key])->fetchColumn();
        [$previousWindow, $previousCount] = array_map('intval', explode(':', (string)$value, 2) + [0, 0]);
        $count = $previousWindow === $window ? $previousCount : 0;
        if ($count >= $limit) {
            return false;
        }

        Setting::set($key, $window . ':' . ($count + 1));
        return true;
    }
}
