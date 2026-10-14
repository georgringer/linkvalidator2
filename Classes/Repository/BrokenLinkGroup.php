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
namespace GeorgRinger\Linkvalidator2\Repository;

/**
 * Property of a broken link results can be counted by
 */
enum BrokenLinkGroup
{
    case Status;
    case LinkType;
    case Table;
    case Language;

    /**
     * Column in the query of {@see BrokenLinkRepository}
     */
    public function getColumn(): string
    {
        return match ($this) {
            self::Status => 't.status',
            self::LinkType => 't.link_type',
            self::Table => 'b.table_name',
            self::Language => 'b.language',
        };
    }
}
