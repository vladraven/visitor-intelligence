<?php

declare(strict_types=1);

namespace VisitorIntelligence\Device;

defined('ABSPATH') || exit;

final class DeviceDetector
{
    /**
     * @return array{
     *     device_type: ?string,
     *     browser: ?string,
     *     browser_version: ?string,
     *     os: ?string,
     *     os_version: ?string
     * }
     */
    public function detect(string $userAgent): array
    {
        $userAgent = trim($userAgent);

        if ($userAgent === '') {
            return [
                'device_type' => null,
                'browser' => null,
                'browser_version' => null,
                'os' => null,
                'os_version' => null,
            ];
        }

        $browser = $this->detectBrowser($userAgent);
        $operatingSystem = $this->detectOperatingSystem($userAgent);

        return [
            'device_type' => $this->detectDeviceType($userAgent),
            'browser' => $browser['name'],
            'browser_version' => $browser['version'],
            'os' => $operatingSystem['name'],
            'os_version' => $operatingSystem['version'],
        ];
    }

    private function detectDeviceType(string $userAgent): ?string
    {
        if ($this->matches($userAgent, [
            '/bot\b/i',
            '/crawler/i',
            '/spider/i',
            '/slurp/i',
            '/headless/i',
            '/curl\//i',
            '/wget\//i',
            '/python-requests/i',
        ])) {
            return 'bot';
        }

        if ($this->matches($userAgent, [
            '/iPad/i',
            '/Tablet/i',
            '/PlayBook/i',
            '/Silk/i',
            '/Kindle/i',
            '/Android(?!.*Mobile)/i',
        ])) {
            return 'tablet';
        }

        if ($this->matches($userAgent, [
            '/Mobi/i',
            '/Android/i',
            '/iPhone/i',
            '/iPod/i',
            '/Windows Phone/i',
            '/BlackBerry/i',
            '/BB10/i',
            '/Opera Mini/i',
            '/Opera Mobi/i',
        ])) {
            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * @return array{name: ?string, version: ?string}
     */
    private function detectBrowser(string $userAgent): array
    {
        $patterns = [
            'Edge' => [
                '/EdgA?\/([\d\.]+)/i',
                '/EdgiOS\/([\d\.]+)/i',
                '/Edg\/([\d\.]+)/i',
            ],
            'Opera' => [
                '/OPR\/([\d\.]+)/i',
                '/Opera Mini\/([\d\.]+)/i',
                '/Opera Mobi\/([\d\.]+)/i',
                '/Opera\/([\d\.]+)/i',
            ],
            'Samsung Internet' => [
                '/SamsungBrowser\/([\d\.]+)/i',
            ],
            'Chrome' => [
                '/(?:Chrome|CriOS)\/([\d\.]+)/i',
            ],
            'Firefox' => [
                '/(?:Firefox|FxiOS)\/([\d\.]+)/i',
            ],
            'Safari' => [
                '/Version\/([\d\.]+).*Safari\//i',
            ],
            'Internet Explorer' => [
                '/MSIE\s([\d\.]+)/i',
                '/Trident\/.*rv:([\d\.]+)/i',
            ],
            'Android Browser' => [
                '/Android.*Version\/([\d\.]+).*Safari\//i',
            ],
        ];

        foreach ($patterns as $name => $browserPatterns) {
            foreach ($browserPatterns as $pattern) {
                if (preg_match($pattern, $userAgent, $matches) === 1) {
                    return [
                        'name' => $name,
                        'version' => $this->normalizeVersion(
                            $matches[1] ?? null
                        ),
                    ];
                }
            }
        }

        return [
            'name' => null,
            'version' => null,
        ];
    }

    /**
     * @return array{name: ?string, version: ?string}
     */
    private function detectOperatingSystem(string $userAgent): array
    {
        if (preg_match('/Windows NT 10\.0/i', $userAgent) === 1) {
            return [
                'name' => 'Windows',
                'version' => '10/11',
            ];
        }

        if (preg_match('/Windows NT 6\.4/i', $userAgent) === 1) {
            return [
                'name' => 'Windows',
                'version' => '10/11',
            ];
        }

        $windows = [
            '6.3' => '8.1',
            '6.2' => '8',
            '6.1' => '7',
            '6.0' => 'Vista',
            '5.1' => 'XP',
            '5.2' => 'XP',
        ];

        if (preg_match('/Windows NT ([\d\.]+)/i', $userAgent, $matches) === 1) {
            return [
                'name' => 'Windows',
                'version' => $windows[$matches[1]] ?? $matches[1],
            ];
        }

        if (preg_match('/Windows Phone(?: OS)?[\s\/]([\d\.]+)/i', $userAgent, $matches) === 1) {
            return [
                'name' => 'Windows Phone',
                'version' => $this->normalizeVersion($matches[1]),
            ];
        }

        if (preg_match('/Android[\s\/]?([\d\.]+)?/i', $userAgent, $matches) === 1) {
            return [
                'name' => 'Android',
                'version' => $this->normalizeVersion(
                    $matches[1] ?? null
                ),
            ];
        }

        if (preg_match('/(?:iPhone OS|CPU iPhone OS)\s([\d_]+)/i', $userAgent, $matches) === 1) {
            return [
                'name' => 'iOS',
                'version' => str_replace(
                    '_',
                    '.',
                    $matches[1]
                ),
            ];
        }

        if (preg_match('/(?:iPad; CPU OS|CPU OS)\s([\d_]+)/i', $userAgent, $matches) === 1) {
            return [
                'name' => 'iOS',
                'version' => str_replace(
                    '_',
                    '.',
                    $matches[1]
                ),
            ];
        }

        if (preg_match('/Mac OS X\s*([\d_\.]+)?/i', $userAgent, $matches) === 1) {
            return [
                'name' => 'macOS',
                'version' => isset($matches[1])
                    && $matches[1] !== ''
                    ? str_replace(
                        '_',
                        '.',
                        $matches[1]
                    )
                    : null,
            ];
        }

        if (preg_match('/CrOS\s+[^\s;]+\s+([\d\.]+)/i', $userAgent, $matches) === 1) {
            return [
                'name' => 'ChromeOS',
                'version' => $this->normalizeVersion(
                    $matches[1]
                ),
            ];
        }

        if (preg_match('/Ubuntu/i', $userAgent) === 1) {
            return [
                'name' => 'Ubuntu',
                'version' => null,
            ];
        }

        if (preg_match('/(?:Linux|X11)/i', $userAgent) === 1) {
            return [
                'name' => 'Linux',
                'version' => null,
            ];
        }

        return [
            'name' => null,
            'version' => null,
        ];
    }

    /**
     * @param array<int, string> $patterns
     */
    private function matches(
        string $value,
        array $patterns
    ): bool {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeVersion(
        ?string $version
    ): ?string {
        if ($version === null) {
            return null;
        }

        $version = trim($version);

        return $version === ''
            ? null
            : $version;
    }
}