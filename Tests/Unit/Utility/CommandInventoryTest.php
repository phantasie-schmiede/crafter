<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PSBits\Crafter\Utility\CommandInventory;

use function array_keys;

/**
 * Class CommandInventoryTest
 *
 * @package PSBits\Crafter\Tests\Unit\Utility
 */
final class CommandInventoryTest extends TestCase
{
    private CommandInventory $inventory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inventory = new CommandInventory();
    }

    #[Test]
    public function findsEveryConcreteCommandShippedByTheExtension(): void
    {
        self::assertSame(
            [
                'crafter:audit',
                'crafter:config:dump',
                'crafter:list',
                'crafter:make:backend-controller',
                'crafter:make:controller',
                'crafter:make:event-listener',
                'crafter:make:extension',
                'crafter:make:model',
                'crafter:make:repository',
                'crafter:make:service',
                'crafter:make:view-helper',
                'crafter:skeleton:list',
                'crafter:skeleton:publish',
                'crafter:skeleton:show',
                'crafter:skeleton:where',
                'crafter:sync',
            ],
            array_keys($this->inventory->find())
        );
    }

    #[Test]
    public function carriesTheDescriptionOfEveryCommand(): void
    {
        $commands = $this->inventory->find();

        foreach ($commands as $name => $details) {
            self::assertStringStartsWith('crafter:', $name);
            self::assertArrayHasKey('description', $details);
            self::assertNotEmpty($details['description']);
        }

        self::assertSame(
            ['description' => 'Creates a domain model and optionally its repository'],
            $commands['crafter:make:model']
        );
        self::assertSame(
            ['description' => 'Lists all crafter commands with a short description'],
            $commands['crafter:list']
        );
    }

    #[Test]
    public function picksUpNewCommandFiles(): void
    {
        $path = dirname(__DIR__, 3) . '/Classes/Command/InventoryProbeCommand.php';
        file_put_contents(
            $path,
            <<<'PHP'
        <?php

        declare(strict_types=1);

        namespace PSBits\Crafter\Command;

        use Symfony\Component\Console\Attribute\AsCommand;

        #[AsCommand(
            name: 'crafter:inventory:probe',
            description: 'Probe command for the inventory test'
        )]
        class InventoryProbeCommand extends AbstractCrafterCommand
        {
            protected function handle(\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Style\SymfonyStyle $io): int
            {
                return \Symfony\Component\Console\Command\Command::SUCCESS;
            }
        }
        PHP
        );

        try {
            $commands = $this->inventory->find();

            self::assertArrayHasKey('crafter:inventory:probe', $commands);
            self::assertSame(
                ['description' => 'Probe command for the inventory test'],
                $commands['crafter:inventory:probe']
            );
        } finally {
            unlink($path);
        }
    }
}
