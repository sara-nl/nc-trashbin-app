<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: Antoon Prins <antoon.prins@surf.nl>
// SPDX-License-Identifier: AGPL-3.0-or-later


namespace OCA\SURFTrashbin\Db;

use OC\User\User;
use OCA\SURFTrashbin\AppInfo\Application;
use OCP\AppFramework\Db\QBMapper;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class FunctionalAccountMapper extends QBMapper
{
    private const TABLE_NAME = 'share';

    /** @var IUserManager */
    private $userManager;

    /** @var LoggerInterface */
    private LoggerInterface $logger;

    public function __construct(
        IDBConnection $dbConnection,
        IUserManager $userManager,
        LoggerInterface $logger
    ) {
        parent::__construct($dbConnection, self::TABLE_NAME);
        $this->userManager = $userManager;
        $this->logger = $logger;
    }

    public function getGroupsUserOwns(User $user)
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME, 's')
            ->where($qb->expr()->eq('s.share_type', $qb->createNamedParameter(0, $qb::PARAM_INT)))
            ->andWhere($qb->expr()->eq('s.share_with', $qb->createNamedParameter($user->getUID())))
            ->andWhere($qb->expr()->iLike('s.uid_owner', $qb->createNamedParameter('f_%')))
            ->andWhere($qb->expr()->eq('s.uid_initiator', 's.uid_owner'));
        $stmt = $qb->executeQuery();
        $shares = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        $this->logger->debug(' - shares fetchAll: ' . print_r($shares, true));

        $groups = \array_map(function ($share) {
            return [
                'gid' => \substr($share['uid_owner'], 2),
                'name' => $this->userManager->get($share['uid_owner'])->getDisplayName()
            ];
        }, $shares);

        $this->logger->debug(' - GROUPS/TRASHBINS: ' . print_r($groups, true), ['app', Application::APP_ID]);

        return $groups;
    }

    public function isUserOwnerOfGroup(string $uid, string $gid)
    {
        $fuid = 'f_' . $gid;

        if (!$this->userManager->userExists($fuid)) {
            return false;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from(self::TABLE_NAME, 's')
            ->where($qb->expr()->eq('s.share_with', $qb->createNamedParameter($uid)))
            ->andWhere($qb->expr()->eq('s.uid_owner', $qb->createNamedParameter($$fuid)))
            ->andWhere($qb->expr()->eq('s.share_type', $qb->createNamedParameter(0, $qb::PARAM_INT)))
            ->andWhere($qb->expr()->eq('s.uid_initiator', 's.uid_owner'));
        return $this->findEntity($qb);
    }
}
