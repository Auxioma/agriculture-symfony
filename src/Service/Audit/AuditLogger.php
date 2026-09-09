<?php

namespace App\Service\Audit;

use App\Entity\Audit\AuditLog;
use App\Entity\Identity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Trace les actions admin sensibles (cahier fonctionnel : "les actions sensibles sont journalisées" ;
 * cahier DevOps : "journaliser les actions admin sensibles : validation producteur, blocage compte, lecture
 * d'une conversation signalée"). Ne fait qu'un persist() -- le flush() reste à la charge de l'appelant, qui
 * flush de toute façon juste après pour sauvegarder l'effet de l'action elle-même.
 */

class AuditLogger
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly RequestStack $requestStack,
    ) {
    }

    public function log(string $action, string $schemaName, string $tableName, string $recordId, ?array $oldData = null, ?array $newData = null): void
    {
        $log = new AuditLog();
        $actor = $this->security->getUser();
        $log->setActor($actor instanceof User ? $actor : null);
        $log->setAction($action);
        $log->setSchemaName($schemaName);
        $log->setTableName($tableName);
        $log->setRecordId($recordId);
        $log->setOldData($oldData);
        $log->setNewData($newData);
        $log->setIpAddress($this->requestStack->getCurrentRequest()?->getClientIp());
        $this->em->persist($log);
    }
}