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
 * Sort order of the report, by column. Unknown values fall back to the default.
 */
final readonly class ReportOrdering
{
    public const DEFAULT_FIELD = 'page';

    /**
     * Report column => columns of the broken link table (b) and of the target (t)
     */
    public const FIELDS = [
        'page' => ['b.pid'],
        'record' => ['b.table_name', 'b.record_uid', 'b.field'],
        'linkText' => ['b.link_text'],
        'target' => ['b.link_string'],
        'problem' => ['t.error_type'],
        'since' => ['b.first_detected'],
    ];

    public function __construct(
        public string $field = self::DEFAULT_FIELD,
        public bool $descending = false,
        public bool $groupByPage = false,
    ) {}

    public static function fromModuleData(ModuleData $moduleData): self
    {
        $field = (string)$moduleData->get('orderField', self::DEFAULT_FIELD);
        return new self(
            isset(self::FIELDS[$field]) ? $field : self::DEFAULT_FIELD,
            $moduleData->get('orderDirection') === 'desc',
            (bool)$moduleData->get('groupByPage', false),
        );
    }

    /**
     * @return list<string>
     */
    public function getColumns(): array
    {
        $columns = self::FIELDS[$this->field];
        return $this->groupByPage && $this->field !== 'page' ? ['b.pid', ...$columns] : $columns;
    }

    public function getDirection(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }
}
