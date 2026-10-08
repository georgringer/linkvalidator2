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

namespace TYPO3\CMS\Linkvalidator2\Check;

final readonly class CheckResult
{
    public function __construct(
        public CheckStatus $status,
        public string $errorType = '',
        public array $details = [],
    ) {}

    public static function ok(): self
    {
        return new self(CheckStatus::Ok);
    }

    public static function broken(string $errorType, array $details = []): self
    {
        return new self(CheckStatus::Broken, $errorType, $details);
    }

    public static function unknown(string $errorType, array $details = []): self
    {
        return new self(CheckStatus::Unknown, $errorType, $details);
    }

    public function isOk(): bool
    {
        return $this->status === CheckStatus::Ok;
    }
}
