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

namespace TYPO3\CMS\Linkvalidator2\Tests\Unit\Check;

use GuzzleHttp\Exception\ConnectException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Client\NetworkExceptionInterface;
use Psr\Http\Message\RequestInterface;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Http\Request;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use TYPO3\CMS\Linkvalidator2\Check\Checker\ExternalUrlChecker;
use TYPO3\CMS\Linkvalidator2\Check\CheckStatus;
use TYPO3\CMS\Linkvalidator2\Configuration\ExtensionSettings;
use TYPO3\CMS\Linkvalidator2\Extraction\LinkCandidate;
use TYPO3\CMS\Linkvalidator2\Target\LinkTarget;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ExternalUrlCheckerTest extends UnitTestCase
{
    public static function networkErrorDataProvider(): \Generator
    {
        $request = new Request('https://example.org/');
        // Guzzle 7 passes the cURL error number as handler context, Guzzle 8 only in the message
        yield 'unknown host' => [new ConnectException('cURL error 6: Could not resolve host: example.org', $request), CheckStatus::Broken, 'url.unknownHost'];
        yield 'timeout' => [new ConnectException('cURL error 28: Operation timed out', $request), CheckStatus::Unknown, 'url.timeout'];
        yield 'tls' => [new ConnectException('cURL error 60: SSL certificate problem', $request), CheckStatus::Unknown, 'url.tlsError'];
        yield 'other' => [new ConnectException('cURL error 7: Failed to connect', $request), CheckStatus::Unknown, 'url.connectionFailed'];
        // Guzzle 8 throws timeouts as a network exception of its own
        yield 'timeout without connect exception' => [
            new class ('cURL error 28: Operation timed out', $request) extends \RuntimeException implements NetworkExceptionInterface {
                public function __construct(string $message, private RequestInterface $request)
                {
                    parent::__construct($message);
                }

                public function getRequest(): RequestInterface
                {
                    return $this->request;
                }
            },
            CheckStatus::Unknown,
            'url.timeout',
        ];
    }

    #[DataProvider('networkErrorDataProvider')]
    #[Test]
    public function networkErrorsAreClassifiedByTheirCurlError(\Throwable $exception, CheckStatus $status, string $errorType): void
    {
        $result = $this->createSubject($exception)->check(...$this->createLink());

        self::assertSame([$status, $errorType], [$result->status, $result->errorType]);
        self::assertSame($exception->getMessage(), $result->details['message']);
        self::assertGreaterThan(0, $result->details['errno']);
    }

    #[Test]
    public function messageLeavesOutTheUrlAndTheReferenceToTheCurlDocumentation(): void
    {
        $exception = new ConnectException(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate (see https://curl.se/libcurl/c/libcurl-errors.html) for https://example.org/',
            new Request('https://example.org/')
        );

        self::assertSame(
            'cURL error 60: SSL certificate problem: unable to get local issuer certificate',
            $this->createSubject($exception)->check(...$this->createLink())->details['message']
        );
    }

    #[Test]
    public function timeoutTellsTheConfiguredSeconds(): void
    {
        $subject = $this->createSubject(new ConnectException('cURL error 28: Operation timed out', new Request('https://example.org/')), '7');

        self::assertSame(7, $subject->check(...$this->createLink())->details['seconds'] ?? null);
    }

    private function createSubject(\Throwable $exception, string $timeout = '10'): ExternalUrlChecker
    {
        $requestFactory = self::createStub(RequestFactory::class);
        $requestFactory->method('request')->willThrowException($exception);
        $extensionConfiguration = self::createStub(ExtensionConfiguration::class);
        $extensionConfiguration->method('get')->willReturnCallback(
            static fn(string $extension, string $path): ?string => $path === 'externalTimeout' ? $timeout : null
        );
        return new ExternalUrlChecker($requestFactory, new ExtensionSettings($extensionConfiguration));
    }

    /**
     * @return array{0: LinkTarget, 1: LinkCandidate}
     */
    private function createLink(): array
    {
        return [
            new LinkTarget(LinkService::TYPE_URL, 'url:https://example.org/', ['url' => 'https://example.org/']),
            new LinkCandidate('tt_content', 1, 1, 0, 'bodytext', 'https://example.org/', ''),
        ];
    }
}
