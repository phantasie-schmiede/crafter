<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Data;

use PSBits\Crafter\Enum\WriteStatus;

/**
 * Class WriteResult
 *
 * @package PSBits\Crafter\Data
 */
readonly class WriteResult
{
    public function __construct(
        public string      $path,
        public WriteStatus $status,
        public bool        $dryRun = false,
    ) {
    }
}
