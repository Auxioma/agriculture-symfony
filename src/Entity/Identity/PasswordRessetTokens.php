<?php

namespace App\Entity\Identity;

use App\Repository\Identity\PasswordRessetTokensRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: PasswordRessetTokensRepository::class)]
class PasswordRessetTokens
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    public function getId(): ?int
    {
        return $this->id;
    }
}
