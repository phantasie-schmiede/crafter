<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Data\ClassBlueprint;
use PSBits\Crafter\Service\ClassGenerator;
use PSBits\Crafter\Service\FileWriter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class AbstractMakeClassCommand
 *
 * Shared skeleton of all commands that create a single PHP class of the crafter.classTypes catalogue. Subclasses
 * only describe the class by building a ClassBlueprint.
 *
 * @package PSBits\Crafter\Command
 */
abstract class AbstractMakeClassCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_NAME     = 'name';
    public const string NAME_PATTERN      = '/^[A-Z][A-Za-z0-9]*$/';
    public const string OPTION_DEPENDENCY = 'dependency';
    public const string OPTION_SUB_PATH   = 'sub-path';
    public const string SUB_PATH_PATTERN  = '#^[A-Z][A-Za-z0-9]*(/[A-Z][A-Za-z0-9]*)*$#';

    public function __construct(
        protected readonly ClassGenerator $classGenerator,
        protected readonly FileWriter     $fileWriter,
    ) {
        parent::__construct();
    }

    abstract protected function buildBlueprint(
        InputInterface $input,
        SymfonyStyle   $io,
        string         $extensionKey,
        string         $name,
    ): ?ClassBlueprint;

    protected function addDependencyOption(): static
    {
        $this->addOption(
            self::OPTION_DEPENDENCY,
            'd',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Constructor dependency, either a fully qualified class name or a short name like ContactRepository'
        );

        return $this;
    }

    protected function addSubPathOption(): static
    {
        $this->addOption(
            self::OPTION_SUB_PATH,
            null,
            InputOption::VALUE_REQUIRED,
            'Sub directory below the base directory of the class type, e.g. Contact'
        );

        return $this;
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addFileOptions();
        $this->addArgument(self::ARGUMENT_NAME, InputArgument::OPTIONAL, $this->getNameDescription());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io           = $this->getIo($input, $output);
        $extensionKey = $this->getExtensionKey($input);

        if (null === $extensionKey) {
            $io->error('The option --extension is required.');

            return Command::FAILURE;
        }

        $name = $this->determineName($input, $io);

        if (null === $name) {
            return Command::FAILURE;
        }

        $blueprint = $this->buildBlueprint($input, $io, $extensionKey, $name);

        if (null === $blueprint) {
            return Command::FAILURE;
        }

        $this->fileWriter->reset(
            (bool)$input->getOption(self::OPTION_DRY_RUN),
            (bool)$input->getOption(self::OPTION_FORCE)
        );
        $this->classGenerator->generate($blueprint);
        $this->showResults($io, $results = $this->fileWriter->getResults());
        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        if (true === (bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $io->note('Dry run - no file has been written.');

            return Command::SUCCESS;
        }

        $className = $this->classGenerator->getClassName($blueprint);

        if (false === $this->hasChanges($results)) {
            $io->warning(sprintf(
                'Nothing has been written, "%s" is already up to date. Use --force to overwrite a file that differs.',
                $className
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf('%s "%s" has been created.', $this->getArtifactLabel(), $className));

        return Command::SUCCESS;
    }

    /**
     * @return string[] fully qualified class names
     */
    protected function getDependencies(InputInterface $input, string $extensionKey): array
    {
        $dependencies = [];

        foreach ((array)$input->getOption(self::OPTION_DEPENDENCY) as $dependency) {
            $dependency = trim((string)$dependency);

            if ('' !== $dependency) {
                $dependencies[] = $this->classGenerator->resolveDependency($dependency, $extensionKey);
            }
        }

        return $dependencies;
    }

    protected function getSubPath(InputInterface $input, SymfonyStyle $io): ?string
    {
        $subPath = $input->getOption(self::OPTION_SUB_PATH);

        if (!is_string($subPath) || '' === $subPath) {
            return '';
        }

        $subPath = trim(str_replace('\\', '/', $subPath), '/');

        if (1 !== preg_match(self::SUB_PATH_PATTERN, $subPath)) {
            $io->error('The sub path must consist of UpperCamelCase segments, e.g. Contact/Address.');

            return null;
        }

        return $subPath;
    }

    abstract protected function getArtifactLabel(): string;

    abstract protected function getNameDescription(): string;

    private function determineName(InputInterface $input, SymfonyStyle $io): ?string
    {
        $name = $input->getArgument(self::ARGUMENT_NAME);

        if ((!is_string($name) || '' === $name) && true === $input->isInteractive()) {
            $name = (string)$io->ask('Name (UpperCamelCase)');
        }

        if (!is_string($name) || 1 !== preg_match(self::NAME_PATTERN, $name)) {
            $io->error('The name must be given in UpperCamelCase, e.g. ContactImport.');

            return null;
        }

        return $name;
    }
}
