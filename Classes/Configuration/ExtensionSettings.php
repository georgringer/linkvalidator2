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
namespace GeorgRinger\Linkvalidator2\Configuration;

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

    public function isManualCheckAllowed(): bool
    {
        return (bool)(int)$this->get('allowManualCheck', '1');
    }

    public function isPageModuleCalloutEnabled(): bool
    {
        return (bool)(int)$this->get('pageModuleCallout', '1');
    }

    public function getExternalCacheLifetime(): int
    {
        return max(0, (int)$this->get('externalCacheLifetime', '168')) * 3600;
    }

    public function getExternalTimeout(): int
    {
        return max(1, (int)$this->get('externalTimeout', '10'));
    }

    public function getExternalUserAgent(): string
    {
        return trim($this->get('externalUserAgent', 'TYPO3 LinkValidator')) ?: 'TYPO3 LinkValidator';
    }

    /**
     * @return list<string>
     */
    private function getList(string $path): array
    {
        return GeneralUtility::trimExplode(',', $this->get($path, ''), true);
    }

    private function get(string $path, string $default): string
    {
        try {
            return (string)($this->extensionConfiguration->get('linkvalidator2', $path) ?? $default);
        } catch (ExtensionConfigurationExtensionNotConfiguredException|ExtensionConfigurationPathDoesNotExistException) {
            return $default;
        }
    }
}
