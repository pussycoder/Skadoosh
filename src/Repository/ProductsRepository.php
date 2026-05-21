<?php

namespace App\Repository;

use App\Entity\Products;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Products>
 */
class ProductsRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Products::class);
    }

    /**
     * @return Products[]
     */
    public function findForShop(?string $categorySlug = null, int $limit = 24, ?string $query = null): array
    {
        $builder = $this->createQueryBuilder('p')
            ->leftJoin('p.Category', 'c')
            ->addSelect('c')
            ->orderBy('p.id', 'DESC')
            ->setMaxResults($limit);

        $categoryNames = match (strtolower((string) $categorySlug)) {
            'mens', 'men' => ['mens', 'men', 'male'],
            'womens', 'women' => ['womens', 'women', 'female'],
            'accessories', 'accessory' => ['accessories', 'accessory'],
            default => [],
        };

        if ($categoryNames !== []) {
            $builder
                ->andWhere('LOWER(c.name) IN (:categoryNames)')
                ->setParameter('categoryNames', $categoryNames);
        }

        $search = trim(strtolower((string) $query));
        if ($search !== '') {
            $builder
                ->andWhere('LOWER(p.name) LIKE :search OR LOWER(p.description) LIKE :search OR LOWER(c.name) LIKE :search')
                ->setParameter('search', '%' . $search . '%');
        }

        return $builder->getQuery()->getResult();
    }

//    /**
//     * @return Products[] Returns an array of Products objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('p.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Products
//    {
//        return $this->createQueryBuilder('p')
//            ->andWhere('p.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
