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
namespace TYPO3\CMS\Linkvalidator2\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Linkvalidator2\Check\CacheableLinkCheckerInterface;
use TYPO3\CMS\Linkvalidator2\Check\CheckerRegistry;
use TYPO3\CMS\Linkvalidator2\Check\CheckStatus;
use TYPO3\CMS\Linkvalidator2\Exclusion\ExclusionMatcher;
use TYPO3\CMS\Linkvalidator2\Exclusion\ExclusionRepository;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkExtractor;
use TYPO3\CMS\Linkvalidator2\Repository\BrokenLinkRepository;
use TYPO3\CMS\Linkvalidator2\Repository\RecordStatusRepository;
use TYPO3\CMS\Linkvalidator2\Repository\TargetRepository;
use TYPO3\CMS\Linkvalidator2\Target\LinkTargetFactory;

final readonly class RecordCheckService
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
        private LinkExtractor $linkExtractor,
        private LinkTargetFactory $linkTargetFactory,
        private CheckerRegistry $checkerRegistry,
        private TargetRepository $targetRepository,
        private BrokenLinkRepository $brokenLinkRepository,
        private RecordStatusRepository $recordStatusRepository,
        private Context $context,
        private ConnectionPool $connectionPool,
        private ExclusionRepository $exclusionRepository,
        private ExclusionMatcher $exclusionMatcher,
    ) {}

    /**
     * @param bool $cachedOnly Cacheable checks (external URLs) only use stored results, regardless of their
     *                         age; links without a stored result are skipped. For interactive use until
     *                         checks run in batches.
     * @param int $freshSince Stored results checked since this timestamp are reused regardless of the cache
     *                        lifetime, so a run requests each URL once
     * @return list<CheckedLink>
     */
    public function checkRecord(string $table, int $uid, bool $cachedOnly = false, int $freshSince = 0): array
    {
        $row = $this->tcaSchemaFactory->has($table) ? BackendUtility::getRecord($table, $uid) : null;
        if ($row === null) {
            $this->brokenLinkRepository->removeForRecord($table, $uid);
            $this->recordStatusRepository->remove($table, $uid);
            return [];
        }
        $schema = $this->tcaSchemaFactory->get($table);
        $now = (int)$this->context->getPropertyFromAspect('date', 'timestamp');
        $pid = $this->getContainerPid($table, $schema, $row);
        $language = $this->getLanguage($schema, $row);

        if (!$this->isVisible($schema, $row, $now)) {
            $this->brokenLinkRepository->removeForRecord($table, $uid);
            $this->recordStatusRepository->markChecked($table, $uid, $pid, $language, 0, $now);
            return [];
        }

        $checkedLinks = [];
        $brokenLinks = [];
        $skippedUnchecked = false;
        $seen = [];
        $exclusions = $this->exclusionRepository->findForPid($pid);
        $excludedCount = 0;
        foreach ($this->linkExtractor->extract($table, $row, $pid, $language) as $candidate) {
            $target = $this->linkTargetFactory->create($candidate);
            $checker = $target !== null ? $this->checkerRegistry->getChecker($target->type) : null;
            if ($target === null || $checker === null) {
                continue;
            }
            // @todo Deduplicated per field only: the same target in two fields of a record yields
            //       two broken link rows. Confirm this is intended (each field is fixed separately).
            $key = $candidate->field . '|' . $target->identifier;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            if ($this->exclusionMatcher->isExcluded($exclusions, $target)) {
                $excludedCount++;
                continue;
            }
            $stored = $checker instanceof CacheableLinkCheckerInterface ? $this->targetRepository->findStored($target) : null;
            if ($stored !== null && (
                $cachedOnly
                || $stored['lastChecked'] + $checker->getCacheLifetime() > $now
                || ($freshSince > 0 && $stored['lastChecked'] >= $freshSince)
            )) {
                // Reused results keep their check time, so they expire after the cache lifetime
                ['uid' => $targetUid, 'result' => $result] = $stored;
            } elseif ($cachedOnly && $checker instanceof CacheableLinkCheckerInterface) {
                $skippedUnchecked = true;
                continue;
            } else {
                $result = $checker->check($target, $candidate);
                $targetUid = $this->targetRepository->store($target, $result, $now);
            }
            $checkedLinks[] = new CheckedLink($candidate, $target, $result);
            if (!$result->isOk()) {
                $brokenLinks[] = ['candidate' => $candidate, 'targetUid' => $targetUid, 'result' => $result];
            }
        }
        $this->brokenLinkRepository->replaceForRecord($table, $uid, $brokenLinks, $now);
        // Notices are stored for the report, but the record is not broken because of them
        $brokenCount = count(array_filter($brokenLinks, static fn(array $brokenLink): bool => $brokenLink['result']->status !== CheckStatus::Info));
        // A link without stored result makes the record due: it is the first to be checked completely
        $this->recordStatusRepository->markChecked($table, $uid, $pid, $language, $brokenCount, $skippedUnchecked ? 0 : $now, $excludedCount);
        return $checkedLinks;
    }

    /**
     * Removes stored results of records that are stored for this page but no longer found on it:
     * deleted, moved, imported around the DataHandler or excluded from checking since.
     *
     * @param list<array{table: string, uid: int}> $foundRecords Records on the page, see PageRecordFinder::findOnPage()
     */
    public function removeStaleResults(int $pid, array $foundRecords): void
    {
        $found = [];
        foreach ($foundRecords as ['table' => $table, 'uid' => $uid]) {
            $found[$table . ':' . $uid] = true;
        }
        $stored = [...$this->recordStatusRepository->findRecordsByPid($pid), ...$this->brokenLinkRepository->findRecordsByPid($pid)];
        foreach ($stored as ['table' => $table, 'uid' => $uid]) {
            if (!isset($found[$table . ':' . $uid])) {
                $this->brokenLinkRepository->removeForRecord($table, $uid);
                $this->recordStatusRepository->remove($table, $uid);
            }
        }
    }

    /**
     * Removes stored results of records whose page no longer exists, e.g. below a deleted page.
     */
    public function removeResultsOfMissingPages(): void
    {
        $pids = array_values(array_unique([...$this->recordStatusRepository->findPids(), ...$this->brokenLinkRepository->findPids()]));
        $missing = array_diff($pids, $this->findExistingPages($pids), [0]);
        if ($missing !== []) {
            $this->brokenLinkRepository->removeByPids($missing);
            $this->recordStatusRepository->removeByPids($missing);
        }
    }

    /**
     * @param list<int> $pids
     * @return list<int>
     */
    private function findExistingPages(array $pids): array
    {
        $existing = [];
        foreach (array_chunk($pids, 1000) as $chunk) {
            $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));
            $existing = [...$existing, ...array_map(intval(...), $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where($queryBuilder->expr()->in('uid', $queryBuilder->createNamedParameter($chunk, Connection::PARAM_INT_ARRAY)))
                ->executeQuery()
                ->fetchFirstColumn())];
        }
        return $existing;
    }

    /**
     * Pages are their own container; a translated page belongs to its default language page.
     */
    private function getContainerPid(string $table, TcaSchema $schema, array $row): int
    {
        if ($table !== 'pages') {
            return (int)$row['pid'];
        }
        if ($schema->hasCapability(TcaSchemaCapability::Language)) {
            $parentField = $schema->getCapability(TcaSchemaCapability::Language)->getTranslationOriginPointerField()->getName();
            if ((int)($row[$parentField] ?? 0) > 0) {
                return (int)$row[$parentField];
            }
        }
        return (int)$row['uid'];
    }

    private function getLanguage(TcaSchema $schema, array $row): int
    {
        if (!$schema->hasCapability(TcaSchemaCapability::Language)) {
            return 0;
        }
        return (int)($row[$schema->getCapability(TcaSchemaCapability::Language)->getLanguageField()->getName()] ?? 0);
    }

    /**
     * Links in hidden or expired records are not visible to visitors and not reported.
     *
     * @todo Only the record itself is considered. Records on a hidden/expired page or below a
     *       page with extend_to_subpages are still reported although visitors never see them.
     *       Treat them as skipped together with the language rule (step 7).
     */
    private function isVisible(TcaSchema $schema, array $row, int $now): bool
    {
        if ($schema->hasCapability(TcaSchemaCapability::RestrictionDisabledField)
            && (int)($row[(string)$schema->getCapability(TcaSchemaCapability::RestrictionDisabledField)] ?? 0) === 1
        ) {
            return false;
        }
        if ($schema->hasCapability(TcaSchemaCapability::RestrictionEndTime)) {
            $endtime = (int)($row[(string)$schema->getCapability(TcaSchemaCapability::RestrictionEndTime)] ?? 0);
            if ($endtime > 0 && $endtime <= $now) {
                return false;
            }
        }
        return true;
    }
}
