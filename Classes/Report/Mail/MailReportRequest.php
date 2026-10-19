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

namespace GeorgRinger\Linkvalidator2\Report\Mail;

use GeorgRinger\Linkvalidator2\Report\ReportFilter;

/**
 * What a mail report covers: a page with its subpages, filtered like the report in the module.
 */
final readonly class MailReportRequest
{
    /**
     * @param list<string> $includeTables
     * @param list<string> $excludeTables
     * @param string|null $minAge Only links broken for at least this long, for example "7 days"
     * @param string|null $maxAge Only links broken for at most this long
     */
    public function __construct(
        public int $pageUid,
        public int $depth,
        public ReportFilter $filter,
        public array $includeTables = [],
        public array $excludeTables = [],
        public ?string $minAge = null,
        public ?string $maxAge = null,
    ) {}
}
