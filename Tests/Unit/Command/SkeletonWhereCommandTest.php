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
 * Class SkeletonWhereCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class SkeletonWhereCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function showsTheLookupChain(): void
    {
        $tester = $this->runCommand($this->skeletonWhereCommand, ['identifier' => 'Php/FileHeader']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Lookup for skeleton "Php/FileHeader"', $tester->getDisplay());
        self::assertStringContainsString('used', $tester->getDisplay());
    }

    #[Test]
    public function failsForAnUnknownSkeleton(): void
    {
        $tester = $this->runCommand($this->skeletonWhereCommand, ['identifier' => 'Php/DoesNotExist']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Skeleton not found in any root path.', $tester->getDisplay());
    }
}
