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
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Mail\FluidEmail;
use TYPO3\CMS\Core\Mail\TemplatedEmailFactory;

final readonly class ReportMailFactory
{
    public function __construct(
        private TemplatedEmailFactory $templatedEmailFactory,
        private LanguageServiceFactory $languageServiceFactory,
    ) {}

    /**
     * @param list<MailReportItem> $inline The links listed in the text, at most the inline limit
     * @param int $total All links of the report, also those not listed
     * @param string|null $csv Attached if not null
     * @param ServerRequestInterface|null $request For the host in the layout of the mail
     */
    public function create(MailReportOptions $options, string $pageTitle, int $total, array $inline, ?string $csv, string $moduleUrl, int $now, ?ServerRequestInterface $request = null): FluidEmail
    {
        $subject = $options->subject
            ?? sprintf($this->languageServiceFactory->create($options->language)->sL('linkvalidator2.messages:mail.subject'), $pageTitle);
        $email = $this->templatedEmailFactory->create($request);
        $email
            ->to(...array_map(static fn(string $address): Address => new Address($address), $options->to))
            ->subject($subject)
            ->setTemplate($options->template)
            ->assignMultiple([
                'headline' => $subject,
                'pageTitle' => $pageTitle,
                'total' => $total,
                'items' => $options->content->includesInline() ? $inline : [],
                'more' => $options->content->includesInline() ? max(0, $total - count($inline)) : 0,
                'hasCsv' => $csv !== null,
                'moduleUrl' => $moduleUrl,
                'language' => $options->language,
            ]);
        if ($options->from !== null) {
            $email->from(new Address($options->from));
        }
        if ($csv !== null) {
            $email->attach($csv, 'broken-links-' . gmdate('Y-m-d', $now) . '.csv', 'text/csv');
        }
        return $email;
    }
}
