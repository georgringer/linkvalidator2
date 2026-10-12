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

/**
 * Registered only through autoconfiguration of LinkCheckerInterface, no tag configured here.
 */
final readonly class TestLinkChecker implements LinkCheckerInterface
{
    public function getSupportedTypes(): array
    {
        return ['checked'];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        return $target->resolved['id'] === 'missing'
            ? CheckResult::broken('checked.missing', ['id' => $target->resolved['id']])
            : CheckResult::ok();
    }
}
