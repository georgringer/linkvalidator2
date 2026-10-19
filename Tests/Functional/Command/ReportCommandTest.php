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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Command;

use GeorgRinger\Linkvalidator2\Command\ReportCommand;
use GeorgRinger\Linkvalidator2\Report\ErrorMessageFormatter;
use GeorgRinger\Linkvalidator2\Report\Mail\AgeParser;
use GeorgRinger\Linkvalidator2\Report\Mail\BackendUrlFactory;
use GeorgRinger\Linkvalidator2\Report\Mail\CsvWriter;
use GeorgRinger\Linkvalidator2\Report\Mail\MailReportBuilder;
use GeorgRinger\Linkvalidator2\Report\Mail\ReportMailFactory;
use GeorgRinger\Linkvalidator2\Repository\BrokenLinkRepository;
use GeorgRinger\Linkvalidator2\Service\PageRecordFinder;
use GeorgRinger\Linkvalidator2\Tests\Functional\Report\Mail\InsertBrokenLinkTrait;
use GeorgRinger\Linkvalidator2\Tests\Functional\SiteConfigurationTrait;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\NullTransport;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;
use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\MailerInterface;
use TYPO3\CMS\Core\Mail\TemplatedEmailFactory;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ReportCommandTest extends FunctionalTestCase
{
    use InsertBrokenLinkTrait;
    use SiteConfigurationTrait;

    private const NOW = 1_792_396_800;
    private const DAY = 86400;

    protected array $testExtensionsToLoad = ['georgringer/linkvalidator2'];

    /** @var list<RawMessage> */
    private array $sent = [];

    private ?\Throwable $transportError = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/pages.csv');
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/tt_content.csv');
        $this->writeSiteConfiguration();
        $this->get(Context::class)->setAspect('date', new DateTimeAspect(new \DateTimeImmutable('@' . self::NOW)));
    }

    #[Test]
    public function sendsOneMailWithTheMatchingLinks(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/gone');

        $tester = $this->runCommand(['page' => '1', '--to' => ['editor@example.org']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->sent);
        self::assertSame('Broken links: Root', $this->sent[0]->getSubject());
        self::assertSame('editor@example.org', $this->sent[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('https://example.org/gone', (string)$this->sent[0]->getTextBody());
        self::assertCount(0, $this->sent[0]->getAttachments());
    }

    #[Test]
    public function sendsNothingWithoutHitsAndSucceeds(): void
    {
        $tester = $this->runCommand(['page' => '1', '--to' => ['editor@example.org']]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(0, $this->sent);
    }

    #[Test]
    public function sendsAnEmptyMailWithSendEmpty(): void
    {
        $tester = $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--send-empty' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertCount(1, $this->sent);
        self::assertStringContainsString('No broken links', (string)$this->sent[0]->getTextBody());
    }

    #[Test]
    public function noCsvWithoutHitsEvenInAttachMode(): void
    {
        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--send-empty' => true, '--content' => 'attach']);

        self::assertCount(1, $this->sent);
        self::assertCount(0, $this->sent[0]->getAttachments());
    }

    #[Test]
    public function attachModeAttachesTheCsvWithAllLinks(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/one');
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/two');

        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--content' => 'attach']);

        self::assertCount(1, $this->sent[0]->getAttachments());
        $csv = $this->sent[0]->getAttachments()[0]->getBody();
        self::assertStringContainsString('https://example.org/one', $csv);
        self::assertStringContainsString('https://example.org/two', $csv);
        self::assertStringNotContainsString('https://example.org/one', (string)$this->sent[0]->getTextBody());
    }

    #[Test]
    public function inlineLimitCutsTheListInTheText(): void
    {
        foreach (['one', 'two', 'three'] as $name) {
            $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/' . $name);
        }

        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--inline-limit' => '1']);

        self::assertStringContainsString('And 2 more', (string)$this->sent[0]->getTextBody());
    }

    #[Test]
    public function minAndMaxAgeNarrowTheResult(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - 10 * self::DAY, link: 'https://example.org/old');
        $this->insert(1, 'tt_content', 13, self::NOW - 3600, link: 'https://example.org/new');

        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--min-age' => '7 days']);
        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--max-age' => '1 day']);

        self::assertStringContainsString('https://example.org/old', (string)$this->sent[0]->getTextBody());
        self::assertStringNotContainsString('https://example.org/new', (string)$this->sent[0]->getTextBody());
        self::assertStringContainsString('https://example.org/new', (string)$this->sent[1]->getTextBody());
        self::assertStringNotContainsString('https://example.org/old', (string)$this->sent[1]->getTextBody());
    }

    #[Test]
    public function statusDefaultsToBrokenAndAllRemovesTheFilter(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/broken');
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/notice', status: 'info');

        $this->runCommand(['page' => '1', '--to' => ['editor@example.org']]);
        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--status' => 'all']);

        self::assertStringNotContainsString('https://example.org/notice', (string)$this->sent[0]->getTextBody());
        self::assertStringContainsString('https://example.org/notice', (string)$this->sent[1]->getTextBody());
    }

    #[Test]
    public function includeAndExcludeTablesApply(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/content');
        $this->insert(1, 'pages', 1, self::NOW - self::DAY, link: 'https://example.org/page');

        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--include-tables' => 'pages,tt_content', '--exclude-tables' => 'pages']);

        self::assertStringContainsString('https://example.org/content', (string)$this->sent[0]->getTextBody());
        self::assertStringNotContainsString('https://example.org/page', (string)$this->sent[0]->getTextBody());
    }

    #[Test]
    public function failsForAnUnknownPage(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '4711', '--to' => ['editor@example.org']])->getStatusCode());
    }

    #[Test]
    public function failsForAnInvalidRecipient(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY);

        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '1', '--to' => ['editor@example.org', 'not-an-address']])->getStatusCode());
        self::assertCount(0, $this->sent);
    }

    #[Test]
    public function failsForAnEmptyRecipientList(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '1'])->getStatusCode());
        self::assertCount(0, $this->sent);
    }

    #[Test]
    public function failsForAPageWithoutSiteAndWithoutBaseUrl(): void
    {
        $this->getConnectionPool()->getConnectionForTable('pages')->insert('pages', ['uid' => 50, 'pid' => 0, 'title' => 'Outside', 'doktype' => 1]);
        $this->insert(50, 'pages', 50, self::NOW - self::DAY);

        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '50', '--to' => ['editor@example.org']])->getStatusCode());
        self::assertCount(0, $this->sent);
        self::assertSame(Command::SUCCESS, $this->runCommand(['page' => '50', '--to' => ['editor@example.org'], '--base-url' => 'https://cli.example.net/'])->getStatusCode());
        self::assertCount(1, $this->sent);
    }

    #[Test]
    public function failsForAnEmptyAgeRange(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--min-age' => '7 days', '--max-age' => '1 day'])->getStatusCode());
        self::assertCount(0, $this->sent);
    }

    #[Test]
    public function failsForAnUnreadableAge(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--min-age' => 'soon'])->getStatusCode());
        self::assertCount(0, $this->sent);
    }

    #[Test]
    public function aLimitOfZeroListsNoLinksInTheText(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY, link: 'https://example.org/gone');

        $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--inline-limit' => '0']);

        self::assertStringNotContainsString('https://example.org/gone', (string)$this->sent[0]->getTextBody());
        self::assertStringContainsString('And 1 more', (string)$this->sent[0]->getTextBody());
    }

    #[Test]
    public function failsForAnUnknownContentMode(): void
    {
        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '1', '--to' => ['editor@example.org'], '--content' => 'everything'])->getStatusCode());
    }

    #[Test]
    public function aTransportErrorMakesTheCommandFail(): void
    {
        $this->insert(1, 'tt_content', 13, self::NOW - self::DAY);
        $this->transportError = new TransportException('Connection refused');

        self::assertSame(Command::FAILURE, $this->runCommand(['page' => '1', '--to' => ['editor@example.org']])->getStatusCode());
    }

    private function runCommand(array $input): CommandTester
    {
        $mailer = new class ($this->sent, $this->transportError) implements MailerInterface {
            /** @param list<RawMessage> $sent */
            public function __construct(private array &$sent, private readonly ?\Throwable $error) {}

            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                if ($this->error !== null) {
                    throw $this->error;
                }
                $this->sent[] = $message;
            }

            public function getSentMessage(): ?SentMessage
            {
                return null;
            }

            public function getTransport(): TransportInterface
            {
                return new NullTransport();
            }

            public function getRealTransport(): TransportInterface
            {
                return new NullTransport();
            }
        };
        $command = new ReportCommand(
            new MailReportBuilder(
                $this->get(BrokenLinkRepository::class),
                $this->get(PageRecordFinder::class),
                $this->get(ErrorMessageFormatter::class),
                new AgeParser(),
            ),
            new BackendUrlFactory($this->get(SiteFinder::class), $this->get(BackendEntryPointResolver::class), $this->get(Router::class)),
            new ReportMailFactory($this->get(TemplatedEmailFactory::class), $this->get(LanguageServiceFactory::class)),
            new CsvWriter(),
            $mailer,
            $this->get(LanguageServiceFactory::class),
            $this->get(Context::class),
        );
        $tester = new CommandTester($command);
        $tester->execute($input);
        return $tester;
    }
}
