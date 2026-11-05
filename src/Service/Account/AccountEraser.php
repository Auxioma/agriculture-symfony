<?php

namespace App\Service\Account;

use App\Entity\Billing\Subscription;
use App\Entity\Identity\DataRequest;
use App\Entity\Identity\User;
use App\Entity\Producer\ProducerProfile;
use App\Enum\SubscriptionStatus;
use App\Service\Audit\AuditLogger;
use App\Service\Payment\PaymentGatewayInterface;
use App\Service\Payment\StripeFailureLogger;
use App\Service\Producer\ProducerMediaKey;
use App\Service\Producer\ProductPhotoStorage;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ExceptionInterface as StripeException;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Suppression d'un compte (cahier fonctionnel : « suppression ou anonymisation du compte »). On anonymise, on n'efface pas :
 * le MLD veut préserver les obligations comptables et les statistiques (factures, paiements, avis, demandes et réponses
 * restent, sans nom). Ce qui identifie la personne ou l'exploitation part :
 *  - le compte, par identity.anonymize_user() (MLD), plus les secrets de double authentification, les adresses et les sessions ;
 *  - la fiche publique : désactivée, et son nom, ses textes, sa ville, sa position et son lien sont vidés ;
 *  - les fichiers : photos de l'exploitation et des produits, justificatifs de vérification ;
 *  - ses labels, zones, horaires, réponses rapides, cartes enregistrées et l'équipe invitée ; ses produits sont désactivés ;
 *  - chez Stripe, le client (e-mail, nom, cartes) ; ses factures restent.
 * Les conversations et les réponses envoyées restent : le cahier garde l'historique en cas de litige.
 *
 * L'abonnement est résilié avant, par l'appelant (AuthController::deleteMyAccount) : si Stripe échoue, rien n'est supprimé.
 */
final class AccountEraser
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PaymentGatewayInterface $paymentGateway,
        private readonly StripeFailureLogger $stripeFailures,
        private readonly AuditLogger $audit,
        private readonly ProductPhotoStorage $productPhotos,
        private readonly LoggerInterface $logger,
        #[Autowire(service: 'producer_media.storage')] private readonly FilesystemOperator $mediaStorage,
        #[Autowire(service: 'verification_documents.storage')] private readonly FilesystemOperator $documentStorage,
    ) {
    }

    // * l'abonnement qui donne encore des droits (en cours, d'essai ou en retard de paiement), celui que la suppression résilie
    public function subscriptionInForce(User $user): ?Subscription
    {
        $producer = $user->getProducerProfile();
        if ($producer === null) {
            return null;
        }

        return $this->em->getRepository(Subscription::class)->findOneBy(
            ['producer' => $producer, 'status' => [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue]],
            ['createdAt' => 'DESC']
        );
    }

    /**
     * @param Subscription|null $cancelled abonnement déjà résilié chez Stripe par l'appelant : on le passe à « cancelled » ici
     */
    public function erase(User $user, ?Subscription $cancelled): void
    {
        $userId = $user->getId()->toRfc4122();
        $producer = $user->getProducerProfile();
        $providerSubscriptionId = $cancelled?->getProviderSubscriptionId();

        $mediaKeys = [];
        $documentKeys = [];

        $this->em->wrapInTransaction(function () use ($user, $userId, $producer, $cancelled, &$mediaKeys, &$documentKeys): void {
            if ($producer !== null) {
                [$mediaKeys, $documentKeys] = $this->erasedProducer($producer);
            }
            $cancelled?->setStatus(SubscriptionStatus::Cancelled);
            foreach ($user->getTeamMemberships() as $membership) {
                $this->em->remove($membership);
            }
            $this->em->flush();

            $connection = $this->em->getConnection();
            $connection->executeStatement('SELECT identity.anonymize_user(:id)', ['id' => $userId]);
            $connection->executeStatement('UPDATE identity.users SET totp_secret = NULL, backup_codes = NULL WHERE id = :id', ['id' => $userId]);
            $connection->executeStatement('DELETE FROM identity.user_addresses WHERE user_id = :id', ['id' => $userId]);
            // * le jeton d'accès (JWT) tombe tout seul : il porte l'ancien e-mail, qui n'existe plus
            $connection->executeStatement('UPDATE identity.refresh_tokens SET revoked_at = now() WHERE user_id = :id AND revoked_at IS NULL', ['id' => $userId]);

            $request = new DataRequest();
            $request->setIdUser($user);
            $request->setRequestType('deletion');
            $request->setStatus('done');
            $request->setRequestedAt(new \DateTimeImmutable());
            $request->setProcessedAt(new \DateTimeImmutable());
            $this->em->persist($request);

            // * ni nom ni e-mail dans la trace : l'identifiant suffit
            $this->audit->log('account_deleted', 'identity', 'users', $userId, null, [
                'producer' => $producer !== null,
                'subscription_cancelled' => $cancelled !== null,
            ]);
        });

        // * après l'enregistrement : si la base a échoué, aucun fichier n'a disparu ; un fichier resté seul ne coûte que de la place
        foreach (array_filter($mediaKeys) as $key) {
            $this->deleteFile($this->mediaStorage, $key);
        }
        foreach (array_filter($documentKeys) as $key) {
            $this->deleteFile($this->documentStorage, $key);
        }

        if ($providerSubscriptionId !== null) {
            try {
                $this->paymentGateway->anonymizeCustomer($providerSubscriptionId);
            } catch (StripeException $e) {
                // * le compte est déjà anonymisé et l'abonnement résilié : on trace, sans faire échouer la suppression
                $this->stripeFailures->log('anonymize_customer', $e);
            }
        }
    }

    /**
     * @return array{0: list<string|null>, 1: list<string|null>} les fichiers à retirer du stockage une fois la base enregistrée :
     *                                                           photos, puis justificatifs
     */
    private function erasedProducer(ProducerProfile $producer): array
    {
        $mediaKeys = [];
        $documentKeys = [];
        foreach ($producer->getProducerMedia() as $media) {
            $mediaKeys[] = ProducerMediaKey::of($media);
            $this->em->remove($media);
        }
        foreach ($producer->getProducts() as $product) {
            foreach ($product->getMedia() as $photo) {
                $mediaKeys[] = $this->productPhotos->keyOf($photo);
                $this->em->remove($photo);
            }
            // * les lignes restent (statistiques), mais plus rien n'est proposé
            $product->setIsActive(false);
        }
        foreach ($producer->getVerificationDocuments() as $document) {
            $documentKeys[] = $document->getFileUrl();
            $this->em->remove($document);
        }
        foreach ([$producer->getLabels(), $producer->getTeamMembers(), $producer->getDeliveryZones(), $producer->getOpeningHours(), $producer->getQuickReplies(), $producer->getPaymentMethods()] as $rows) {
            foreach ($rows as $row) {
                $this->em->remove($row);
            }
        }
        // ! en SQL : la fiche garde un cascade persist vers ses réglages, qui « annulerait » un remove() pendant le flush
        $this->em->getConnection()->executeStatement('DELETE FROM producer.producer_settings WHERE producer_id = :id', ['id' => $producer->getId()->toRfc4122()]);

        // * la fiche n'est plus publique (sa visibilité ne dépend que de is_active) et ne reçoit plus de demandes
        $producer->setIsActive(false);
        $producer->setFarmName('Exploitation supprimée');
        // * le lien public portait le nom de la ferme ; unique, donc un suffixe au hasard
        $producer->setSlug('supprimee-'.bin2hex(random_bytes(6)));
        $producer->setDescription(null);
        $producer->setStory(null);
        $producer->setCity(null);
        $producer->setPostalCode(null);
        $producer->setAddressVisibility(null);
        $producer->setLocation(null);

        return [$mediaKeys, $documentKeys];
    }

    private function deleteFile(FilesystemOperator $storage, string $key): void
    {
        try {
            $storage->delete($key);
        } catch (\Throwable $e) {
            $this->logger->warning('Fichier d\'un compte supprimé non retiré du stockage.', ['key' => $key, 'error' => $e->getMessage()]);
        }
    }
}
