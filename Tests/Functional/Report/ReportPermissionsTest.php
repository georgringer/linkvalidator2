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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Report;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use GeorgRinger\Linkvalidator2\Report\ReportFilter;
use GeorgRinger\Linkvalidator2\Report\ReportOrdering;
use GeorgRinger\Linkvalidator2\Report\ReportPermissions;
use GeorgRinger\Linkvalidator2\Report\ReportPermissionsFactory;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The report lists only what the user may fix (Forge #105413, #104004)
 */
final class ReportPermissionsTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const PAGE_IDS = [1, 2, 9];

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_notice.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_permissions.csv');
        $this->writeSiteConfiguration();
        $pages = $this->getConnectionPool()->getConnectionForTable('pages');
        // Content of page 1 may be edited, but not page 1 itself; page 9 may be edited, page 2 not its content
        $pages->update('pages', ['perms_everybody' => Permission::CONTENT_EDIT | Permission::PAGE_SHOW], ['uid' => 1]);
        $pages->update('pages', ['perms_everybody' => Permission::PAGE_EDIT | Permission::PAGE_SHOW], ['uid' => 2]);
        $pages->update('pages', ['perms_everybody' => Permission::PAGE_EDIT | Permission::PAGE_SHOW], ['uid' => 9]);
        $checkService = $this->get(RecordCheckService::class);
        foreach ([10, 14, 15, 16] as $uid) {
            $checkService->checkRecord('tt_content', $uid);
        }
        $checkService->checkRecord('pages', 9);
    }

    #[Test]
    public function adminSeesEverything(): void
    {
        $this->setUpBackendUser(1);

        self::assertNull($this->createPermissions());
        // 7 in content 10, 1 in 14, 2 in 15 (one notice), 1 in 16, 1 in page 9
        self::assertSame(12, $this->get(BrokenLinkRepository::class)->countByPids(self::PAGE_IDS));
    }

    #[Test]
    public function editorSeesOnlyWhatTheyMayFix(): void
    {
        $this->setUpBackendUser(2);

        self::assertSame(
            [
                // Page field on page 9: edit permission on the page itself, not its parent (#105413)
                'pages:9:link' => 1,
                // Content of page 1, header_link allowed as non-exclude field
                'tt_content:10:bodytext' => 6,
                'tt_content:10:header_link' => 1,
                // Not listed: 14 (language 1 not allowed), 15 (no content edit on page 2),
                // 16 (CType "header" not allowed by authMode, #104004)
            ],
            $this->findVisible()
        );
    }

    #[Test]
    public function contentEditorSeesNeitherPagesNorExcludeFieldsNotGranted(): void
    {
        $this->setUpBackendUser(3);

        self::assertSame(
            [
                'tt_content:10:bodytext' => 6,
                // All languages allowed
                'tt_content:14:bodytext' => 1,
            ],
            $this->findVisible()
        );
    }

    #[Test]
    public function countsAndFilterOptionsFollowThePermissions(): void
    {
        $this->setUpBackendUser(2);
        $permissions = $this->createPermissions();
        $repository = $this->get(BrokenLinkRepository::class);

        self::assertSame(8, $repository->countByPids(self::PAGE_IDS, new ReportFilter(), $permissions));
        $filterValues = $repository->findFilterValues(self::PAGE_IDS, $permissions);
        self::assertSame([0 => 8], $filterValues['language']);
        self::assertSame(['pages', 'tt_content'], array_keys($filterValues['table']));
        self::assertSame(8, array_sum($filterValues['table']));
        self::assertSame(8, array_sum($filterValues['status']));
        self::assertSame(8, array_sum($filterValues['linkType']));
        self::assertSame(7, $repository->countBrokenByPid(1, $permissions));
    }

    private function createPermissions(): ?ReportPermissions
    {
        return $this->get(ReportPermissionsFactory::class)->createForUser($GLOBALS['BE_USER']);
    }

    /**
     * @return array<string, int> number of rows by "table:uid:field"
     */
    private function findVisible(): array
    {
        $rows = $this->get(BrokenLinkRepository::class)->findByPids(self::PAGE_IDS, new ReportFilter(), new ReportOrdering(), 0, 0, $this->createPermissions());
        $result = [];
        foreach ($rows as $row) {
            $key = $row['table_name'] . ':' . $row['record_uid'] . ':' . $row['field'];
            $result[$key] = ($result[$key] ?? 0) + 1;
        }
        ksort($result);
        return $result;
    }
}
