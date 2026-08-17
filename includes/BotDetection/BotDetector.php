<?php

declare(strict_types=1);

namespace VisitorIntelligence\BotDetection;

use VisitorIntelligence\Core\Contracts\BotDetectorInterface;

defined('ABSPATH') || exit;

final class BotDetector implements BotDetectorInterface
{
    private const KNOWN_BOTS = [
        'bot',
        'crawl',
        'spider',
        'slurp',
        'search',
        'curl',
        'python',
        'wget',
        'selenium',
        'puppeteer',
        'phantomjs',
        'headless',
    ];

    private const SIGNAL_WEIGHTS = [
        'known_bot_ua' => 100,
        'missing_accept' => 30,
        'missing_accept_language' => 30,
        'high_request_rate' => 40,
        'no_browser_collector' => 20,
        'headless_webdriver' => 80,
        'rapid_navigation' => 35,
    ];

    private const BOT_THRESHOLD = 60;

    private const SUSPICIOUS_THRESHOLD = 20;

    private const HIGH_REQUEST_RATE_THRESHOLD = 30;

    private const RAPID_NAVIGATION_THRESHOLD_MS = 200;

    public function analyze(array $context): array
    {
        $signals =
            $this->getSignals(
                $context
            );

        $score =
            $this->calculateScore(
                $signals
            );

        return [
            'score' =>
                $score,

            'classification' =>
                $this->classify(
                    $context,
                    $score
                ),

            'signals' =>
                $signals,

            'js_detected' =>
                $this->getJsDetected(
                    $context
                ),

            'analyzed_at' =>
                gmdate(
                    'Y-m-d H:i:s'
                ),
        ];
    }

    public function getScore(
        array $context
    ): int {
        return $this->calculateScore(
            $this->getSignals(
                $context
            )
        );
    }

    public function getClassification(
        array $context
    ): string {
        $signals =
            $this->getSignals(
                $context
            );

        $score =
            $this->calculateScore(
                $signals
            );

        return $this->classify(
            $context,
            $score
        );
    }

    public function getSignals(
        array $context
    ): array {
        $signals = [];

        $userAgent =
            $this->getString(
                $context,
                'user_agent'
            );

        $accept =
            $this->getString(
                $context,
                'accept'
            );

        $acceptLanguage =
            $this->getString(
                $context,
                'accept_language'
            );

        if (
            $this->isKnownBotUserAgent(
                $userAgent
            )
        ) {
            $signals['known_bot_ua'] =
                self::SIGNAL_WEIGHTS[
                    'known_bot_ua'
                ];
        }

        if (
            $this->isKnownContextValue(
                $context,
                'accept'
            )
            && $accept === ''
        ) {
            $signals['missing_accept'] =
                self::SIGNAL_WEIGHTS[
                    'missing_accept'
                ];
        }

        if (
            $this->isKnownContextValue(
                $context,
                'accept_language'
            )
            && $acceptLanguage === ''
        ) {
            $signals['missing_accept_language'] =
                self::SIGNAL_WEIGHTS[
                    'missing_accept_language'
                ];
        }

        $requestRate =
            $this->getNumeric(
                $context,
                'requests_last_10_seconds'
            );

        if (
            $requestRate !== null
            && $requestRate >
                self::HIGH_REQUEST_RATE_THRESHOLD
        ) {
            $signals['high_request_rate'] =
                self::SIGNAL_WEIGHTS[
                    'high_request_rate'
                ];
        }

        if (
            array_key_exists(
                'js_executed',
                $context
            )
            && $context['js_executed'] === false
        ) {
            $signals['no_browser_collector'] =
                self::SIGNAL_WEIGHTS[
                    'no_browser_collector'
                ];
        }

        if (
            array_key_exists(
                'webdriver',
                $context
            )
            && $this->isTruthy(
                $context['webdriver']
            )
        ) {
            $signals['headless_webdriver'] =
                self::SIGNAL_WEIGHTS[
                    'headless_webdriver'
                ];
        }

        $navigationDelta =
            $this->getNumeric(
                $context,
                'navigation_delta_ms'
            );

        if (
            $navigationDelta !== null
            && $navigationDelta >= 0
            && $navigationDelta <
                self::RAPID_NAVIGATION_THRESHOLD_MS
        ) {
            $signals['rapid_navigation'] =
                self::SIGNAL_WEIGHTS[
                    'rapid_navigation'
                ];
        }

        return $signals;
    }

    private function calculateScore(
        array $signals
    ): int {
        $score = 0;

        foreach (
            $signals as $weight
        ) {
            if (
                !is_numeric(
                    $weight
                )
            ) {
                continue;
            }

            $score += max(
                0,
                (int) $weight
            );
        }

        return min(
            100,
            $score
        );
    }

    private function classify(
        array $context,
        int $score
    ): string {
        if (
            $score >=
            self::BOT_THRESHOLD
        ) {
            return 'bot';
        }

        if (
            $score >=
            self::SUSPICIOUS_THRESHOLD
        ) {
            return 'suspicious';
        }

        if (
            $this->hasSufficientData(
                $context
            )
        ) {
            return 'human';
        }

        return 'unknown';
    }

    private function hasSufficientData(
        array $context
    ): bool {
        if ($context === []) {
            return false;
        }

        $knownFields = 0;

        foreach (
            [
                'user_agent',
                'accept',
                'accept_language',
                'js_executed',
                'webdriver',
                'requests_last_10_seconds',
                'navigation_delta_ms',
            ] as $key
        ) {
            if (
                !array_key_exists(
                    $key,
                    $context
                )
            ) {
                continue;
            }

            $knownFields++;

            if (
                $key === 'user_agent'
                && trim(
                    (string) $context[$key]
                ) !== ''
            ) {
                return true;
            }

            if (
                $key === 'js_executed'
            ) {
                return true;
            }

            if (
                $key === 'webdriver'
            ) {
                return true;
            }

            if (
                $key === 'requests_last_10_seconds'
                && is_numeric(
                    $context[$key]
                )
            ) {
                return true;
            }

            if (
                $key === 'navigation_delta_ms'
                && is_numeric(
                    $context[$key]
                )
            ) {
                return true;
            }

            if (
                in_array(
                    $key,
                    [
                        'accept',
                        'accept_language',
                    ],
                    true
                )
                && trim(
                    (string) $context[$key]
                ) !== ''
            ) {
                return true;
            }
        }

        return $knownFields > 0;
    }

    private function getJsDetected(
        array $context
    ): ?bool {
        if (
            !array_key_exists(
                'js_executed',
                $context
            )
        ) {
            return null;
        }

        if (
            is_bool(
                $context['js_executed']
            )
        ) {
            return $context['js_executed'];
        }

        if (
            is_string(
                $context['js_executed']
            )
        ) {
            $value =
                strtolower(
                    trim(
                        $context['js_executed']
                    )
                );

            if (
                in_array(
                    $value,
                    [
                        '1',
                        'true',
                        'yes',
                        'on',
                    ],
                    true
                )
            ) {
                return true;
            }

            if (
                in_array(
                    $value,
                    [
                        '0',
                        'false',
                        'no',
                        'off',
                    ],
                    true
                )
            ) {
                return false;
            }
        }

        return null;
    }

    private function isKnownBotUserAgent(
        string $userAgent
    ): bool {
        if (
            $userAgent === ''
        ) {
            return false;
        }

        foreach (
            self::KNOWN_BOTS as $pattern
        ) {
            if (
                str_contains(
                    $userAgent,
                    $pattern
                )
            ) {
                return true;
            }
        }

        return false;
    }

    private function isKnownContextValue(
        array $context,
        string $key
    ): bool {
        return array_key_exists(
            $key,
            $context
        );
    }

    private function getString(
        array $context,
        string $key
    ): string {
        if (
            !array_key_exists(
                $key,
                $context
            )
        ) {
            return '';
        }

        if (
            !is_scalar(
                $context[$key]
            )
        ) {
            return '';
        }

        return trim(
            (string) $context[$key]
        );
    }

    private function getNumeric(
        array $context,
        string $key
    ): ?float {
        if (
            !array_key_exists(
                $key,
                $context
            )
            || !is_numeric(
                $context[$key]
            )
        ) {
            return null;
        }

        return (float) $context[$key];
    }

    private function isTruthy(
        mixed $value
    ): bool {
        if (
            is_bool($value)
        ) {
            return $value;
        }

        if (
            is_int($value)
            || is_float($value)
        ) {
            return $value !== 0;
        }

        if (
            is_string($value)
        ) {
            return in_array(
                strtolower(
                    trim($value)
                ),
                [
                    '1',
                    'true',
                    'yes',
                    'on',
                ],
                true
            );
        }

        return false;
    }
}