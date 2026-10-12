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
namespace GeorgRinger\Linkvalidator2\Exclusion;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * What the current backend user may do with exclusions. Creating and changing them is up to the
 * DataHandler as well, this is for what the module offers: no button that ends in "Access denied".
 */
final class ExclusionPermissions
{
    /**
     * Exclusions are created and changed on a page: the table must be allowed to modify and the
     * content of the page may be edited. The root level (page 0) is for administrators.
     *
     * @param array<string, mixed> $pageRecord The page, only needed for pages
     */
    public function mayEditOnPage(int $pageUid, array $pageRecord): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        return $pageUid > 0
            && $pageRecord !== []
            && $backendUser->check('tables_modify', ExclusionRepository::TABLE)
            && $backendUser->doesUserHaveAccess($pageRecord, Permission::CONTENT_EDIT);
    }

    /**
     * An exclusion of a page above may be shown if the user may read the table and the page it
     * is stored on, which are the pages of the rootline, not necessarily in the mounts of the user.
     */
    public function maySee(Exclusion $exclusion): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser->isAdmin()) {
            return true;
        }
        if (!$backendUser->check('tables_select', ExclusionRepository::TABLE)) {
            return false;
        }
        return $exclusion->pid === 0
            || BackendUtility::readPageAccess($exclusion->pid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW)) !== false;
    }

    private function getBackendUser(): BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'];
    }
}
