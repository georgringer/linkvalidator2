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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Target;

use PHPUnit\Framework\Attributes\Test;
use GeorgRinger\Linkvalidator2\Service\CheckedLink;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use GeorgRinger\Linkvalidator2\Tests\Functional\BrokenLinkResultsTrait;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Forge #94086: links of link handlers registered by extensions
 */
final class CustomLinkHandlerTest extends FunctionalTestCase
{
    use BrokenLinkResultsTrait;

    use SiteConfigurationTrait;

    protected array $testExtensionsToLoad = [
        'georgringer/linkvalidator2',
        __DIR__ . '/../Fixtures/Extensions/test_link_handler',
    ];

    /**
     * @var array<string, array{status: string, errorType: string}> by link
     */
    private array $results = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->writeSiteConfiguration();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content_custom_link_handler.csv');
        foreach ($this->get(RecordCheckService::class)->checkRecord('tt_content', 30) as $checkedLink) {
            /** @var CheckedLink $checkedLink */
            $this->results[$checkedLink->candidate->link] = [
                'status' => $checkedLink->result->status->value,
                'errorType' => $checkedLink->result->errorType,
            ];
        }
    }

    #[Test]
    public function checkerOfAnExtensionChecksItsLinkType(): void
    {
        self::assertSame(['status' => 'broken', 'errorType' => 'checked.missing'], $this->results['t3://checked?id=missing'] ?? null);
        self::assertSame(['status' => 'ok', 'errorType' => ''], $this->results['t3://checked?id=1'] ?? null);
    }

    #[Test]
    public function linkOfARegisteredHandlerWithoutCheckerIsSkipped(): void
    {
        self::assertArrayNotHasKey('t3://unchecked?id=missing', $this->results);
    }

    #[Test]
    public function linkOfAnUnknownHandlerIsInvalid(): void
    {
        self::assertSame(['status' => 'broken', 'errorType' => 'link.invalid'], $this->results['t3://gibtsnicht?uid=1'] ?? null);
    }

    #[Test]
    public function onlyBrokenLinksAreStored(): void
    {
        $errorTypes = $this->selectErrorTypes(['record_uid' => 30]);

        self::assertSame(['checked.missing', 'link.invalid'], $errorTypes);
    }
}
