<?php

declare(strict_types=1);

namespace VisitorIntelligence\Collection;

use VisitorIntelligence\BotDetection\BotDetector;
use VisitorIntelligence\Core\Config;
use VisitorIntelligence\Core\Contracts\SourceDetectorInterface;
use VisitorIntelligence\Database\Repositories\PageviewRepository;
use VisitorIntelligence\Device\DeviceDetector;
use VisitorIntelligence\GeoIP\GeoIpManager;
use VisitorIntelligence\Identity\VisitorManager;
use VisitorIntelligence\Sessions\SessionManager;

defined('ABSPATH') || exit;

final class ServerCollector
{
    /**
     * @var array{
     *     endpoint: string,
     *     visitorId: string,
     *     sessionId: string,
     *     pageviewId: string,
     *     trackingMode: string,
     *     sourceType: string,
     *     sourceName: ?string,
     *     sourceDomain: ?string,
     *     sourceConfidence: string
     * }|null
     */
    private ?array $clientContext = null;

    private bool $handled = false;

    public function __construct(
        private readonly VisitorManager $visitors,
        private readonly SessionManager $sessions,
        private readonly PageviewRepository $pageviews,
        private readonly GeoIpManager $geoIp,
        private readonly BotDetector $bots,
        private readonly DeviceDetector $deviceDetector,
        private readonly SourceDetectorInterface $sources
    ) {
    }

    public function handleServerRequest(): void
    {
        if ($this->handled) {
            return;
        }

        if (
            !(bool) Config::get(
                'enabled',
                true
            )
            || !(bool) Config::get(
                'tracking_enabled',
                true
            )
        ) {
            $this->handled = true;

            return;
        }

        if (
            (bool) Config::get(
                'respect_dnt',
                true
            )
            && $this->hasDoNotTrack()
        ) {
            $this->handled = true;

            return;
        }

        if (!$this->isTrackableRequest()) {
            $this->handled = true;

            return;
        }

        try {
            $this->collect();
        } catch (\Throwable $exception) {
            $this->clientContext = null;

            $this->reportError(
                $exception
            );

            throw $exception;
        }

        $this->handled = true;
    }

    /**
     * @return array{
     *     endpoint: string,
     *     visitorId: string,
     *     sessionId: string,
     *     pageviewId: string,
     *     trackingMode: string,
     *     sourceType: string,
     *     sourceName: ?string,
     *     sourceDomain: ?string,
     *     sourceConfidence: string
     * }|null
     */
    public function getClientContext(): ?array
    {
        if (!$this->handled) {
            $this->handleServerRequest();
        }

        return $this->clientContext;
    }

    private function collect(): void
    {
        $visitorId =
            $this->visitors->resolveVisitorId();

        $this->assertIdentifier(
            $visitorId,
            'VisitorManager'
        );

        $sessionId =
            $this->sessions->resolveSessionId(
                $visitorId
            );

        $this->assertIdentifier(
            $sessionId,
            'SessionManager'
        );

        $userAgent =
            $this->requestHeader(
                'HTTP_USER_AGENT',
                512
            );

        $acceptLanguage =
            $this->requestHeader(
                'HTTP_ACCEPT_LANGUAGE',
                128
            );

        $accept =
            $this->requestHeader(
                'HTTP_ACCEPT',
                512
            );

        $url =
            $this->currentUrl();

        if ($url === '') {
            throw new \RuntimeException(
                'Unable to resolve current request URL.'
            );
        }

        $referrer =
            $this->requestHeader(
                'HTTP_REFERER',
                2048
            );

        if ($referrer !== '') {
            $referrer =
                esc_url_raw(
                    $referrer
                );
        }

        $bot =
            $this->bots->analyze(
                [
                    'user_agent' =>
                        $userAgent,

                    'accept_language' =>
                        $acceptLanguage,

                    'accept' =>
                        $accept,
                ]
            );

        $clientIp =
            $this->clientIp();

        $geo =
            $this->geoIp->lookup(
                $clientIp
            );

        $botScore =
            isset(
                $bot['score']
            )
                ? max(
                    0,
                    min(
                        100,
                        (int) $bot['score']
                    )
                )
                : 0;

        $botClassification =
            isset(
                $bot['classification']
            )
            && is_string(
                $bot['classification']
            )
                ? trim(
                    $bot['classification']
                )
                : 'unknown';

        if (
            $botClassification === ''
        ) {
            $botClassification =
                'unknown';
        }

        $sourceContext =
            $this->buildSourceContext(
                $url,
                $referrer
            );

        if (
            !$this->sources->supports(
                $sourceContext
            )
        ) {
            throw new \RuntimeException(
                'Source detector does not support the current request context.'
            );
        }

        $source =
            $this->sources->detect(
                $sourceContext
            );

        $sourceType =
            $this->sourceString(
                $source,
                'source_type',
                'unknown'
            );

        $sourceName =
            $this->sourceNullableString(
                $source,
                'source_name'
            );

        $sourceDomain =
            $this->sourceNullableString(
                $source,
                'source_domain'
            );

        $sourceConfidence =
            $this->sourceString(
                $source,
                'confidence',
                'low'
            );

        $this->validateSourceType(
            $sourceType
        );

        $this->validateConfidence(
            $sourceConfidence
        );

        $trackingMode =
            $this->resolveTrackingMode();

        $device =
            $this->deviceDetector->detect(
                $userAgent
            );

        $this->visitors->touch(
            $visitorId,
            [
                'country_code' =>
                    $geo['country_code']
                    ?? null,

                'country_name' =>
                    $geo['country_name']
                    ?? null,

                'region_code' =>
                    $geo['region_code']
                    ?? null,

                'region_name' =>
                    $geo['region_name']
                    ?? null,

                'city' =>
                    $geo['city']
                    ?? null,

                'latitude' =>
                    $geo['latitude']
                    ?? null,

                'longitude' =>
                    $geo['longitude']
                    ?? null,

                'geo_source' =>
                    $geo['source']
                    ?? null,

                'geo_database_version' =>
                    $geo['database_version']
                    ?? null,

                'device_type' =>
                    $device['device_type']
                    ?? null,

                'browser' =>
                    $device['browser']
                    ?? null,

                'browser_version' =>
                    $device['browser_version']
                    ?? null,

                'os' =>
                    $device['os']
                    ?? null,

                'os_version' =>
                    $device['os_version']
                    ?? null,

                'bot_score' =>
                    $botScore,

                'bot_classification' =>
                    $botClassification,
            ]
        );

        $this->sessions->ensureSession(
            $sessionId,
            $visitorId,
            [
                'landing_url' =>
                    $url,

                'referrer_url' =>
                    $referrer !== ''
                        ? $referrer
                        : null,

                'source_type' =>
                    $sourceType,

                'source_name' =>
                    $sourceName,

                'source_domain' =>
                    $sourceDomain,

                'country_code' =>
                    $geo['country_code']
                    ?? null,

                'country_name' =>
                    $geo['country_name']
                    ?? null,

                'region_code' =>
                    $geo['region_code']
                    ?? null,

                'region_name' =>
                    $geo['region_name']
                    ?? null,

                'city' =>
                    $geo['city']
                    ?? null,

                'latitude' =>
                    $geo['latitude']
                    ?? null,

                'longitude' =>
                    $geo['longitude']
                    ?? null,

                'geo_source' =>
                    $geo['source']
                    ?? null,

                'geo_database_version' =>
                    $geo['database_version']
                    ?? null,

                'bot_score' =>
                    $botScore,

                'bot_classification' =>
                    $botClassification,

                'tracking_mode' =>
                    $trackingMode,
            ]
        );

        $pageviewId =
            wp_generate_uuid4();

        $this->assertIdentifier(
            $pageviewId,
            'WordPress UUID generator'
        );

        $created =
            $this->pageviews->createForSession(
                $pageviewId,
                $visitorId,
                $sessionId,
                [
                    'url' =>
                        $url,

                    'post_id' =>
                        $this->queriedPostId(),

                    'referrer_url' =>
                        $referrer !== ''
                            ? $referrer
                            : null,

                    'is_landing' =>
                        1,

                    'bot_score' =>
                        $botScore,

                    'bot_classification' =>
                        $botClassification,
                ]
            );

        if (
            $created < 1
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Pageview creation returned an invalid database result: %s',
                    $pageviewId
                )
            );
        }

        if (
            !$this->pageviews->existsById(
                $pageviewId
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Pageview was not persisted: %s',
                    $pageviewId
                )
            );
        }

        $this->clientContext = [
            'endpoint' =>
                rest_url(
                    'vi/v1/collect'
                ),

            'visitorId' =>
                $visitorId,

            'sessionId' =>
                $sessionId,

            'pageviewId' =>
                $pageviewId,

            'trackingMode' =>
                $trackingMode,

            'sourceType' =>
                $sourceType,

            'sourceName' =>
                $sourceName,

            'sourceDomain' =>
                $sourceDomain,

            'sourceConfidence' =>
                $sourceConfidence,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSourceContext(
        string $currentUrl,
        string $referrer
    ): array {
        $context = [
            'current_url' =>
                $currentUrl,

            'referrer_url' =>
                $referrer,

            'user_agent' =>
                $this->requestHeader(
                    'HTTP_USER_AGENT',
                    512
                ),
        ];

        foreach (
            [
                'utm_source',
                'utm_medium',
                'utm_campaign',
                'utm_term',
                'utm_content',
                'gclid',
                'fbclid',
                'msclkid',
            ] as $parameter
        ) {
            if (
                isset(
                    $_GET[$parameter]
                )
            ) {
                $context[$parameter] =
                    sanitize_text_field(
                        wp_unslash(
                            (string) $_GET[$parameter]
                        )
                    );
            }
        }

        return $context;
    }

    private function isTrackableRequest(): bool
    {
        if (
            defined('REST_REQUEST')
            && REST_REQUEST
        ) {
            return false;
        }

        if (
            wp_doing_ajax()
        ) {
            return false;
        }

        if (
            function_exists(
                'wp_doing_cron'
            )
            && wp_doing_cron()
        ) {
            return false;
        }

        if (
            is_admin()
            && !wp_doing_ajax()
        ) {
            return false;
        }

        $method =
            isset(
                $_SERVER['REQUEST_METHOD']
            )
                ? strtoupper(
                    (string) $_SERVER['REQUEST_METHOD']
                )
                : 'GET';

        return in_array(
            $method,
            [
                'GET',
                'HEAD',
            ],
            true
        );
    }

    private function currentUrl(): string
    {
        $uri =
            isset(
                $_SERVER['REQUEST_URI']
            )
                ? wp_unslash(
                    (string) $_SERVER['REQUEST_URI']
                )
                : '/';

        if (
            $uri === ''
        ) {
            $uri = '/';
        }

        $url =
            home_url(
                $uri
            );

        $url =
            esc_url_raw(
                $url
            );

        return is_string(
            $url
        )
            ? $url
            : '';
    }

    private function queriedPostId(): ?int
    {
        $postId =
            get_queried_object_id();

        if (
            !is_int(
                $postId
            )
            && !is_numeric(
                $postId
            )
        ) {
            return null;
        }

        $postId =
            (int) $postId;

        return $postId > 0
            ? $postId
            : null;
    }

    private function clientIp(): string
    {
        $remoteAddress =
            isset(
                $_SERVER['REMOTE_ADDR']
            )
                ? trim(
                    (string) $_SERVER['REMOTE_ADDR']
                )
                : '';

        if (
            filter_var(
                $remoteAddress,
                FILTER_VALIDATE_IP
            )
        ) {
            return $remoteAddress;
        }

        return '';
    }

    private function requestHeader(
        string $serverKey,
        int $maxLength
    ): string {
        if (
            !isset(
                $_SERVER[$serverKey]
            )
        ) {
            return '';
        }

        $value =
            wp_unslash(
                (string) $_SERVER[$serverKey]
            );

        $value =
            sanitize_text_field(
                $value
            );

        return substr(
            $value,
            0,
            $maxLength
        );
    }

    private function hasDoNotTrack(): bool
    {
        return isset(
            $_SERVER['HTTP_DNT']
        )
        && trim(
            (string) $_SERVER['HTTP_DNT']
        ) === '1';
    }

    private function resolveTrackingMode(): string
    {
        $trackingMode =
            strtolower(
                trim(
                    (string) Config::get(
                        'tracking_mode',
                        'full'
                    )
                )
            );

        if (
            $trackingMode === ''
        ) {
            return 'full';
        }

        if (
            !in_array(
                $trackingMode,
                [
                    'full',
                    'server_only',
                ],
                true
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Invalid tracking mode: %s',
                    $trackingMode
                )
            );
        }

        return $trackingMode;
    }

    private function assertIdentifier(
        string $value,
        string $source
    ): void {
        $value =
            trim(
                $value
            );

        if (
            preg_match(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $value
            ) !== 1
        ) {
            throw new \RuntimeException(
                sprintf(
                    '%s returned an invalid UUID.',
                    $source
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $source
     */
    private function sourceString(
        array $source,
        string $key,
        string $default
    ): string {
        if (
            !isset(
                $source[$key]
            )
            || !is_scalar(
                $source[$key]
            )
        ) {
            return $default;
        }

        $value =
            trim(
                (string) $source[$key]
            );

        return $value !== ''
            ? $value
            : $default;
    }

    /**
     * @param array<string, mixed> $source
     */
    private function sourceNullableString(
        array $source,
        string $key
    ): ?string {
        if (
            !array_key_exists(
                $key,
                $source
            )
            || $source[$key] === null
        ) {
            return null;
        }

        if (
            !is_scalar(
                $source[$key]
            )
        ) {
            return null;
        }

        $value =
            trim(
                (string) $source[$key]
            );

        return $value !== ''
            ? $value
            : null;
    }

    private function validateSourceType(
        string $sourceType
    ): void {
        if (
            !in_array(
                $sourceType,
                [
                    'paid',
                    'organic',
                    'social',
                    'email',
                    'referral',
                    'direct',
                    'unknown',
                ],
                true
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Source detector returned invalid source type: %s',
                    $sourceType
                )
            );
        }
    }

    private function validateConfidence(
        string $confidence
    ): void {
        if (
            !in_array(
                $confidence,
                [
                    'high',
                    'medium',
                    'low',
                ],
                true
            )
        ) {
            throw new \RuntimeException(
                sprintf(
                    'Source detector returned invalid confidence: %s',
                    $confidence
                )
            );
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function reportError(
        \Throwable $exception,
        array $context = []
    ): void {
        do_action(
            'vi_server_collector_error',
            $exception,
            $context
        );
    }
}