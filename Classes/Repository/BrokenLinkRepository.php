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
namespace TYPO3\CMS\Linkvalidator2\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Linkvalidator2\Check\CheckResult;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;

final readonly class BrokenLinkRepository
{
    private const TABLE = 'tx_linkvalidator2_broken_link';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    /**
     * @param list<array{candidate: LinkCandidate, targetUid: int, result: CheckResult}> $brokenLinks
     */
    public function replaceForRecord(string $table, int $recordUid, array $brokenLinks, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $firstDetected = [];
        $existingRows = $connection->select(['field', 'target', 'first_detected'], self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid])->fetchAllAssociative();
        foreach ($existingRows as $row) {
            $firstDetected[$row['field'] . '|' . $row['target']] = (int)$row['first_detected'];
        }
        $connection->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
        foreach ($brokenLinks as ['candidate' => $candidate, 'targetUid' => $targetUid, 'result' => $result]) {
            $connection->insert(self::TABLE, [
                'target' => $targetUid,
                'table_name' => $table,
                'record_uid' => $recordUid,
                'field' => $candidate->field,
                'pid' => $candidate->pid,
                'language' => $candidate->language,
                'workspace' => 0,
                'link_string' => $candidate->link,
                'link_text' => $candidate->linkText,
                'status' => $result->status->value,
                'error_type' => $result->errorType,
                'error_details' => json_encode($result->details, JSON_THROW_ON_ERROR),
                'first_detected' => $firstDetected[$candidate->field . '|' . $targetUid] ?? $now,
                'last_seen' => $now,
            ]);
        }
    }

    public function removeForRecord(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
    }
}
