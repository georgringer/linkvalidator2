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

use GeorgRinger\Linkvalidator2\Report\ReportFilter;

/**
 * What a mail report covers: a page with its subpages, filtered like the report in the module.
 */
final readonly class MailReportRequest
{
    /**
     * @param list<string> $includeTables
     * @param list<string> $excludeTables
     * @param string|null $minAge Only links broken for at least this long, for example "7 days"
     * @param string|null $maxAge Only links broken for at most this long
     */
    public function __construct(
        public int $pageUid,
        public int $depth,
        public ReportFilter $filter,
        public array $includeTables = [],
        public array $excludeTables = [],
        public ?string $minAge = null,
        public ?string $maxAge = null,
    ) {}

    /**
     * The times a link may have been first detected: not before the first value (from --max-age), not
     * after the second one (from --min-age).
     *
     * @return array{0: int|null, 1: int|null}
     * @throws \InvalidArgumentException If an age cannot be used or no link can be broken that long
     */
    public function getDetectedRange(int $now): array
    {
        $since = $this->maxAge !== null ? $this->toTimestamp($this->maxAge, $now) : null;
        $before = $this->minAge !== null ? $this->toTimestamp($this->minAge, $now) : null;
        if ($since !== null && $before !== null && $before < $since) {
            throw new \InvalidArgumentException(sprintf('No link can be broken for at least "%s" and at most "%s".', $this->minAge, $this->maxAge), 1792436301);
        }
        return [$since, $before];
    }

    private function toTimestamp(string $age, int $now): int
    {
        try {
            $interval = \DateInterval::createFromDateString(trim($age));
        } catch (\Throwable) {
            $interval = false;
        }
        if ($interval === false || trim($age) === '') {
            throw new \InvalidArgumentException(sprintf('"%s" is not an age like "7 days" or "12 hours".', $age), 1792435848);
        }
        $timestamp = (new \DateTimeImmutable('@' . $now))->sub($interval)->getTimestamp();
        if ($timestamp >= $now) {
            throw new \InvalidArgumentException(sprintf('The age "%s" must be longer than zero.', $age), 1792435862);
        }
        return $timestamp;
    }
}
