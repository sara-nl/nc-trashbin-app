<?php

declare(strict_types=1);

namespace OCA\SURFTrashbin\Db;

use OCA\Files_Trashbin\AppInfo\Application;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use PDO;
use Psr\Log\LoggerInterface;

class TrashbinMapper extends QBMapper
{

    public const TABLE_NAME = 'files_trash';
    public const TABLE_COLUMN_ID = 'id';
    public const TABLE_COLUMN_USER = 'user';
    public const TABLE_COLUMN_TIMESTAMP = 'timestamp';
    public const TABLE_COLUMN_LOCATION = 'location';
    public const TABLE_COLUMN_DELETED_BY = 'deleted_by';

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
     * Returns the trashbin item for the specified id, user, timestamp and deletedBy parameters
     * 
     * @param string $id the id of the trashbin item to retrieve
     * @param string $user the user of the trashbin item to retrieve
     * @param string $timestamp the timestamp of the trashbin item to retrieve
     * @param string $deletedBy the user that deleted the trashbin item to retrieve
     * @return array {string id, string user, int timestamp, string location, string deletedBy} the trashbin item array or an empty array if not retrieved
     */
    public function getItem(string $id, string $user, int $timestamp, string $deletedBy)
    {
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from(self::TABLE_NAME)
            ->where($query->expr()->eq(self::TABLE_COLUMN_ID, $query->createNamedParameter($id)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_USER, $query->createNamedParameter($user)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_TIMESTAMP, $query->createNamedParameter($timestamp)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_DELETED_BY, $query->createNamedParameter($deletedBy)));
        $statement = $query->executeQuery();
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        $items = \array_map(function ($item) {
            return [
                self::TABLE_COLUMN_ID => $item[self::TABLE_COLUMN_ID],
                self::TABLE_COLUMN_USER => $item[self::TABLE_COLUMN_USER],
                self::TABLE_COLUMN_TIMESTAMP => $item[self::TABLE_COLUMN_TIMESTAMP],
                self::TABLE_COLUMN_LOCATION => $item[self::TABLE_COLUMN_LOCATION],
                self::TABLE_COLUMN_DELETED_BY => $item[self::TABLE_COLUMN_DELETED_BY],
            ];
        }, $result);

        return count($items) == 1 ? $items[0] : [];
    }

    /**
     * @param string $id the id of the trashbin item to retrieve
     * @param string $timestamp the timestamp of the trashbin item to retrieve
     * @return array an array of trashbin items as column/value arrays
     */
    public function getItems(string $id, int $timestamp)
    {
        $query = $this->db->getQueryBuilder();
        $query->select('*')
            ->from(self::TABLE_NAME)
            ->where($query->expr()->eq(self::TABLE_COLUMN_ID, $query->createNamedParameter($id)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_TIMESTAMP, $query->createNamedParameter($timestamp)));
        $statement = $query->executeQuery();
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        $items = \array_map(function ($item) {
            return [
                self::TABLE_COLUMN_ID => $item[self::TABLE_COLUMN_ID],
                self::TABLE_COLUMN_USER => $item[self::TABLE_COLUMN_USER],
                self::TABLE_COLUMN_TIMESTAMP => $item[self::TABLE_COLUMN_TIMESTAMP],
                self::TABLE_COLUMN_LOCATION => $item[self::TABLE_COLUMN_LOCATION],
                self::TABLE_COLUMN_DELETED_BY => $item[self::TABLE_COLUMN_DELETED_BY],
            ];
        }, $result);

        return $items;
    }

    /**
     * Insert a trashbin item according to the specified parameters.
     * 
     */
    public function insertItem(string $id, string $userUID, int $timestamp, string $location, string $deletedByUID): int
    {
        $query = $this->db->getQueryBuilder();
        $query->insert(self::TABLE_NAME)
            ->setValue(self::TABLE_COLUMN_ID, $query->createNamedParameter($id))
            ->setValue(self::TABLE_COLUMN_USER, $query->createNamedParameter($userUID))
            ->setValue(self::TABLE_COLUMN_TIMESTAMP, $query->createNamedParameter($timestamp))
            ->setValue(self::TABLE_COLUMN_LOCATION, $query->createNamedParameter($location))
            ->setValue(self::TABLE_COLUMN_DELETED_BY, $query->createNamedParameter($deletedByUID));
        $result = $query->executeStatement();
        if (!$result) {
            $this->logger->error('Unable to insert trashbin item.', ['app' => Application::APP_ID]);
        }
        return $result;
    }

    /**
     * Delete the trashbin items with the specified id and timestamp.
     * 
     * @var string $id
     * @var int $timestamp
     * @var string $uid
     * @return int the number of items deleted
     */
    public function deleteItems(string $id, int $timestamp, string $uid): int
    {
        $query = $this->db->getQueryBuilder();
        $query->delete(self::TABLE_NAME)
            ->where($query->expr()->eq(self::TABLE_COLUMN_ID, $query->createNamedParameter($id)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_USER, $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_TIMESTAMP, $query->createNamedParameter($timestamp)));
        $result = $query->executeStatement();

        if (!$result) {
            $this->logger->error('Unable to delete trashbin items.', ['app' => Application::APP_ID]);
        }
        return $result;
    }
}
