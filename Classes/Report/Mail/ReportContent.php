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
 * What the mail of the report contains: the most important links in the text, all of them with every
 * column as CSV attachment, or both.
 */
enum ReportContent: string
{
    case Inline = 'inline';
    case Attach = 'attach';
    case Both = 'both';

    public function includesInline(): bool
    {
        return $this !== self::Attach;
    }

    public function includesCsv(): bool
    {
        return $this !== self::Inline;
    }
}
