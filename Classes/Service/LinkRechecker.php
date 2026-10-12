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

use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Repository\RecordStatusRepository;

/**
 * Checks the link of one stored result again, for "Check again" in the report.
 */
final readonly class LinkRechecker
{
    /** Nothing of the link is left in the record */
    public const GONE = 'gone';

    public function __construct(
        private RecordCheckService $recordCheckService,
        private BrokenLinkRepository $brokenLinkRepository,
        private RecordStatusRepository $recordStatusRepository,
    ) {}

    /**
     * The record is checked at once, with a new request for an external URL. The result of a
     * target is stored once, whoever links to it, so all stored results of the target take it:
     * the report is up to date without checking the other records.
     *
     * @param array<string, mixed> $result A row of the stored results, with the identifier of its target
     * @return string Status of the link, or "gone"
     */
    public function recheck(array $result): string
    {
        $identifier = (string)($result['identifier'] ?? '');
        $checkedLinks = $this->recordCheckService->checkRecord(
            (string)$result['table_name'],
            (int)$result['record_uid'],
            false,
            0,
            $identifier !== '' ? [$identifier] : []
        );
        foreach ($checkedLinks as $checkedLink) {
            if ($checkedLink->candidate->field !== (string)$result['field'] || $checkedLink->target->identifier !== $identifier) {
                continue;
            }
            foreach ($this->brokenLinkRepository->applyResultToTarget((int)$result['target'], $checkedLink->result) as $record) {
                $this->recordStatusRepository->adjustBrokenCount($record['table'], $record['uid'], $record['brokenDelta']);
            }
            return $checkedLink->result->status->value;
        }
        return self::GONE;
    }
}
