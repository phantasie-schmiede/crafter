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
use PSBits\Crafter\Data\PropertyDefinition;
use PSBits\Crafter\Service\ControllerGenerator;
use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Service\ModelPropertyReader;
use PSBits\Crafter\Service\PathResolver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MakeControllerCommand
 *
 * Creates a frontend controller including its Fluid templates, language files and plugin registration.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:controller',
    description: 'Creates a frontend controller, optionally with CRUD actions, templates and language files'
)]
class MakeControllerCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_NAME       = 'name';
    public const string OPTION_ACTIONS      = 'actions';
    public const string OPTION_MODEL        = 'model';
    public const string OPTION_NO_LANGUAGE  = 'no-language';
    public const string OPTION_NO_PLUGIN    = 'no-plugin';
    public const string OPTION_NO_TEMPLATES = 'no-templates';
    public const string OPTION_PLUGIN_NAME  = 'plugin-name';

    public function __construct(
        protected readonly ControllerGenerator $controllerGenerator,
        protected readonly FileWriter          $fileWriter,
        protected readonly ModelPropertyReader $modelPropertyReader,
        protected readonly PathResolver        $pathResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addFileOptions();
        $this->addArgument(self::ARGUMENT_NAME, InputArgument::OPTIONAL, 'Controller name without suffix, e.g. Product');
        $this->addOption(
            self::OPTION_ACTIONS,
            'a',
            InputOption::VALUE_REQUIRED,
            'Comma separated list of actions, e.g. "list,show". Use "crud" for all CRUD actions.'
        );
        $this->addOption(
            self::OPTION_MODEL,
            'm',
            InputOption::VALUE_REQUIRED,
            'Model the controller works with, defaults to the controller name'
        );
        $this->addOption(
            self::OPTION_PLUGIN_NAME,
            null,
            InputOption::VALUE_REQUIRED,
            'Name of the plugin registered in ExtensionInformation, defaults to the plural of the controller name'
        );
        $this->addOption(self::OPTION_NO_LANGUAGE, null, InputOption::VALUE_NONE, 'Do not create language files');
        $this->addOption(self::OPTION_NO_PLUGIN, null, InputOption::VALUE_NONE, 'Do not register a plugin');
        $this->addOption(self::OPTION_NO_TEMPLATES, null, InputOption::VALUE_NONE, 'Do not create Fluid templates');
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

        $modelName = (string)($input->getOption(self::OPTION_MODEL) ?? '');
        $actions   = $this->determineActions($input, $io, $extensionKey);

        if ([] === $actions) {
            $io->error('At least one action is required.');

            return Command::FAILURE;
        }

        $blueprint = new ControllerBlueprint(
            extensionKey     : $extensionKey,
            name             : $name,
            modelName        : $modelName,
            actions          : $actions,
            properties       : $this->readProperties($extensionKey, '' !== $modelName ? $modelName : $name),
            withTemplates    : false === (bool)$input->getOption(self::OPTION_NO_TEMPLATES),
            withLanguageFiles: false === (bool)$input->getOption(self::OPTION_NO_LANGUAGE),
            withPlugin       : false === (bool)$input->getOption(self::OPTION_NO_PLUGIN),
            pluginName       : (string)($input->getOption(self::OPTION_PLUGIN_NAME) ?? '')
        );
        $this->fileWriter->reset(
            (bool)$input->getOption(self::OPTION_DRY_RUN),
            (bool)$input->getOption(self::OPTION_FORCE)
        );
        $this->controllerGenerator->generate($blueprint);
        $this->showResults($io, $results = $this->fileWriter->getResults());
        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        if (true === (bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $io->note('Dry run - no file has been written.');

            return Command::SUCCESS;
        }

        if (false === $this->hasChanges($results)) {
            $io->warning(sprintf(
                'Nothing has been written, all files of controller "%sController" are already up to date.'
                . ' Use --force to overwrite files that differ.',
                $name
            ));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Controller "%sController" has been created.', $name));

        if (true === $blueprint->withPlugin) {
            $io->note(sprintf(
                'The plugin "%s" is registered in ExtensionInformation. Foundation derives the plugin'
                . ' configuration from the PluginAction attributes, no further registration is required.',
                $blueprint->getPluginName()
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * @return string[]
     */
    private function determineActions(InputInterface $input, SymfonyStyle $io, string $extensionKey): array
    {
        $available = array_keys($this->controllerGenerator->getAvailableActions($extensionKey));
        $actions   = (string)($input->getOption(self::OPTION_ACTIONS) ?? '');

        if ('' === $actions && true === $input->isInteractive()) {
            $actions = true === $io->confirm('Generate the CRUD actions?')
                ? 'crud'
                : implode(',', (array)$io->choice('Actions', $available, 'list', true));
        }

        if ('' === $actions || 'crud' === $actions) {
            $crud = $this->controllerGenerator->getAvailableActions($extensionKey);

            return array_keys($crud);
        }

        return array_values(array_filter(array_map('trim', explode(',', $actions))));
    }

    private function determineName(InputInterface $input, SymfonyStyle $io): ?string
    {
        $name = $input->getArgument(self::ARGUMENT_NAME);

        if ((!is_string($name) || '' === $name) && true === $input->isInteractive()) {
            $name = (string)$io->ask('Controller name without the suffix "Controller" (UpperCamelCase)');
        }

        if (!is_string($name) || 1 !== preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            $io->error('The controller name must be given in UpperCamelCase, e.g. Product.');

            return null;
        }

        return $name;
    }

    /**
     * @return PropertyDefinition[]
     */
    private function readProperties(string $extensionKey, string $modelName): array
    {
        return $this->modelPropertyReader->read(
            $this->pathResolver->resolveTargetPath('model', $extensionKey, ['name' => $modelName]),
            $extensionKey
        );
    }
}
