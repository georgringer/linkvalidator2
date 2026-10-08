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

final readonly class RecordStatusRepository
{
    private const TABLE = 'tx_linkvalidator2_record_status';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function markChecked(string $table, int $recordUid, int $pid, int $language, int $brokenCount, int $now): void
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $identifier = ['table_name' => $table, 'record_uid' => $recordUid, 'workspace' => 0];
        $data = ['pid' => $pid, 'language' => $language, 'last_checked' => $now, 'broken_count' => $brokenCount];
        if ($connection->count('uid', self::TABLE, $identifier) > 0) {
            $connection->update(self::TABLE, $data, $identifier);
            return;
        }
        $connection->insert(self::TABLE, $identifier + $data);
    }

    public function remove(string $table, int $recordUid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['table_name' => $table, 'record_uid' => $recordUid]);
    }
}
