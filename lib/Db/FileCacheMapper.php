<?php

declare(strict_types=1);

namespace OCA\SURFTrashbin\Db;

use Exception;
use OCA\Files_Trashbin\AppInfo\Application;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use PDO;
use Psr\Log\LoggerInterface;

class FileCacheMapper extends QBMapper
{

    public const TABLE_NAME = 'filecache';
    public const TABLE_COLUMN_FILEID = 'fileid';
    public const TABLE_COLUMN_STORAGE = 'storage';
    public const TABLE_COLUMN_PATH = 'path';
    public const TABLE_COLUMN_PATH_HASH = 'path_hash';
    public const TABLE_COLUMN_PARENT = 'parent';
    public const TABLE_COLUMN_NAME = 'name';
    public const TABLE_COLUMN_MIMETYPE = 'mimetype';
    public const TABLE_COLUMN_MIMEPART = 'mimepart';
    public const TABLE_COLUMN_SIZE = 'size';
    public const TABLE_COLUMN_MTIME = 'mtime';
    public const TABLE_COLUMN_STORAGE_MTIME = 'storage_mtime';
    public const TABLE_COLUMN_ENCRYPTED = 'encrypted';
    public const TABLE_COLUMN_UNENCRYPTED_SIZE = 'unencrypted_size';
    public const TABLE_COLUMN_ETAG = 'etag';
    public const TABLE_COLUMN_PERMISSIONS = 'permissions';
    public const TABLE_COLUMN_CHECKSUM = 'checksum';

    public const TABLE_COLUMN_STORAGE_ID = 'storage_id';

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $dbConnection,
        LoggerInterface $logger
    ) {
        parent::__construct($dbConnection, self::TABLE_NAME);
        $this->logger = $logger;
    }

    /**
     * @param int $fileid the fileid of the filecache item to retrieve
     * @return array the filecache item as column/value array or an empty array if not retrieved
     */
    public function getItemByFileId(int $fileid): array
    {
        try {
            $statement = $this->db->executeQuery(
                <<<'SQL'
                select distinct fc1.*, s.id as storage_id
                from oc_filecache fc1 
                 join oc_storages s
                 on s.numeric_id = fc1.storage
                where fc1.fileid=?
                SQL,
                [$fileid]
            );
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();

            $items = \array_map(function ($item) {
                return $this->result2Item($item);
            }, $result);

            return count($items) == 1 ? $items[0] : [];
        } catch (Exception $e) {
            $this->logger->error($e->getTraceAsString(), ['app' => Application::APP_ID]);
        }
        return [];
    }

    /**
     * Returns the filecache items for the specified node path and name.
     * 
     * @param string $path
     * @param string $name
     * @return array the filecache items
     */
    public function getItems(string $path, string $name): array
    {
        try {
            $statement = $this->db->executeQuery(
                <<<'SQL'
                select distinct fc1.*, s.id as storage_id
                from oc_filecache fc1 
                 join oc_storages s
                 on s.numeric_id = fc1.storage
                where fc1.path=? and fc1.name=?
                SQL,
                [$path, $name]
            );
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();

            $items = \array_map(function ($item) {
                return $this->result2Item($item);
            }, $result);

            return count($items) == 0 ? [] : $items;
        } catch (Exception $e) {
            $this->logger->error($e->getTraceAsString(), ['app' => Application::APP_ID]);
        }
        return [];
    }

    /**
     * Returns the item of the user who trigger the action that lead to the existence of the f_account item with the specifief fileid.
     * Use case: user deletes a node of an f_account (group account). This will result in 2 filecache entries. 
     * One being the user filecache item, the other being the f_account filecache item for the deleted node.
     * This method returns the user's filecache item.
     * @param int $fAccountFileid the fileid of the f_account filecache item
     * @return array the user's filecache item
     * @throws Exception if the number of user and functional account filecache items are not what was expected
     */
    public function getUserforFAccountItem(int $fAccountFileid): array
    {
        try {
            $statement = $this->db->executeQuery(
                <<<'SQL'
                select distinct fc1.*,s.id as storage_id
                from *PREFIX*filecache fc1 
                 inner join oc_filecache fc2
                  on fc2.path_hash = fc1.path_hash
                 join *PREFIX*storages s
                 on s.numeric_id = fc1.storage
                where fc2.fileid=?
                SQL,
                [$fAccountFileid]
            );
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();

            $items = \array_map(function ($item) {
                return $this->result2Item($item);
            }, $result);

            if (count($items) > 2) {
                throw new Exception('Expected no more than 2 filecache items, retrieved ' . count($items));
            }

            // return the user item
            if (count($items) == 2) {
                foreach ($items as $item) {
                    if ($item[self::TABLE_COLUMN_FILEID] != $fAccountFileid) {
                        return $item;
                    }
                }
            }
            return [];
        } catch (Exception $e) {
            $this->logger->error($e->getTraceAsString(), ['app' => Application::APP_ID]);
        }
        return [];
    }

    /**
     * Insert the specified filecache item.
     * 
     * @param filecache item as associative array
     * @return the number of affected rows
     */
    public function insertItem(array $filecacheItem): int
    {
        try {
            $query = $this->db->getQueryBuilder();
            $query->insert(self::TABLE_NAME)
                ->setValue(self::TABLE_COLUMN_STORAGE, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_STORAGE]))
                ->setValue(self::TABLE_COLUMN_PATH, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_PATH]))
                ->setValue(self::TABLE_COLUMN_PATH_HASH, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_PATH_HASH]))
                ->setValue(self::TABLE_COLUMN_PARENT, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_PARENT]))
                ->setValue(self::TABLE_COLUMN_NAME, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_NAME]))
                ->setValue(self::TABLE_COLUMN_MIMETYPE, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_MIMETYPE]))
                ->setValue(self::TABLE_COLUMN_MIMEPART, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_MIMEPART]))
                ->setValue(self::TABLE_COLUMN_SIZE, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_SIZE]))
                ->setValue(self::TABLE_COLUMN_MTIME, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_MTIME]))
                ->setValue(self::TABLE_COLUMN_STORAGE_MTIME, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_STORAGE_MTIME]))
                ->setValue(self::TABLE_COLUMN_ENCRYPTED, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_ENCRYPTED]))
                ->setValue(self::TABLE_COLUMN_UNENCRYPTED_SIZE, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_UNENCRYPTED_SIZE]))
                ->setValue(self::TABLE_COLUMN_ETAG, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_ETAG]))
                ->setValue(self::TABLE_COLUMN_PERMISSIONS, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_PERMISSIONS]))
                ->setValue(self::TABLE_COLUMN_CHECKSUM, $query->createNamedParameter($filecacheItem[self::TABLE_COLUMN_CHECKSUM]));
            $result = $query->executeStatement();
            if (!$result) {
                $this->logger->error('Unable to insert filecache item.', ['app' => Application::APP_ID]);
            }
            return $result;
        } catch (Exception $e) {
            $this->logger->error($e->getTraceAsString(), ['app' => Application::APP_ID]);
        }
        return 0;
    }

    /**
     * Returns the 'root' (files_trashbin/files) filecache item for the specified storage.
     * 
     * @var int the numeric storage id
     * @return array {string fileid, int storage, string path, string path_hash, int parent, string name, int mimetype, int mimepart, int size, int mtime, int storage_mtime, int encrypted, int unencrypted_size, string etag, int permissions, string checksum}
     * -- the filecache item
     */
    public function getTrashbinRootItem(string $storage): array
    {
        try {
            $query = $this->db->getQueryBuilder();
            $query->select('*')
                ->from(self::TABLE_NAME)
                ->where($query->expr()->eq(self::TABLE_COLUMN_PATH, $query->createNamedParameter('files_trashbin/files')))
                ->andWhere($query->expr()->eq(self::TABLE_COLUMN_STORAGE, $query->createNamedParameter($storage)));
            $statement = $query->executeQuery();
            $result = $statement->fetchAll(PDO::FETCH_ASSOC);
            $statement->closeCursor();

            $items = \array_map(function ($item) {
                return $this->result2Item($item);
            }, $result);

            return count($items) == 1 ? $items[0] : [];
        } catch (Exception $e) {
            $this->logger->error($e->getTraceAsString(), ['app' => Application::APP_ID]);
        }
        return [];
    }

    private function result2Item(array $result): array
    {
        $item =  [
            self::TABLE_COLUMN_FILEID => $result[self::TABLE_COLUMN_FILEID],
            self::TABLE_COLUMN_STORAGE => $result[self::TABLE_COLUMN_STORAGE],
            self::TABLE_COLUMN_PATH => $result[self::TABLE_COLUMN_PATH],
            self::TABLE_COLUMN_PATH_HASH => $result[self::TABLE_COLUMN_PATH_HASH],
            self::TABLE_COLUMN_PARENT => $result[self::TABLE_COLUMN_PARENT],
            self::TABLE_COLUMN_NAME => $result[self::TABLE_COLUMN_NAME],
            self::TABLE_COLUMN_MIMETYPE => $result[self::TABLE_COLUMN_MIMETYPE],
            self::TABLE_COLUMN_MIMEPART => $result[self::TABLE_COLUMN_MIMEPART],
            self::TABLE_COLUMN_SIZE => $result[self::TABLE_COLUMN_SIZE],
            self::TABLE_COLUMN_MTIME => $result[self::TABLE_COLUMN_MTIME],
            self::TABLE_COLUMN_STORAGE_MTIME => $result[self::TABLE_COLUMN_STORAGE_MTIME],
            self::TABLE_COLUMN_ENCRYPTED => $result[self::TABLE_COLUMN_ENCRYPTED],
            self::TABLE_COLUMN_UNENCRYPTED_SIZE => $result[self::TABLE_COLUMN_UNENCRYPTED_SIZE],
            self::TABLE_COLUMN_ETAG => $result[self::TABLE_COLUMN_ETAG],
            self::TABLE_COLUMN_PERMISSIONS => $result[self::TABLE_COLUMN_PERMISSIONS],
            self::TABLE_COLUMN_CHECKSUM => $result[self::TABLE_COLUMN_CHECKSUM],
        ];
        if (isset($result[self::TABLE_COLUMN_STORAGE_ID])) {
            $item[self::TABLE_COLUMN_STORAGE_ID] = $result[self::TABLE_COLUMN_STORAGE_ID];
        }
        return $item;
    }
}
