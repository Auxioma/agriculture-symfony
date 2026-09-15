<?php

namespace App\Dto\Producer;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Payload attendu par POST /api/producer/team, désérialisé et validé automatiquement par
 * #[MapRequestPayload] dans ProducerTeamController::inviteMember(). $email doit correspondre à un
 * compte existant -- cette version restreinte de l'équipe multi-utilisateurs (cahier fonctionnel,
 * "Équipe exploitation" V2/Premium) ne crée pas de compte à la volée, voir le docblock de
 * ProducerTeamController pour le détail du périmètre.
 */

final readonly class InviteTeamMemberRequest
{
    public function __construct(
        #[Assert\NotBlank]
        #[Assert\Email]
        public string $email,

        #[Assert\Length(max: 120)]
        public ?string $role = null,
    ) {
    }
}
