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
use PSBits\Crafter\Service\ConfigurationService;
use PSBits\Crafter\Service\FileWriter;
use PSBits\Crafter\Service\SkeletonRenderer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Class MakeViewHelperCommand
 *
 * Creates a Fluid view helper including initializeArguments() and render().
 *
 * @package PSBits\Crafter\Command
 */
#[AsCommand(
    name: 'crafter:make:view-helper',
    description: 'Creates a Fluid view helper'
)]
class MakeViewHelperCommand extends AbstractMakeClassCommand
{
    public const string ARGUMENT_PATTERN = '/^(?<name>[a-z][A-Za-z0-9]*)(?::(?<type>[a-z]+))?'
        . '(?::(?<required>0|1))?(?::(?<description>.*))?$/';
    public const string CLASS_TYPE                 = 'viewHelper';
    public const string OPTION_ARGUMENT            = 'argument';
    public const string OPTION_RETURN_TYPE         = 'return-type';
    public const string SKELETON_INITIALIZE        = 'Php/InitializeArguments';
    public const string SKELETON_REGISTER_ARGUMENT = 'Php/RegisterArgument';
    public const string SKELETON_RENDER            = 'Php/ViewHelperRender';

    public function __construct(
        ClassGenerator                          $classGenerator,
        FileWriter                              $fileWriter,
        protected readonly ConfigurationService $configurationService,
        protected readonly SkeletonRenderer     $skeletonRenderer,
    ) {
        parent::__construct($classGenerator, $fileWriter);
    }

    protected function configure(): void
    {
        parent::configure();
        $this->addDependencyOption();
        $this->addSubPathOption();
        $this->addOption(
            self::OPTION_ARGUMENT,
            'a',
            InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
            'Argument as name[:type[:required[:description]]], e.g. contact:object:1:The contact to render'
        );
        $this->addOption(
            self::OPTION_RETURN_TYPE,
            'r',
            InputOption::VALUE_REQUIRED,
            'Return type of the render method',
            'string'
        );
    }

    protected function buildBlueprint(
        InputInterface $input,
        SymfonyStyle   $io,
        string         $extensionKey,
        string         $name,
    ): ?ClassBlueprint {
        $subPath = $this->getSubPath($input, $io);

        if (null === $subPath) {
            return null;
        }

        $arguments = $this->renderArguments($input, $io, $extensionKey);

        if (null === $arguments) {
            return null;
        }

        $render = $this->renderRenderMethod($input, $io, $extensionKey);

        if (null === $render) {
            return null;
        }

        return new ClassBlueprint(
            extensionKey: $extensionKey,
            name        : $name,
            type        : self::CLASS_TYPE,
            dependencies: $this->getDependencies($input, $extensionKey),
            methods     : array_values(array_filter([$arguments, $render])),
            subPath     : $subPath
        );
    }

    protected function getArtifactLabel(): string
    {
        return 'View helper';
    }

    protected function getNameDescription(): string
    {
        return 'Name of the view helper without the ViewHelper suffix, e.g. CountyNames';
    }

    /**
     * @return string|null the rendered initializeArguments() method, an empty string if there is no argument or null
     *                     on invalid input
     */
    private function renderArguments(InputInterface $input, SymfonyStyle $io, string $extensionKey): ?string
    {
        $availableTypes = (array)$this->configurationService->getValue(
            'viewHelperArgumentTypes',
            $extensionKey,
            ['mixed']
        );
        $registrations = [];

        foreach ((array)$input->getOption(self::OPTION_ARGUMENT) as $definition) {
            $definition = trim((string)$definition);

            if ('' === $definition) {
                continue;
            }

            if (1 !== preg_match(self::ARGUMENT_PATTERN, $definition, $match)) {
                $io->error(sprintf(
                    'The argument "%s" is malformed. Expected name[:type[:required[:description]]].',
                    $definition
                ));

                return null;
            }

            $type = '' === ($match['type'] ?? '') ? 'mixed' : $match['type'];

            if (false === in_array($type, $availableTypes, true)) {
                $io->error(sprintf(
                    'Unknown argument type "%s". Available types: %s',
                    $type,
                    implode(', ', array_map(static fn(mixed $value): string => (string)$value, $availableTypes))
                ));

                return null;
            }

            $registrations[] = rtrim($this->skeletonRenderer->render(
                self::SKELETON_REGISTER_ARGUMENT,
                [
                    'argumentDescription' => $match['description'] ?? '',
                    'argumentName'        => $match['name'],
                    'argumentRequired'    => '1' === ($match['required'] ?? '') ? 'true' : 'false',
                    'argumentType'        => $type,
                ],
                $extensionKey
            ));
        }

        if ([] === $registrations) {
            return '';
        }

        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_INITIALIZE,
            ['registerArguments' => implode("\n", $registrations)],
            $extensionKey
        ));
    }

    private function renderRenderMethod(InputInterface $input, SymfonyStyle $io, string $extensionKey): ?string
    {
        $returnTypes = (array)$this->configurationService->getValue('viewHelperReturnTypes', $extensionKey, []);
        $returnType  = (string)$input->getOption(self::OPTION_RETURN_TYPE);

        if (false === isset($returnTypes[$returnType])) {
            $io->error(sprintf(
                'Unknown return type "%s". Available types: %s',
                $returnType,
                implode(', ', array_keys($returnTypes))
            ));

            return null;
        }

        $configuration = (array)$returnTypes[$returnType];
        $docBlock      = (string)($configuration['docBlock'] ?? '');

        return rtrim($this->skeletonRenderer->render(
            self::SKELETON_RENDER,
            [
                'renderDocBlock'    => '' === $docBlock ? '' : '/** ' . $docBlock . ' */',
                'renderReturnType'  => $returnType,
                'renderReturnValue' => (string)($configuration['value'] ?? 'null'),
            ],
            $extensionKey
        ));
    }
}
