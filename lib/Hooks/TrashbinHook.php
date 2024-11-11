<?php

namespace OCA\SURFTrashbin\Hooks;

use Psr\Log\LoggerInterface;

class TrashbinHook
{

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    public function permanentDelete(array $params): void
    {
        $this->logger->debug(' - permanentDelete hook triggered: ' . print_r($params, true));
    }
}
