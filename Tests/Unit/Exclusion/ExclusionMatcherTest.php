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

namespace GeorgRinger\Linkvalidator2\Tests\Unit\Exclusion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use GeorgRinger\Linkvalidator2\Exclusion\Exclusion;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionMatcher;
use GeorgRinger\Linkvalidator2\Exclusion\Matcher\AllExclusionMatcher;
use GeorgRinger\Linkvalidator2\Exclusion\Matcher\PageExclusionMatcher;
use GeorgRinger\Linkvalidator2\Exclusion\Matcher\UrlExclusionMatcher;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExclusionMatcherTest extends UnitTestCase
{
    public static function matchingDataProvider(): \Generator
    {
        $url = static fn(string $url): LinkTarget => new LinkTarget(LinkService::TYPE_URL, 'url:' . $url, ['url' => $url]);
        $page = new LinkTarget(LinkService::TYPE_PAGE, 'page:3', ['pageuid' => 3]);
        $exclude = static fn(string $value): array => ['type' => 'url', 'value' => $value];

        yield 'domain' => [$exclude('example.org'), $url('https://example.org/path'), true];
        yield 'domain covers subdomains' => [$exclude('example.org'), $url('https://www.example.org/'), true];
        yield 'domain is case-insensitive' => [$exclude('Example.ORG'), $url('https://EXAMPLE.org'), true];
        yield 'domain with scheme' => [$exclude('https://example.org'), $url('http://example.org/other'), true];
        yield 'domain with trailing slash' => [$exclude('example.org/'), $url('https://example.org/other'), true];
        yield 'domain only as suffix of another' => [$exclude('example.org'), $url('https://notexample.org/'), false];
        yield 'domain as subdomain of another' => [$exclude('example.org'), $url('https://example.org.evil.test/'), false];
        yield 'url itself' => [$exclude('https://example.org/path?a=1'), $url('https://example.org/path?a=1'), true];
        yield 'url ignores scheme' => [$exclude('http://example.org/path'), $url('https://example.org/path'), true];
        yield 'url ignores its anchor' => [$exclude(' https://example.org/path#top '), $url('https://example.org/path'), true];
        yield 'url covers what starts with it' => [$exclude('example.org/blog'), $url('https://example.org/blog/article?x=1'), true];
        yield 'url on a subdomain' => [$exclude('example.org/blog'), $url('https://www.example.org/blog'), true];
        yield 'url other path' => [$exclude('example.org/blog'), $url('https://example.org/shop'), false];
        yield 'url on another domain' => [$exclude('example.org/blog'), $url('https://other.org/blog'), false];
        yield 'empty value' => [$exclude(''), $url('https://example.org/'), false];
        yield 'url does not exclude pages' => [$exclude('example.org'), $page, false];
        yield 'page' => [['type' => 'page', 'target_page' => '3'], $page, true];
        yield 'other page' => [['type' => 'page', 'target_page' => '4'], $page, false];
        yield 'page does not exclude urls' => [['type' => 'page', 'target_page' => '3'], $url('https://example.org/'), false];
        yield 'all links: page' => [['type' => 'all'], $page, true];
        yield 'all links: url' => [['type' => 'all'], $url('https://example.org/'), true];
        yield 'unknown type' => [['type' => 'unknown'], $page, false];
    }

    #[DataProvider('matchingDataProvider')]
    #[Test]
    public function exclusionMatchesTarget(array $row, LinkTarget $target, bool $expected): void
    {
        $subject = new ExclusionMatcher([
            new UrlExclusionMatcher(),
            new PageExclusionMatcher(),
            new AllExclusionMatcher(),
        ]);

        self::assertSame($expected, $subject->isExcluded([Exclusion::fromRow(['uid' => 1, 'pid' => 0, ...$row])], $target));
    }

    #[Test]
    public function targetWithoutExclusionsIsNotExcluded(): void
    {
        $subject = new ExclusionMatcher([new PageExclusionMatcher()]);

        self::assertFalse($subject->isExcluded([], new LinkTarget(LinkService::TYPE_PAGE, 'page:3', ['pageuid' => 3])));
    }
}
