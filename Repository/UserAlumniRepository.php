<?php

namespace App\Repository;

use App\Entity\Service\UserAlumni;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\NonUniqueResultException;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UuidType;

/**
 * Class UserAlumniRepository
 * 
 * This class is responsible for handling UserAlumni entity operations.
 * 
 * @method UserAlumni|null find($id, $lockMode = null, $lockVersion = null)
 * @method UserAlumni|null findOneBy(array $criteria, array $orderBy = null)
 * @method UserAlumni[]    findAll()
 * @method UserAlumni[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class UserAlumniRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserAlumni::class);
    }

    /**
     * Saves a UserAlumni entity.
     *
     * @param UserAlumni $entity The UserAlumni entity to save.
     * @param bool $flush Whether to flush the changes to the database.
     */
    public function save(UserAlumni $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);
        $this->flushIfRequired($flush);
    }

    /**
     * Removes a UserAlumni entity.
     *
     * @param UserAlumni $entity The UserAlumni entity to remove.
     * @param bool $flush Whether to flush the changes to the database.
     */
    public function remove(UserAlumni $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);
        $this->flushIfRequired($flush);
    }

    /**
     * Finds a UserAlumni by UUID.
     *
     * @param string $uuid The UUID of the UserAlumni.
     * @return UserAlumni|null The UserAlumni entity or null if not found.
     */
    public function findUserByUuid(string $uuid): ?UserAlumni
    {
        try {
            return $this->createQueryBuilder('u')
                ->where('u.uuid = :uuid')
                ->setParameter('uuid', $uuid, UuidType::NAME)
                ->getQuery()
                ->getOneOrNullResult();
        } catch (NonUniqueResultException $e) {
            // Log the exception if necessary
            return null;
        }
    }

    /**
     * Searches for UserAlumni entities with autocomplete functionality.
     *
     * @param string $clientUuid The UUID of the client.
     * @param string|null $keyword The keyword to search for (optional).
     * @param int|null $page The page number for pagination (optional).
     * @param int|null $itemsPerPage The number of items per page (optional).
     * @return Query The query object for the search results.
     */
    public function searchByAutocomplete(string $clientUuid, ?string $keyword = '', ?int $page = 1, ?int $itemsPerPage = 10): Query
    {
        $queryBuilder = $this->createQueryBuilder('u')
            ->join('u.profiles', 'p')
            ->where('p.clientUuid = :clientUuid')
            ->andWhere('u.enabled = :enabled')
            ->setParameter('clientUuid', $clientUuid, UuidType::NAME)
            ->setParameter('enabled', true);

        if ($keyword) {
            $queryBuilder->andWhere('p.firstName LIKE :name OR p.lastName LIKE :name')
                ->setParameter('name', '%' . $keyword . '%');
        }

        // Pagination
        $this->applyPagination($queryBuilder, $page, $itemsPerPage);

        return $queryBuilder->getQuery();
    }

    /**
     * Flushes the entity manager if required.
     *
     * @param bool $flush Whether to flush the changes to the database.
     * @return void
     */
    private function flushIfRequired(bool $flush): void
    {
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Applies pagination to the query builder.
     *
     * @param QueryBuilder $queryBuilder The query builder to apply pagination to.
     * @param int $page The page number for pagination.
     * @param int $itemsPerPage The number of items per page.
     * @return void
     */
    private function applyPagination($queryBuilder, int $page, int $itemsPerPage): void
    {
        $queryBuilder->setFirstResult(($page - 1) * $itemsPerPage)
            ->setMaxResults($itemsPerPage);
    }
}
