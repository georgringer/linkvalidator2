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
namespace GeorgRinger\Linkvalidator2\MessageHandler;

use GeorgRinger\Linkvalidator2\Check\CheckStatus;
use GeorgRinger\Linkvalidator2\Message\CheckRecordsMessage;
use GeorgRinger\Linkvalidator2\Service\RecordCheckService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use TYPO3\CMS\Core\Cache\CacheManager;

#[AsMessageHandler]
final readonly class CheckRecordsHandler
{
    public function __construct(
        private RecordCheckService $recordCheckService,
        private CacheManager $cacheManager,
        private LoggerInterface $logger,
    ) {}

    public function __invoke(CheckRecordsMessage $message): void
    {
        // A worker handles many messages in one process and closes the database connection after each
        // of them. The runtime cache keeps prepared statements (BackendUtility::BEgetRootLine()) of the
        // closed connection and would serve records and pages that changed since.
        $this->cacheManager->getCache('runtime')->flush();
        $start = microtime(true);
        $this->logger->info('Checking records', ['table' => $message->table, 'records' => count($message->uids)]);
        $brokenLinks = 0;
        foreach ($message->uids as $uid) {
            foreach ($this->recordCheckService->checkRecord($message->table, $uid) as $checkedLink) {
                $brokenLinks += in_array($checkedLink->result->status, [CheckStatus::Broken, CheckStatus::Unknown], true) ? 1 : 0;
            }
        }
        $this->logger->info('Checked records', [
            'table' => $message->table,
            'records' => count($message->uids),
            'brokenLinks' => $brokenLinks,
            'seconds' => round(microtime(true) - $start, 1),
        ]);
    }
}
