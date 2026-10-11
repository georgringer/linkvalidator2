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
namespace TYPO3\CMS\Linkvalidator2\Report;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\DataHandling\TableColumnType;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * @internal
 */
final readonly class ReportPermissionsFactory
{
    public function __construct(
        private TcaSchemaFactory $tcaSchemaFactory,
    ) {}

    /**
     * @return ReportPermissions|null Null for admins, who may fix everything
     */
    public function createForUser(BackendUserAuthentication $user): ?ReportPermissions
    {
        if ($user->isAdmin()) {
            return null;
        }
        $tables = [];
        foreach (GeneralUtility::trimExplode(',', (string)($user->groupData['tables_modify'] ?? ''), true) as $table) {
            if (!$this->tcaSchemaFactory->has($table)) {
                continue;
            }
            $fields = [];
            $authMode = [];
            foreach ($this->tcaSchemaFactory->get($table)->getFields() as $field) {
                $name = $field->getName();
                if (!$field->supportsAccessControl() || $user->check('non_exclude_fields', $table . ':' . $name)) {
                    $fields[] = $name;
                }
                $configuration = $field->getConfiguration();
                if ($field->isType(TableColumnType::SELECT) && (string)($configuration['authMode'] ?? '') !== '') {
                    // Blank values are always allowed, like in BackendUserAuthentication::checkAuthMode()
                    $values = [''];
                    foreach ($configuration['items'] ?? [] as $item) {
                        $value = (string)($item['value'] ?? '');
                        if ($value !== '' && $user->checkAuthMode($table, $name, $value)) {
                            $values[] = $value;
                        }
                    }
                    $authMode[$name] = $values;
                }
            }
            $tables[$table] = ['fields' => $fields, 'authMode' => $authMode];
        }
        $allowedLanguages = trim((string)($user->groupData['allowed_languages'] ?? ''));
        return new ReportPermissions(
            $user->getPagePermsClause(Permission::PAGE_EDIT),
            $user->getPagePermsClause(Permission::CONTENT_EDIT),
            $tables,
            $allowedLanguages === '' ? null : GeneralUtility::intExplode(',', $allowedLanguages, true),
        );
    }
}
