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
namespace GeorgRinger\Linkvalidator2\Tests\Functional\Exclusion;

use GeorgRinger\Linkvalidator2\Exclusion\Exclusion;
use GeorgRinger\Linkvalidator2\Exclusion\ExclusionPermissions;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the module offers for exclusions: no button that ends in "Access denied"
 */
final class ExclusionPermissionsTest extends FunctionalTestCase
{
    private const TABLE = 'tx_linkvalidator2_exclusion';

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        // The editor has the page 1 as mount, may read and edit its content
        $this->getConnectionPool()->getConnectionForTable('be_users')->update('be_users', ['db_mountpoints' => '1'], ['uid' => 2]);
        $this->setPagePermissions(1, Permission::PAGE_SHOW | Permission::CONTENT_EDIT);
    }

    #[Test]
    public function anAdministratorMayEditOnAnyPageAndOnTheRootLevel(): void
    {
        $this->setUpBackendUser(1);

        self::assertTrue($this->get(ExclusionPermissions::class)->mayEditOnPage(1, $this->getPage(1)));
        self::assertTrue($this->get(ExclusionPermissions::class)->mayEditOnPage(0, []));
    }

    #[Test]
    public function anEditorWithTheTableAndTheContentOfThePageMayEdit(): void
    {
        $this->allowTable('tables_modify');
        $this->setUpBackendUser(2);

        self::assertTrue($this->get(ExclusionPermissions::class)->mayEditOnPage(1, $this->getPage(1)));
    }

    #[Test]
    public function anEditorWithoutTheTableMayNotEdit(): void
    {
        $this->setUpBackendUser(2);

        self::assertFalse($this->get(ExclusionPermissions::class)->mayEditOnPage(1, $this->getPage(1)));
    }

    #[Test]
    public function anEditorWhoMayNotEditTheContentOfThePageMayNotEdit(): void
    {
        $this->allowTable('tables_modify');
        $this->setPagePermissions(1, Permission::PAGE_SHOW);
        $this->setUpBackendUser(2);

        self::assertFalse($this->get(ExclusionPermissions::class)->mayEditOnPage(1, $this->getPage(1)));
    }

    #[Test]
    public function anEditorMayNotEditOnTheRootLevel(): void
    {
        $this->allowTable('tables_modify');
        $this->setUpBackendUser(2);

        self::assertFalse($this->get(ExclusionPermissions::class)->mayEditOnPage(0, []));
    }

    #[Test]
    public function anExclusionIsSeenWithTheTableAndTheReadAccessToItsPage(): void
    {
        $this->allowTable('tables_select');
        $this->setUpBackendUser(2);
        $permissions = $this->get(ExclusionPermissions::class);

        self::assertTrue($permissions->maySee($this->exclusionOnPage(1)));
        // Page 2 is not readable for everybody
        self::assertFalse($permissions->maySee($this->exclusionOnPage(2)));
    }

    #[Test]
    public function anExclusionOfTheRootLevelIsSeenWithTheTable(): void
    {
        $this->allowTable('tables_select');
        $this->setUpBackendUser(2);

        self::assertTrue($this->get(ExclusionPermissions::class)->maySee($this->exclusionOnPage(0)));
    }

    #[Test]
    public function noExclusionIsSeenWithoutTheTable(): void
    {
        $this->setUpBackendUser(2);
        $permissions = $this->get(ExclusionPermissions::class);

        self::assertFalse($permissions->maySee($this->exclusionOnPage(1)));
        self::assertFalse($permissions->maySee($this->exclusionOnPage(0)));
    }

    #[Test]
    public function anAdministratorSeesEveryExclusion(): void
    {
        $this->setUpBackendUser(1);

        self::assertTrue($this->get(ExclusionPermissions::class)->maySee($this->exclusionOnPage(2)));
    }

    private function allowTable(string $field): void
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('be_groups');
        $current = (string)$connection->select([$field], 'be_groups', ['uid' => 1])->fetchOne();
        $connection->update('be_groups', [$field => $current . ',' . self::TABLE], ['uid' => 1]);
    }

    private function setPagePermissions(int $pageUid, int $permissions): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['perms_everybody' => $permissions], ['uid' => $pageUid]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPage(int $pageUid): array
    {
        return BackendUtility::getRecord('pages', $pageUid) ?? [];
    }

    private function exclusionOnPage(int $pid): Exclusion
    {
        return new Exclusion(1, $pid, 'url', ['uid' => 1, 'pid' => $pid, 'type' => 'url', 'value' => 'example.org']);
    }
}
