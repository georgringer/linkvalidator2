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
namespace GeorgRinger\Linkvalidator2\Check\Checker;

use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Exception\TooManyRedirectsException;
use GuzzleHttp\RequestOptions;
use Psr\Http\Client\NetworkExceptionInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\LinkHandling\LinkService;
use GeorgRinger\Linkvalidator2\Check\CacheableLinkCheckerInterface;
use GeorgRinger\Linkvalidator2\Check\CheckResult;
use GeorgRinger\Linkvalidator2\Configuration\ExtensionSettings;
use GeorgRinger\Linkvalidator2\Extraction\LinkCandidate;
use GeorgRinger\Linkvalidator2\Target\LinkTarget;

/**
 * Requests external URLs. Only a definite answer counts as broken (404, 410, unknown host);
 * anything that may be temporary or caused by the checking server is "unknown".
 *
 * @todo Crawl delay per domain and respecting Retry-After on 429.
 * @todo Escalate "unknown" to "broken" after repeated failures (failure_count/first_failed_at
 *       are already tracked by the target repository).
 */
final readonly class ExternalUrlChecker implements CacheableLinkCheckerInterface
{
    private const MAX_REDIRECTS = 5;
    private const BROKEN_STATUS_CODES = [404 => 'url.notFound', 410 => 'url.gone'];
    /** Methods servers commonly reject for HEAD although the URL works with GET */
    private const HEAD_NOT_SUPPORTED_STATUS_CODES = [405, 501];
    private const CURL_ERROR_COULD_NOT_RESOLVE_HOST = 6;
    private const CURL_ERROR_TIMEOUT = 28;
    private const CURL_ERRORS_TLS = [35, 51, 53, 54, 58, 59, 60, 64, 66, 77, 80, 82, 83, 90, 91];

    public function __construct(
        private RequestFactory $requestFactory,
        private ExtensionSettings $extensionSettings,
    ) {}

    public function getSupportedTypes(): array
    {
        return [LinkService::TYPE_URL];
    }

    public function getCacheLifetime(): int
    {
        return $this->extensionSettings->getExternalCacheLifetime();
    }

    public function check(LinkTarget $target, LinkCandidate $source): CheckResult
    {
        $url = (string)($target->resolved['url'] ?? '');
        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return CheckResult::unknown('url.unsupportedScheme', ['scheme' => $scheme]);
        }
        try {
            $statusCode = $this->request($url, 'HEAD');
            if (in_array($statusCode, self::HEAD_NOT_SUPPORTED_STATUS_CODES, true)) {
                $statusCode = $this->request($url, 'GET');
            }
        } catch (TooManyRedirectsException) {
            return CheckResult::unknown('url.tooManyRedirects', ['maxRedirects' => self::MAX_REDIRECTS]);
        } catch (NetworkExceptionInterface $e) {
            return $this->classifyConnectionError($e);
        } catch (GuzzleException $e) {
            return CheckResult::unknown('url.requestFailed', ['message' => $e->getMessage()]);
        }
        return $this->classifyStatusCode($statusCode);
    }

    private function request(string $url, string $method): int
    {
        return $this->requestFactory->request($url, $method, [
            RequestOptions::HTTP_ERRORS => false,
            RequestOptions::ALLOW_REDIRECTS => ['max' => self::MAX_REDIRECTS, 'strict' => false, 'referer' => false],
            RequestOptions::TIMEOUT => $this->extensionSettings->getExternalTimeout(),
            RequestOptions::HEADERS => ['User-Agent' => $this->extensionSettings->getExternalUserAgent()],
            // GET is only a fallback for HEAD and its body is never read. Streaming is limited to it,
            // because it switches Guzzle to the stream handler, which reports no cURL error numbers.
            RequestOptions::STREAM => $method === 'GET',
        ])->getStatusCode();
    }

    private function classifyStatusCode(int $statusCode): CheckResult
    {
        if ($statusCode >= 200 && $statusCode < 300) {
            return CheckResult::ok();
        }
        if (isset(self::BROKEN_STATUS_CODES[$statusCode])) {
            return CheckResult::broken(self::BROKEN_STATUS_CODES[$statusCode], ['httpStatus' => $statusCode]);
        }
        // 401, 403, 429, 5xx and anything else: maybe only refused to this server or temporary
        return CheckResult::unknown('url.httpStatus', ['httpStatus' => $statusCode]);
    }

    private function classifyConnectionError(NetworkExceptionInterface $e): CheckResult
    {
        $errorNumber = $this->getCurlErrorNumber($e);
        // The number alone is ambiguous (e.g. 60 for a wrong host name or an incomplete
        // certificate chain), the message tells the cause and is shown in the report
        $message = (string)preg_replace('# \(see https://curl\.se/[^)]*\)| for \S+$#', '', $e->getMessage());
        $details = ['message' => $message, 'errno' => $errorNumber];
        return match (true) {
            $errorNumber === self::CURL_ERROR_COULD_NOT_RESOLVE_HOST => CheckResult::broken('url.unknownHost', $details),
            $errorNumber === self::CURL_ERROR_TIMEOUT => CheckResult::unknown('url.timeout', $details + ['seconds' => $this->extensionSettings->getExternalTimeout()]),
            in_array($errorNumber, self::CURL_ERRORS_TLS, true) => CheckResult::unknown('url.tlsError', $details),
            default => CheckResult::unknown('url.connectionFailed', $details),
        };
    }

    /**
     * Guzzle 7 passes the number as handler context, Guzzle 8 only in the message
     * ("cURL error 28: …").
     */
    private function getCurlErrorNumber(NetworkExceptionInterface $e): int
    {
        if (method_exists($e, 'getHandlerContext') && isset($e->getHandlerContext()['errno'])) {
            return (int)$e->getHandlerContext()['errno'];
        }
        return preg_match('/cURL error (\d+)/', $e->getMessage(), $matches) === 1 ? (int)$matches[1] : 0;
    }
}
