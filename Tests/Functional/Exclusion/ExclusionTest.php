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

namespace TYPO3\CMS\Linkvalidator2\Tests\Functional\Exclusion;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Linkvalidator2\Exclusion\ExclusionMatcher;
use TYPO3\CMS\Linkvalidator2\Exclusion\ExclusionRepository;
use TYPO3\CMS\Linkvalidator2\Repository\RecordStatusRepository;
use TYPO3\CMS\Linkvalidator2\Service\RecordCheckService;
use TYPO3\CMS\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Exclusions apply to links in records on their page and its subpages (#92822, #85127)
 */
final class ExclusionTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const BROKEN_LINK_TABLE = 'tx_linkvalidator2_broken_link';
    private const EXCLUSION_TABLE = 'tx_linkvalidator2_exclusion';

    protected array $testExtensionsToLoad = ['typo3/cms-linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_notice.csv');
    }

    #[Test]
    public function linksToAnExcludedPageAreNotReported(): void
    {
        $this->addExclusion(1, ['type' => 'page', 'target_page' => 3]);

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);

        self::assertSame(['page:4', 'page:5', 'page:6', 'page:999', 'invalid:link.invalid:t3://gibtsnicht?uid=1'], $this->getReportedTargets(10));
    }

    #[Test]
    public function excludedLinksAreCountedPerRecord(): void
    {
        $this->addExclusion(1, ['type' => 'page', 'target_page' => 3]);

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);

        // Page 3 in bodytext and in header_link
        self::assertSame(2, $this->get(RecordStatusRepository::class)->countExcluded([1]));
    }

    #[Test]
    public function excludingAllLinksCountsEveryLink(): void
    {
        $this->addExclusion(1, ['type' => 'all']);

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        self::assertSame(2, $this->get(RecordStatusRepository::class)->countExcluded([2]));
        self::assertSame(0, $this->get(RecordStatusRepository::class)->countExcluded([1]));
    }

    #[Test]
    public function exclusionAppliesToSubpages(): void
    {
        $this->addExclusion(1, ['type' => 'page', 'target_page' => 3]);

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        self::assertSame(['page:11'], $this->getReportedTargets(15));
    }

    #[Test]
    public function exclusionDoesNotApplyToOtherBranches(): void
    {
        $this->addExclusion(2, ['type' => 'page', 'target_page' => 3]);

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);

        self::assertContains('page:3', $this->getReportedTargets(10));
    }

    #[Test]
    public function exclusionOnTheRootLevelAppliesEverywhere(): void
    {
        $this->addExclusion(0, ['type' => 'page', 'target_page' => 3]);

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        self::assertSame(['page:11'], $this->getReportedTargets(15));
    }

    public static function inactiveExclusionDataProvider(): \Generator
    {
        yield 'hidden' => [['hidden' => 1]];
        yield 'expired' => [['endtime' => 1000]];
        yield 'not started yet' => [['starttime' => 2145916800]];
        yield 'deleted' => [['deleted' => 1]];
    }

    #[DataProvider('inactiveExclusionDataProvider')]
    #[Test]
    public function inactiveExclusionDoesNotApply(array $fields): void
    {
        $this->addExclusion(1, ['type' => 'page', 'target_page' => 3, ...$fields]);

        $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);

        self::assertContains('page:3', $this->getReportedTargets(10));
    }

    /**
     * E.g. an archive whose links are not maintained any more
     */
    #[Test]
    public function excludingAllLinksTurnsOffCheckingForTheSubtree(): void
    {
        $this->addExclusion(1, ['type' => 'all']);

        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('tt_content', 15);

        self::assertSame([], $checkedLinks);
        self::assertSame([], $this->getReportedTargets(15));
    }

    #[Test]
    public function excludedExternalUrlsAreNotRequested(): void
    {
        $this->getConnectionPool()->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 20,
            'pid' => 2,
            'CType' => 'text',
            'bodytext' => '<p><a href="https://sub.excluded.invalid/">domain</a> <a href="https://other.invalid/path#anchor">url</a> <a href="t3://page?uid=2">page</a></p>',
        ]);
        $this->addExclusion(1, ['type' => 'url', 'value' => 'excluded.invalid']);
        $this->addExclusion(2, ['type' => 'url', 'value' => 'https://other.invalid/path']);

        $checkedLinks = $this->get(RecordCheckService::class)->checkRecord('tt_content', 20);

        self::assertSame(['page:2'], array_map(static fn($checkedLink): string => $checkedLink->target->identifier, $checkedLinks));
    }

    #[Test]
    public function everyTypeHasAFormAndAMatcher(): void
    {
        $schema = $this->get(TcaSchemaFactory::class)->get(ExclusionRepository::TABLE);
        $types = array_column($schema->getField('type')->getConfiguration()['items'], 'value');

        self::assertSame($types, array_values(array_filter($types, $schema->hasSubSchema(...))), 'Type without form');
        self::assertEqualsCanonicalizing($types, $this->get(ExclusionMatcher::class)->getTypes(), 'Type without matcher');
    }

    public static function titleDataProvider(): \Generator
    {
        yield 'url' => [['type' => 'url', 'value' => 'example.org/blog'], 'example.org/blog'];
        yield 'page' => [['type' => 'page', 'target_page' => 2], 'Visible [2]'];
        yield 'deleted page' => [['type' => 'page', 'target_page' => 4], '[4]'];
        yield 'missing page' => [['type' => 'page', 'target_page' => 999], '[999]'];
        yield 'all links' => [['type' => 'all'], 'All links'];
        yield 'all links with reason' => [['type' => 'all', 'reason' => 'Archive'], 'All links: Archive'];
    }

    /**
     * Ignoring links to a deleted page is the common case, so its uid is shown instead of "N/A"
     */
    #[DataProvider('titleDataProvider')]
    #[Test]
    public function exclusionIsTitledByItsTarget(array $fields, string $expected): void
    {
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');

        self::assertSame($expected, BackendUtility::getRecordTitle(self::EXCLUSION_TABLE, ['uid' => 1, 'pid' => 1, 'reason' => '', 'value' => '', 'target_page' => 0, ...$fields]));
    }

    /**
     * E.g. after the extension providing the type was removed: the record can still be edited,
     * to change its type or to delete it.
     */
    #[Test]
    public function exclusionOfAnUnknownTypeCanBeEdited(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $GLOBALS['BE_USER'] = $this->setUpBackendUser(1);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('en');
        $this->addExclusion(1, ['type' => 'removed']);

        $formData = $this->get(FormDataCompiler::class)->compile(
            [
                'vanillaUid' => (int)$this->getConnectionPool()->getConnectionForTable(self::EXCLUSION_TABLE)->lastInsertId(),
                'request' => new ServerRequest(),
                'command' => 'edit',
                'tableName' => self::EXCLUSION_TABLE,
            ],
            $this->get(TcaDatabaseRecord::class),
        );

        self::assertStringContainsString('type', $formData['processedTca']['types'][$formData['recordTypeValue']]['showitem']);
    }

    private function addExclusion(int $pid, array $fields): void
    {
        $this->getConnectionPool()->getConnectionForTable(self::EXCLUSION_TABLE)
            ->insert(self::EXCLUSION_TABLE, ['pid' => $pid, ...$fields]);
    }

    /**
     * @return list<string>
     */
    private function getReportedTargets(int $contentUid): array
    {
        $queryBuilder = $this->getConnectionPool()->getQueryBuilderForTable(self::BROKEN_LINK_TABLE);
        return $queryBuilder
            ->select('t.identifier')
            ->from(self::BROKEN_LINK_TABLE, 'b')
            ->join('b', 'tx_linkvalidator2_target', 't', 't.uid = b.target')
            ->where($queryBuilder->expr()->eq('b.record_uid', $contentUid), $queryBuilder->expr()->eq('b.table_name', $queryBuilder->quote('tt_content')))
            ->orderBy('b.uid')
            ->executeQuery()
            ->fetchFirstColumn();
    }
}
