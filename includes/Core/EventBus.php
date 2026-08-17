<?php

declare(strict_types=1);

namespace VisitorIntelligence\Core;

use VisitorIntelligence\Core\Contracts\EventInterface;

defined('ABSPATH') || exit;

final class EventBus
{
    /**
     * @var array<string, array<int, array<string, callable>>>
     */
    private array $listeners = [];

    private int $listenerSequence = 0;

    public function subscribe(
        string $event,
        callable $listener,
        int $priority = 10
    ): string {
        $event = trim($event);

        if ($event === '') {
            throw new \InvalidArgumentException(
                'Event name cannot be empty.'
            );
        }

        if ($priority < 0) {
            throw new \InvalidArgumentException(
                'Event priority cannot be negative.'
            );
        }

        $id = 'listener_' . ++$this->listenerSequence;

        $this->listeners[$event][$priority][$id] = $listener;

        ksort($this->listeners[$event]);

        return $id;
    }

    public function unsubscribe(
        string $event,
        string $listenerId
    ): bool {
        if (!isset($this->listeners[$event])) {
            return false;
        }

        foreach ($this->listeners[$event] as $priority => $listeners) {
            if (!isset($listeners[$listenerId])) {
                continue;
            }

            unset(
                $this->listeners[$event][$priority][$listenerId]
            );

            if ($this->listeners[$event][$priority] === []) {
                unset(
                    $this->listeners[$event][$priority]
                );
            }

            if ($this->listeners[$event] === []) {
                unset(
                    $this->listeners[$event]
                );
            }

            return true;
        }

        return false;
    }

    public function dispatch(
        EventInterface $event
    ): void {
        $eventType = $event->getType();

        if (!$this->hasListeners($eventType)) {
            return;
        }

        foreach ($this->listeners[$eventType] as $listeners) {
            foreach ($listeners as $listener) {
                $listener($event);
            }
        }
    }

    public function dispatchPayload(
        string $event,
        mixed $payload = null
    ): void {
        if (!$this->hasListeners($event)) {
            return;
        }

        foreach ($this->listeners[$event] as $listeners) {
            foreach ($listeners as $listener) {
                $listener($payload);
            }
        }
    }

    public function hasListeners(
        string $event
    ): bool {
        return isset($this->listeners[$event])
            && $this->listeners[$event] !== [];
    }

    public function listenerCount(
        ?string $event = null
    ): int {
        if ($event !== null) {
            if (!isset($this->listeners[$event])) {
                return 0;
            }

            return array_sum(
                array_map(
                    'count',
                    $this->listeners[$event]
                )
            );
        }

        $count = 0;

        foreach ($this->listeners as $priorities) {
            $count += array_sum(
                array_map(
                    'count',
                    $priorities
                )
            );
        }

        return $count;
    }

    public function clear(
        ?string $event = null
    ): void {
        if ($event === null) {
            $this->listeners = [];

            return;
        }

        unset(
            $this->listeners[$event]
        );
    }

    /**
     * @return array<string, array<int, array<string, callable>>>
     */
    public function getListeners(): array
    {
        return $this->listeners;
    }
}