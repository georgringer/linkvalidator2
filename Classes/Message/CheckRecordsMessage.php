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
namespace GeorgRinger\Linkvalidator2\Message;

/**
 * Records of one table whose links are to be checked, queued by the fillqueue command.
 */
final readonly class CheckRecordsMessage
{
    /**
     * @param list<int> $uids
     */
    public function __construct(
        public string $table,
        public array $uids,
    ) {}
}
