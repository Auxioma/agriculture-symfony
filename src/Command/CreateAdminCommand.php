<?php

namespace App\Command;

use App\Entity\Identity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Cette commande permet d'insérer un nouvel utilisateur ayant le rôle ROLE_ADMIN
 * directement depuis le terminal. Elle vérifie l'unicité de l'adresse email
 * et se charge de hasher le mot de passe avant la mise en base de données.
 *
 * Utilisation :
 *   php bin/console app:create-admin <email> <password>
 *
 * Arguments :
 *   - email    (string, requis) : Adresse email du compte admin.
 *   - password (string, requis) : Mot de passe en clair (sera hashé automatiquement).
 *
 * Codes de retour :
 *   - Command::SUCCESS (0) : Compte administrateur créé avec succès.
 *   - Command::FAILURE (1) : L'adresse email existe déjà en base de données.
 */

#[AsCommand(name: 'app:create-admin', description: 'Crée un compte administrateur back-office')]
final class CreateAdminCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly UserPasswordHasherInterface $hasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        if ($this->em->getRepository(User::class)->findOneBy(['email' => $email]) !== null) {
            $io->error('Un compte existe déjà avec cet email.');

            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setFirstName('Admin');
        $user->setLastName('TrouveMoi');
        $user->setPasswordHash($this->hasher->hashPassword($user, $input->getArgument('password')));
        $user->setRoles([User::ROLE_ADMIN]);

        $this->em->persist($user);
        $this->em->flush();

        $io->success(sprintf('Administrateur créé : %s', $email));

        return Command::SUCCESS;
    }
}