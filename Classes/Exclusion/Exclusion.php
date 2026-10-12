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

namespace GeorgRinger\Linkvalidator2\Exclusion;

/**
 * An active exclusion record. It applies to links in records on its page and the
 * subpages, on the root level to all pages.
 */
final readonly class Exclusion
{
    /**
     * @param array<string, mixed> $row The record, for the fields of the type
     */
    public function __construct(
        public int $uid,
        public int $pid,
        public string $type,
        public array $row,
    ) {}

    public static function fromRow(array $row): self
    {
        return new self((int)$row['uid'], (int)$row['pid'], (string)$row['type'], $row);
    }
}
