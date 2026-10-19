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

/**
 * One broken link of the mail report, with everything the mail and the CSV show.
 */
final readonly class MailReportItem
{
    public function __construct(
        public string $pageTitle,
        public int $pageUid,
        public string $table,
        public int $uid,
        public string $field,
        public int $language,
        public string $recordTitle,
        public string $linkText,
        public string $target,
        public string $linkType,
        public string $status,
        public string $errorType,
        public string $errorMessage,
        public int $firstDetected,
        public int $lastSeen,
        public string $editUrl,
        public string $moduleUrl,
    ) {}
}
