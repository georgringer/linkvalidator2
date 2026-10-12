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
namespace GeorgRinger\Linkvalidator2\Report;

use TYPO3\CMS\Backend\Module\ModuleData;

/**
 * Restricts the broken links shown in the report. An empty value means "all".
 */
final readonly class ReportFilter
{
    public const PROPERTIES = ['status', 'linkType', 'table', 'language', 'search'];

    public function __construct(
        public string $status = '',
        public string $linkType = '',
        public string $table = '',
        public ?int $language = null,
        public string $search = '',
    ) {}

    public static function fromModuleData(ModuleData $moduleData): self
    {
        $language = (string)$moduleData->get('language', '');
        return new self(
            (string)$moduleData->get('status', ''),
            (string)$moduleData->get('linkType', ''),
            (string)$moduleData->get('table', ''),
            $language !== '' && is_numeric($language) ? (int)$language : null,
            trim((string)$moduleData->get('search', '')),
        );
    }

    public function isActive(): bool
    {
        return $this->status !== '' || $this->linkType !== '' || $this->table !== '' || $this->language !== null || $this->search !== '';
    }
}
