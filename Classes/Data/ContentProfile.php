<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Data;

/**
 * Class ContentProfile
 *
 * Summarizes which file kinds an extension contains. Tools of the quality group are enabled per signal, e.g.
 * PHP-CS-Fixer for `php` and TypoScript lint for `typoscript`. Signals are derived from the file extensions the
 * profile was built from, so the profile never has to be synchronized with the tool configuration.
 *
 * @package PSBits\Crafter\Data
 */
readonly class ContentProfile
{
    /**
     * @param array<string, bool> $signals
     */
    public function __construct(
        public array $signals,
    ) {
    }

    public function has(string $signal): bool
    {
        return true === ($this->signals[$signal] ?? false);
    }
}
