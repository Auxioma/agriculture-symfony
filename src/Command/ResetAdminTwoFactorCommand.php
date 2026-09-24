<?php

namespace App\Command;

use App\Entity\Identity\User;
use App\Service\Audit\AuditLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Filet de sécurité de la 2FA obligatoire (cahier DevOps, "2FA obligatoire admin") : un admin qui a perdu à la
 * fois son application d'authentification et ses codes de secours ne peut plus se connecter, et l'écran
 * d'activation n'offre volontairement aucun moyen de désactiver la 2FA soi-même (une session volée suffirait
 * alors à la retirer). Cette commande, réservée à qui a un accès serveur, efface le secret TOTP et les codes de
 * secours du compte : à sa prochaine connexion, RequireAdminTwoFactorListener l'oblige à réactiver sa 2FA.
 *
 * Utilisation :
 *   php bin/console app:reset-admin-2fa <email>
 *
 * Codes de retour :
 *   - Command::SUCCESS (0) : 2FA réinitialisée, ou déjà inactive sur ce compte.
 *   - Command::FAILURE (1) : aucun compte avec cet email.
 */
#[AsCommand(name: 'app:reset-admin-2fa', description: "Réinitialise la 2FA d'un compte back-office verrouillé")]
final class ResetAdminTwoFactorCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AuditLogger $auditLogger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email du compte à réinitialiser');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');

        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user instanceof User) {
            $io->error('Aucun compte avec cet email.');

            return Command::FAILURE;
        }

        if (!$user->isTotpAuthenticationEnabled()) {
            $io->warning(sprintf('La 2FA n\'est pas activée sur %s : rien à réinitialiser.', $email));

            return Command::SUCCESS;
        }

        $user->disableTwoFactor();
        // * Pas de session HTTP ici : AuditLogger ne peut pas identifier d'acteur, la méthode le dit explicitement.
        $this->auditLogger->log('admin_2fa_reset', 'identity', 'users', $user->getId()->toRfc4122(), ['totp_enabled' => true], ['totp_enabled' => false, 'method' => 'console']);
        $this->em->flush();

        $io->success(sprintf('2FA réinitialisée sur %s : le compte devra la réactiver à sa prochaine connexion.', $email));

        return Command::SUCCESS;
    }
}
