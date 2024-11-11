<?php

namespace OCA\SURFTrashbin\Event;

use OCA\SURFTrashbin\Service\TrashbinService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\NodeDeletedEvent;
use Psr\Log\LoggerInterface;

class NodeDeletedEventListener implements IEventListener
{

    /** @var TrashbinService */
    private $trashbinService;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    public function __construct(
        TrashbinService $trashbinService,
        LoggerInterface $logger
    ) {
        $this->trashbinService = $trashbinService;
        $this->logger = $logger;
        $this->logger->debug(' - NodeDeletedEventListener initialized');
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
        $this->logger->debug(' - Event type: ' . $event::class);
        if (!($event instanceof NodeDeletedEvent)) {
            return;
        }

        $this->trashbinService->handleDeleteNode($event->getNode());
    }
}
