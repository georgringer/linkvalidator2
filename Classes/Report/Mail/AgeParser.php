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

/**
 * Turns an age such as "7 days" into the timestamp that long before now.
 */
final readonly class AgeParser
{
    public function toTimestamp(string $age, int $now): int
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
