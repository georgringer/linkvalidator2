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
namespace GeorgRinger\Linkvalidator2\Check\Checker;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class RecordChecker implements LinkCheckerInterface
{
    public function __construct(
        private ConnectionPool $connectionPool,
        private TcaSchemaFactory $tcaSchemaFactory,
        private Context $context,
    ) {}

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_RECORD];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        $table = (string)$target->resolved['table'];
        $uid = (int)$target->resolved['uid'];
        if (!$this->tcaSchemaFactory->has($table)) {
            return CheckResult::broken('record.unknownTable', ['table' => $table]);
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $fields = ['uid'];
        $deletedField = $schema->hasCapability(TcaSchemaCapability::SoftDelete)
            ? (string)$schema->getCapability(TcaSchemaCapability::SoftDelete) : '';
        $hiddenField = $schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)
            ? (string)$schema->getCapability(TcaSchemaCapability::RestrictionDisabledField) : '';
        $endtimeField = $schema->hasCapability(TcaSchemaCapability::RestrictionEndTime)
            ? (string)$schema->getCapability(TcaSchemaCapability::RestrictionEndTime) : '';
        foreach ([$deletedField, $hiddenField, $endtimeField] as $field) {
            if ($field !== '') {
                $fields[] = $field;
            }
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();
        $record = $queryBuilder
            ->select(...$fields)
            ->from($table)
            ->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)))
            ->executeQuery()
            ->fetchAssociative();

        $details = ['table' => $table, 'uid' => $uid];
        if ($record === false || ($deletedField !== '' && (int)$record[$deletedField] === 1)) {
            return CheckResult::broken('record.missing', $details);
        }
        if ($hiddenField !== '' && (int)$record[$hiddenField] === 1) {
            return CheckResult::broken('record.hidden', $details);
        }
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        if ($endtimeField !== '' && (int)$record[$endtimeField] > 0 && (int)$record[$endtimeField] <= $now) {
            return CheckResult::broken('record.expired', $details);
        }
        return CheckResult::ok();
    }
}
