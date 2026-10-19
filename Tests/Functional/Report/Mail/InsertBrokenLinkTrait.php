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

namespace GeorgRinger\Linkvalidator2\Tests\Functional\Report\Mail;

trait InsertBrokenLinkTrait
{
    private function insert(int $pid, string $table, int $uid, int $firstDetected, string $errorType = 'url.notFound', string $link = 'https://example.org/x', string $linkText = 'text', string $status = 'broken'): void
    {
        $targets = $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_target');
        $targets->insert('tx_linkvalidator2_target', [
            'link_type' => 'url',
            'identifier' => 'url:' . $link . $firstDetected . $uid,
            'identifier_hash' => sha1($link . $firstDetected . $uid . $table . $status),
            'status' => $status,
            'error_type' => $errorType,
        ]);
        $this->getConnectionPool()->getConnectionForTable('tx_linkvalidator2_broken_link')->insert('tx_linkvalidator2_broken_link', [
            'target' => (int)$targets->lastInsertId(),
            'table_name' => $table,
            'record_uid' => $uid,
            'field' => 'bodytext',
            'pid' => $pid,
            'link_string' => $link,
            'link_text' => $linkText,
            'first_detected' => $firstDetected,
            'last_seen' => $firstDetected,
        ]);
    }
}
