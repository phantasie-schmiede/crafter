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
 * Class MakeRepositoryCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class MakeRepositoryCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsARepository(): void
    {
        $tester = $this->runCommand($this->makeRepositoryCommand, [
            'name'        => 'Product',
            '--extension' => self::EXTENSION_KEY,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Domain/Repository/ProductRepository.php'));
        self::assertStringContainsString('Repository "ProductRepository" has been created.', $tester->getDisplay());
    }

    #[Test]
    public function requiresTheExtensionOption(): void
    {
        $tester = $this->runCommand($this->makeRepositoryCommand, ['name' => 'Product']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The option --extension is required.', $tester->getDisplay());
    }
}
