<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Antoon Prins <antoon.prins@surf.nl>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\SURFTrashbin\AppInfo;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\SURFTrashbin\Event\NodeDeletedEventListener;
use OCA\SURFTrashbin\Event\NodeRestoredEventListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Files\Events\Node\NodeDeletedEvent;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'surf_trashbin';

    public function __construct()
    {
        parent::__construct(self::APP_ID);
    }

    /**
     * There are 2 events we enhance via the typed event dispatcher:
     * Node deleted event: move an item to the trashbin
     * Node restored event: restore a node
     */
    public function register(IRegistrationContext $context): void
    {
        // move a node to the trashbin event
        $context->registerEventListener(NodeDeletedEvent::class, NodeDeletedEventListener::class);
        // restore a node event
        $context->registerEventListener(NodeRestoredEvent::class, NodeRestoredEventListener::class);
    }

    public function boot(IBootContext $context): void {}
}
