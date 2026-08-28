<?php

namespace App\Entity\Identity;

use App\Repository\Identity\LoginAttemptRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoginAttemptRepository::class)]
#[ORM\Table(name: 'loginAttemps', schema: 'identity')]
class LoginAttempt
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
