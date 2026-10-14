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
 * Class ListCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class ListCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function listsEveryCrafterCommandWithItsDescription(): void
    {
        $tester = $this->runCommand($this->listCommand);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Crafter commands', $tester->getDisplay());
        self::assertStringContainsString('crafter:audit', $tester->getDisplay());
        self::assertStringContainsString('crafter:make:model', $tester->getDisplay());
        self::assertStringContainsString('Creates a domain model and optionally its repository', $tester->getDisplay());
        self::assertStringContainsString('crafter:list', $tester->getDisplay());
        self::assertStringContainsString('vendor/bin/typo3 <command> --help', $tester->getDisplay());
    }
}
