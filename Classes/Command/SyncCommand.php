<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Service\SyncService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Class SyncCommand
 *
 * Brings the toolchain of an existing extension in line with `crafter.tools`: quality configuration, workflow and
 * agent instructions are renewed according to the `crafter.sync.renewable` policies, and the managed keys of
 * composer.json and package.json are re-merged.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:sync',
    description: 'Renews the quality toolchain of an existing extension'
)]
class SyncCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_EXTENSION_KEY = 'extensionKey';

    public function __construct(
        protected readonly FileWriter  $fileWriter,
        protected readonly SyncService $syncService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addOption(
            self::OPTION_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            'Show what would be renewed without touching any file'
        );
        $this->addArgument(
            self::ARGUMENT_EXTENSION_KEY,
            InputArgument::OPTIONAL,
            'Extension key to sync, alternatively given via --extension'
        );
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $extensionKey = $this->determineExtensionKey($input);

        if (null === $extensionKey) {
            $io->error('No extension given. Use the argument or --extension.');

            return Command::FAILURE;
        }

        $results = $this->syncService->sync($extensionKey, (bool)$input->getOption(self::OPTION_DRY_RUN));

        $io->title(sprintf('Sync of "%s"', $extensionKey));

        if ([] === $results) {
            $io->success('Nothing to do, the toolchain is up to date.');

            return Command::SUCCESS;
        }

        $this->showResults($io, $results);
        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        if (true === (bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $io->note('Dry run - no file has been written.');
        }

        return Command::SUCCESS;
    }

    private function determineExtensionKey(InputInterface $input): ?string
    {
        $extensionKey = $input->getArgument(self::ARGUMENT_EXTENSION_KEY);

        if (is_string($extensionKey) && '' !== $extensionKey) {
            return $extensionKey;
        }

        return $this->getExtensionKey($input);
    }
}
