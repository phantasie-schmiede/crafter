<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Fixtures;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Class CallbackEventDispatcher
 *
 * Minimal PSR-14 dispatcher that hands every event to the registered callbacks. Keeps the event tests free of the
 * TYPO3 listener provider infrastructure, which is not available in a unit test.
 *
 * @package PSBits\Crafter\Tests\Fixtures
 */
final class CallbackEventDispatcher implements EventDispatcherInterface
{
    /** @var array<int, callable> */
    private array $listeners = [];

    /** @var object[] */
    private array $dispatchedEvents = [];

    public function addListener(callable $listener): void
    {
        $this->listeners[] = $listener;
    }

    public function dispatch(object $event): object
    {
        $this->dispatchedEvents[] = $event;

        foreach ($this->listeners as $listener) {
            $listener($event);
        }

        return $event;
    }

    /**
     * @return object[]
     */
    public function getDispatchedEvents(?string $className = null): array
    {
        if (null === $className) {
            return $this->dispatchedEvents;
        }

        return array_values(array_filter(
            $this->dispatchedEvents,
            static fn(object $event): bool => $event instanceof $className
        ));
    }
}
