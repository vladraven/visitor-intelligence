<?php

declare(strict_types=1);

namespace VisitorIntelligence\API;

use VisitorIntelligence\Core\Plugin;
use VisitorIntelligence\Database\Repositories\VisitorRepository;

defined('ABSPATH') || exit;

final class VisitorAPI
{
    public static function getVisitorSummary(string $visitorId): array
    {
        /** @var VisitorRepository $repo */
        $repo = Plugin::instance()->container()->get(VisitorRepository::class);

        return $repo->getSummary($visitorId) ?? [];
    }

    public static function registerEvent(array $eventData): void
    {
        do_action('vi_register_custom_event', $eventData);
    }
}