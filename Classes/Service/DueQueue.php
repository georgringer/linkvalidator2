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
namespace GeorgRinger\Linkvalidator2\Service;

use GeorgRinger\Linkvalidator2\Message\CheckDueRecordsMessage;
use GeorgRinger\Linkvalidator2\Repository\RecordStatusRepository;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Marks records as due and queues them for the transport with priority, so that they are
 * checked before the regular queue, which can hold the records of a whole site.
 */
final readonly class DueQueue
{
    private const CHUNK_SIZE = 25;

    public function __construct(
        private RecordStatusRepository $recordStatusRepository,
        private MessageBusInterface $messageBus,
    ) {}

    /**
     * @param list<array{table: string, uid: int}> $records
     */
    public function queue(array $records): void
    {
        if ($records === []) {
            return;
        }
        $this->recordStatusRepository->markDue($records);
        $uidsByTable = [];
        foreach ($records as ['table' => $table, 'uid' => $uid]) {
            $uidsByTable[$table][$uid] = $uid;
        }
        foreach ($uidsByTable as $table => $uids) {
            foreach (array_chunk(array_values($uids), self::CHUNK_SIZE) as $chunk) {
                $this->messageBus->dispatch(new CheckDueRecordsMessage((string)$table, $chunk));
            }
        }
    }
}
