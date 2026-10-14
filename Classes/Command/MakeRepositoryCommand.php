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
use PSBits\Crafter\Service\RepositoryGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Class MakeRepositoryCommand
 *
 * Creates a repository for an existing domain model.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:repository',
    description: 'Creates a repository for an existing domain model'
)]
class MakeRepositoryCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_NAME = 'name';

    public function __construct(
        protected readonly FileWriter          $fileWriter,
        protected readonly RepositoryGenerator $repositoryGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(
            <<<'HELP'
            Creates a repository for an existing domain model. The class extends the repository
            base class configured under crafter.defaults.repositoryExtends and is ready to hold
            the search methods of the model.

            Example:
              crafter:make:repository --extension budget_manager Contact
            HELP
        );
        $this->addFileOptions();
        $this->addArgument(
            self::ARGUMENT_NAME,
            InputArgument::OPTIONAL,
            'Name of the model the repository belongs to, e.g. Contact'
        );
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $extensionKey = $this->getExtensionKey($input);

        if (null === $extensionKey) {
            $io->error('The option --extension is required.');

            return Command::FAILURE;
        }

        $name = $this->determineName($input, $io);

        if (null === $name) {
            return Command::FAILURE;
        }

        $this->fileWriter->reset(
            (bool)$input->getOption(self::OPTION_DRY_RUN),
            (bool)$input->getOption(self::OPTION_FORCE)
        );
        $this->repositoryGenerator->generate($extensionKey, $name);
        $this->showResults($io, $results = $this->fileWriter->getResults());
        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        if (true === (bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $io->note('Dry run - no file has been written.');

            return Command::SUCCESS;
        }

        if (false === $this->hasChanges($results)) {
            $io->warning(
                sprintf(
                    'Nothing has been written, "%sRepository" is already up to date.'
                . ' Use --force to overwrite a file that differs.',
                    $name
                )
            );

            return Command::SUCCESS;
        }

        $io->success(sprintf('Repository "%sRepository" has been created.', $name));

        return Command::SUCCESS;
    }

    private function determineName(InputInterface $input, SymfonyStyle $io): ?string
    {
        $name = $input->getArgument(self::ARGUMENT_NAME);

        if ((!is_string($name) || '' === $name) && true === $input->isInteractive()) {
            $name = (string)$io->ask('Model name (UpperCamelCase)');
        }

        if (!is_string($name) || 1 !== preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            $io->error('The model name must be given in UpperCamelCase, e.g. ContactGroup.');

            return null;
        }

        return $name;
    }
}
