<?php

/**
 * This class implements the logic and mechanisms for the SURF trashbin app.
 * 
 */

namespace OCA\SURFTrashbin\Service;

use Exception;
use OC\Files\View;
use OCA\SURFTrashbin\Db\FileCacheMapper;
use OCA\SURFTrashbin\Db\ShareMapper;
use OCA\SURFTrashbin\Db\TrashbinMapper;
use OCP\Files\Node;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class TrashbinService
{

	/** @var FileCacheMapper */
	private $fileCacheMapper;

	/** @var TrashbinMapper */
	private $trashbinMapper;

	/** @var ShareMapper */
	private $shareMapper;

	/** @var IUserSession */
	private $userSession;

	/** @var LoggerInterface */
	private $logger;

	public const ACCOUNT_TYPE_F_ACCOUNT = 'f_account';
	public const ACCOUNT_TYPE_USER = 'user';

	public function __construct(
		FileCacheMapper $fileCacheMapper,
		TrashbinMapper $trashbinMapper,
		ShareMapper $shareMapper,
		IUserSession $userSession,
		LoggerInterface $logger
	) {
		$this->fileCacheMapper = $fileCacheMapper;
		$this->trashbinMapper = $trashbinMapper;
		$this->shareMapper = $shareMapper;
		$this->userSession = $userSession;
		$this->logger = $logger;
	}

	/**
	 * Adds an entry in the trashbin and filecache of the owner of the shared folder from which the specified node was deleted.
	 * Also create a 0 byte trashbin node for the owner of the shared folder in which the node was deleted by the user.
	 * The result will be that the owner will also see the deleted node in the trashbin, 
	 * and be able to restore it just like the user who deleted it in the first place.
	 * 
	 * @var Node the deleted node
	 * @return void
	 */
	public function handleDeleteNode(Node $nodeDeleted): void
	{
		// are we dealing with an fAccount node?
		$fAccountFilecacheItem = $this->getFAccountFilecacheItem($nodeDeleted->getId());
		if (!isset($fAccountFilecacheItem)) {
			// we are not dealing with a node from a shared f_account folder 
			$this->logger->debug(' - we are NOT dealing with a node from a shared f_account folder; just return ');
			return;
		}

		$userAccountFilecacheItem = $this->fileCacheMapper->getUserforFAccountItem($fAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_FILEID]);
		if (\count($userAccountFilecacheItem) == 0) {
			// we are not dealing with a node deleted by a f_account group user 
			$this->logger->debug(' - we are NOT dealing with a node deleted by a f_account group user; just return ');
			return;
		}

		// find the corresponding trashbin entry of the current user
		[$name, $timestamp] = $this->getNameAndTimestamp($userAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_NAME]);
		if (!isset($name) || !isset($timestamp)) {
			// user is probably restoring a sub folder which does not contain the timestamp
			return;
		}
		$sessionUID = $this->userSession->getUser()->getUID();
		[
			$trashbinItemId,,
			$trashbinItemTimestamp,
			$trashbinItemLocation,
			$trashbinItemDeletedBy
		] = array_values($this->trashbinMapper->getItem($name, $sessionUID, $timestamp, $sessionUID));

		// create the shared folder owner copies of the entries; except when the owner is the one deleting the node
		[$fAccountUID,] = $this->getAccountUIDAndTypeFromStorageId($fAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_STORAGE_ID]);
		$ownerUID = $this->shareMapper->getGroupOwnerUID($fAccountUID);
		if ($sessionUID != $ownerUID) {
			$this->trashbinMapper->insertItem(
				$trashbinItemId,
				$ownerUID,
				$trashbinItemTimestamp,
				$trashbinItemLocation,
				$trashbinItemDeletedBy,
			);
		}

		// Insert the owner's filecache item.
		// Note that we do not create the whole tree in case of a folder, the owner does not need to be able to browse it,
		// but only restore it. Therefore the folder that was being deleted will suffice. No need to add any sub folders or files.

		// First make sure the trashbin is there; this will also create the trashbin filecache entries 
		$this->setUpTrash($ownerUID);
		// the parent is the owner's trashbin root filecache item; we need the owner's storage numeric id
		$numericStorageId = $this->getNumericStorageId($ownerUID);
		[FileCacheMapper::TABLE_COLUMN_FILEID => $parentFileId] = $this->fileCacheMapper->getTrashbinRootItem($numericStorageId);
		// if we are the user: copy and insert the user account filecache item to create a filecache item for the owner
		// so that the owner will see the deleted node
		if ($sessionUID !== $ownerUID) {
			$ownerAccountFilecacheItem = $userAccountFilecacheItem;
			$ownerAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_STORAGE] = $numericStorageId;
			$ownerAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_PARENT] = $parentFileId;
			$this->fileCacheMapper->insertItem($ownerAccountFilecacheItem);
		}

		// finally create a 0 byte trashbin node copy for the owner of the f_account folder
		// this will give the owner the restore/permanent-delete functionality because a file is 'actually there'.
		if ($sessionUID !== $ownerUID) {
			$view = new View('/' . $ownerUID);
			$userView = new View('/' . $sessionUID);
			$nodePath = 'files_trashbin/files/' . $userAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_NAME];
			if (!$userView->file_exists($nodePath)) {
				throw new Exception("Node '$nodePath' does not exist for user '$sessionUID'.");
			}
			if ($userView->is_dir($nodePath)) {
				$this->logger->debug(" node is dir");
				$view->mkdir($nodePath);
			} else {
				$this->logger->debug(" node is file");
			}
			$view->touch($nodePath, $userView->filemtime($nodePath));
		}
	}

	/**
	 * Restores the correct original file if it is the owner that restores it.
	 * Cleans up any remaining trashbin and filecache table items and storage.
	 */
	public function handleRestoreNode(Node $sourceNode): void
	{
		$name = $sourceNode->getName(); // format: {folder-or-filename}.d{timestamp}{/any-sub-folder-path}
		[$fileOrFolderName, $timestamp] = $this->getNameAndTimestamp($name);

		// get the trashbin items so we can decide what to do
		$userTrashbinItem = null;
		$ownerTrashbinItem = null;
		$fAccountTrashbinItem = null;
		$trashbinItems = $this->trashbinMapper->getItems($fileOrFolderName, $timestamp);
		if (count($trashbinItems) < 2) {
			// we are not dealing with restoring a node on an f_account
			return;
		}
		foreach ($trashbinItems as $item) {
			if (\str_starts_with($item[TrashbinMapper::TABLE_COLUMN_USER], 'f_')) {
				$fAccountTrashbinItem = $item;
			} else if ($item[TrashbinMapper::TABLE_COLUMN_USER] != $item[TrashbinMapper::TABLE_COLUMN_DELETED_BY]) {
				$ownerTrashbinItem = $item;
			} else {
				$userTrashbinItem = $item;
			}
		}

		// get the filecache items
		// note that the filecache item tree of the session user (owner, user) who restored the node is already deleted
		// so either user or owner item will be null
		$userFilecacheItem = null;
		$ownerFilecacheItem = null;
		$fAccountFilecacheItem = null;
		$filecacheItems = $this->fileCacheMapper->getItems("files_trashbin/files/$name", $name);
		foreach ($filecacheItems as $item) {
			[$uid,] = $this->getAccountUIDAndTypeFromStorageId($item[FileCacheMapper::TABLE_COLUMN_STORAGE_ID]);
			if ($fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER] == $uid) {
				$fAccountFilecacheItem = $item;
			} else if (isset($ownerTrashbinItem) && $ownerTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER] == $uid) {
				$ownerFilecacheItem = $item;
			} else if (isset($userTrashbinItem) && $userTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER] == $uid) {
				$userFilecacheItem = $item;
			}
		}

		// In case we are the owner and we're restoring a node deleted by another user nextcloud has now restored the 0 bytes copy,
		// and we must correct this by replacing it with the original node from the user storage.
		$sessionUID = $this->userSession->getUser()->getUID();
		$deletedByUID = $fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_DELETED_BY];
		$fAccountOriginalLocation = $fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_LOCATION];
		$fAccountUID = $fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER];
		$ownerUID = isset($ownerTrashbinItem) ? $ownerTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER] : null;
		$deletedByUID = $fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_DELETED_BY];
		if ($sessionUID === $ownerUID && $sessionUID !== $deletedByUID) {
			$view = new View('');
			$source = "/$deletedByUID/files_trashbin/files/$name";
			$location = trim($fAccountOriginalLocation, '/');
			$target = "/$fAccountUID/files/$location/$fileOrFolderName";
			self::moveRecursive($view, $source, $target);
			// and delete the 0 bytes node in the owner's trashbin
			$view = new View("/$ownerUID");
			$view->unlink("files_trashbin/files/$name");
		}

		// unlink all filecache items
		$fAccountView = new View("/$fAccountUID");
		$fAccountView->unlink($fAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_PATH]);
		if (isset($ownerFilecacheItem)) {
			$ownerView = new View("/$ownerUID");
			$ownerView->unlink($ownerFilecacheItem[FileCacheMapper::TABLE_COLUMN_PATH]);
		}
		if (isset($userFilecacheItem)) {
			$userUID = $userTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER];
			$userView = new View("/$userUID");
			$userView->unlink($userFilecacheItem[FileCacheMapper::TABLE_COLUMN_PATH]);
		}

		// make sure any remaining trashbin items are removed
		$this->trashbinMapper->deleteItems($fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_ID], $fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_TIMESTAMP], $fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER]);
		if (isset($ownerTrashbinItem)) {
			$this->trashbinMapper->deleteItems($ownerTrashbinItem[TrashbinMapper::TABLE_COLUMN_ID], $ownerTrashbinItem[TrashbinMapper::TABLE_COLUMN_TIMESTAMP], $ownerTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER]);
		}
		if (isset($userTrashbinItem)) {
			$this->trashbinMapper->deleteItems($userTrashbinItem[TrashbinMapper::TABLE_COLUMN_ID], $userTrashbinItem[TrashbinMapper::TABLE_COLUMN_TIMESTAMP], $userTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER]);
		}
	}

	/**
	 * Recursive function to move a whole directory
	 *
	 * @param View $view file view for the users root directory
	 * @param string $source source path, relative to the users files directory
	 * @param string $destination destination path relative to the users root directory
	 * @return int|float
	 * @throws Exceptions\CopyRecursiveException
	 */
	private static function moveRecursive(View $view, string $source, string $destination): int|float
	{
		$size = 0;
		if ($view->is_dir($source)) {
			$view->mkdir($destination);
			$view->touch($destination, $view->filemtime($source));
			foreach ($view->getDirectoryContent($source) as $i) {
				$pathDir = $source . '/' . $i['name'];
				if ($view->is_dir($pathDir)) {
					$size += self::copy_recursive($view, $pathDir, $destination . '/' . $i['name']);
				} else {
					$size += $view->filesize($pathDir);
					$result = self::move($view, $pathDir, $destination . '/' . $i['name']);
					if (!$result) {
						throw new Exception('Recursive move error. Result was: ' . print_r($result, true));
					}
				}
			}
		} else {
			$size += $view->filesize($source);
			$result = self::move($view, $source, $destination);
			if (!$result) {
				throw new \OCA\Files_Trashbin\Exceptions\CopyRecursiveException();
			}
		}
		return $size;
	}

	private static function move(View $view, $source, $target)
	{
		/** @var \OC\Files\Storage\Storage $sourceStorage */
		[$sourceStorage, $sourceInternalPath] = $view->resolvePath($source);
		/** @var \OC\Files\Storage\Storage $targetStorage */
		[$targetStorage, $targetInternalPath] = $view->resolvePath($target);
		/** @var \OC\Files\Storage\Storage $ownerTrashStorage */

		$result = $targetStorage->moveFromStorage($sourceStorage, $sourceInternalPath, $targetInternalPath);
		if ($result) {
			$targetStorage->getUpdater()->update($targetInternalPath);
		}
		return $result;
	}

	/**
	 * recursive copy to copy a whole directory
	 *
	 * @param View $view file view for the users root directory
	 * @param string $source source path, relative to the users files directory
	 * @param string $destination destination path relative to the users root directory
	 * @return int|float
	 * @throws Exceptions\CopyRecursiveException
	 */
	private static function copy_recursive(View $view, $source, $destination): int|float
	{
		$size = 0;
		if ($view->is_dir($source)) {
			$view->mkdir($destination);
			$view->touch($destination, $view->filemtime($source));
			foreach ($view->getDirectoryContent($source) as $i) {
				$pathDir = $source . '/' . $i['name'];
				if ($view->is_dir($pathDir)) {
					$size += self::copy_recursive($view, $pathDir, $destination . '/' . $i['name']);
				} else {
					$size += $view->filesize($pathDir);
					$result = $view->copy($pathDir, $destination . '/' . $i['name']);
					if (!$result) {
						throw new \OCA\Files_Trashbin\Exceptions\CopyRecursiveException();
					}
					$view->touch($destination . '/' . $i['name'], $view->filemtime($pathDir));
				}
			}
		} else {
			$size += $view->filesize($source);
			$result = $view->copy($source, $destination);
			if (!$result) {
				throw new \OCA\Files_Trashbin\Exceptions\CopyRecursiveException();
			}
			$view->touch($destination, $view->filemtime($source));
		}
		return $size;
	}

	/**
	 * Returns the f_account filecache item for the specified fileid or null if it is not an f_account item.
	 */
	public function getFAccountFilecacheItem(int $fileId)
	{
		$item = $this->fileCacheMapper->getItemByFileId($fileId);
		[, $accountType] = $this->getAccountUIDAndTypeFromStorageId($item[FileCacheMapper::TABLE_COLUMN_STORAGE_ID]);
		if (self::ACCOUNT_TYPE_F_ACCOUNT == $accountType) {
			return $item;
		}
		return null;
	}

	/**
	 * Returns an account array based according to:
	 * [
	 * 		TrashbinService::ACCOUNT_UID => 'uid',
	 * 		TrashbinService::ACCOUNT_TYPE => 'f_account' or 'user'
	 * ]
	 * @return array {string account_uid, string account_type} -- the account array or an empty array if the account could not be retrieved
	 */
	public function getAccountUIDAndTypeFromStorageId(string $storageId): array
	{
		$account = [];
		$fragments = \explode('::', $storageId);
		$accountUID = \count($fragments) == 2 ? $fragments[1] : null;
		if (!isset($accountUID)) {
			return [null, null];
		}
		array_push($account, $accountUID);
		if (\str_starts_with($accountUID, 'f_')) {
			array_push($account, self::ACCOUNT_TYPE_F_ACCOUNT);
		} else {
			array_push($account, self::ACCOUNT_TYPE_USER);
		}
		return $account;
	}

	/**
	 * Returns the numeric storage id for the root of the specified uid.
	 * 
	 * @var string uid
	 * @return int the numeric storage id
	 */
	private function getNumericStorageId(string $uid): int
	{
		/** @var View */
		$view = new View('/' . $uid);
		$root = $view->getRoot();
		return $view->getMount($root)->getNumericStorageId();
	}

	/**
	 * Sets up the trashbin folder if not exists yet.
	 * The trashbin filecache items will also be created.
	 * 
	 * @var string 
	 */
	private function setUpTrash(string $uid): void
	{
		$view = new View('/' . $uid);
		if (!$view->is_dir('files_trashbin')) {
			$view->mkdir('files_trashbin');
		}
		if (!$view->is_dir('files_trashbin/files')) {
			$view->mkdir('files_trashbin/files');
		}
	}

	/**
	 * Splits the specified name into name and timestamp parts and returns them.
	 * 
	 * @param string name
	 * @return array {string fileOrFoldername, int timestamp}
	 */
	public function getNameAndTimestamp(string $name): array
	{
		// the name is in format: {folder-or-filename}.d{timestamp}{/any-sub-folder-path}
		// if not ... something entirely different is wrong
		$exploded = explode(".d", $name);
		$timestamp = array_pop($exploded);
		if (!is_numeric($timestamp)) {
			return [null, null];
		}
		$fileOrFolderName = implode(".d", $exploded);
		return [$fileOrFolderName, intval($timestamp)];
	}
}
