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
namespace GeorgRinger\Linkvalidator2\Check;

/**
 * A checker whose check is expensive, e.g. a network request. Its result is stored per
 * target and reused until it is older than the cache lifetime.
 */
interface CacheableLinkCheckerInterface extends LinkCheckerInterface
{
    /**
     * @return int Seconds a stored result stays valid
     */
    public function getCacheLifetime(): int;
}
