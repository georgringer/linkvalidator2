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
namespace TYPO3\CMS\Linkvalidator2\Check;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\UserAspect;
use TYPO3\CMS\Core\Context\VisibilityAspect;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Error\Http\StatusException;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Frontend\Page\PageInformationCreationFailedException;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;

/**
 * Asks the frontend's page resolution whether a visitor gets a page, without rendering it:
 * hidden pages and rootline (extendToSubpages), page types, shortcuts, languages and access
 * are decided exactly as for a request. Results are kept for the current process.
 *
 * The resolution is done as an anonymous visitor regardless of a logged-in backend user, and
 * without the error handling of the site, which might fetch an error page for every failure.
 */
final class FrontendPageResolver
{
    public const FAILURE_NO_SITE = 1;
    /**
     * Failures after all page, access and language checks passed, about the rendering setup of the
     * site rather than the page: no TypoScript at all for the site
     */
    private const SETUP_FAILURE_CODES = [1705656657];

    /** @var array<string, int|null> */
    private array $results = [];

    public function __construct(
        private readonly PageInformationFactory $pageInformationFactory,
        private readonly SiteFinder $siteFinder,
        private readonly Context $context,
    ) {}

    /**
     * @return int|null Null when a visitor gets the page, otherwise the code of the failure
     *                  (exception code of the frontend's page resolution or FAILURE_NO_SITE)
     */
    public function getFailureCode(int $pageUid, int $languageId = 0): ?int
    {
        return $this->results[$pageUid . '-' . $languageId] ??= $this->resolve($pageUid, $languageId);
    }

    private function resolve(int $pageUid, int $languageId): ?int
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
            $language = $site->getLanguageById($languageId);
        } catch (SiteNotFoundException|\InvalidArgumentException) {
            return self::FAILURE_NO_SITE;
        }
        $configuration = $site->getConfiguration();
        unset($configuration['errorHandling']);
        $site = new Site($site->getIdentifier(), $site->getRootPageId(), $configuration);
        $base = $site->getBase();
        $request = (new ServerRequest((string)$base, 'GET'))
            ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_FE)
            ->withAttribute('site', $site)
            ->withAttribute('language', $language)
            ->withAttribute('routing', new PageArguments($pageUid, '0', []))
            ->withAttribute('normalizedParams', NormalizedParams::createFromServerParams([
                'HTTP_HOST' => $base->getHost() ?: 'localhost',
                'HTTPS' => $base->getScheme() === 'https' ? 'on' : 'off',
            ]));

        $aspects = [];
        foreach (['backend.user', 'visibility', 'language'] as $name) {
            $aspects[$name] = $this->context->hasAspect($name) ? $this->context->getAspect($name) : null;
        }
        $this->context->setAspect('backend.user', new UserAspect());
        $this->context->setAspect('visibility', new VisibilityAspect());
        try {
            $this->pageInformationFactory->create($request);
            return null;
        } catch (PageInformationCreationFailedException|StatusException $e) {
            return in_array((int)$e->getCode(), self::SETUP_FAILURE_CODES, true) ? null : (int)$e->getCode();
        } finally {
            foreach ($aspects as $name => $aspect) {
                if ($aspect !== null) {
                    $this->context->setAspect($name, $aspect);
                } else {
                    $this->context->unsetAspect($name);
                }
            }
        }
    }
}
