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

namespace TYPO3\CMS\Linkvalidator2\Tests\Functional\Check;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Linkvalidator2\Check\Checker\PageChecker;
use TYPO3\CMS\Linkvalidator2\Check\CheckResult;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;
use TYPO3\CMS\Linkvalidator2\Target\LinkTarget;
use TYPO3\CMS\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class PageCheckerTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    protected array $testExtensionsToLoad = ['typo3/cms-linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages_visibility.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['shortcut' => 4], ['uid' => 24]);
        $this->writeSiteConfiguration();
    }

    public static function pageResultsDataProvider(): \Generator
    {
        yield 'visible' => [2, 'ok', '', []];
        yield 'hidden' => [3, 'broken', 'page.hidden', []];
        yield 'deleted' => [4, 'broken', 'page.missing', []];
        yield 'missing' => [999, 'broken', 'page.missing', []];
        yield 'expired' => [5, 'broken', 'page.expired', []];
        yield 'folder' => [6, 'broken', 'page.noFrontend', []];
        yield 'shortcut to deleted page' => [24, 'broken', 'page.targetNotReachable', []];
        yield 'restricted to frontend groups' => [11, 'info', 'page.accessRestricted', ['fe_group' => '1']];
        yield 'starts in the future' => [12, 'info', 'page.notYetVisible', ['starttime' => 4102444800]];
        yield 'visible until' => [13, 'info', 'page.visibleUntil', ['endtime' => 4102444800]];
        yield 'child of hidden with subpages' => [15, 'broken', 'page.hiddenByParent', ['parentPage' => 14]];
        yield 'child of expired with subpages' => [17, 'broken', 'page.expiredByParent', ['parentPage' => 16]];
        yield 'child of restricted with subpages' => [19, 'info', 'page.accessRestricted', ['fe_group' => '1', 'parentPage' => 18]];
        yield 'child of future with subpages' => [21, 'info', 'page.notYetVisible', ['starttime' => 4102444800, 'parentPage' => 20]];
        yield 'child of hidden without subpages' => [23, 'ok', '', []];
    }

    #[DataProvider('pageResultsDataProvider')]
    #[Test]
    public function pageLinkIsCheckedLikeTheFrontendResolvesIt(int $pageUid, string $status, string $errorType, array $details): void
    {
        $result = $this->check($pageUid);

        self::assertSame($status, $result->status->value, $result->errorType . ' ' . json_encode($result->details));
        self::assertSame($errorType, $result->errorType);
        foreach ($details as $key => $value) {
            self::assertSame($value, $result->details[$key] ?? null, 'Detail "' . $key . '"');
        }
    }

    #[Test]
    public function loggedInBackendUserDoesNotMakeHiddenPagesReachable(): void
    {
        $this->setUpBackendUser(1);
        $context = $this->get(Context::class);
        $userAspect = $context->getAspect('backend.user');
        $languageAspect = $context->getAspect('language');

        self::assertSame('page.hidden', $this->check(3)->errorType);
        self::assertSame('page.hiddenByParent', $this->check(15)->errorType);
        self::assertTrue($this->check(2)->isOk());
        self::assertSame($userAspect, $context->getAspect('backend.user'), 'Backend user aspect is restored');
        self::assertSame($languageAspect, $context->getAspect('language'), 'Language aspect is restored');
        self::assertInstanceOf(UserAspect::class, $userAspect);
    }

    private function check(int $pageUid): CheckResult
    {
        return $this->get(PageChecker::class)->check(
            new LinkTarget(LinkService::TYPE_PAGE, 'page:' . $pageUid, ['type' => LinkService::TYPE_PAGE, 'pageuid' => $pageUid]),
            new LinkCandidate('tt_content', 1, 1, 0, 'bodytext', 't3://page?uid=' . $pageUid, '')
        );
    }
}
