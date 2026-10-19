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

namespace GeorgRinger\Linkvalidator2\Report\Mail;

use TYPO3\CMS\Backend\Routing\Router;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Routing\BackendEntryPointResolver;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * The command has no request, so the host comes from the site of the page. A backend entry point
 * configured as a full URL wins, because the backend may live on another domain.
 */
final readonly class BackendUrlFactory
{
    private const MODULE_ROUTE = 'linkvalidator2_report';
    private const EDIT_ROUTE = 'record_edit';

    public function __construct(
        private SiteFinder $siteFinder,
        private BackendEntryPointResolver $backendEntryPointResolver,
        private Router $router,
    ) {}

    public function create(int $pageUid, ?string $baseUrl): BackendUrls
    {
        $base = (string)$baseUrl;
        if ($base === '') {
            try {
                $base = (string)$this->siteFinder->getSiteByPageId($pageUid)->getBase();
            } catch (SiteNotFoundException $e) {
                throw new \RuntimeException(sprintf('Page %d belongs to no site, so the host of the backend is unknown. Pass --base-url.', $pageUid), 1792436100, $e);
            }
        }
        $uri = new Uri($base);
        if ($uri->getHost() === '') {
            throw new \RuntimeException(sprintf('The base URL "%s" has no host.', $base), 1792436112);
        }
        $request = $this->createRequest($uri);
        return new BackendUrls(
            $this->backendEntryPointResolver->getUriFromRequest($request, ltrim($this->router->getRoute(self::MODULE_ROUTE)->getPath(), '/')),
            $this->backendEntryPointResolver->getUriFromRequest($request, ltrim($this->router->getRoute(self::EDIT_ROUTE)->getPath(), '/')),
        );
    }

    /**
     * The normalized params get their own script path. The one of the running script, in the command
     * line vendor/bin/typo3, would be taken as the directory of the site and cut the host.
     */
    private function createRequest(Uri $uri): ServerRequest
    {
        $publicPath = Environment::getPublicPath();
        $request = new ServerRequest($uri->withPath('/')->withQuery('')->withFragment(''), 'GET', 'php://input', [], [
            'HTTP_HOST' => $uri->getHost() . ($uri->getPort() !== null ? ':' . $uri->getPort() : ''),
            'HTTPS' => $uri->getScheme() === 'https' ? 'on' : 'off',
            'REQUEST_URI' => '/',
            'SCRIPT_NAME' => '/index.php',
            'SCRIPT_FILENAME' => $publicPath . '/index.php',
            'DOCUMENT_ROOT' => $publicPath,
        ]);
        return $request->withAttribute(
            'normalizedParams',
            new NormalizedParams($request->getServerParams(), $GLOBALS['TYPO3_CONF_VARS']['SYS'], $publicPath . '/index.php', $publicPath)
        );
    }
}
