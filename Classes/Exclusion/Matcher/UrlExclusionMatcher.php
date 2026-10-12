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

namespace GeorgRinger\Linkvalidator2\Exclusion\Matcher;

use TYPO3\CMS\Core\LinkHandling\LinkService;
use GeorgRinger\Linkvalidator2\Exclusion\Exclusion;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionMatcherInterface;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

/**
 * External URLs starting with the value: a domain (incl. subdomains) or a URL, which also
 * covers everything below it. Scheme and anchor do not matter, so editors can enter
 * "example.org", "example.org/blog" or paste a URL.
 */
final readonly class UrlExclusionMatcher implements ExclusionMatcherInterface
{
    public function getType(): string
    {
        return 'url';
    }

    public function matches(Exclusion $exclusion, LinkTarget $target): bool
    {
        if ($target->type !== LinkService::TYPE_URL) {
            return false;
        }
        [$host, $path] = $this->split((string)($exclusion->row['value'] ?? ''));
        [$targetHost, $targetPath] = $this->split((string)($target->resolved['url'] ?? ''));
        if ($host === '' || ($targetHost !== $host && !str_ends_with($targetHost, '.' . $host))) {
            return false;
        }
        return $path === '' || $path === '/' || str_starts_with($targetPath, $path);
    }

    /**
     * @return array{0: string, 1: string} Host in lowercase, path with query
     */
    private function split(string $url): array
    {
        $url = (string)preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', explode('#', trim($url), 2)[0]);
        $position = strcspn($url, '/?');
        return [trim(strtolower(substr($url, 0, $position)), '.'), substr($url, $position)];
    }
}
