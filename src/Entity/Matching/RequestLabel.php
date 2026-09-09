<?php

/**
 * Label souhaité par le client sur une ClientRequest (ex. "je veux du Bio"). Clé composite (request, label)
 * -- l'une des 10 entités à clé composite intentionnelle du projet, voir trouvemoi-agri-make-entity-guide.md.
 * $required distingue un critère obligatoire d'une simple préférence -- utile pour le calcul de score dans
 * matching.populate_request_matches(). Aucun contrôleur n'écrit encore dans cette table.
 */

namespace App\Entity\Matching;

use App\Entity\Catalog\Label;
use App\Repository\Matching\RequestLabelRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RequestLabelRepository::class)]
#[ORM\Table(name: 'request_labels', schema: 'matching')]
class RequestLabel
{
    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'labels')]
    #[ORM\JoinColumn(nullable: false)]
    private ClientRequest $request;

    #[ORM\Id]
    #[ORM\ManyToOne(inversedBy: 'requestLabels')]
    #[ORM\JoinColumn(nullable: false)]
    private Label $label;

    #[ORM\Column]
    private bool $required = false;

    public function getRequest(): ClientRequest
    {
        return $this->request;
    }

    public function setRequest(ClientRequest $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function getLabel(): Label
    {
        return $this->label;
    }

    public function setLabel(Label $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function isRequired(): bool
    {
        return $this->required;
    }

    public function setRequired(bool $required): static
    {
        $this->required = $required;

        return $this;
    }
}
