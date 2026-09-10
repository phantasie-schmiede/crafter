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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use TYPO3\CMS\Core\Core\Environment;

/**
 * Class SkeletonListCommand
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:skeleton:list',
    description: 'Lists all available skeletons and the root path each one is currently loaded from'
)]
class SkeletonListCommand extends AbstractCrafterCommand
{
    public function __construct(
        protected readonly PathResolver $pathResolver,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $extensionKey = $this->getExtensionKey($input);
        $io           = $this->getIo($input, $output);
        $projectPath  = rtrim(Environment::getProjectPath(), '/') . '/';
        $rows         = [];

        foreach ($this->pathResolver->listSkeletons($extensionKey) as $identifier => $skeleton) {
            $rows[] = [
                $identifier,
                $skeleton['priority'],
                str_replace($projectPath, '', $skeleton['path']),
                true === $skeleton['overridden'] ? '<fg=yellow>yes</>' : 'no',
            ];
        }

        if ([] === $rows) {
            $io->warning('No skeletons found.');

            return Command::FAILURE;
        }

        $io->title('Available skeletons' . (null !== $extensionKey ? ' for "' . $extensionKey . '"' : ''));
        $io->table([
            'Identifier',
            'Priority',
            'File',
            'Overrides default',
        ], $rows);

        return Command::SUCCESS;
    }
}
