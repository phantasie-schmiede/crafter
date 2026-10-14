<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;

use function file_get_contents;

/**
 * Class MakeEventListenerCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class MakeEventListenerCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsAnEventListenerForTheGivenEvent(): void
    {
        $tester = $this->runCommand($this->makeEventListenerCommand, [
            'name'        => 'ProductUpdate',
            '--extension' => self::EXTENSION_KEY,
            '--event'     => 'TYPO3\CMS\Core\Database\Event\AfterFileOperationEvent',
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $listener = (string)file_get_contents($this->getExtensionFilePath('Classes/EventListener/ProductUpdate.php'));
        self::assertStringContainsString('AfterFileOperationEvent', $listener);
        self::assertStringContainsString('Event listener "ProductUpdate" has been created.', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnEventWithoutNamespace(): void
    {
        $tester = $this->runCommand($this->makeEventListenerCommand, [
            'name'        => 'ProductUpdate',
            '--extension' => self::EXTENSION_KEY,
            '--event'     => 'AfterFileOperationEvent',
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('fully qualified class name', $tester->getDisplay());
    }
}
