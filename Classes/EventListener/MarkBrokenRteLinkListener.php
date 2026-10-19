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
namespace GeorgRinger\Linkvalidator2\EventListener;

use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Html\Event\BrokenLinkAnalysisEvent;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use GeorgRinger\Linkvalidator2\Check\CheckerRegistry;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Report\ErrorMessageFormatter;
use GeorgRinger\Linkvalidator2\Repository\TargetRepository;
use GeorgRinger\Linkvalidator2\Target\LinkTargetFactory;

/**
 * Marks broken links when a rich text field is opened in the editor, using the same
 * checkers as the report. External links are not requested here: their last known
 * result is taken from the target cache. The event does not tell which record the link
 * belongs to: the page is taken from the record being edited, for record link identifiers
 * mapped in Page TSconfig.
 */
final readonly class MarkBrokenRteLinkListener
{
    public function __construct(
        private LinkTargetFactory $linkTargetFactory,
        private CheckerRegistry $checkerRegistry,
        private TargetRepository $targetRepository,
        private ErrorMessageFormatter $errorMessageFormatter,
    ) {}

    #[AsEventListener('linkvalidator2/rte-check-link', before: 'rte-check-link-external,rte-check-link-to-page,rte-check-link-to-file')]
    public function __invoke(BrokenLinkAnalysisEvent $event): void
    {
        $resolved = $event->getLinkData();
        $resolved['type'] ??= $event->getLinkType();
        $source = new LinkCandidate('', 0, $this->getEditedPageUid(), 0, '', '', '');
        $target = $this->linkTargetFactory->createFromResolved($resolved, $source);
        if ($target === null) {
            return;
        }
        if ($target->type === LinkService::TYPE_URL) {
            $result = $this->targetRepository->findResult($target);
        } else {
            $result = $this->checkerRegistry->check($target, $source);
            if ($result === null) {
                return;
            }
        }
        $event->markAsCheckedLink();
        // Without the right Page TSconfig the identifier is taken as table: not reliable
        if ($result?->status === CheckStatus::Broken && $result->errorType !== 'record.unknownTable') {
            $event->markAsBrokenLink($this->errorMessageFormatter->format($result->errorType, $result->details));
        }
    }

    /**
     * Page of the record opened in the form engine ("edit[table][uid]=edit", or the page
     * a new record is created on), 0 if unknown
     */
    private function getEditedPageUid(): int
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (!$request instanceof ServerRequestInterface) {
            return 0;
        }
        $edit = $request->getQueryParams()['edit'] ?? (array)($request->getParsedBody()['edit'] ?? []);
        foreach (is_array($edit) ? $edit : [] as $table => $records) {
            foreach (is_array($records) ? $records : [] as $uid => $command) {
                $uid = (int)$uid;
                if ($command === 'new') {
                    // A negative uid creates the record after that record
                    return $uid >= 0 ? $uid : (int)(BackendUtility::getRecord((string)$table, -$uid, 'pid')['pid'] ?? 0);
                }
                $row = BackendUtility::getRecord((string)$table, $uid, 'pid');
                if ($row !== null) {
                    return $table === 'pages' ? $uid : (int)$row['pid'];
                }
            }
        }
        return 0;
    }
}
