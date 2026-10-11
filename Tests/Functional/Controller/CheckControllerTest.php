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
use TYPO3\CMS\Linkvalidator2\Controller\CheckController;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class CheckControllerTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['typo3/cms-linkvalidator2'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/be_users.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
    }

    #[Test]
    public function startReturnsPagesOfTheSelectedScope(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(CheckController::class)->startAction(
            (new ServerRequest('https://typo3-testing.local/'))->withQueryParams(['id' => 1, 'depth' => 1])
        );

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response);
        $pageIds = $data['pageIds'];
        sort($pageIds);
        // Hidden pages and folders included, deleted page 4 and the translation 10 not
        self::assertSame([1, 2, 3, 5, 6, 7, 8, 9], $pageIds);
        self::assertIsInt($data['runStartedAt']);
    }

    #[Test]
    public function startWithoutModuleAccessIsDenied(): void
    {
        $this->setUpBackendUser(2);

        $response = $this->get(CheckController::class)->startAction(
            (new ServerRequest('https://typo3-testing.local/'))->withQueryParams(['id' => 1, 'depth' => 0])
        );

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function startForMissingPageIsRejected(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(CheckController::class)->startAction(
            (new ServerRequest('https://typo3-testing.local/'))->withQueryParams(['id' => 4711, 'depth' => 0])
        );

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function pageActionChecksThePage(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(CheckController::class)->pageAction(
            (new ServerRequest('https://typo3-testing.local/', 'POST'))->withParsedBody(['pageId' => 1, 'runStartedAt' => time()])
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            ['done' => true, 'recordCount' => 6, 'checkedCount' => 6, 'brokenLinkCount' => 8, 'newBrokenLinkCount' => 8],
            $this->decode($response)
        );
    }

    #[Test]
    public function pageActionWithoutModuleAccessIsDenied(): void
    {
        $this->setUpBackendUser(2);

        $response = $this->get(CheckController::class)->pageAction(
            (new ServerRequest('https://typo3-testing.local/', 'POST'))->withParsedBody(['pageId' => 1, 'runStartedAt' => time()])
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame(0, $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_record_status')
            ->count('*', 'tx_linkvalidator2_record_status', []));
    }

    #[Test]
    public function pageActionWithoutRunStartIsRejected(): void
    {
        $this->setUpBackendUser(1);

        $response = $this->get(CheckController::class)->pageAction(
            (new ServerRequest('https://typo3-testing.local/', 'POST'))->withParsedBody(['pageId' => 1])
        );

        self::assertSame(400, $response->getStatusCode());
    }

    private function decode(ResponseInterface $response): array
    {
        return json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
    }
}
