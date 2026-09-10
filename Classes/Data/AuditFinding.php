<?php

declare(strict_types=1);

/*
 * This file is part of PSBits Crafter.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 */

namespace PSBits\Crafter\Data;

use PSBits\Crafter\Enum\AuditSeverity;

/**
 * Class AuditFinding
 *
 * A single result of `crafter:audit`. The hint carries the Crafter command that resolves the finding, so the report
 * is directly actionable.
 *
 * @package PSBits\Crafter\Data
 */
readonly class AuditFinding
{
    public function __construct(
        public string        $category,
        public AuditSeverity $severity,
        public string        $message,
        public string        $path = '',
        public string        $hint = '',
    ) {
    }
}
