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
namespace TYPO3\CMS\Linkvalidator2\Target;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\LinkHandling\Exception\UnknownLinkHandlerException;
use TYPO3\CMS\Core\LinkHandling\Exception\UnknownUrnException;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;

final readonly class LinkTargetFactory
{
    public function __construct(
        private LinkService $linkService,
    ) {}

    /**
     * Returns null for links that are not checked: email, telephone,
     * in-page anchors, unknown schemes and links from content to its own page.
     */
    public function create(LinkCandidate $candidate): ?LinkTarget
    {
        try {
            $resolved = $this->linkService->resolve($candidate->link);
        } catch (UnknownLinkHandlerException|UnknownUrnException|\InvalidArgumentException $e) {
            return LinkTarget::invalid($candidate->link, $e->getMessage());
        }
        return $this->createFromResolved($resolved, $candidate);
    }

    /**
     * @param array $resolved Link data as returned by LinkService::resolve()
     */
    public function createFromResolved(array $resolved, LinkCandidate $candidate): ?LinkTarget
    {
        $type = (string)($resolved['type'] ?? LinkService::TYPE_UNKNOWN);
        return match ($type) {
            LinkService::TYPE_PAGE => $this->createPageTarget($candidate, $resolved),
            LinkService::TYPE_RECORD => $this->createRecordTarget($candidate, $resolved),
            LinkService::TYPE_FILE => new LinkTarget(
                $type,
                'file:' . (($resolved['file'] ?? null) instanceof File ? $resolved['file']->getUid() : $candidate->link),
                $resolved
            ),
            LinkService::TYPE_FOLDER => new LinkTarget(
                $type,
                'folder:' . (($resolved['folder'] ?? null) instanceof Folder ? $resolved['folder']->getCombinedIdentifier() : $candidate->link),
                $resolved
            ),
            LinkService::TYPE_URL => $this->createUrlTarget($resolved),
            LinkService::TYPE_EMAIL, LinkService::TYPE_TELEPHONE, LinkService::TYPE_INPAGE => null,
            default => $this->createCustomTarget($type, $resolved, $candidate),
        };
    }

    private function createPageTarget(LinkCandidate $candidate, array $resolved): ?LinkTarget
    {
        $pageUid = $resolved['pageuid'] ?? null;
        if ($pageUid === 'current') {
            // A link page pointing to itself cannot be linked by the frontend
            return $candidate->table === 'pages'
                ? LinkTarget::invalid($candidate->link, 'Page links to itself', 'page.linksToItself')
                : null;
        }
        if (!MathUtility::canBeInterpretedAsInteger($pageUid) || (int)$pageUid <= 0) {
            return LinkTarget::invalid($candidate->link, 'Page link without valid uid');
        }
        $resolved['pageuid'] = (int)$pageUid;
        $fragment = (string)($resolved['fragment'] ?? '');
        return new LinkTarget(LinkService::TYPE_PAGE, 'page:' . $pageUid . ($fragment !== '' ? '#' . $fragment : ''), $resolved);
    }

    private function createRecordTarget(LinkCandidate $candidate, array $resolved): LinkTarget
    {
        $identifier = (string)($resolved['identifier'] ?? '');
        $uid = (int)($resolved['uid'] ?? 0);
        if ($identifier === '' || $uid <= 0) {
            return LinkTarget::invalid($candidate->link, 'Record link without identifier or uid');
        }
        $pageTsConfig = BackendUtility::getPagesTSconfig($candidate->pid);
        $table = (string)($pageTsConfig['TCEMAIN.']['linkHandler.'][$identifier . '.']['configuration.']['table'] ?? $identifier);
        $resolved['table'] = $table;
        return new LinkTarget(LinkService::TYPE_RECORD, 'record:' . $table . ':' . $uid, $resolved);
    }

    /**
     * The fragment is not sent to the server, so URLs differing only in it share one target.
     */
    private function createUrlTarget(array $resolved): LinkTarget
    {
        $url = explode('#', (string)($resolved['url'] ?? ''), 2)[0];
        $resolved['url'] = $url;
        return new LinkTarget(LinkService::TYPE_URL, 'url:' . $url, $resolved);
    }

    /**
     * A type of a link handler registered by an extension is checked by the checker for that type, or
     * skipped when there is none. Any other type comes from an internal link LinkService cannot resolve,
     * e.g. a record link without identifier, which ends up in the legacy notation converter.
     */
    private function createCustomTarget(string $type, array $resolved, LinkCandidate $candidate): ?LinkTarget
    {
        if (isset($GLOBALS['TYPO3_CONF_VARS']['SYS']['linkHandler'][$type])) {
            return new LinkTarget($type, $type . ':' . $candidate->link, $resolved);
        }
        return $this->createUnresolvableTarget($candidate->link !== '' ? $candidate->link : (string)($resolved['url'] ?? ''));
    }

    private function createUnresolvableTarget(string $link): ?LinkTarget
    {
        return str_starts_with(strtolower($link), 't3://') ? LinkTarget::invalid($link, 'Link cannot be resolved') : null;
    }
}
