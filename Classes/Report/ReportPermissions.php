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

/**
 * What a non-admin backend user may fix, applied to the report in SQL: tables to modify, fields
 * per table (exclude fields only when granted), allowed values of authMode fields, languages and
 * page permissions (page fields: edit page, other records: edit content of their page).
 *
 * @internal
 */
final readonly class ReportPermissions
{
    /**
     * @param array<string, array{fields: list<string>, authMode: array<string, list<string>>}> $tables
     * @param list<int>|null $languages Null when all languages are allowed
     */
    public function __construct(
        public string $pageEditClause,
        public string $contentEditClause,
        public array $tables,
        public ?array $languages,
    ) {}
}
