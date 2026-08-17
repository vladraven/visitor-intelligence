<?php

declare(strict_types=1);

namespace VisitorIntelligence\SourceAttribution;

use VisitorIntelligence\Core\Contracts\SourceDetectorInterface;

defined('ABSPATH') || exit;

final class SourceDetector implements SourceDetectorInterface
{
    private const SOURCE_PAID = 'paid';

    private const SOURCE_ORGANIC = 'organic';

    private const SOURCE_SOCIAL = 'social';

    private const SOURCE_EMAIL = 'email';

    private const SOURCE_REFERRAL = 'referral';

    private const SOURCE_DIRECT = 'direct';

    private const SOURCE_UNKNOWN = 'unknown';

    private const CONFIDENCE_HIGH = 'high';

    private const CONFIDENCE_MEDIUM = 'medium';

    private const CONFIDENCE_LOW = 'low';

    private const PRIORITY = 100;

    /**
     * @var array<string, string>
     */
    private const SEARCH_ENGINES = [
        'google.' => 'Google',
        'bing.com' => 'Bing',
        'duckduckgo.com' => 'DuckDuckGo',
        'search.yahoo.com' => 'Yahoo',
        'yahoo.' => 'Yahoo',
        'yandex.' => 'Yandex',
        'baidu.com' => 'Baidu',
        'ecosia.org' => 'Ecosia',
        'ecosia.com' => 'Ecosia',
        'search.brave.com' => 'Brave Search',
        'brave.com' => 'Brave Search',
        'naver.com' => 'Naver',
        'daum.net' => 'Daum',
        'seznam.cz' => 'Seznam',
        'qwant.com' => 'Qwant',
    ];

    /**
     * @var array<string, string>
     */
    private const SOCIAL_NETWORKS = [
        'facebook.com' => 'Facebook',
        'fb.com' => 'Facebook',
        'instagram.com' => 'Instagram',
        't.co' => 'X',
        'twitter.com' => 'X',
        'x.com' => 'X',
        'linkedin.com' => 'LinkedIn',
        'lnkd.in' => 'LinkedIn',
        'youtube.com' => 'YouTube',
        'youtu.be' => 'YouTube',
        'reddit.com' => 'Reddit',
        'redd.it' => 'Reddit',
        'pinterest.com' => 'Pinterest',
        'pin.it' => 'Pinterest',
        'tiktok.com' => 'TikTok',
        'threads.net' => 'Threads',
        'threads.com' => 'Threads',
        'snapchat.com' => 'Snapchat',
        'discord.com' => 'Discord',
        'discord.gg' => 'Discord',
        'telegram.me' => 'Telegram',
        't.me' => 'Telegram',
        'vk.com' => 'VK',
        'ok.ru' => 'Odnoklassniki',
    ];

    /**
     * @var array<string, string>
     */
    private const EMAIL_PROVIDERS = [
        'mail.google.com' => 'Gmail',
        'gmail.com' => 'Gmail',
        'outlook.live.com' => 'Outlook',
        'outlook.office.com' => 'Outlook',
        'outlook.com' => 'Outlook',
        'mail.yahoo.com' => 'Yahoo Mail',
        'mail.yahoo.co.uk' => 'Yahoo Mail',
        'icloud.com' => 'iCloud Mail',
        'mail.ru' => 'Mail.ru',
        'yandex.ru' => 'Yandex Mail',
        'yandex.com' => 'Yandex Mail',
        'proton.me' => 'Proton Mail',
        'protonmail.com' => 'Proton Mail',
        'zoho.com' => 'Zoho Mail',
    ];

    /**
     * @var string[]
     */
    private const PAID_MEDIUMS = [
        'cpc',
        'ppc',
        'paid',
        'ads',
        'advertising',
        'paidsearch',
        'paid_search',
        'paid-social',
        'paid_social',
        'display',
        'retargeting',
        'remarketing',
    ];

    /**
     * @var string[]
     */
    private const EMAIL_MEDIUMS = [
        'email',
        'newsletter',
        'e-mail',
        'mail',
    ];

    public function detect(
        array $context
    ): array {
        $normalized =
            $this->normalizeContext(
                $context
            );

        $referrerUrl =
            $normalized['referrer_url'];

        $referrerHost =
            $this->extractHost(
                $referrerUrl
            );

        $currentHost =
            $this->resolveCurrentHost(
                $normalized
            );

        $utmSource =
            $normalized['utm_source'];

        $utmMedium =
            $normalized['utm_medium'];

        $utmCampaign =
            $normalized['utm_campaign'];

        $gclid =
            $normalized['gclid'];

        $fbclid =
            $normalized['fbclid'];

        $msclkid =
            $normalized['msclkid'];

        /*
         * 1. Paid advertising.
         *
         * Click identifiers are authoritative paid signals.
         * UTM medium is also accepted according to the specification.
         */
        if (
            $gclid !== ''
            || $fbclid !== ''
            || $msclkid !== ''
            || $this->matchesPaidMedium(
                $utmMedium
            )
        ) {
            $sourceName =
                $this->resolvePaidSourceName(
                    $utmSource,
                    $gclid,
                    $fbclid,
                    $msclkid
                );

            return $this->result(
                self::SOURCE_PAID,
                $sourceName,
                $referrerHost,
                $referrerUrl,
                self::CONFIDENCE_HIGH,
                [
                    'detector' => 'paid',
                    'utm_source' => $utmSource !== ''
                        ? $utmSource
                        : null,
                    'utm_medium' => $utmMedium !== ''
                        ? $utmMedium
                        : null,
                    'utm_campaign' => $utmCampaign !== ''
                        ? $utmCampaign
                        : null,
                    'gclid' => $gclid !== ''
                        ? true
                        : false,
                    'fbclid' => $fbclid !== ''
                        ? true
                        : false,
                    'msclkid' => $msclkid !== ''
                        ? true
                        : false,
                ]
            );
        }

        /*
         * 2. Organic search.
         *
         * Search-engine referrer wins over generic referral,
         * provided no paid signal was detected above.
         */
        $searchEngine =
            $this->matchKnownDomain(
                $referrerHost,
                self::SEARCH_ENGINES
            );

        if (
            $searchEngine !== null
        ) {
            return $this->result(
                self::SOURCE_ORGANIC,
                $searchEngine,
                $referrerHost,
                $referrerUrl,
                self::CONFIDENCE_HIGH,
                [
                    'detector' => 'search_engine',
                    'search_engine' => $searchEngine,
                ]
            );
        }

        /*
         * 3. Social networks.
         */
        $socialNetwork =
            $this->matchKnownDomain(
                $referrerHost,
                self::SOCIAL_NETWORKS
            );

        if (
            $socialNetwork !== null
        ) {
            return $this->result(
                self::SOURCE_SOCIAL,
                $socialNetwork,
                $referrerHost,
                $referrerUrl,
                self::CONFIDENCE_HIGH,
                [
                    'detector' => 'social',
                    'social_network' => $socialNetwork,
                ]
            );
        }

        /*
         * 4. Email.
         *
         * UTM medium has priority over webmail referrer because
         * campaign tagging is an explicit attribution signal.
         */
        if (
            $this->matchesEmailMedium(
                $utmMedium
            )
        ) {
            return $this->result(
                self::SOURCE_EMAIL,
                $this->normalizeSourceName(
                    $utmSource
                ),
                $referrerHost,
                $referrerUrl,
                self::CONFIDENCE_HIGH,
                [
                    'detector' => 'email_utm',
                    'utm_source' => $utmSource !== ''
                        ? $utmSource
                        : null,
                    'utm_medium' => $utmMedium,
                ]
            );
        }

        $emailProvider =
            $this->matchKnownDomain(
                $referrerHost,
                self::EMAIL_PROVIDERS
            );

        if (
            $emailProvider !== null
        ) {
            return $this->result(
                self::SOURCE_EMAIL,
                $emailProvider,
                $referrerHost,
                $referrerUrl,
                self::CONFIDENCE_MEDIUM,
                [
                    'detector' => 'webmail',
                    'email_provider' => $emailProvider,
                ]
            );
        }

        /*
         * 5. External referral.
         *
         * A same-site referrer is not an external acquisition source.
         */
        if (
            $referrerHost !== ''
            && $currentHost !== ''
            && !$this->sameDomain(
                $referrerHost,
                $currentHost
            )
        ) {
            return $this->result(
                self::SOURCE_REFERRAL,
                $this->normalizeSourceName(
                    $referrerHost
                ),
                $referrerHost,
                $referrerUrl,
                self::CONFIDENCE_HIGH,
                [
                    'detector' => 'external_referral',
                    'current_host' => $currentHost,
                ]
            );
        }

        /*
         * 6. Direct.
         *
         * Empty HTTP Referer is the canonical direct signal.
         */
        if (
            $referrerUrl === ''
        ) {
            return $this->result(
                self::SOURCE_DIRECT,
                null,
                null,
                null,
                self::CONFIDENCE_HIGH,
                [
                    'detector' => 'direct',
                ]
            );
        }

        /*
         * Same-site navigation without a recognized acquisition
         * source is not external referral and is therefore unknown
         * rather than direct.
         */
        if (
            $referrerHost !== ''
            && $currentHost !== ''
            && $this->sameDomain(
                $referrerHost,
                $currentHost
            )
        ) {
            return $this->result(
                self::SOURCE_UNKNOWN,
                null,
                $referrerHost,
                $referrerUrl,
                self::CONFIDENCE_LOW,
                [
                    'detector' => 'internal_referrer',
                    'current_host' => $currentHost,
                ]
            );
        }

        return $this->result(
            self::SOURCE_UNKNOWN,
            null,
            $referrerHost !== ''
                ? $referrerHost
                : null,
            $referrerUrl !== ''
                ? $referrerUrl
                : null,
            self::CONFIDENCE_LOW,
            [
                'detector' => 'unknown',
            ]
        );
    }

    public function supports(
        array $context
    ): bool {
        if (
            $context === []
        ) {
            return false;
        }

        foreach (
            [
                'referrer_url',
                'referer',
                'http_referer',
                'current_url',
                'url',
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'gclid',
                'fbclid',
                'msclkid',
            ] as $key
        ) {
            if (
                array_key_exists(
                    $key,
                    $context
                )
            ) {
                return true;
            }
        }

        return false;
    }

    public function getSourceType(): string
    {
        return 'attribution';
    }

    public function getPriority(): int
    {
        return self::PRIORITY;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *     referrer_url: string,
     *     current_url: string,
     *     current_host: string,
     *     utm_source: string,
     *     utm_medium: string,
     *     utm_campaign: string,
     *     gclid: string,
     *     fbclid: string,
     *     msclkid: string
     * }
     */
    private function normalizeContext(
        array $context
    ): array {
        $referrer =
            $this->firstString(
                $context,
                [
                    'referrer_url',
                    'referer',
                    'http_referer',
                ]
            );

        $currentUrl =
            $this->firstString(
                $context,
                [
                    'current_url',
                    'url',
                ]
            );

        return [
            'referrer_url' =>
                $this->normalizeUrl(
                    $referrer
                ),

            'current_url' =>
                $this->normalizeUrl(
                    $currentUrl
                ),

            'current_host' =>
                $this->extractHost(
                    $currentUrl
                ),

            'utm_source' =>
                $this->normalizeParameter(
                    $this->firstString(
                        $context,
                        [
                            'utm_source',
                        ]
                    )
                ),

            'utm_medium' =>
                $this->normalizeParameter(
                    $this->firstString(
                        $context,
                        [
                            'utm_medium',
                        ]
                    )
                ),

            'utm_campaign' =>
                $this->normalizeParameter(
                    $this->firstString(
                        $context,
                        [
                            'utm_campaign',
                        ]
                    )
                ),

            'gclid' =>
                $this->normalizeParameter(
                    $this->firstString(
                        $context,
                        [
                            'gclid',
                        ]
                    )
                ),

            'fbclid' =>
                $this->normalizeParameter(
                    $this->firstString(
                        $context,
                        [
                            'fbclid',
                        ]
                    )
                ),

            'msclkid' =>
                $this->normalizeParameter(
                    $this->firstString(
                        $context,
                        [
                            'msclkid',
                        ]
                    )
                ),
        ];
    }

    private function resolveCurrentHost(
        array $context
    ): string {
        if (
            $context['current_host'] !== ''
        ) {
            return $context['current_host'];
        }

        $currentHost =
            $this->extractHost(
                $context['current_url']
            );

        if (
            $currentHost !== ''
        ) {
            return $currentHost;
        }

        $homeUrl =
            function_exists(
                'home_url'
            )
                ? home_url('/')
                : '';

        return $this->extractHost(
            $homeUrl
        );
    }

    private function resolvePaidSourceName(
        string $utmSource,
        string $gclid,
        string $fbclid,
        string $msclkid
    ): ?string {
        if (
            $utmSource !== ''
        ) {
            return $this->normalizeSourceName(
                $utmSource
            );
        }

        if (
            $gclid !== ''
        ) {
            return 'Google Ads';
        }

        if (
            $fbclid !== ''
        ) {
            return 'Meta Ads';
        }

        if (
            $msclkid !== ''
        ) {
            return 'Microsoft Ads';
        }

        return null;
    }

    private function matchesPaidMedium(
        string $medium
    ): bool {
        if (
            $medium === ''
        ) {
            return false;
        }

        $normalized =
            strtolower(
                trim(
                    $medium
                )
            );

        foreach (
            self::PAID_MEDIUMS as $candidate
        ) {
            if (
                $normalized ===
                strtolower(
                    $candidate
                )
            ) {
                return true;
            }
        }

        return preg_match(
            '/(?:cpc|ppc|paid|ads)/i',
            $normalized
        ) === 1;
    }

    private function matchesEmailMedium(
        string $medium
    ): bool {
        if (
            $medium === ''
        ) {
            return false;
        }

        $normalized =
            strtolower(
                trim(
                    $medium
                )
            );

        foreach (
            self::EMAIL_MEDIUMS as $candidate
        ) {
            if (
                $normalized ===
                strtolower(
                    $candidate
                )
            ) {
                return true;
            }
        }

        return preg_match(
            '/(?:email|newsletter)/i',
            $normalized
        ) === 1;
    }

    /**
     * @param array<string, string> $knownDomains
     */
    private function matchKnownDomain(
        string $host,
        array $knownDomains
    ): ?string {
        if (
            $host === ''
        ) {
            return null;
        }

        foreach (
            $knownDomains as $domain => $name
        ) {
            if (
                $this->domainMatches(
                    $host,
                    $domain
                )
            ) {
                return $name;
            }
        }

        return null;
    }

    private function domainMatches(
        string $host,
        string $knownDomain
    ): bool {
        $host =
            strtolower(
                trim(
                    $host
                )
            );

        $knownDomain =
            strtolower(
                trim(
                    $knownDomain
                )
            );

        if (
            $host === ''
            || $knownDomain === ''
        ) {
            return false;
        }

        if (
            $host === $knownDomain
        ) {
            return true;
        }

        if (
            str_starts_with(
                $knownDomain,
                '.'
            )
        ) {
            $knownDomain =
                ltrim(
                    $knownDomain,
                    '.'
                );
        }

        return str_ends_with(
            $host,
            '.' . $knownDomain
        );
    }

    private function sameDomain(
        string $left,
        string $right
    ): bool {
        $left =
            strtolower(
                trim(
                    $left
                )
            );

        $right =
            strtolower(
                trim(
                    $right
                )
            );

        if (
            $left === ''
            || $right === ''
        ) {
            return false;
        }

        return (
            $left === $right
            || $this->isSubdomainOf(
                $left,
                $right
            )
            || $this->isSubdomainOf(
                $right,
                $left
            )
        );
    }

    private function isSubdomainOf(
        string $host,
        string $parent
    ): bool {
        return str_ends_with(
            $host,
            '.' . ltrim(
                $parent,
                '.'
            )
        );
    }

    private function extractHost(
        string $url
    ): string {
        $url =
            trim(
                $url
            );

        if (
            $url === ''
        ) {
            return '';
        }

        $parts =
            wp_parse_url(
                $url
            );

        if (
            !is_array(
                $parts
            )
            || !isset(
                $parts['host']
            )
        ) {
            return '';
        }

        $host =
            strtolower(
                trim(
                    (string) $parts['host']
                )
            );

        return rtrim(
            $host,
            '.'
        );
    }

    private function normalizeUrl(
        string $url
    ): string {
        $url =
            trim(
                $url
            );

        if (
            $url === ''
        ) {
            return '';
        }

        $url =
            esc_url_raw(
                $url
            );

        return is_string(
            $url
        )
            ? trim(
                $url
            )
            : '';
    }

    private function normalizeParameter(
        string $value
    ): string {
        $value =
            trim(
                $value
            );

        if (
            $value === ''
        ) {
            return '';
        }

        return substr(
            $value,
            0,
            255
        );
    }

    private function normalizeSourceName(
        string $value
    ): ?string {
        $value =
            trim(
                $value
            );

        if (
            $value === ''
        ) {
            return null;
        }

        return substr(
            $value,
            0,
            128
        );
    }

    /**
     * @param array<string, mixed> $context
     * @param string[] $keys
     */
    private function firstString(
        array $context,
        array $keys
    ): string {
        foreach (
            $keys as $key
        ) {
            if (
                !array_key_exists(
                    $key,
                    $context
                )
            ) {
                continue;
            }

            if (
                !is_scalar(
                    $context[$key]
                )
            ) {
                continue;
            }

            $value =
                trim(
                    (string) $context[$key]
                );

            if (
                $value !== ''
            ) {
                return $value;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function result(
        string $sourceType,
        ?string $sourceName,
        ?string $sourceDomain,
        ?string $referrerUrl,
        string $confidence,
        array $signals
    ): array {
        return [
            'source_type' =>
                $sourceType,

            'source_name' =>
                $sourceName,

            'source_domain' =>
                $sourceDomain,

            'referrer_url' =>
                $referrerUrl,

            'confidence' =>
                $confidence,

            'signals' =>
                $signals,
        ];
    }
}