<?php

/**
 * Repository Doctrine pour SupportReplyTemplate -- aucune requête personnalisée, hérite uniquement
 * des méthodes CRUD standard de ServiceEntityRepository (find/findBy/findOneBy/findAll).
 */

namespace App\Repository\Support;

use App\Entity\Support\SupportReplyTemplate;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupportReplyTemplate>
 */
class SupportReplyTemplateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportReplyTemplate::class);
    }
}
