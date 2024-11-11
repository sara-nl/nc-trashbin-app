<?php

declare(strict_types=1);

namespace OCA\SURFTrashbin\Db;

use Exception;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use PDO;
use Psr\Log\LoggerInterface;

class ShareMapper extends QBMapper
{

    private const TABLE_NAME = 'share';
    private const TABLE_COLUMN_SHARE_TYPE = 'share_type';
    private const TABLE_COLUMN_SHARE_WITH = 'share_with';
    private const TABLE_COLUMN_UID_OWNER = 'uid_owner';
    private const TABLE_COLUMN_UID_INITIATOR = 'uid_initiator';

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
     * Returns the uid of the owner of the functional account group folder.
     * 
     * @param string $fAccountUID the functional account of the group folder.
     * @return string the owner uid or an empty string if not found.
     * @throws Exception if the number of owners found is unexpected.
     */
    public function getGroupOwnerUID(string $fAccountUID): string
    {
        $query = $this->db->getQueryBuilder();
        $query->select('share_with')
            ->from(self::TABLE_NAME)
            ->where($query->expr()->eq(self::TABLE_COLUMN_SHARE_TYPE, $query->createNamedParameter(0)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_UID_OWNER, $query->createNamedParameter($fAccountUID)))
            ->andWhere($query->expr()->eq(self::TABLE_COLUMN_UID_INITIATOR, $query->createNamedParameter($fAccountUID)));
        $this->logger->debug(" SQL: " . print_r($query->getSQL(), true));
        $statement = $query->executeQuery();
        $result = $statement->fetchAll(PDO::FETCH_ASSOC);
        $statement->closeCursor();

        if (\count($result) > 1) {
            throw new Exception('Expecting one owner, found ' . \count($result));
        }

        return \count($result) == 1 ? $result[0][self::TABLE_COLUMN_SHARE_WITH] : null;
    }
}
