<?php

declare(strict_types=1);

namespace VisitorIntelligence\Database;

defined('ABSPATH') || exit;

final class Schema
{
    public static function visitors(Database $database): string
    {
        return $database->table('visitors');
    }

    public static function sessions(Database $database): string
    {
        return $database->table('sessions');
    }

    public static function pageviews(Database $database): string
    {
        return $database->table('pageviews');
    }

    public static function events(Database $database): string
    {
        return $database->table('events');
    }

    public static function dailyStats(Database $database): string
    {
        return $database->table('daily_stats');
    }

    public static function all(Database $database): array
    {
        return [
            'visitors' => self::visitors($database),
            'sessions' => self::sessions($database),
            'pageviews' => self::pageviews($database),
            'events' => self::events($database),
            'daily_stats' => self::dailyStats($database),
        ];
    }
}