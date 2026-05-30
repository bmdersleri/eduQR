<?php

declare(strict_types=1);

namespace EduQR\Support;

use EduQR\Config;

final class DeviceHash
{
    public static function compute(string $cookieId, string $userAgent): string
    {
        $secret = Config::get('APP_SECRET', '');

        return hash('sha256', $secret . '|' . $cookieId . '|' . $userAgent);
    }
}
