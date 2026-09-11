<?php

/**
 * Module "Pages légales" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf,
 * section Back-office Symfony). Publiées ensuite en lecture publique par LegalController
 * (GET /api/legal, GET /api/legal/{code}) pour les pages CGU/confidentialité/mentions légales du
 * front Angular.
 *
 * persistEntity()/updateEntity() désactivent automatiquement toute autre ligne du même
 * (code, locale) dès qu'une version est marquée active : au plus une version "live" par page et
 * par langue à la fois, cohérent avec le rôle de $version/$publishedAt (historique des versions
 * publiées) décrit sur l'entité.
 */

namespace App\Controller\Admin;

use App\Entity\Content\LegalPage;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;

class LegalPageCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return LegalPage::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud->setEntityLabelInSingular('Page légale')->setEntityLabelInPlural('Pages légales');
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        yield TextField::new('code')->setHelp('Identifiant stable de la page, ex. "cgu", "confidentialite", "mentions-legales".');
        yield TextField::new('locale')->setHelp('Code langue ISO, ex. "fr".');
        yield IntegerField::new('version')->hideOnIndex();
        yield TextField::new('title')->setLabel('Titre');
        yield TextareaField::new('content')->setLabel('Contenu')->hideOnIndex();
        yield BooleanField::new('isActive')->setLabel('Version active');
        yield DateTimeField::new('publishedAt')->hideOnForm();
    }

    public function persistEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof LegalPage && $entityInstance->isActive()) {
            $entityInstance->setPublishedAt(new \DateTimeImmutable());
            $this->deactivateOtherVersions($entityManager, $entityInstance);
        }

        parent::persistEntity($entityManager, $entityInstance);
    }

    public function updateEntity(EntityManagerInterface $entityManager, object $entityInstance): void
    {
        if ($entityInstance instanceof LegalPage && $entityInstance->isActive()) {
            if ($entityInstance->getPublishedAt() === null) {
                $entityInstance->setPublishedAt(new \DateTimeImmutable());
            }
            $this->deactivateOtherVersions($entityManager, $entityInstance);
        }

        parent::updateEntity($entityManager, $entityInstance);
    }

    private function deactivateOtherVersions(EntityManagerInterface $entityManager, LegalPage $activePage): void
    {
        $others = $entityManager->getRepository(LegalPage::class)->createQueryBuilder('l')
            ->where('l.code = :code')
            ->andWhere('l.locale = :locale')
            ->andWhere('l.id != :id')
            ->setParameter('code', $activePage->getCode())
            ->setParameter('locale', $activePage->getLocale())
            ->setParameter('id', $activePage->getId())
            ->getQuery()
            ->getResult();

        foreach ($others as $other) {
            $other->setIsActive(false);
        }
    }
}
