<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use PSBits\Crafter\Service\ConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;

/**
 * Class ConfigDumpCommand
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:config:dump',
    description: 'Shows the effective Crafter configuration and which layer provided each value'
)]
class ConfigDumpCommand extends AbstractCrafterCommand
{
    public const string OPTION_ORIGINS = 'origins';

    public function __construct(
        protected readonly ConfigurationService $configurationService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(
            <<<'HELP'
            Shows the effective Crafter configuration for an extension as merged YAML, including
            the fieldTypes and classTypes catalogues and the skeleton root paths. The configuration
            is resolved from the five layers: the shipped defaults, the project Crafter.yaml, the
            extension Crafter.yaml, the command line and events.

            With --origins every single value is listed together with the layer that provided it.

            Example:
              crafter:config:dump --extension budget_manager --origins
            HELP
        );
        $this->addOption(
            self::OPTION_ORIGINS,
            'o',
            InputOption::VALUE_NONE,
            'List the origin of every single configuration value instead of the merged YAML'
        );
    }

    protected function handle(InputInterface $input, SymfonyStyle $io): int
    {
        $extensionKey = $this->getExtensionKey($input);
        $io->title('Crafter configuration' . (null !== $extensionKey ? ' for "' . $extensionKey . '"' : ''));
        $layerRows = [];

        foreach ($this->configurationService->getLayers($extensionKey) as $origin => $file) {
            $layerRows[] = [
                $origin,
                $file,
            ];
        }

        $io->section('Layers (later wins)');
        $io->table([
            'Layer',
            'File',
        ], $layerRows);

        if (true === $input->getOption(self::OPTION_ORIGINS)) {
            $originRows = [];

            foreach ($this->configurationService->getOrigins($extensionKey) as $path => $origin) {
                $originRows[] = [
                    $path,
                    $origin,
                ];
            }

            $io->section('Value origins');
            $io->table([
                'Path',
                'Layer',
            ], $originRows);

            return Command::SUCCESS;
        }

        $io->section('Effective configuration');
        $io->writeln(Yaml::dump(
            [ConfigurationService::ROOT_KEY => $this->configurationService->get($extensionKey)],
            10,
            2
        ));

        return Command::SUCCESS;
    }
}
