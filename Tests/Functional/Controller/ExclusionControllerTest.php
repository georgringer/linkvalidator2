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

namespace TYPO3\CMS\Linkvalidator2\Tests\Functional\Controller;

use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Linkvalidator2\Controller\ExclusionController;
use TYPO3\CMS\Linkvalidator2\Service\RecordCheckService;
use TYPO3\CMS\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * "Ignore" in the report creates an exclusion on the page of the record (#92822)
 */
final class ExclusionControllerTest extends FunctionalTestCase
{
    use SiteConfigurationTrait;

    private const EXCLUSION_TABLE = 'tx_linkvalidator2_exclusion';

    protected array $testExtensionsToLoad = ['typo3/cms-linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_groups.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->get(RecordCheckService::class)->checkRecord('tt_content', 10);
    }

    #[Test]
    public function ignoringCreatesAnExclusionAndRemovesTheResults(): void
    {
        $this->setUpUser(1);

        $response = $this->create(['pid' => 1, 'type' => 'page', 'value' => '3']);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Root [1]', json_decode((string)$response->getBody(), true)['page']);
        $exclusion = $this->getConnectionPool()->getConnectionForTable(self::EXCLUSION_TABLE)
            ->select(['pid', 'type', 'target_page'], self::EXCLUSION_TABLE)->fetchAssociative();
        self::assertSame(['pid' => 1, 'type' => 'page', 'target_page' => 3], array_map(static fn($value) => is_numeric($value) ? (int)$value : $value, $exclusion));
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_broken_link')
            ->count('*', 'tx_linkvalidator2_broken_link', ['record_uid' => 10, 'link_string' => 't3://page?uid=3']));
    }

    #[Test]
    public function anchorIsRemovedFromTheUrl(): void
    {
        $this->setUpUser(1);

        $this->create(['pid' => 1, 'type' => 'url', 'value' => 'https://www.example.org/path?q=1#top']);

        self::assertSame('https://www.example.org/path?q=1', $this->getConnectionPool()->getConnectionForTable(self::EXCLUSION_TABLE)
            ->select(['value'], self::EXCLUSION_TABLE)->fetchOne());
    }

    #[Test]
    public function unknownTypeIsRejected(): void
    {
        $this->setUpUser(1);

        self::assertSame(400, $this->create(['pid' => 1, 'type' => 'domain', 'value' => 'example.org'])->getStatusCode());
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(self::EXCLUSION_TABLE)->count('*', self::EXCLUSION_TABLE, []));
    }

    #[Test]
    public function userWithoutPermissionCannotIgnore(): void
    {
        $this->setUpUser(2);

        self::assertSame(403, $this->create(['pid' => 1, 'type' => 'page', 'value' => '3'])->getStatusCode());
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable(self::EXCLUSION_TABLE)->count('*', self::EXCLUSION_TABLE, []));
    }

    private function setUpUser(int $uid): void
    {
        $backendUser = $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
    }

    private function create(array $body): ResponseInterface
    {
        return $this->get(ExclusionController::class)->createAction(
            (new ServerRequest('https://typo3-testing.local/', 'POST'))->withParsedBody($body)
        );
    }
}
