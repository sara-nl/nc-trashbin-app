<?php

namespace OCA\SURFTrashbin\Hooks;

use Exception;
use OC\Files\View;
use OCA\SURFTrashbin\Db\FileCacheMapper;
use OCA\SURFTrashbin\Db\TrashbinMapper;
use OCA\SURFTrashbin\Service\TrashbinService;

class TrashbinHook
{
    /** @var TrashbinService */
    private TrashbinService $trashbinService;

    /** @var FileCacheMapper */
    private FileCacheMapper $fileCacheMapper;

    /** @var TrashbinMapper */
    private TrashbinMapper $trashbinMapper;

    public function __construct(
        TrashbinService $trashbinService,
        FileCacheMapper $fileCacheMapper,
        TrashbinMapper $trashbinMapper,
    ) {
        $this->trashbinService = $trashbinService;
        $this->fileCacheMapper = $fileCacheMapper;
        $this->trashbinMapper = $trashbinMapper;
    }

    public function permanentDelete(array $params): void
    {
        // get the filecache items and find out if we are dealing with an f_account item
        $path = $params['path'];
        $cleanPath = trim($path, '/');
        $fragments = explode('/', $cleanPath);
        $name = array_pop($fragments);
        $fileCacheItems = $this->fileCacheMapper->getItems($cleanPath, $name);
        $fAccountFileCacheItem = null;
        $fAccountUID = null;
        $ownerOrUserFileCacheItem = null;
        $ownerOrUserUserUID = null;
        // Note that if the session user IS the owner then that filecache item is already deleted,
        // so only the f_account item will be returned
        foreach ($fileCacheItems as $item) {
            [$accountUID, $accountType] = $this->trashbinService->getAccountUIDAndTypeFromStorageId($item[FileCacheMapper::TABLE_COLUMN_STORAGE_ID]);
            if (TrashbinService::ACCOUNT_TYPE_F_ACCOUNT === $accountType) {
                $fAccountFileCacheItem = $item;
                $fAccountUID = $accountUID;
            } else if (TrashbinService::ACCOUNT_TYPE_USER === $accountType) {
                $ownerOrUserFileCacheItem = $item;
                $ownerOrUserUserUID = $accountUID;
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
        $fAccountView->unlink($fAccountFileCacheItem[FileCacheMapper::TABLE_COLUMN_PATH]);
        
        if (isset($ownerOrUserFileCacheItem)) {
            $ownerOrUserView = new View("/$ownerOrUserUserUID");
            $ownerOrUserView->unlink($ownerOrUserFileCacheItem[FileCacheMapper::TABLE_COLUMN_PATH]);
        }


        // retrieve the trashbin items so we can delete them from the table
        [$fileOrFoldername, $timestamp] = $this->trashbinService->getNameAndTimestamp($name);
        $trashbinItems = $this->trashbinMapper->getItems($fileOrFoldername, $timestamp);
        foreach ($trashbinItems as $item) {
            // to be sure; check that we are dealing with the right items
            if (
                $fAccountUID === $item[TrashbinMapper::TABLE_COLUMN_USER]
                || $ownerOrUserUserUID === $item[TrashbinMapper::TABLE_COLUMN_USER]
            ) {
                $this->trashbinMapper->deleteItems(
                    $item[TrashbinMapper::TABLE_COLUMN_ID],
                    $item[TrashbinMapper::TABLE_COLUMN_TIMESTAMP],
                    $item[TrashbinMapper::TABLE_COLUMN_USER]
                );
            }
        }
    }
}