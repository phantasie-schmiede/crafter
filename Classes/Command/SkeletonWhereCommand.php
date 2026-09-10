<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Service\PathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Class SkeletonWhereCommand
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:skeleton:where',
    description: 'Shows all lookup locations of a skeleton and which one wins'
)]
class SkeletonWhereCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_IDENTIFIER = 'identifier';

    public function __construct(
        protected readonly PathResolver $pathResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addArgument(
            self::ARGUMENT_IDENTIFIER,
            InputArgument::REQUIRED,
            'Skeleton identifier, e.g. Php/FileHeader'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionKey = $this->getExtensionKey($input);
        $identifier   = (string)$input->getArgument(self::ARGUMENT_IDENTIFIER);
        $io           = $this->getIo($input, $output);
        $projectPath  = rtrim(Environment::getProjectPath(), '/') . '/';
        $candidates   = $this->pathResolver->getSkeletonCandidates($identifier, $extensionKey);
        $isWinner     = true;
        $rows         = [];

        foreach ($candidates as $candidate) {
            $status = '<fg=gray>missing</>';

            if (true === $candidate['exists']) {
                $status   = true === $isWinner ? '<fg=green>used</>' : '<fg=yellow>shadowed</>';
                $isWinner = false;
            }

            $rows[] = [
                $candidate['priority'],
                $candidate['root'],
                str_replace($projectPath, '', $candidate['path']),
                $status,
            ];
        }

        $io->title('Lookup for skeleton "' . $identifier . '"');
        $io->table([
            'Priority',
            'Root',
            'File',
            'Status',
        ], $rows);

        if (true === $isWinner) {
            $io->error('Skeleton not found in any root path.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
