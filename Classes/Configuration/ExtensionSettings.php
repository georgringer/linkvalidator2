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
namespace TYPO3\CMS\Linkvalidator2\Configuration;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final readonly class ExtensionSettings
{
    public function __construct(
        private ExtensionConfiguration $extensionConfiguration,
    ) {}

    public function isFieldExcluded(string $table, string $field): bool
    {
        $excluded = $this->getList('excludedFields');
        return in_array($table . '.*', $excluded, true) || in_array($table . '.' . $field, $excluded, true);
    }

    /**
     * @return list<string>
     */
    private function getList(string $path): array
    {
        try {
            $value = (string)$this->extensionConfiguration->get('linkvalidator2', $path);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return [];
        }
        return GeneralUtility::trimExplode(',', $value, true);
    }
}
