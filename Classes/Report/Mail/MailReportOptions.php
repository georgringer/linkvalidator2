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

final readonly class MailReportOptions
{
    /**
     * @param list<string> $to
     * @param string|null $from System sender if null
     * @param string|null $subject "Broken links: <page title>" if null
     * @param int $inlineLimit Most links listed in the text; the others are counted
     * @param string $template Name of the Fluid email template
     * @param string $language Language key of the labels in the mail
     */
    public function __construct(
        public array $to,
        public ?string $from,
        public ?string $subject,
        public ReportContent $content,
        public int $inlineLimit,
        public string $template,
        public string $language,
    ) {}
}
