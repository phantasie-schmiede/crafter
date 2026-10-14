<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Service\SkeletonRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class SkeletonShowCommand
 *
 * Renders a skeleton with the effective configuration so overridden stubs and placeholders can be checked without
 * generating any file.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:skeleton:show',
    description: 'Renders a skeleton with the effective variables without writing a file'
)]
class SkeletonShowCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_IDENTIFIER = 'identifier';
    public const string OPTION_SET          = 'set';
    public const string OPTION_VARIABLES    = 'variables';

    public function __construct(
        protected readonly SkeletonRenderer $skeletonRenderer,
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
        $this->addOption(
            self::OPTION_SET,
            's',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Additional placeholder value in the form name=value'
        );
        $this->addOption(
            self::OPTION_VARIABLES,
            null,
            InputOption::VALUE_NONE,
            'List the available placeholders instead of rendering'
        );
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $extensionKey = $this->getExtensionKey($input);
        $identifier   = (string)$input->getArgument(self::ARGUMENT_IDENTIFIER);

        if (true === $input->getOption(self::OPTION_VARIABLES)) {
            $rows = [];

            foreach ($this->skeletonRenderer->getGlobalVariables($extensionKey) as $name => $value) {
                $rows[] = [
                    $name,
                    $value,
                ];
            }

            sort($rows);
            $io->table([
                'Placeholder',
                'Value',
            ], $rows);

            return Command::SUCCESS;
        }

        $io->writeln($this->skeletonRenderer->render(
            $identifier,
            $this->parseVariables($input),
            $extensionKey
        ));

        return Command::SUCCESS;
    }

    /**
     * @return array<string, string>
     */
    private function parseVariables(InputInterface $input): array
    {
        $variables = [];

        foreach ((array)$input->getOption(self::OPTION_SET) as $assignment) {
            [
                $name,
                $value,
            ] = array_pad(explode('=', (string)$assignment, 2), 2, '');

            if ('' !== $name) {
                $variables[$name] = $value;
            }
        }

        return $variables;
    }
}
