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
namespace GeorgRinger\Linkvalidator2\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class TargetRepository
{
    public const TABLE = 'tx_linkvalidator2_target';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {}

    public function findResult(LinkTarget $target): ?CheckResult
    {
        return $this->findStored($target)['result'] ?? null;
    }

    /**
     * @return array{uid: int, result: CheckResult, lastChecked: int}|null
     */
    public function findStored(LinkTarget $target): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->select(['uid', 'status', 'error_type', 'error_details', 'last_checked'], self::TABLE, ['identifier_hash' => $target->getHash()])
            ->fetchAssociative();
        if ($row === false || CheckStatus::tryFrom((string)$row['status']) === null) {
            return null;
        }
        return [
            'uid' => (int)$row['uid'],
            'result' => new CheckResult(
                CheckStatus::from((string)$row['status']),
                (string)$row['error_type'],
                (array)json_decode((string)$row['error_details'], true)
            ),
            'lastChecked' => (int)$row['last_checked'],
        ];
    }

    public function store(LinkTarget $target, CheckResult $result, int $now): int
    {
        $connection = $this->connectionPool->getConnectionForTable(self::TABLE);
        $existing = $connection->select(['uid', 'failure_count', 'first_failed_at'], self::TABLE, ['identifier_hash' => $target->getHash()])->fetchAssociative();
        $data = [
            'link_type' => $target->type,
            'identifier' => $target->identifier,
            'identifier_hash' => $target->getHash(),
            'status' => $result->status->value,
            'error_type' => $result->errorType,
            'error_details' => json_encode($result->details, JSON_THROW_ON_ERROR),
            'last_checked' => $now,
            'failure_count' => $result->isOk() ? 0 : (int)($existing['failure_count'] ?? 0) + 1,
            'first_failed_at' => $result->isOk() ? 0 : ((int)($existing['first_failed_at'] ?? 0) ?: $now),
        ];
        if ($existing === false) {
            $connection->insert(self::TABLE, $data);
            return (int)$connection->lastInsertId();
        }
        $connection->update(self::TABLE, $data, ['uid' => (int)$existing['uid']]);
        return (int)$existing['uid'];
    }
}
