<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core;

defined('ABSPATH') || exit;

final class Logger
{
    private const PREFIX = '[Visitor Intelligence]';

    private const LEVEL_DEBUG = 'DEBUG';
    private const LEVEL_INFO = 'INFO';
    private const LEVEL_WARNING = 'WARNING';
    private const LEVEL_ERROR = 'ERROR';

    private const DEFAULT_MAX_MESSAGE_LENGTH = 4000;
    private const DEFAULT_MAX_CONTEXT_DEPTH = 6;
    private const DEFAULT_MAX_CONTEXT_ITEMS = 100;

    private const SENSITIVE_KEYS = [
        'ip',
        'ip_address',
        'remote_addr',
        'raw_ip',
        'visitor_id',
        'session_id',
        'pageview_id',
        'event_id',
        'email',
        'user_email',
        'authorization',
        'cookie',
        'cookies',
        'set_cookie',
        'token',
        'access_token',
        'refresh_token',
        'password',
        'passwd',
        'secret',
        'api_key',
        'apikey',
        'private_key',
        'client_secret',
        'session_token',
        'csrf_token',
        'nonce',
    ];

    private const SENSITIVE_KEY_PATTERNS = [
        '/(?:^|_)(?:token|secret|password|passwd|api[_-]?key|private[_-]?key)(?:$|_)/i',
        '/(?:^|_)(?:access|refresh|session|csrf)[_-]?token(?:$|_)/i',
        '/(?:^|_)(?:authorization|cookie|set[_-]?cookie)(?:$|_)/i',
        '/(?:^|_)(?:visitor|session|pageview|event)[_-]?id(?:$|_)/i',
        '/(?:^|_)(?:user[_-]?email|email)(?:$|_)/i',
        '/(?:^|_)(?:ip|ip[_-]?address|remote[_-]?addr|raw[_-]?ip)(?:$|_)/i',
    ];

    public function debug(
        string $message,
        array $context = []
    ): void {
        $this->write(
            self::LEVEL_DEBUG,
            $message,
            $context
        );
    }

    public function info(
        string $message,
        array $context = []
    ): void {
        $this->write(
            self::LEVEL_INFO,
            $message,
            $context
        );
    }

    public function warning(
        string $message,
        array $context = []
    ): void {
        $this->write(
            self::LEVEL_WARNING,
            $message,
            $context
        );
    }

    public function error(
        string $message,
        array $context = []
    ): void {
        $this->write(
            self::LEVEL_ERROR,
            $message,
            $context
        );
    }

    private function write(
        string $level,
        string $message,
        array $context
    ): void {
        if (!$this->isEnabled()) {
            return;
        }

        $level =
            $this->normalizeLevel(
                $level
            );

        $message =
            $this->sanitizeMessage(
                $message
            );

        $context =
            $this->sanitizeContext(
                $context
            );

        $line =
            sprintf(
                '%s %s: %s',
                self::PREFIX,
                $level,
                $message
            );

        if ($context !== []) {
            $encoded =
                wp_json_encode(
                    $context,
                    JSON_UNESCAPED_SLASHES
                    | JSON_UNESCAPED_UNICODE
                    | JSON_INVALID_UTF8_SUBSTITUTE
                );

            if (is_string($encoded)) {
                $line .= ' ' . $encoded;
            } else {
                $line .= ' {"_context_error":"json_encode_failed"}';
            }
        }

        $this->writeLine(
            $line
        );
    }

    private function isEnabled(): bool
    {
        $configured =
            Config::get(
                'logging_enabled',
                null
            );

        if ($configured !== null) {
            return $configured === true;
        }

        return defined('WP_DEBUG')
            && WP_DEBUG === true;
    }

    private function writeLine(
        string $line
    ): void {
        $result =
            error_log(
                $line
            );

        if ($result === false) {
            do_action(
                'vi_logger_write_failed'
            );
        }
    }

    private function normalizeLevel(
        string $level
    ): string {
        $level =
            strtoupper(
                trim($level)
            );

        if (
            !in_array(
                $level,
                [
                    self::LEVEL_DEBUG,
                    self::LEVEL_INFO,
                    self::LEVEL_WARNING,
                    self::LEVEL_ERROR,
                ],
                true
            )
        ) {
            return self::LEVEL_ERROR;
        }

        return $level;
    }

    private function sanitizeMessage(
        string $message
    ): string {
        $message =
            $this->sanitizeString(
                $message
            );

        $maxLength =
            $this->getMaxMessageLength();

        if (
            function_exists(
                'mb_strlen'
            )
            && function_exists(
                'mb_substr'
            )
        ) {
            if (
                mb_strlen(
                    $message,
                    'UTF-8'
                ) > $maxLength
            ) {
                return mb_substr(
                    $message,
                    0,
                    $maxLength,
                    'UTF-8'
                ) . '…';
            }

            return $message;
        }

        if (
            strlen(
                $message
            ) > $maxLength
        ) {
            return substr(
                $message,
                0,
                $maxLength
            ) . '…';
        }

        return $message;
    }

    private function sanitizeContext(
        array $context
    ): array {
        return $this->sanitizeArray(
            $context,
            0
        );
    }

    private function sanitizeArray(
        array $context,
        int $depth
    ): array {
        if (
            $depth >=
            $this->getMaxContextDepth()
        ) {
            return [
                '_truncated' =>
                    'maximum context depth exceeded',
            ];
        }

        $result = [];

        $items =
            0;

        foreach (
            $context as $key => $value
        ) {
            if (
                $items >=
                $this->getMaxContextItems()
            ) {
                $result['_truncated'] =
                    'maximum context item count exceeded';

                break;
            }

            $items++;

            $originalKey =
                (string) $key;

            $normalizedKey =
                $this->normalizeKey(
                    $originalKey
                );

            if (
                $this->isSensitiveKey(
                    $normalizedKey
                )
            ) {
                $result[
                    $originalKey
                ] = '[redacted]';

                continue;
            }

            $result[
                $originalKey
            ] =
                $this->sanitizeValue(
                    $value,
                    $depth + 1
                );
        }

        return $result;
    }

    private function sanitizeValue(
        mixed $value,
        int $depth
    ): mixed {
        if (is_string($value)) {
            return $this->sanitizeString(
                $value
            );
        }

        if (is_array($value)) {
            return $this->sanitizeArray(
                $value,
                $depth
            );
        }

        if (is_object($value)) {
            if (
                $value instanceof \Throwable
            ) {
                return [
                    'type' =>
                        get_class(
                            $value
                        ),

                    'message' =>
                        $this->sanitizeMessage(
                            $value->getMessage()
                        ),

                    'code' =>
                        $value->getCode(),
                ];
            }

            return sprintf(
                '[object:%s]',
                get_class($value)
            );
        }

        if (is_resource($value)) {
            return '[resource]';
        }

        if (
            is_bool($value)
            || is_int($value)
            || is_float($value)
        ) {
            return $value;
        }

        if ($value === null) {
            return null;
        }

        return '[unsupported]';
    }

    private function sanitizeString(
        string $value
    ): string {
        $value =
            preg_replace(
                '/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i',
                'Bearer [redacted]',
                $value
            ) ?? $value;

        $value =
            preg_replace(
                '/(?:authorization|token|password|secret|api[_-]?key|client[_-]?secret)\s*[:=]\s*[^\s,;]+/i',
                '$1=[redacted]',
                $value
            ) ?? $value;

        $value =
            preg_replace(
                '/([?&](?:token|access_token|refresh_token|api_key|apikey|password|secret)=)[^&\s]+/i',
                '$1[redacted]',
                $value
            ) ?? $value;

        return $this->sanitizeMessageLength(
            $value
        );
    }

    private function sanitizeMessageLength(
        string $value
    ): string {
        $maxLength =
            $this->getMaxMessageLength();

        if (
            function_exists(
                'mb_strlen'
            )
            && function_exists(
                'mb_substr'
            )
        ) {
            if (
                mb_strlen(
                    $value,
                    'UTF-8'
                ) > $maxLength
            ) {
                return mb_substr(
                    $value,
                    0,
                    $maxLength,
                    'UTF-8'
                ) . '…';
            }

            return $value;
        }

        if (
            strlen(
                $value
            ) > $maxLength
        ) {
            return substr(
                $value,
                0,
                $maxLength
            ) . '…';
        }

        return $value;
    }

    private function normalizeKey(
        string $key
    ): string {
        $key =
            strtolower(
                trim($key)
            );

        return str_replace(
            ['-', ' '],
            '_',
            $key
        );
    }

    private function isSensitiveKey(
        string $key
    ): bool {
        if (
            in_array(
                $key,
                self::SENSITIVE_KEYS,
                true
            )
        ) {
            return true;
        }

        foreach (
            self::SENSITIVE_KEY_PATTERNS
            as $pattern
        ) {
            if (
                preg_match(
                    $pattern,
                    $key
                ) === 1
            ) {
                return true;
            }
        }

        return false;
    }

    private function getMaxMessageLength(): int
    {
        $value =
            Config::get(
                'logging_max_message_length',
                self::DEFAULT_MAX_MESSAGE_LENGTH
            );

        if (
            !is_int($value)
            && !(
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            return self::DEFAULT_MAX_MESSAGE_LENGTH;
        }

        return max(
            256,
            min(
                32768,
                (int) $value
            )
        );
    }

    private function getMaxContextDepth(): int
    {
        $value =
            Config::get(
                'logging_max_context_depth',
                self::DEFAULT_MAX_CONTEXT_DEPTH
            );

        if (
            !is_int($value)
            && !(
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            return self::DEFAULT_MAX_CONTEXT_DEPTH;
        }

        return max(
            1,
            min(
                20,
                (int) $value
            )
        );
    }

    private function getMaxContextItems(): int
    {
        $value =
            Config::get(
                'logging_max_context_items',
                self::DEFAULT_MAX_CONTEXT_ITEMS
            );

        if (
            !is_int($value)
            && !(
                is_string($value)
                && ctype_digit($value)
            )
        ) {
            return self::DEFAULT_MAX_CONTEXT_ITEMS;
        }

        return max(
            10,
            min(
                1000,
                (int) $value
            )
        );
    }
}