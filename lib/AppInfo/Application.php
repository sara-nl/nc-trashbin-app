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
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\IUserSession;
use OCP\Util;

class Application extends App implements IBootstrap
{
    public const APP_ID = 'surf_trashbin';

    public function __construct()
    {
        parent::__construct(self::APP_ID);

        /**
         * Permanent delete event: delete a node permanently.
         *
         * The files_trashbin app emits the legacy '\OCP\Trashbin'/'delete' hook on
         * permanent delete (verified against NC stable33/stable34; there is no typed
         * event equivalent). We register the slot here in the constructor rather than in
         * boot(), because the WebDAV/Sabre request path (used by the Files trashbin UI)
         * does not invoke IBootstrap::boot() for every app, whereas the App constructor
         * always runs when the app is loaded. Registering in boot() silently breaks the
         * permanent-delete cleanup.
         */
        $container = $this->getContainer();
        $trashbinHook = new TrashbinHook(
            $container->get(TrashbinService::class),
            $container->get(FileCacheMapper::class),
            $container->get(TrashbinMapper::class),
            $container->get(IUserSession::class)
        );
        Util::connectHook('\OCP\Trashbin', 'delete', $trashbinHook, 'permanentDelete');
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
