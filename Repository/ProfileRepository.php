<?php

namespace App\Repository\Service;

use Alumni\MessengerBundle\Message\Command\Notification\FindDigestUsersCommand;
use Alumni\MessengerBundle\Message\Command\Notification\SendBatchNotificationCommand;
use App\Entity\Service\Profile;
use App\Service\GeolocationService;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * @method Profile|null find($id, $lockMode = null, $lockVersion = null)
 * @method Profile|null findOneBy(array $criteria, array $orderBy = null)
 * @method Profile[]    findAll()
 * @method Profile[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProfileRepository extends ServiceEntityRepository
{

    public function __construct(ManagerRegistry $registry, private readonly GeolocationService $geolocationService)
    {
        parent::__construct($registry, Profile::class);
    }

    public function save(Profile $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Profile $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findProfileByUuid(string $uuid): ?Profile
    {
        try {
            return $this->createQueryBuilder('p')
                ->where('p.uuid = :uuid')
                ->setParameter('uuid', $uuid, UuidType::NAME)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (NonUniqueResultException) {
            return null;
        }
    }

    public function getProfileByUserUuidAndClientUuid(string $userUuid, string $clientUuid): ?Profile
    {
        try {
            return $this->createQueryBuilder('p')
                ->innerJoin('p.userAlumni', 'ua')
                ->where('p.clientUuid = :clientUuid')
                ->andWhere('ua.uuid = :userUuid')
                ->setParameter('clientUuid', $clientUuid, UuidType::NAME)
                ->setParameter('userUuid', $userUuid, UuidType::NAME)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (NonUniqueResultException) {
            return null;
        }
    }
}
