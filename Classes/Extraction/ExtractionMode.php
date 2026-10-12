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
namespace GeorgRinger\Linkvalidator2\Extraction;

enum ExtractionMode
{
    /** The field value is a single typolink, e.g. "t3://page?uid=1 _blank" */
    case Typolink;
    /** The field value is HTML containing <a href> tags */
    case Html;
    /** URLs written as plain text, found by the "url" soft reference parser */
    case PlainUrl;
}
