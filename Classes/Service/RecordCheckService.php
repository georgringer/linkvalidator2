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
use TYPO3\CMS\Core\Schema\Capability\TcaSchemaCapability;
use TYPO3\CMS\Core\Schema\TcaSchema;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Linkvalidator2\Check\CheckerRegistry;
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
    ) {}

    /**
     * @return list<CheckedLink>
     */
    public function checkRecord(string $table, int $uid): array
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
        $seen = [];
        foreach ($this->linkExtractor->extract($table, $row, $pid, $language) as $candidate) {
            $target = $this->linkTargetFactory->create($candidate);
            $checker = $target !== null ? $this->checkerRegistry->getChecker($target->type) : null;
            if ($target === null || $checker === null) {
                continue;
            }
            $key = $candidate->field . '|' . $target->identifier;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result = $checker->check($target, $candidate);
            $targetUid = $this->targetRepository->store($target, $result, $now);
            $checkedLinks[] = new CheckedLink($candidate, $target, $result);
            if (!$result->isOk()) {
                $brokenLinks[] = ['candidate' => $candidate, 'targetUid' => $targetUid, 'result' => $result];
            }
        }
        $this->brokenLinkRepository->replaceForRecord($table, $uid, $brokenLinks, $now);
        $this->recordStatusRepository->markChecked($table, $uid, $pid, $language, count($brokenLinks), $now);
        return $checkedLinks;
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
