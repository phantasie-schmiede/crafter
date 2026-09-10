<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Data\WriteResult;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Class AbstractCrafterCommand
 *
 * @package PSBits\Crafter\Command
 */
abstract class AbstractCrafterCommand extends Command
{
    public const string OPTION_DRY_RUN   = 'dry-run';
    public const string OPTION_EXTENSION = 'extension';
    public const string OPTION_FORCE     = 'force';

    protected function configure(): void
    {
        $this->addOption(
            self::OPTION_EXTENSION,
            'e',
            InputOption::VALUE_REQUIRED,
            'Extension key the configuration and skeleton cascade is resolved for'
        );
    }

    protected function addFileOptions(): static
    {
        $this->addOption(
            self::OPTION_DRY_RUN,
            null,
            InputOption::VALUE_NONE,
            'Show what would be written without touching any file'
        );
        $this->addOption(
            self::OPTION_FORCE,
            'f',
            InputOption::VALUE_NONE,
            'Overwrite existing files'
        );

        return $this;
    }

    protected function getExtensionKey(InputInterface $input): ?string
    {
        $extensionKey = $input->getOption(self::OPTION_EXTENSION);

        return is_string($extensionKey) && '' !== $extensionKey ? $extensionKey : null;
    }

    protected function getIo(InputInterface $input, OutputInterface $output): SymfonyStyle
    {
        return new SymfonyStyle($input, $output);
    }

    /**
     * @param WriteResult[] $results
     */
    protected function hasChanges(array $results): bool
    {
        foreach ($results as $result) {
            if (true === $result->status->hasChangedFile()) {
                return true;
            }
        }

        return false;
    }

    protected function makeRelative(string $absolutePath): string
    {
        return str_replace(rtrim(Environment::getProjectPath(), '/') . '/', '', $absolutePath);
    }

    /**
     * @param WriteResult[] $results
     */
    protected function showResults(SymfonyStyle $io, array $results): void
    {
        $rows = [];

        foreach ($results as $result) {
            $rows[] = [
                $result->status->getLabel(),
                $this->makeRelative($result->path),
            ];
        }

        $io->table(['Status', 'File'], $rows);
    }
}
