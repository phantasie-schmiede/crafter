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
use PSBits\Crafter\Service\PathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Environment;

use function sprintf;

/**
 * Class SkeletonPublishCommand
 *
 * Copies a shipped skeleton into an overriding root path so it can be adjusted without forking the extension.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:skeleton:publish',
    description: 'Copies a skeleton into an overriding root path for customization'
)]
class SkeletonPublishCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_IDENTIFIER = 'identifier';
    public const int EXTENSION_PRIORITY     = 20;
    public const string OPTION_PRIORITY     = 'priority';
    public const int PROJECT_PRIORITY       = 10;

    public function __construct(
        protected readonly FileWriter   $fileWriter,
        protected readonly PathResolver $pathResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addFileOptions();
        $this->addArgument(
            self::ARGUMENT_IDENTIFIER,
            InputArgument::REQUIRED,
            'Skeleton identifier, e.g. Php/FileHeader'
        );
        $this->addOption(
            self::OPTION_PRIORITY,
            'p',
            InputOption::VALUE_REQUIRED,
            'Priority of the target root path as configured in crafter.skeletonRootPaths'
        );
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $extensionKey = $this->getExtensionKey($input);
        $identifier   = (string)$input->getArgument(self::ARGUMENT_IDENTIFIER);
        $priority     = $this->determinePriority($input, $extensionKey);
        $rootPaths    = $this->pathResolver->getResolvedRootPaths($extensionKey);

        if (!isset($rootPaths[$priority])) {
            $io->error(
                sprintf(
                    'No skeleton root path configured for priority %d. Available priorities: %s',
                    $priority,
                    implode(', ', array_keys($rootPaths))
                )
            );

            return Command::FAILURE;
        }

        $sourcePath = $this->pathResolver->resolveSkeleton($identifier, $extensionKey);
        $targetPath = $rootPaths[$priority] . ltrim($identifier, '/') . PathResolver::SKELETON_FILE_EXTENSION;

        if ($sourcePath === $targetPath) {
            $io->note(
                sprintf(
                    'Skeleton "%s" is already published at priority %d. Nothing to do.',
                    $identifier,
                    $priority
                )
            );

            return Command::SUCCESS;
        }

        $this->fileWriter->reset(
            (bool)$input->getOption(self::OPTION_DRY_RUN),
            (bool)$input->getOption(self::OPTION_FORCE)
        );
        $result      = $this->fileWriter->write($targetPath, (string)file_get_contents($sourcePath));
        $projectPath = rtrim(Environment::getProjectPath(), '/') . '/';
        $io->writeln(
            sprintf(
                '%s %s',
                $result->status->getLabel(),
                str_replace($projectPath, '', $result->path)
            )
        );

        if (true === $result->dryRun) {
            $io->note('Dry run - no file has been written.');
        }

        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        return Command::SUCCESS;
    }

    private function determinePriority(InputInterface $input, ?string $extensionKey): int
    {
        $priority = $input->getOption(self::OPTION_PRIORITY);

        if (is_numeric($priority)) {
            return (int)$priority;
        }

        return null !== $extensionKey ? self::EXTENSION_PRIORITY : self::PROJECT_PRIORITY;
    }
}
