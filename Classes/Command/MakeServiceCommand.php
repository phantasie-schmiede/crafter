<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Command;

use JsonException;
use PSBits\Crafter\Data\ClassBlueprint;
use PSBits\Crafter\Exception\CrafterException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Exception\InvalidArgumentException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates a service class with its constructor dependencies.
 */
#[AsCommand(
    name: 'crafter:make:service',
    description: 'Creates a service class'
)]
class MakeServiceCommand extends AbstractMakeClassCommand
{
    public const string CLASS_TYPE = 'service';

    /**
     * @throws CrafterException
     * @throws InvalidArgumentException
     * @throws JsonException
     */
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

        return new ClassBlueprint(
            extensionKey: $extensionKey,
            name        : $name,
            type        : self::CLASS_TYPE,
            dependencies: $this->getDependencies($input, $extensionKey),
            subPath     : $subPath
        );
    }

    /**
     * @throws InvalidArgumentException
     */
    protected function configure(): void
    {
        parent::configure();
        $this->setHelp(
            <<<'HELP'
            Creates a service class with its constructor dependencies.

            Dependencies are given with the repeatable option --dependency as fully qualified
            class names or as short names like ContactRepository, which are resolved against the
            target extension. Use --sub-path to place the service in a sub directory of the base
            directory of the class type.

            Example:
              crafter:make:service --extension budget_manager Import \
                  --dependency ContactRepository --sub-path Import
            HELP
        );
        $this->addDependencyOption();
        $this->addSubPathOption();
    }

    protected function getArtifactLabel(): string
    {
        return 'Service';
    }

    protected function getNameDescription(): string
    {
        return 'Name of the service without the Service suffix, e.g. Import';
    }
}
