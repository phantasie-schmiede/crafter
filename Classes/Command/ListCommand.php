<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Utility\CommandInventory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class ListCommand
 *
 * Lists all crafter commands with a short description.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:list',
    description: 'Lists all crafter commands with a short description'
)]
class ListCommand extends AbstractCrafterCommand
{
    public function __construct(
        protected readonly CommandInventory $commandInventory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            <<<'HELP'
            Lists all commands provided by the crafter extension together with a short description.

            The list is the same you get from the "vendor/bin/crafter" wrapper without a command argument.
            Use "crafter <command> --help" for the arguments and options of a single command.
            HELP
        );
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $rows = [];

        foreach ($this->commandInventory->find() as $name => $details) {
            $rows[] = [$name, $details['description']];
        }

        $io->title('Crafter commands');
        $io->table(['Command', 'Description'], $rows);
        $io->note('Run "vendor/bin/crafter <command> --help" for the arguments and options of a single command.');

        return Command::SUCCESS;
    }
}
