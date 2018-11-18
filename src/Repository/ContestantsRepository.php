<?php

namespace App\Repository;

use App\Entity\Contestant;
use App\Enum\AgeCategoryEnum;
use App\Enum\WeightCategoryEnum;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Symfony\Bridge\Doctrine\RegistryInterface;

/**
 * @method Contestant|null find($id, $lockMode = null, $lockVersion = null)
 * @method Contestant|null findOneBy(array $criteria, array $orderBy = null)
 * @method Contestant[]    findAll()
 * @method Contestant[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContestantsRepository extends ServiceEntityRepository
{
    public function __construct(RegistryInterface $registry)
    {
        parent::__construct($registry, Contestant::class);
    }

    /**
     * @param \DateTimeInterface $after
     * @param \DateTimeInterface|null $before
     * @return Contestant[] Returns an array of ChangeSet objects
     */
    public function findByDate(\DateTimeInterface $after, \DateTimeInterface $before = null): array
    {
        if ($before === null) {
            $before = new \DateTime();
        }
        return $this->createQueryBuilder('c')
            ->andWhere('c.timestamp BETWEEN :from AND :to')
            ->setParameter('from', $after)
            ->setParameter('to', $before)
            ->orderBy('c.timestamp', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findAllById($array): array
    {
        $query = $this->createQueryBuilder('c');

        foreach ($array as $id) {
            $query->orWhere('c.id = ' . $id);
        }

        return $query->getQuery()
            ->getResult();
    }

    public function countCategory(string $age = null, string $weight = null): int
    {
        $query = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)');
        if ($weight) {
            $query->where('c.weightCategory = :weight')
                ->setParameter('weight', $weight);
        } else {
            $query->where('c.weightCategory != :weight')
                ->setParameter('weight', WeightCategoryEnum::camp_only);
        }
        if ($age) {
            $query->andWhere('c.ageCategory = :age')
                ->setParameter('age', $age);
        }

        return $query->getQuery()
            ->getSingleScalarResult();
    }

    public function countCamp(string $age = null): int
    {
        $query = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.itc != :itc')
            ->setParameter('itc', 'no');
        if ($age) {
            $query->andWhere('c.ageCategory = :age')
                ->setParameter('age', $age);
        }

        return $query->getQuery()
            ->getSingleScalarResult();
    }

//     * @return Contestant[] Returns an array of Contestant objects
//     */
    /*
    public function findByExampleField($value)
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.exampleField = :val')
            ->setParameter('val', $value)
            ->orderBy('o.id', 'ASC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult()
        ;
    }
    */

    /*
    public function findOneBySomeField($value): ?Contestant
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.exampleField = :val')
            ->setParameter('val', $value)
            ->getQuery()
            ->getOneOrNullResult()
        ;
    }
    */
}
