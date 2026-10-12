<?php

declare(strict_types=1);

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace GeorgRinger\Linkvalidator2\Extraction;

/**
 * A link found in a field of a record, before it is resolved.
 */
final readonly class LinkCandidate
{
    public function __construct(
        public string $table,
        public int $recordUid,
        public int $pid,
        public int $language,
        public string $field,
        public string $link,
        public string $linkText,
    ) {}
}
