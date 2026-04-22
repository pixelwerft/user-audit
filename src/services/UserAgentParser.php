<?php

namespace pixelwerft\useraudit\services;

use craft\base\Component;

/**
 * Dependency-free, regex-based UA detection. Covers the cases that
 * actually show up in practice (iOS/Android/Windows/macOS/Linux/
 * ChromeOS, Chrome/Safari/Firefox/Edge/Opera/Samsung). For deeper
 * granularity the innards can later be replaced by
 * `matomo/device-detector` — the `parse()` signature stays stable.
 */
class UserAgentParser extends Component
{
    /**
     * @return array{
     *   deviceType: string|null,
     *   osName: string|null,
     *   osVersion: string|null,
     *   browserName: string|null,
     *   browserVersion: string|null
     * }
     */
    public function parse(?string $ua): array
    {
        $ua = trim((string)$ua);
        if ($ua === '') {
            return [
                'deviceType' => null,
                'osName' => null,
                'osVersion' => null,
                'browserName' => null,
                'browserVersion' => null,
            ];
        }

        return [
            'deviceType' => self::detectDeviceType($ua),
            'osName' => self::detectOsName($ua),
            'osVersion' => self::detectOsVersion($ua),
            'browserName' => self::detectBrowserName($ua),
            'browserVersion' => self::detectBrowserVersion($ua),
        ];
    }

    private static function detectDeviceType(string $ua): string
    {
        if (preg_match('/\b(bot|crawler|spider|slurp|duckduckbot|mediapartners)\b|curl\/|httpie|wget/i', $ua)) {
            return 'bot';
        }
        if (preg_match('/iPad|Tablet|Kindle|PlayBook/i', $ua)) {
            return 'tablet';
        }
        if (preg_match('/Mobi|Android.*Mobile|iPhone|iPod|BlackBerry|Opera Mini/i', $ua)) {
            return 'mobile';
        }
        return 'desktop';
    }

    private static function detectOsName(string $ua): ?string
    {
        if (preg_match('/iPhone|iPad|iPod|iOS/i', $ua)) {
            return 'iOS';
        }
        if (preg_match('/Android/i', $ua)) {
            return 'Android';
        }
        if (preg_match('/Windows NT/i', $ua)) {
            return 'Windows';
        }
        if (preg_match('/Mac OS X|macOS/i', $ua)) {
            return 'macOS';
        }
        if (preg_match('/CrOS/i', $ua)) {
            return 'ChromeOS';
        }
        if (preg_match('/Linux/i', $ua)) {
            return 'Linux';
        }
        return null;
    }

    private static function detectOsVersion(string $ua): ?string
    {
        if (preg_match('/Windows NT ([\d.]+)/i', $ua, $m)) {
            // Windows NT version → common market naming. 10.0 can be
            // either Win10 or Win11 because MS deliberately did not
            // bump the NT version — we honestly show it as "10/11".
            return match ($m[1]) {
                '10.0' => '10/11',
                '6.3' => '8.1',
                '6.2' => '8',
                '6.1' => '7',
                default => $m[1],
            };
        }
        if (preg_match('/Mac OS X ([\d_.]+)/i', $ua, $m)) {
            return str_replace('_', '.', $m[1]);
        }
        if (preg_match('/iPhone OS ([\d_]+)/i', $ua, $m)) {
            return str_replace('_', '.', $m[1]);
        }
        if (preg_match('/CPU OS ([\d_]+)/i', $ua, $m)) {
            return str_replace('_', '.', $m[1]);
        }
        if (preg_match('/Android ([\d.]+)/i', $ua, $m)) {
            return $m[1];
        }
        return null;
    }

    private static function detectBrowserName(string $ua): ?string
    {
        // Order matters: Edge before Chrome (Edge UA contains
        // "Chrome"), Chrome before Safari, Samsung before Chrome.
        if (preg_match('/Edg\//i', $ua)) {
            return 'Edge';
        }
        if (preg_match('/OPR\/|Opera/i', $ua)) {
            return 'Opera';
        }
        if (preg_match('/SamsungBrowser/i', $ua)) {
            return 'Samsung Internet';
        }
        if (preg_match('/Firefox/i', $ua)) {
            return 'Firefox';
        }
        if (preg_match('/Chrome/i', $ua)) {
            return 'Chrome';
        }
        if (preg_match('/Safari/i', $ua)) {
            return 'Safari';
        }
        return null;
    }

    private static function detectBrowserVersion(string $ua): ?string
    {
        if (preg_match('/Edg\/([\d.]+)/i', $ua, $m)) {
            return $m[1];
        }
        if (preg_match('/OPR\/([\d.]+)/i', $ua, $m)) {
            return $m[1];
        }
        if (preg_match('/SamsungBrowser\/([\d.]+)/i', $ua, $m)) {
            return $m[1];
        }
        if (preg_match('/Firefox\/([\d.]+)/i', $ua, $m)) {
            return $m[1];
        }
        if (preg_match('/Chrome\/([\d.]+)/i', $ua, $m)) {
            return $m[1];
        }
        if (preg_match('/Version\/([\d.]+).*Safari/i', $ua, $m)) {
            return $m[1];
        }
        return null;
    }
}
