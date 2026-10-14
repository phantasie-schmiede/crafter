<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Command;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;

use function file_get_contents;

/**
 * Class MakeModelCommandTest
 *
 * @package PSBits\Crafter\Tests\Unit\Command
 */
final class MakeModelCommandTest extends AbstractCommandTestCase
{
    #[Test]
    public function createsAModelWithParsedPropertiesAndRepository(): void
    {
        $tester = $this->runCommand($this->makeModelCommand, [
            'name'              => 'Product',
            '--extension'       => self::EXTENSION_KEY,
            '--property'        => [
                'name=title,type=string,required=1',
                'name=price,type=decimal',
            ],
            '--with-repository' => true,
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $modelPath = $this->getExtensionFilePath('Classes/Domain/Model/Product.php');
        self::assertFileExists($modelPath);
        self::assertFileExists($this->getExtensionFilePath('Classes/Domain/Repository/ProductRepository.php'));
        $model = (string)file_get_contents($modelPath);
        self::assertStringContainsString('required: true', $model);
        self::assertStringContainsString('protected float $price', $model);
        self::assertStringContainsString('Model "Product" has been created.', $tester->getDisplay());
    }

    #[Test]
    #[DataProvider('incompleteProperties')]
    public function rejectsAPropertyWithoutNameOrType(string $property): void
    {
        $tester = $this->runCommand($this->makeModelCommand, [
            'name'        => 'Product',
            '--extension' => self::EXTENSION_KEY,
            '--property'  => [$property],
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('must at least define "name" and "type"', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnUnknownPropertyType(): void
    {
        $tester = $this->runCommand($this->makeModelCommand, [
            'name'        => 'Product',
            '--extension' => self::EXTENSION_KEY,
            '--property'  => ['name=title,type=bogus'],
        ]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Unknown field type "bogus"', $tester->getDisplay());
    }

    #[Test]
    public function rejectsAnInvalidName(): void
    {
        $tester = $this->runCommand($this->makeModelCommand, ['name' => 'product', '--extension' => self::EXTENSION_KEY]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('UpperCamelCase', $tester->getDisplay());
    }

    #[Test]
    public function createsOnlyTheModelWithoutFollowUpFlags(): void
    {
        $tester = $this->runCommand($this->makeModelCommand, [
            'name'        => 'Product',
            '--extension' => self::EXTENSION_KEY,
            '--property'  => ['name=title,type=string'],
        ]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertFileExists($this->getExtensionFilePath('Classes/Domain/Model/Product.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Classes/Domain/Repository/ProductRepository.php'));
        self::assertFileDoesNotExist($this->getExtensionFilePath('Classes/Controller/ProductController.php'));
    }

    #[Test]
    public function requiresTheExtensionOption(): void
    {
        $tester = $this->runCommand($this->makeModelCommand, ['name' => 'Product']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('The option --extension is required.', $tester->getDisplay());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function incompleteProperties(): iterable
    {
        yield 'missing name' => ['type=string'];
        yield 'missing type' => ['name=title'];
        yield 'missing both' => ['required=1'];
    }
}
