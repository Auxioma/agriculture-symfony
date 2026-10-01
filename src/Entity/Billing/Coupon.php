<?php

/**
 * Code de réduction (cahier fonctionnel : coupons promotionnels sur les abonnements producteur).
 * $maxRedemptions plafonne le nombre d'utilisations, chaque utilisation individuelle étant tracée dans
 * CouponRedemption. $providerCouponId référence un Coupon Stripe créé au préalable dans le Dashboard --
 * même principe que PlanPrice::$providerPriceId, géré via CouponCrudController (back-office). $discountPercent
 * reste purement informatif côté Symfony (afficher "-10%" sans rappeler Stripe) : c'est le Coupon Stripe
 * référencé par $providerCouponId qui applique réellement la réduction lors du checkout.
 * Branché uniquement sur POST /api/subscription/checkout (souscription initiale) -- volontairement pas sur
 * change-plan, qui modifierait un abonnement déjà facturé sans notion claire de "première utilisation".
 */

namespace App\Entity\Billing;

use App\Repository\Billing\CouponRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Types\UuidType;
use Symfony\Component\Uid\Uuid;

#[ORM\Entity(repositoryClass: CouponRepository::class)]
#[ORM\Table(name: 'coupons', schema: 'billing')]
class Coupon
{
    #[ORM\Id]
    #[ORM\Column(type: UuidType::NAME, unique: true)]
    private Uuid $id;

    #[ORM\Column(type: 'citext')]
    private string $code;

    #[ORM\Column(type: Types::DECIMAL, precision: 12, scale: 2, nullable: true)]
    private ?string $discountPercent = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validFrom = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, nullable: true)]
    private ?\DateTimeImmutable $validUntil = null;

    #[ORM\Column(nullable: true)]
    private ?int $maxRedemptions = null;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $metadata = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $providerCouponId = null;

    /**
     * @var Collection<int, CouponRedemption>
     */
    #[ORM\OneToMany(targetEntity: CouponRedemption::class, mappedBy: 'coupon')]
    private Collection $couponRedemptions;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->couponRedemptions = new ArrayCollection();
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCode(): string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;

        return $this;
    }

    public function getDiscountPercent(): ?string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(?string $discountPercent): static
    {
        $this->discountPercent = $discountPercent;

        return $this;
    }

    public function getValidFrom(): ?\DateTimeImmutable
    {
        return $this->validFrom;
    }

    public function setValidFrom(?\DateTimeImmutable $validFrom): static
    {
        $this->validFrom = $validFrom;

        return $this;
    }

    public function getValidUntil(): ?\DateTimeImmutable
    {
        return $this->validUntil;
    }

    public function setValidUntil(?\DateTimeImmutable $validUntil): static
    {
        $this->validUntil = $validUntil;

        return $this;
    }

    public function getMaxRedemptions(): ?int
    {
        return $this->maxRedemptions;
    }

    public function setMaxRedemptions(?int $maxRedemptions): static
    {
        $this->maxRedemptions = $maxRedemptions;

        return $this;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): static
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function getProviderCouponId(): ?string
    {
        return $this->providerCouponId;
    }

    public function setProviderCouponId(?string $providerCouponId): static
    {
        $this->providerCouponId = $providerCouponId;

        return $this;
    }

    /**
     * @return Collection<int, CouponRedemption>
     */
    public function getCouponRedemptions(): Collection
    {
        return $this->couponRedemptions;
    }

    public function addCouponRedemption(CouponRedemption $couponRedemption): static
    {
        if (!$this->couponRedemptions->contains($couponRedemption)) {
            $this->couponRedemptions->add($couponRedemption);
            $couponRedemption->setCoupon($this);
        }

        return $this;
    }

    public function removeCouponRedemption(CouponRedemption $couponRedemption): static
    {
        if ($this->couponRedemptions->removeElement($couponRedemption));

        return $this;
    }
}
