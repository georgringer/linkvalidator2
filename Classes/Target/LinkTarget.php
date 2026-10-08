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

namespace TYPO3\CMS\Linkvalidator2\Target;

/**
 * A resolved link target. The identifier is normalized, so equal targets
 * found in different records share one identifier.
 */
final readonly class LinkTarget
{
    public const TYPE_INVALID = 'invalid';

    public function __construct(
        public string $type,
        public string $identifier,
        public array $resolved = [],
    ) {}

    public static function invalid(string $link, string $reason): self
    {
        return new self(self::TYPE_INVALID, self::TYPE_INVALID . ':' . $link, ['reason' => $reason]);
    }

    public function getHash(): string
    {
        return sha1($this->identifier);
    }
}
