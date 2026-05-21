<?php

namespace App\Repository;

use App\Entity\Orders;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class OrdersRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Orders::class);
    }

    /**
     * @return Orders[]
     */
    public function findForCustomer(User $user): array
    {
        $emails = array_values(array_filter([$user->getEmail(), $user->getUsername()]));

        $builder = $this->createQueryBuilder('o')
            ->leftJoin('o.processedBy', 'u')
            ->addSelect('u')
            ->where('o.processedBy = :user')
            ->setParameter('user', $user)
            ->orderBy('o.createdAt', 'DESC');

        if ($emails !== []) {
            $builder
                ->orWhere('o.customerEmail IN (:emails)')
                ->setParameter('emails', $emails);
        }

        return $builder->getQuery()->getResult();
    }
}