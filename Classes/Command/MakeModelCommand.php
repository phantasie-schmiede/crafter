<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Data\ControllerBlueprint;
use PSBits\Crafter\Data\ModelBlueprint;
use PSBits\Crafter\Data\PropertyDefinition;
use PSBits\Crafter\Exception\CrafterException;
use PSBits\Crafter\Service\ControllerGenerator;
use PSBits\Crafter\Service\FieldTypeRegistry;
use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Service\ModelGenerator;
use PSBits\Crafter\Service\RepositoryGenerator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

use function in_array;
use function is_string;
use function sprintf;

/**
 * Class MakeModelCommand
 *
 * Creates a domain model and optionally the matching repository.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:model',
    description: 'Creates a domain model and optionally its repository'
)]
class MakeModelCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_NAME          = 'name';
    public const string OPTION_NO_CONTROLLER   = 'no-controller';
    public const string OPTION_NO_REPOSITORY   = 'no-repository';
    public const string OPTION_PROPERTY        = 'property';
    public const string OPTION_WITH_CONTROLLER = 'with-controller';
    public const string OPTION_WITH_REPOSITORY = 'with-repository';

    public function __construct(
        protected readonly ControllerGenerator $controllerGenerator,
        protected readonly FieldTypeRegistry   $fieldTypeRegistry,
        protected readonly FileWriter          $fileWriter,
        protected readonly ModelGenerator      $modelGenerator,
        protected readonly RepositoryGenerator $repositoryGenerator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addFileOptions();
        $this->addArgument(self::ARGUMENT_NAME, InputArgument::OPTIONAL, 'Model name, e.g. Contact');
        $this->addOption(
            self::OPTION_PROPERTY,
            'p',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Property as comma separated key=value pairs, e.g. "name=title,type=string,required=1".'
            . ' Keys: name, type, required, linkedClass, foreignField. Repeatable.'
        );
        $this->addOption(
            self::OPTION_WITH_REPOSITORY,
            null,
            InputOption::VALUE_NONE,
            'Always create the matching repository'
        );
        $this->addOption(
            self::OPTION_NO_REPOSITORY,
            null,
            InputOption::VALUE_NONE,
            'Never create the matching repository'
        );
        $this->addOption(
            self::OPTION_WITH_CONTROLLER,
            null,
            InputOption::VALUE_NONE,
            'Always create a CRUD controller including templates and language files'
        );
        $this->addOption(
            self::OPTION_NO_CONTROLLER,
            null,
            InputOption::VALUE_NONE,
            'Never create a controller'
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

        $blueprint = new ModelBlueprint($extensionKey, $name, $this->determineProperties($input, $io, $extensionKey));
        $this->fileWriter->reset(
            (bool)$input->getOption(self::OPTION_DRY_RUN),
            (bool)$input->getOption(self::OPTION_FORCE)
        );
        $this->modelGenerator->generate($blueprint);

        if (true === $this->shouldCreateRepository($input, $io, $name)) {
            $this->repositoryGenerator->generate($extensionKey, $name);
        }

        if (true === $this->shouldCreateController($input, $io, $name)) {
            $this->controllerGenerator->generate(new ControllerBlueprint(
                extensionKey: $extensionKey,
                name        : $name,
                actions     : array_keys($this->controllerGenerator->getAvailableActions($extensionKey)),
                properties  : $blueprint->properties
            ));
        }

        $this->showResults($io, $results = $this->fileWriter->getResults());
        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        if (true === (bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $io->note('Dry run - no file has been written.');

            return Command::SUCCESS;
        }

        if (false === $this->hasChanges($results)) {
            $io->warning(
                sprintf(
                    'Nothing has been written, all files of model "%s" are already up to date.'
                . ' Use --force to overwrite files that differ.',
                    $name
                )
            );

            return Command::SUCCESS;
        }

        $io->success(sprintf('Model "%s" has been created.', $name));
        $io->note(
            sprintf(
                'The TCA is derived from the attributes in the model, there is nothing to add in Configuration/TCA.'
            . ' Run "vendor/bin/typo3 extension:setup" to update the database schema of %s.',
                $blueprint->getTableName()
            )
        );

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

    /**
     * @return PropertyDefinition[]
     */
    private function determineProperties(InputInterface $input, SymfonyStyle $io, string $extensionKey): array
    {
        $definitions = [];

        foreach ((array)$input->getOption(self::OPTION_PROPERTY) as $property) {
            $definitions[] = $this->parseProperty((string)$property, $extensionKey);
        }

        if ([] !== $definitions || false === $input->isInteractive()) {
            return $definitions;
        }

        $types = $this->fieldTypeRegistry->getNames($extensionKey);
        $io->section('Properties');
        $io->writeln('Leave the name empty to finish.');

        while (true) {
            $name = trim((string)$io->ask('Property name (lowerCamelCase)', ''));

            if ('' === $name) {
                break;
            }

            if (1 !== preg_match('/^[a-z][A-Za-z0-9]*$/', $name)) {
                $io->warning('The property name must be given in lowerCamelCase.');

                continue;
            }

            $type            = (string)$io->choice('Type', $types, 'string');
            $linkedClassType = $this->fieldTypeRegistry->getLinkedClassType($type, $extensionKey);
            $linkedClass     = '';
            $foreignField    = '';

            if ('' !== $linkedClassType) {
                $linkedClass = trim((string)$io->ask(
                    sprintf(
                        'Referenced %s class (UpperCamelCase)',
                        $linkedClassType
                    ),
                    ucfirst($name)
                ));
            }

            if (str_contains(
                implode(' ', $this->fieldTypeRegistry->getAttributes($type, $extensionKey)),
                '{foreignField}'
            )) {
                $foreignField = trim((string)$io->ask(
                    'Property of the child model pointing back to this model',
                    ''
                ));
            }

            $definitions[] = new PropertyDefinition(
                name        : $name,
                type        : $type,
                required    : $io->confirm('Required?', false),
                linkedClass : $linkedClass,
                foreignField: $foreignField
            );
        }

        return $definitions;
    }

    private function parseProperty(string $property, string $extensionKey): PropertyDefinition
    {
        $values = [];

        foreach (explode(',', $property) as $pair) {
            [$key, $value]      = array_pad(explode('=', trim($pair), 2), 2, '');
            $values[trim($key)] = trim($value);
        }

        if ('' === ($values['name'] ?? '') || '' === ($values['type'] ?? '')) {
            throw new CrafterException(
                sprintf('Property "%s" must at least define "name" and "type".', $property),
                1757426414
            );
        }

        // Validates the type and fails early with the list of available types.
        $this->fieldTypeRegistry->get($values['type'], $extensionKey);

        return new PropertyDefinition(
            name        : $values['name'],
            type        : $values['type'],
            required    : in_array($values['required'] ?? '', ['1', 'true', 'yes'], true),
            linkedClass : $values['linkedClass'] ?? '',
            foreignField: $values['foreignField'] ?? ''
        );
    }

    private function shouldCreateController(InputInterface $input, SymfonyStyle $io, string $name): bool
    {
        if (true === (bool)$input->getOption(self::OPTION_NO_CONTROLLER)) {
            return false;
        }

        if (true === (bool)$input->getOption(self::OPTION_WITH_CONTROLLER)) {
            return true;
        }

        if (false === $input->isInteractive()) {
            return false;
        }

        return $io->confirm(
            sprintf('Create %sController with CRUD actions, templates and language files as well?', $name),
            false
        );
    }

    private function shouldCreateRepository(InputInterface $input, SymfonyStyle $io, string $name): bool
    {
        if (true === (bool)$input->getOption(self::OPTION_NO_REPOSITORY)) {
            return false;
        }

        if (true === (bool)$input->getOption(self::OPTION_WITH_REPOSITORY)) {
            return true;
        }

        if (false === $input->isInteractive()) {
            return false;
        }

        return $io->confirm(sprintf('Create %sRepository as well?', $name));
    }
}
