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
namespace TYPO3Tests\TestLinkHandler;

use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;
use TYPO3\CMS\Core\LinkHandling\LinkService;

/**
 * Stands for the request to an external URL, asked before the real checker (priority in
 * Services.yaml): the number of checks that really ran can be read. Other URLs are skipped.
 */
final class TestUrlChecker implements LinkCheckerInterface
{
    public const URL = 'https://slow.example.org/';

    public static int $checks = 0;
    /** What the next check finds */
    public static bool $working = false;

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_URL];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        if (($target->resolved['url'] ?? '') !== self::URL) {
            return CheckResult::skip();
        }
        self::$checks++;
        return self::$working ? CheckResult::ok() : CheckResult::broken('url.test');
    }
}
