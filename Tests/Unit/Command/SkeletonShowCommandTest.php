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

/**
 * Class SkeletonShowCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class SkeletonShowCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function rendersAPlaceholderFreeStub(): void
    {
        $tester = $this->runCommand($this->skeletonShowCommand, ['identifier' => 'Extension/GitIgnore']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('.Build/', $tester->getDisplay());
    }

    #[Test]
    public function replacesVariablesGivenViaSetOption(): void
    {
        $tester = $this->runCommand($this->skeletonShowCommand, [
            'identifier' => 'Php/FileHeader',
            '--set'      => ['packageTitle=Demo Shop'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('This file is part of Demo Shop.', $tester->getDisplay());
        self::assertStringNotContainsString('{{', $tester->getDisplay());
    }

    #[Test]
    public function listsTheAvailableVariables(): void
    {
        $tester = $this->runCommand($this->skeletonShowCommand, ['identifier' => 'Php/FileHeader', '--variables' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Placeholder', $tester->getDisplay());
        self::assertStringContainsString('extensionKey', $tester->getDisplay());
    }

    #[Test]
    public function failsForAnUnknownSkeleton(): void
    {
        $tester = $this->runCommand($this->skeletonShowCommand, ['identifier' => 'Php/DoesNotExist']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Php/DoesNotExist', $tester->getDisplay());
    }
}
