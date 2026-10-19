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

use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UriInterface;

/**
 * Absolute backend URLs for the links of the mail report.
 */
final readonly class BackendUrls
{
    public function __construct(
        private UriInterface $moduleUri,
        private UriInterface $editUri,
        private ServerRequestInterface $request,
    ) {}

    /**
     * A request for the host of the backend, with its normalized params, for the layout of the mail
     */
    public function getRequest(): ServerRequestInterface
    {
        return $this->request;
    }

    public function module(int $pageUid): string
    {
        return (string)$this->moduleUri->withQuery(http_build_query(['id' => $pageUid]));
    }

    public function edit(string $table, int $uid): string
    {
        return (string)$this->editUri->withQuery(http_build_query(['edit' => [$table => [$uid => 'edit']]]));
    }
}
