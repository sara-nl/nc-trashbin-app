<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Antoon Prins <antoon.prins@surf.nl>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SURFTrashbin\AppInfo;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\SURFTrashbin\Db\FileCacheMapper;
use OCA\SURFTrashbin\Db\TrashbinMapper;
use OCA\SURFTrashbin\Event\NodeDeletedEventListener;
use OCA\SURFTrashbin\Event\NodeRestoredEventListener;
use OCA\SURFTrashbin\Hooks\TrashbinHook;
use OCA\SURFTrashbin\Service\TrashbinService;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Util;
use Psr\Log\LoggerInterface;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'surf_trashbin';

    public function __construct()
    {
        parent::__construct(self::APP_ID);

        /**
         * There are 3 events we enhance: 
         * Node deleted event: move an item to the trashbin
         * Node restored event: restore a node
         * Pemanent delete event: delete a node permanently 
         */
        $dispatcher = $this->getContainer()->get(IEventDispatcher::class);
        // move a node to the trashbin event
        $dispatcher->addServiceListener(NodeDeletedEvent::class, NodeDeletedEventListener::class);
        // restore a node event
        $dispatcher->addServiceListener(NodeRestoredEvent::class, NodeRestoredEventListener::class);
        // permanently delete a node event
        $trashbinHook = new TrashbinHook(
            $this->getContainer()->get(TrashbinService::class),
            $this->getContainer()->get(FileCacheMapper::class),
            $this->getContainer()->get(TrashbinMapper::class),
            $this->getContainer()->get(LoggerInterface::class)
        );
        Util::connectHook('\OCP\Trashbin', 'delete', $trashbinHook, 'permanentDelete');
    }

    public function register(IRegistrationContext $context): void {}

    public function boot(IBootContext $context): void {}
}
