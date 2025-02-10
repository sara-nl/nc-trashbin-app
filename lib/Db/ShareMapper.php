<?php

declare(strict_types=1);

namespace OCA\SURFTrashbin\Db;

use Exception;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use PDO;

class ShareMapper extends QBMapper
{

    private const TABLE_NAME = 'share';
    private const TABLE_COLUMN_SHARE_TYPE = 'share_type';
    private const TABLE_COLUMN_SHARE_WITH = 'share_with';
    private const TABLE_COLUMN_UID_OWNER = 'uid_owner';
    private const TABLE_COLUMN_UID_INITIATOR = 'uid_initiator';

    public function __construct(
        IDBConnection $dbConnection,
    ) {
        parent::__construct($dbConnection, self::TABLE_NAME);
    }

    /**
     * Returns the uid of the owner of the functional account project folder.
     * 
     * @param string $fAccountUID the functional account of the project folder.
     * @return string the owner uid or an empty string if not found.
     * @throws Exception if the number of owners found is unexpected.
     */
    public function getProjectOwnerUID(string $fAccountUID): string
    {
        $query = $this->db->getQueryBuilder();
        $query->select('share_with')
            ->from(self::TABLE_NAME)
            ->where($query->expr()->eq(self::TABLE_COLUMN_SHARE_TYPE, $query->createNamedParameter(0)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_UID_OWNER, $query->createNamedParameter($fAccountUID)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_UID_INITIATOR, $query->createNamedParameter($fAccountUID)));
        $statement = $query->executeQuery();
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        if (\count($result) > 1) {
            throw new Exception('Expecting one owner, found ' . \count($result));
        }

        return \count($result) == 1 ? $result[0][self::TABLE_COLUMN_SHARE_WITH] : null;
    }
}
