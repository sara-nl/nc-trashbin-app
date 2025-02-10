<?php

/**
 * This class implements the logic and mechanisms for the SURF trashbin app.
 * 
 * The sharing scheme is f_account -> project-owner -> user
 * (the functional account shares a folder with the project owner, who shares the folder with the users)
 * 
 * When a file/folder is deleted by a user:
 *  . the project owner gets a copy of the deleted node in it's trashbin as well, so the owner can restore it
 * 
 * When a file/folder is restored by a user:
 *  . the trashbin of the project owner will be emptied as well
 * 
 * When a file/folder is restored by the project owner:
 *  . the trashbin of user who deleted the file will be emptied as well
 * 
 * After restoring the trashbin/filecache of the f_account is cleaned up as well (which not happen automatically)
 * 
 * It does not matter whether project owner or user has quota or not, the effect is the same.
 * 
 */

namespace OCA\SURFTrashbin\Service;

use Exception;
use OC\Files\View;
use OC_Helper;
use OCA\Files_Trashbin\AppInfo\Application;
use OCA\SURFTrashbin\Db\FileCacheMapper;
use OCA\SURFTrashbin\Db\ShareMapper;
use OCA\SURFTrashbin\Db\TrashbinMapper;
use OCP\Files\Node;
use OCP\IUser;
use OCP\IUserManager;
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

	/** @var IUserManager */
	private $userManager;

	/** @var LoggerInterface */
	private $logger;

	public const ACCOUNT_TYPE_F_ACCOUNT = 'f_account';
	public const ACCOUNT_TYPE_USER = 'user';

	public function __construct(
		FileCacheMapper $fileCacheMapper,
		TrashbinMapper $trashbinMapper,
		ShareMapper $shareMapper,
		IUserSession $userSession,
		IUserManager $userManager,
		LoggerInterface $logger
	) {
		$this->fileCacheMapper = $fileCacheMapper;
		$this->trashbinMapper = $trashbinMapper;
		$this->shareMapper = $shareMapper;
		$this->userSession = $userSession;
		$this->userManager = $userManager;
		$this->logger = $logger;
	}

	/**
	 * This method makes sure that in case it is a project user who deletes the files
	 * these files appear in the trashbin of the project owner as well.
	 * 
	 * @var Node the deleted node
	 * @return void
	 */
	public function handleDeleteNode(Node $nodeDeleted): void
	{
		$fAccountFilecacheItem = $this->getFAccountFilecacheItem($nodeDeleted->getId());
		if (!isset($fAccountFilecacheItem)) {
			// we are not dealing with a node from a shared f_account folder 
			return;
		}

		$userAccountFilecacheItem = $this->fileCacheMapper->getUserforFAccountItem($fAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_FILEID]);
		if (\count($userAccountFilecacheItem) == 0) {
			// we are not dealing with a node deleted by an f_account project user 
			return;
		}

		// find the corresponding trashbin entry of the deleted node
		[$name, $timestamp] = $this->getNameAndTimestamp($userAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_NAME]);
		$sessionUID = $this->userSession->getUser()->getUID();
		[$fAccountUID,] = $this->getAccountUIDAndTypeFromStorageId($fAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_STORAGE_ID]);
		$projectOwnerUID = $this->shareMapper->getProjectOwnerUID($fAccountUID);
		[
			$trashbinItemId,,
			$trashbinItemTimestamp,
			$trashbinItemLocation,
			$trashbinItemDeletedBy
		] = array_values($this->trashbinMapper->getItem($name, $fAccountUID, $timestamp, $sessionUID));

		$sessionUserQuota = self::getUserQuota($this->userSession->getUser());
		$copyResult = false;
		if ($sessionUserQuota == 0) {
			// create the user's trashbin item (it's not created because the user has no quota)

			$this->setUpTrash($sessionUID);

			$this->trashbinMapper->insertItem(
				$trashbinItemId,
				$sessionUID,
				$trashbinItemTimestamp,
				$trashbinItemLocation,
				$trashbinItemDeletedBy,
			);
			// and copy the original trashbin file to session user trashbin
			$copyResult = $this->copyNode($fAccountUID, $sessionUID, $userAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_NAME]);
		}
		if ($sessionUID != $projectOwnerUID) {
			// create project owner's trashbin item (it's not created because it's a project user that deleted the node)

			$this->setUpTrash($projectOwnerUID);

			$this->trashbinMapper->insertItem(
				$trashbinItemId,
				$projectOwnerUID,
				$trashbinItemTimestamp,
				$trashbinItemLocation,
				$trashbinItemDeletedBy,
			);
			// and copy the original trashbin file to project owner's trashbin
			$copyResult = $this->copyNode($fAccountUID, $projectOwnerUID, $userAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_NAME]);
		}
		if (!$copyResult) {
			$this->logger->error(" handleDeleteNode copy error - node copy from f_account trashbin did not complete.", ['app' => Application::APP_ID]);
			// TODO ?? throw exception if something went wrong (copyResult is false) ??
		}
	}

	/**
	 * Cleans up any remaining trashbin and filecache table items and storage.
	 * 
	 * @param Node $sourceNode the node being restored
	 */
	public function handleRestoreNode(Node $sourceNode): void
	{
		$name = $sourceNode->getName(); // format: {folder-or-filename}.d{timestamp}{/any-sub-folder-path}

		[$fileOrFolderName, $timestamp] = $this->getNameAndTimestamp($name);
		if (isset($fileOrFolderName) && !isset($timestamp)) {
			// this must be a sub node restored; follow normal behaviour and just return
			return;
		}

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

		$fAccountUID = $fAccountTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER];
		$projectOwnerUID = isset($ownerTrashbinItem) ? $ownerTrashbinItem[TrashbinMapper::TABLE_COLUMN_USER] : null;

		// unlink all filecache items
		$fAccountView = new View("/$fAccountUID");
		$fAccountView->unlink($fAccountFilecacheItem[FileCacheMapper::TABLE_COLUMN_PATH]);
		if (isset($ownerFilecacheItem)) {
			$ownerView = new View("/$projectOwnerUID");
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

	private function copyNode(string $fromUID, string $toUID, string $nodeName): bool
	{
		$view = new View("/");
		$source = "$fromUID/files_trashbin/files/$nodeName";
		$destination = "$toUID/files_trashbin/files/$nodeName";

		if (!$view->file_exists($source)) {
			throw new Exception("Node '$source' does not exist for user '$fromUID'.");
		}

		$fullSourcePath = $this->userManager->get($fromUID)->getHome() . "/files_trashbin/files/$nodeName";
		$fullDestinationPath = $this->userManager->get($toUID)->getHome() . "/files_trashbin/files/$nodeName";

		$copyResult = $this->copyNodeRecursive($view, $source, $destination, $fullSourcePath, $fullDestinationPath);
		if (!$copyResult) {
			$this->logger->error("copyNode error - could not copy '$nodeName' between $fromUID and $toUID trashbins");
		}
		return $copyResult;
	}

	/**
	 * 
	 * @param View $sourceView the source view
	 * @param string $sourceHome the full source home pathh
	 * @param string $sourcePath the path relative to source home
	 * @param string $destination full destination path
	 */
	private function copyNodeRecursive(View $view, string $source, string $destination, string $fullSourcePath, string $fullDestinationPath): bool
	{
		/** @var bool $result */
		$result = false;
		if ($view->is_dir($source)) {
			$result = $view->mkdir($destination);
			if (!$result) {
				$this->logger->error("copyNodeRecursive error: Unable to mkdir '$destination'", ['app' => Application::APP_ID]);
				return $result;
			}
			foreach ($view->getDirectoryContent($source) as $i) {
				$path = $source . '/' . $i['name'];
				$target = $destination . '/' . $i['name'];
				$_fullSourcePath = $fullSourcePath . '/' . $i['name'];
				$_fullDestinationPath = $fullDestinationPath . '/' . $i['name'];
				$result = $this->copyNodeRecursive($view, $path, $target, $_fullSourcePath, $_fullDestinationPath);
				if (!$result) {
					break;
				}
			}
		} else {
			// In case the destination user has no quota we must do a low level copy for which we use the full paths
			$result = @copy($fullSourcePath, $fullDestinationPath);
			// And update the cache which will create the cache item for this file
			[$targetStorage, $targetInternalPath] = $view->resolvePath($destination);
			$targetStorage->getUpdater()->update($targetInternalPath);

			if (!$result) {
				$this->logger->error("copyNodeRecursive error: Unable to copy '$fullSourcePath' to '$fullDestinationPath'", ['app' => Application::APP_ID]);
				return $result;
			}
		}
		return $result;
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
	 * If no timestamp postfix is found then only the name is returned, ie. return [$fileOrFoldername, null]
	 * 
	 * @param string name
	 * @return array {string fileOrFoldername, int timestamp}
	 */
	public function getNameAndTimestamp(string $name): array
	{
		// the name is in format: {file-or-foldername}.d{timestamp}{/any-sub-node-path}
		// if not ... something entirely different is wrong
		$exploded = explode(".d", $name);
		$timestamp = array_pop($exploded);
		if (!is_numeric($timestamp)) {
			return [$name, null];
		}
		$fileOrFolderName = implode(".d", $exploded);
		return [$fileOrFolderName, intval($timestamp)];
	}

	/**
	 * Get the quota of a user
	 *
	 * @param IUser|null $user
	 * @return int|\OCP\Files\FileInfo::SPACE_UNLIMITED|false|float Quota bytes
	 */
	private static function getUserQuota(?IUser $user)
	{
		if (is_null($user)) {
			return \OCP\Files\FileInfo::SPACE_UNLIMITED;
		}
		$userQuota = $user->getQuota();
		if ($userQuota === 'none') {
			return \OCP\Files\FileInfo::SPACE_UNLIMITED;
		}
		return OC_Helper::computerFileSize($userQuota);
	}
}
