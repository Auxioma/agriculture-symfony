<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\CouponCrudController;
use App\Entity\Billing\Coupon;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le volet "Coupons" du module "Abonnements" du back-office (cahier fonctionnel,
 * "coupons abonnement") -- CRUD simple, providerCouponId référence un Coupon créé au préalable dans
 * le Dashboard Stripe (voir CouponCrudController).
 */

final class CouponCrudControllerTest extends ApiTestCase
{
    use EntityFactoryTrait;

    private function loginAsAdmin(): User
    {
        $admin = $this->makeUserWithPassword('admin', 'motdepasse123');
        $admin->setRoles([User::ROLE_ADMIN]);
        $this->em->flush();

        $this->client->followRedirects(true);
        $this->client->request('GET', '/admin/login');
        $this->client->submitForm('Se connecter', [
            '_username' => $admin->getEmail(),
            '_password' => 'motdepasse123',
        ]);

        return $admin;
    }

    public function testCreateCouponSucceeds(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(CouponCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);

        $values[$rootKey]['code'] = 'BIENVENUE10';
        $values[$rootKey]['discountPercent'] = '10';
        $values[$rootKey]['providerCouponId'] = 'coupon_stripe_test';
        $values[$rootKey]['maxRedemptions'] = '100';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT discount_percent, provider_coupon_id, max_redemptions FROM billing.coupons WHERE code = 'BIENVENUE10'"
        );
        self::assertNotFalse($row);
        self::assertSame('10.00', $row['discount_percent']);
        self::assertSame('coupon_stripe_test', $row['provider_coupon_id']);
        self::assertSame(100, $row['max_redemptions']);
    }

    public function testListIndexShowsExistingCoupon(): void
    {
        $this->loginAsAdmin();

        $coupon = new Coupon();
        $coupon->setCode('EXISTANT20');
        $this->em->persist($coupon);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(CouponCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('EXISTANT20', $this->client->getResponse()->getContent());
    }
}
