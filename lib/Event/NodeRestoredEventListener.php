<?php

namespace OCA\SURFTrashbin\Event;

use OCA\Files_Trashbin\Events\NodeRestoredEvent;
use OCA\SURFTrashbin\Service\TrashbinService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

class NodeRestoredEventListener implements IEventListener
{

    /** @var TrashbinService */
    private $trashbinService;

    public function __construct(
        TrashbinService $trashbinService,
    ) {
        $this->trashbinService = $trashbinService;
    }

    /**
     * This method catches the node delete event and triggers the actions 
     * that will make the deleted node visible in the trashbin of the owner of the shared folder.
     * 
     * @param Event $event
     * @psalm-param T $event
     * @return void
     */
    public function handle(Event $event): void
    {
        if (!($event instanceof NodeRestoredEvent)) {
            return;
        }

        $this->trashbinService->handleRestoreNode($event->getSource());
    }
}
