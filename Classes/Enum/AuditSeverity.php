<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Enum;

/**
 * Enum AuditSeverity
 *
 * @package PSBits\Crafter\Enum
 */
enum AuditSeverity: string
{
    case error   = 'error';
    case notice  = 'notice';
    case warning = 'warning';

    public function getLabel(): string
    {
        return match ($this) {
            self::error   => '<fg=red>error</>',
            self::notice  => '<fg=gray>notice</>',
            self::warning => '<fg=yellow>warning</>',
        };
    }

    /**
     * Determines the order of the findings in the report: the most severe one first.
     */
    public function getWeight(): int
    {
        return match ($this) {
            self::error   => 0,
            self::notice  => 2,
            self::warning => 1,
        };
    }
}
