<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Utility;

use PSBits\Crafter\Command\AbstractCrafterCommand;
use ReflectionClass;
use Symfony\Component\Console\Attribute\AsCommand;

use function basename;
use function class_exists;
use function dirname;
use function explode;
use function glob;
use function is_a;
use function ksort;

/**
 * Class CommandInventory
 *
 * Discovers the commands shipped by this extension by reading the AsCommand attributes of the command classes,
 * without booting TYPO3 or instantiating a service.
 *
 * @package PSBits\Crafter\Utility
 */
final class CommandInventory
{
    private const string NAMESPACE_PREFIX = 'PSBits\\Crafter\\Command\\';

    /**
     * @return array<string, array{description: string}> commands keyed by their primary name, sorted by name
     */
    public function find(): array
    {
        $files    = glob(dirname(__DIR__) . '/Command/*Command.php') ?: [];
        $commands = [];

        foreach ($files as $file) {
            $className = self::classFromFileName($file);

            if (
                !class_exists($className)
                || true !== is_a($className, AbstractCrafterCommand::class, true)
                || (new ReflectionClass($className))->isAbstract()
            ) {
                continue;
            }

            $attribute = (new ReflectionClass($className))->getAttributes(AsCommand::class);

            if ([] === $attribute) {
                continue;
            }

            $command = $attribute[0]->newInstance();
            $names   = explode('|', (string)$command->name);

            // Symfony encodes a hidden command as an empty first name
            $primary = '' === $names[0] ? ($names[1] ?? '') : $names[0];

            if ('' === $primary) {
                continue;
            }

            $commands[$primary] = ['description' => (string)$command->description];
        }

        ksort($commands);

        return $commands;
    }

    private static function classFromFileName(string $file): string
    {
        return self::NAMESPACE_PREFIX . basename($file, '.php');
    }
}
