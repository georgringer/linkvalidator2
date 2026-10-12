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
namespace GeorgRinger\Linkvalidator2\MessageHandler;

use GeorgRinger\Linkvalidator2\Message\CheckDueRecordsMessage;
use GeorgRinger\Linkvalidator2\Message\CheckRecordsMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class CheckDueRecordsHandler
{
    public function __construct(
        private CheckRecordsHandler $checkRecordsHandler,
    ) {}

    public function __invoke(CheckDueRecordsMessage $message): void
    {
        ($this->checkRecordsHandler)(new CheckRecordsMessage($message->table, $message->uids));
    }
}
