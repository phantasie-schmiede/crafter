<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Service\BackendControllerGenerator;
use PSBits\Crafter\Service\ClassGenerator;
use PSBits\Crafter\Service\FileWriter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

use function is_string;
use function sprintf;

/**
 * Class MakeBackendControllerCommand
 *
 * Creates a backend module controller including its templates, language files and the module registration.
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:backend-controller',
    description: 'Creates a backend module controller including templates, language files and module registration'
)]
class MakeBackendControllerCommand extends AbstractCrafterCommand
{
    public const string ARGUMENT_NAME       = 'name';
    public const string OPTION_ACTIONS      = 'actions';
    public const string OPTION_DEPENDENCY   = 'dependency';
    public const string OPTION_MODULE_NAME  = 'module-name';
    public const string OPTION_NO_LANGUAGE  = 'no-language';
    public const string OPTION_NO_MODULE    = 'no-module';
    public const string OPTION_NO_TEMPLATES = 'no-templates';

    public function __construct(
        protected readonly BackendControllerGenerator $backendControllerGenerator,
        protected readonly ClassGenerator             $classGenerator,
        protected readonly FileWriter                 $fileWriter,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addFileOptions();
        $this->addArgument(
            self::ARGUMENT_NAME,
            InputArgument::OPTIONAL,
            'Name of the controller without the Controller suffix, e.g. Import'
        );
        $this->addOption(
            self::OPTION_ACTIONS,
            'a',
            InputOption::VALUE_REQUIRED,
            'Comma separated list of actions, the first one becomes the default action',
            'index'
        );
        $this->addOption(
            self::OPTION_DEPENDENCY,
            'd',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Constructor dependency, either a fully qualified class name or a short name like ContactRepository'
        );
        $this->addOption(
            self::OPTION_MODULE_NAME,
            null,
            InputOption::VALUE_REQUIRED,
            'Name of the backend module, defaults to the lower camel case controller name'
        );
        $this->addOption(self::OPTION_NO_TEMPLATES, null, InputOption::VALUE_NONE, 'Do not create Fluid templates');
        $this->addOption(self::OPTION_NO_LANGUAGE, null, InputOption::VALUE_NONE, 'Do not create language files');
        $this->addOption(
            self::OPTION_NO_MODULE,
            null,
            InputOption::VALUE_NONE,
            'Do not register the module in the ExtensionInformation'
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

        $actions = $this->determineActions($input, $io);

        if (null === $actions) {
            return Command::FAILURE;
        }

        $dependencies = [];

        foreach ((array)$input->getOption(self::OPTION_DEPENDENCY) as $dependency) {
            $dependency = trim((string)$dependency);

            if ('' !== $dependency) {
                $dependencies[] = $this->classGenerator->resolveDependency($dependency, $extensionKey);
            }
        }

        $this->fileWriter->reset(
            (bool)$input->getOption(self::OPTION_DRY_RUN),
            (bool)$input->getOption(self::OPTION_FORCE)
        );
        $this->backendControllerGenerator->generate(
            extensionKey     : $extensionKey,
            name             : $name,
            actions          : $actions,
            dependencies     : $dependencies,
            moduleName       : (string)($input->getOption(self::OPTION_MODULE_NAME) ?? ''),
            withTemplates    : false === (bool)$input->getOption(self::OPTION_NO_TEMPLATES),
            withLanguageFiles: false === (bool)$input->getOption(self::OPTION_NO_LANGUAGE),
            withModule       : false === (bool)$input->getOption(self::OPTION_NO_MODULE)
        );
        $this->showResults($io, $results = $this->fileWriter->getResults());
        $this->fileWriter->finish((string)$this->getName(), $extensionKey);

        if (true === (bool)$input->getOption(self::OPTION_DRY_RUN)) {
            $io->note('Dry run - no file has been written.');

            return Command::SUCCESS;
        }

        if (false === $this->hasChanges($results)) {
            $io->warning(
                sprintf(
                    'Nothing has been written, "%sController" is already up to date.'
                . ' Use --force to overwrite a file that differs.',
                    $name
                )
            );

            return Command::SUCCESS;
        }

        $io->success(sprintf('Backend controller "%sController" has been created.', $name));

        return Command::SUCCESS;
    }

    /**
     * @return string[]|null
     */
    private function determineActions(InputInterface $input, SymfonyStyle $io): ?array
    {
        $actions = array_values(array_filter(array_map(
            static fn(string $action): string => lcfirst(trim($action)),
            explode(',', (string)$input->getOption(self::OPTION_ACTIONS))
        )));

        if ([] === $actions) {
            $io->error('At least one action is required.');

            return null;
        }

        foreach ($actions as $action) {
            if (1 !== preg_match('/^[a-z][A-Za-z0-9]*$/', $action)) {
                $io->error(sprintf('The action "%s" must be given in lowerCamelCase, e.g. showUploadForm.', $action));

                return null;
            }
        }

        return array_values(array_unique($actions));
    }

    private function determineName(InputInterface $input, SymfonyStyle $io): ?string
    {
        $name = $input->getArgument(self::ARGUMENT_NAME);

        if ((!is_string($name) || '' === $name) && true === $input->isInteractive()) {
            $name = (string)$io->ask('Controller name without the Controller suffix (UpperCamelCase)');
        }

        if (!is_string($name) || 1 !== preg_match('/^[A-Z][A-Za-z0-9]*$/', $name)) {
            $io->error('The controller name must be given in UpperCamelCase, e.g. Import.');

            return null;
        }

        return preg_replace('/Controller$/', '', $name) ?: $name;
    }
}
