<?php

namespace App\Tests\Functional\Controller\Admin;

use App\Controller\Admin\InvoiceCrudController;
use App\Controller\Admin\PaymentCrudController;
use App\Controller\Admin\PlanPriceCrudController;
use App\Controller\Admin\SubscriptionCrudController;
use App\Controller\Admin\SubscriptionPlanCrudController;
use App\Entity\Billing\Invoice;
use App\Entity\Billing\Payment;
use App\Entity\Catalog\Currency;
use App\Entity\Identity\User;
use App\Tests\ApiTestCase;
use App\Tests\Fixtures\EntityFactoryTrait;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;

/**
 * Teste le module "Abonnements" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Plans, prix, coupons, abonnements actifs, paiements échoués, factures"). Coupons volontairement exclus
 * (V1 après MVP) : voir le docblock de SubscriptionPlanCrudController.
 */

final class BillingCrudControllerTest extends ApiTestCase
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

    public function testCreatingSubscriptionPlanSucceeds(): void
    {
        $this->loginAsAdmin();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SubscriptionPlanCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['code'] = 'pro';
        $values[$rootKey]['name'] = 'Pro';
        $values[$rootKey]['isActive'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            "SELECT id FROM billing.subscription_plans WHERE code = 'pro'"
        );
        self::assertNotFalse($row);
    }

    public function testCreatingPlanPriceSucceeds(): void
    {
        $admin = $this->loginAsAdmin();
        $plan = new \App\Entity\Billing\SubscriptionPlan();
        $plan->setCode('plan-'.bin2hex(random_bytes(6)));
        $plan->setName('Essentiel');
        $plan->setIsActive(true);
        $this->em->persist($plan);

        $currency = new Currency();
        $currency->setCode('EUR');
        $currency->setName('Euro');
        $this->em->persist($currency);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(PlanPriceCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form[method="post"]')->last()->form();
        $values = $form->getPhpValues();
        $rootKey = array_key_first($values);
        $values[$rootKey]['plan'] = $plan->getId()->toRfc4122();
        $values[$rootKey]['billingCycle'] = 'monthly';
        $values[$rootKey]['amount'] = '9.99';
        $values[$rootKey]['currency'] = 'EUR';
        $values[$rootKey]['isActive'] = '1';

        $this->client->request($form->getMethod(), $form->getUri(), $values, $form->getPhpFiles());
        self::assertResponseIsSuccessful();

        $row = $this->em->getConnection()->fetchAssociative(
            'SELECT amount FROM billing.plan_prices WHERE plan_id = :id',
            ['id' => $plan->getId()->toRfc4122()]
        );
        self::assertNotFalse($row);
        self::assertSame('9.99', $row['amount']);
    }

    public function testSubscriptionIndexAndDetailAreReadOnly(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer'), $country);
        $subscription = $this->makeActiveSubscription($producer);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SubscriptionCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SubscriptionCrudController::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($subscription->getId())
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(SubscriptionCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseStatusCodeSame(403);
    }

    public function testInvoiceIndexIsReadOnly(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer2'), $country);
        $subscription = $this->makeActiveSubscription($producer);

        $invoice = new Invoice();
        $invoice->setSubscription($subscription);
        $invoice->setAmount('9.99');
        $invoice->setStatus('paid');
        $this->em->persist($invoice);
        $this->em->flush();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(InvoiceCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
        self::assertResponseIsSuccessful();

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(InvoiceCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseStatusCodeSame(403);
    }

    public function testPaymentIndexShowsFailedPaymentAndIsReadOnly(): void
    {
        $this->loginAsAdmin();
        $country = $this->makeCountry();
        $producer = $this->makeProducerProfile($this->makeUser('producer3'), $country);
        $subscription = $this->makeActiveSubscription($producer);

        $invoice = new Invoice();
        $invoice->setSubscription($subscription);
        $invoice->setAmount('9.99');
        $invoice->setStatus('open');
        $this->em->persist($invoice);

        $payment = new Payment();
        $payment->setInvoice($invoice);
        $payment->setAmount('9.99');
        $payment->setStatus('failed');
        $payment->setFailureReason('card_declined');
        $this->em->persist($payment);
        $this->em->flush();

        $crawler = $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(PaymentCrudController::class)
            ->setAction(Action::INDEX)
            ->generateUrl());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('failed', (string) $crawler->filter('body')->text());

        $this->client->request('GET', self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(PaymentCrudController::class)
            ->setAction(Action::NEW)
            ->generateUrl());
        self::assertResponseStatusCodeSame(403);
    }
}
