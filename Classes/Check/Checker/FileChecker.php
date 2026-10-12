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
namespace GeorgRinger\Linkvalidator2\Check\Checker;

use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\Folder;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Check\LinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

final readonly class FileChecker implements LinkCheckerInterface
{
    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_FILE, LinkService::TYPE_FOLDER];
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        if ($target->type === LinkService::TYPE_FOLDER) {
            $folder = $target->resolved['folder'] ?? null;
            if (!$folder instanceof Folder) {
                return CheckResult::broken('folder.missing');
            }
            return $folder->getStorage()->isOnline() ? CheckResult::ok() : CheckResult::unknown('storage.offline');
        }
        $file = $target->resolved['file'] ?? null;
        if (!$file instanceof File) {
            return CheckResult::broken('file.missing');
        }
        if (!$file->getStorage()->isOnline()) {
            return CheckResult::unknown('storage.offline', ['file' => $file->getUid()]);
        }
        if ($file->isMissing()) {
            return CheckResult::broken('file.missing', ['file' => $file->getUid()]);
        }
        return CheckResult::ok();
    }
}
