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
namespace TYPO3\CMS\Linkvalidator2\EventListener;

use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Html\Event\BrokenLinkAnalysisEvent;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Linkvalidator2\Check\CheckerRegistry;
use TYPO3\CMS\Linkvalidator2\Check\CheckResult;
use TYPO3\CMS\Linkvalidator2\Check\CheckStatus;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;
use TYPO3\CMS\Linkvalidator2\Repository\TargetRepository;
use TYPO3\CMS\Linkvalidator2\Target\LinkTargetFactory;

/**
 * Marks broken links when a rich text field is opened in the editor, using the same
 * checkers as the report. External links are not requested here: their last known
 * result is taken from the target cache.
 */
final readonly class MarkBrokenRteLinkListener
{
    public function __construct(
        private LinkTargetFactory $linkTargetFactory,
        private CheckerRegistry $checkerRegistry,
        private TargetRepository $targetRepository,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    #[AsEventListener('linkvalidator2/rte-check-link', before: 'rte-check-link-external,rte-check-link-to-page,rte-check-link-to-file')]
    public function __invoke(BrokenLinkAnalysisEvent $event): void
    {
        $resolved = $event->getLinkData();
        $resolved['type'] ??= $event->getLinkType();
        // The event does not tell which record the link belongs to
        // @todo Without a pid, record links whose identifier is mapped via TCEMAIN.linkHandler
        //       (e.g. "category" → sys_category) fall back to identifier = table and get marked
        //       as record.unknownTable although valid. Find the page (request/FormEngine context)
        //       or do not mark unknown tables here.
        $source = new LinkCandidate('', 0, 0, 0, '', '', '');
        $target = $this->linkTargetFactory->createFromResolved($resolved, $source);
        if ($target === null) {
            return;
        }
        if ($target->type === LinkService::TYPE_URL) {
            $result = $this->targetRepository->findResult($target);
        } else {
            $checker = $this->checkerRegistry->getChecker($target->type);
            if ($checker === null) {
                return;
            }
            $result = $checker->check($target, $source);
        }
        $event->markAsCheckedLink();
        if ($result?->status === CheckStatus::Broken) {
            $event->markAsBrokenLink($this->getReason($result));
        }
    }

    private function getReason(CheckResult $result): string
    {
        $label = $this->getLanguageService()->sL('linkvalidator2.messages:error.' . $result->errorType);
        return $label !== '' ? $label : $result->errorType;
    }

    private function getLanguageService(): LanguageService
    {
        return $GLOBALS['LANG'] ?? $this->languageServiceFactory->create('default');
    }
}
