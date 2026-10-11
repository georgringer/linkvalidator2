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

namespace TYPO3\CMS\Linkvalidator2\Exclusion;

use TYPO3\CMS\Backend\Utility\BackendUtility;

/**
 * Record title of an exclusion: its URL or domain, the title and uid of its page. Ignoring
 * links to a deleted page is the common case, so a missing page is shown by its uid.
 *
 * @internal
 */
final readonly class ExclusionLabel
{
    public function getTitle(array &$parameters): void
    {
        $row = $parameters['row'] ?? [];
        $type = (string)(is_array($row['type'] ?? null) ? ($row['type'][0] ?? '') : ($row['type'] ?? ''));
        if ($type === 'page') {
            $pageUid = (int)(is_array($row['target_page'] ?? null) ? ($row['target_page'][0]['uid'] ?? 0) : ($row['target_page'] ?? 0));
            $page = $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid, 'title') : null;
            $parameters['title'] = ($page !== null ? $page['title'] . ' ' : '') . '[' . $pageUid . ']';
            return;
        }
        if ($type === 'all') {
            $reason = trim((string)($row['reason'] ?? ''));
            $title = $GLOBALS['LANG']->sL('linkvalidator2.db:exclusion.title.all');
            $parameters['title'] = $reason !== '' ? $title . ': ' . $reason : $title;
            return;
        }
        $parameters['title'] = (string)($row['value'] ?? '') ?: (string)($row['reason'] ?? '');
    }
}
