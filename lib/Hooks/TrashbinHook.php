<?php

/**
 * This class enhances the trashbin delete event with SURF trashbin related functionality.
 * 
 */

namespace OCA\SURFTrashbin\Hooks;

use Exception;
use OC\Files\View;
use OCA\SURFTrashbin\AppInfo\Application;
use OCA\SURFTrashbin\Db\FileCacheMapper;
use OCA\SURFTrashbin\Db\TrashbinMapper;
use OCA\SURFTrashbin\Service\TrashbinService;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

class TrashbinHook
{
    /** @var TrashbinService */
    private TrashbinService $trashbinService;

    /** @var FileCacheMapper */
    private FileCacheMapper $fileCacheMapper;

    /** @var TrashbinMapper */
    private TrashbinMapper $trashbinMapper;

    /** @var IUserSession */
    private $userSession;

    /** @var ILockingProvider */
    private $lockingProvider;

    /** @var LoggerInterface */
    private $logger;

    public function __construct(
        TrashbinService $trashbinService,
        FileCacheMapper $fileCacheMapper,
        TrashbinMapper $trashbinMapper,
        IUserSession $userSession,
        ILockingProvider $lockingProvider,
        LoggerInterface $logger,
    ) {
        $this->trashbinService = $trashbinService;
        $this->fileCacheMapper = $fileCacheMapper;
        $this->trashbinMapper = $trashbinMapper;
        $this->userSession = $userSession;
        $this->lockingProvider = $lockingProvider;
        $this->logger = $logger;
    }

    /**
     * The method called when a node is permanently deleted.
     * 
     * @param array $params ['path' => {path}]
     * @return void
     */
    public function permanentDelete(array $params): void
    {
        /**
         * Only cascade the cleanup when a logged-in user explicitly deletes a
         * trashbin item. The trashbin background job (auto-expiration, e.g. of a
         * zero-quota user's trashbin, which runs within minutes via cron) and CLI
         * maintenance commands emit this same hook. Cascading in those contexts
         * unlinks the f_account's master trashbin copy (and the project owner's
         * copy) while the project owner may still need to restore it - resulting
         * in permanent data loss.
         */
        if ($this->userSession->getUser() === null) {
            return;
        }

        // get the filecache items and find out if we are dealing with an f_account item
        $path = $params['path'];
        /**
         * Mitigate Trashbin bug:
         * Trashbin app leaves '//' in the path which is not present in the filecache path thus preventing proper cleanup. 
         * We replace these with a single '/'.
         */
        $cleanPath = str_replace('//', '/', ltrim($path, '/'));
        $fragments = explode('/', $cleanPath);
        $name = array_pop($fragments);
        $fileCacheItems = $this->fileCacheMapper->getItems($cleanPath, $name);
        $fAccountFileCacheItem = null;
        $fAccountUID = null;
        $ownerOrUserFileCacheItem = null;
        $ownerOrUserUID = null;
        // Note that if the session user IS the owner then that filecache item is already deleted,
        // so only the f_account item will be returned
        foreach ($fileCacheItems as $item) {
            [$accountUID, $accountType] = $this->trashbinService->getAccountUIDAndTypeFromStorageId($item[FileCacheMapper::TABLE_COLUMN_STORAGE_ID]);
            if (TrashbinService::ACCOUNT_TYPE_F_ACCOUNT === $accountType) {
                $fAccountFileCacheItem = $item;
                $fAccountUID = $accountUID;
            } else if (TrashbinService::ACCOUNT_TYPE_USER === $accountType) {
                $ownerOrUserFileCacheItem = $item;
                $ownerOrUserUID = $accountUID;
            } else {
                // this should not happen
                throw new Exception('Unable to handle permanent delete. Found unexpected account type: ' . print_r($accountType, true));
            }
        }
        if (!isset($fAccountFileCacheItem)) {
            // not an f_account folder item, just return
            return;
        }

        $fAccountView = new View("/$fAccountUID");
        $fAccountPath = $fAccountFileCacheItem[FileCacheMapper::TABLE_COLUMN_PATH];

        /**
         * Guard against unlinking the f_account's trashbin node while another
         * process is still copying FROM it. Nextcloud's own move2trash() holds
         * an exclusive lock on this exact path for the duration of
         * Trashbin::copyFilesToUser() (f_account -> deleting user), and our own
         * handleDeleteNode() holds the same kind of window while copying to the
         * project owner. If a user permanently deletes their trashbin item while
         * either copy is still running, unlinking here out from under it throws
         * an uncaught CopyRecursiveException in Nextcloud's own code and can
         * leave every party's trashbin empty. Retry briefly instead of unlinking
         * blindly; a concurrent copy is expected to finish within seconds.
         */
        [$fAccountStorage, $fAccountInternalPath] = $fAccountView->resolvePath($fAccountPath);
        $locked = false;
        for ($attempt = 0; $attempt < 20; $attempt++) {
            try {
                $fAccountStorage->acquireLock($fAccountInternalPath, ILockingProvider::LOCK_EXCLUSIVE, $this->lockingProvider);
                $locked = true;
                break;
            } catch (LockedException $e) {
                usleep(250_000);
            }
        }
        if (!$locked) {
            $this->logger->warning("permanentDelete aborted - '$fAccountPath' is still locked by a concurrent copy after waiting; the f_account trashbin copy is left untouched.", ['app' => Application::APP_ID]);
            return;
        }

        try {
            $fAccountView->unlink($fAccountPath);
        } finally {
            $fAccountStorage->releaseLock($fAccountInternalPath, ILockingProvider::LOCK_EXCLUSIVE, $this->lockingProvider);
        }

        if (isset($ownerOrUserFileCacheItem)) {
            $ownerOrUserView = new View("/$ownerOrUserUID");
            $ownerOrUserView->unlink($ownerOrUserFileCacheItem[FileCacheMapper::TABLE_COLUMN_PATH]);
        }

        // Retrieve the trashbin items so we can delete them from the table
        [$fileOrFoldername, $timestamp] = $this->trashbinService->getNameAndTimestamp($name);
        $trashbinItems = $this->trashbinMapper->getItems($fileOrFoldername, $timestamp);
        foreach ($trashbinItems as $item) {
            $this->trashbinMapper->deleteItems(
                $item[TrashbinMapper::TABLE_COLUMN_ID],
                $item[TrashbinMapper::TABLE_COLUMN_TIMESTAMP],
                $item[TrashbinMapper::TABLE_COLUMN_USER]
            );
        }
    }
}
