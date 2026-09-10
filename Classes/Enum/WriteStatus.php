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
 * Enum WriteStatus
 *
 * @package PSBits\Crafter\Enum
 */
enum WriteStatus: string
{
    case created     = 'created';
    case overwritten = 'overwritten';
    case skipped     = 'skipped';
    case unchanged   = 'unchanged';

    public function getLabel(): string
    {
        return match ($this) {
            self::created     => '<fg=green>created</>',
            self::overwritten => '<fg=yellow>overwritten</>',
            self::skipped     => '<fg=red>skipped (exists)</>',
            self::unchanged   => '<fg=gray>unchanged</>',
        };
    }

    public function hasChangedFile(): bool
    {
        return self::created === $this || self::overwritten === $this;
    }
}
