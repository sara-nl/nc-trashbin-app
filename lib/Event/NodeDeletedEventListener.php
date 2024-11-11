<?php

namespace OCA\SURFTrashbin\Event;

use OCA\SURFTrashbin\Service\TrashbinService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;

class NodeDeletedEventListener implements IEventListener
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
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        $this->trashbinService->handleDeleteNode($event->getNode());
    }
}
