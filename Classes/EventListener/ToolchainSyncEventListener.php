<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\EventListener;

use PSBits\Crafter\Event\AfterGenerationEvent;
use PSBits\Crafter\Service\SyncService;
use TYPO3\CMS\Core\Attribute\AsEventListener;

/**
 * Class ToolchainSyncEventListener
 *
 * Renews the toolchain of the affected extension after every generation command, so composer.json, package.json and
 * the quality configuration stay aligned with the content profile. Skipped while syncing itself to avoid recursion.
 *
 * @package PSBits\Crafter\EventListener
 */
#[AsEventListener(event: AfterGenerationEvent::class)]
final class ToolchainSyncEventListener
{
    public const string SYNC_COMMAND = 'crafter:sync';

    public function __construct(
        protected readonly SyncService $syncService,
    ) {
    }

    public function __invoke(AfterGenerationEvent $event): void
    {
        if (null === $event->getExtensionKey()
            || $event->isDryRun()
            || self::SYNC_COMMAND === $event->getCommand()) {
            return;
        }

        $this->syncService->sync($event->getExtensionKey());
    }
}
