<?php

namespace App\Controller\Admin;

use App\Entity\Catalog\Label;
use App\Entity\Catalog\LabelTranslation;
use App\Form\Admin\LabelTranslationType;
use App\Service\Platform\PlatformSettings;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Filter\BooleanFilter;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Écran "Labels" du back-office (maquette Figma "Labels et certifications" / "Modifier label"). "Type" reprend
 * Label::$requiresDocument : un label qui exige un justificatif est une certification (Bio, HVE, AOP/AOC), les
 * autres sont des pratiques déclaratives (Local, agriculture raisonnée).
 *
 * Traductions (labelTranslations) éditées via editTranslations() ci-dessous, PAS via un CollectionField
 * EasyAdmin : clé primaire composite (label+locale), voir CategoryCrudController.
 */
class LabelCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Label::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('label')
            ->setEntityLabelInPlural('Labels et certifications')
            ->setPageTitle(Crud::PAGE_INDEX, 'Labels et certifications')
            ->setPageTitle(Crud::PAGE_NEW, 'Ajouter un label')
            ->setPageTitle(Crud::PAGE_EDIT, 'Modifier le label')
            ->setSearchFields(['name', 'code'])
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $isReadOnlyPage = Crud::PAGE_INDEX === $pageName || Crud::PAGE_DETAIL === $pageName;

        yield IdField::new('id')->hideOnForm()->hideOnIndex();
        yield TextField::new('name')->setLabel('Nom du label');
        yield TextField::new('code')->setLabel('Code')->hideOnIndex();
        yield TextareaField::new('description')->setLabel('Description')->hideOnIndex();

        if ($isReadOnlyPage) {
            yield BooleanField::new('requiresDocument')
                ->setLabel('Type')
                ->setTemplatePath('admin/field/boolean_label.html.twig')
                ->setCustomOption('onLabel', 'Certification')
                ->setCustomOption('offLabel', 'Pratique');
            yield IntegerField::new('producerCount')
                ->setLabel('Producteurs associés')
                ->formatValue(static fn (int $count) => sprintf('%d producteur%s', $count, $count > 1 ? 's' : ''))
                ->setSortable(false);
            yield BooleanField::new('isActive')
                ->setLabel('Statut')
                ->setTemplatePath('admin/field/boolean_label.html.twig')
                ->setCustomOption('onLabel', 'Actif')
                ->setCustomOption('offLabel', 'Inactif')
                ->setCustomOption('asBadge', true);
        } else {
            yield BooleanField::new('requiresDocument')->setLabel('Certification (justificatif requis)');
            yield BooleanField::new('isActive')->setLabel('Actif');
        }
    }

    public function configureFilters(Filters $filters): Filters
    {
        // * "Actif"/"Inactif" par défaut de BooleanFilter (traduit
        // * globalement par label.true/label.false dans EasyAdminBundle -- le changer affecterait tous les
        // * autres booléens du back-office, pas seulement celui-ci).
        return $filters->add(BooleanFilter::new('isActive')->setFormTypeOption('choices', ['Actif' => true, 'Inactif' => false]));
    }

    public function configureActions(Actions $actions): Actions
    {
        $translations = Action::new('translations', 'Traductions')->linkToCrudAction('editTranslations');

        // * Suppression réservée aux labels sans rattachement : Label::$producerLabels et $requestLabels sont en
        // * orphanRemoval, supprimer un label utilisé effacerait silencieusement le label des fiches producteur.
        // * Un label utilisé se désactive plutôt (champ "Actif").
        return $actions
            ->update(Crud::PAGE_INDEX, Action::NEW, static fn (Action $action) => $action->setLabel('Ajouter un label'))
            ->update(Crud::PAGE_INDEX, Action::EDIT, static fn (Action $action) => $action->setLabel('Modifier'))
            ->update(Crud::PAGE_INDEX, Action::DELETE, static fn (Action $action) => $action->displayIf(static fn (Label $label) => self::isUnused($label)))
            ->update(Crud::PAGE_DETAIL, Action::DELETE, static fn (Action $action) => $action->displayIf(static fn (Label $label) => self::isUnused($label)))
            ->add(Crud::PAGE_INDEX, $translations)
            ->add(Crud::PAGE_DETAIL, $translations);
    }

    #[AdminRoute(path: '/{entityId}/translations', name: 'translations')]
    public function editTranslations(AdminContext $context, EntityManagerInterface $em, Request $request): Response
    {
        $label = $context->getEntity()->getInstance();

        $existing = [];
        foreach ($label->getLabelTranslations() as $translation) {
            $existing[$translation->getLocale()] = $translation;
        }

        // * setName('') explicite : voir le commentaire équivalent sur CategoryCrudController::editTranslations().
        $initialData = [];
        foreach (PlatformSettings::LOCALES as $code => $name) {
            $initialData[$code] = $existing[$code] ?? (new LabelTranslation())->setLabel($label)->setLocale($code)->setName('');
        }

        $formBuilder = $this->createFormBuilder($initialData);
        foreach (PlatformSettings::LOCALES as $code => $name) {
            $formBuilder->add($code, LabelTranslationType::class, ['label' => $name, 'required' => false]);
        }
        $form = $formBuilder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = $form->getData();
            foreach (PlatformSettings::LOCALES as $code => $name) {
                /** @var LabelTranslation $translation */
                $translation = $submitted[$code];
                $hasContent = '' !== trim((string) $translation->getName());

                if ($hasContent) {
                    if (!isset($existing[$code])) {
                        $label->addLabelTranslation($translation);
                    }
                    $em->persist($translation);
                } elseif (isset($existing[$code])) {
                    $label->removeLabelTranslation($existing[$code]);
                }
            }
            $em->flush();
            $this->addFlash('success', 'Traductions enregistrées.');

            return $this->redirect($this->urlToDetail($label));
        }

        return $this->render('admin/translations.html.twig', [
            'form' => $form,
            'entityLabel' => $label->getName(),
            'backUrl' => $this->urlToDetail($label),
        ]);
    }

    private static function isUnused(Label $label): bool
    {
        return $label->getProducerLabels()->isEmpty() && $label->getRequestLabels()->isEmpty();
    }

    private function urlToDetail(Label $label): string
    {
        return $this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($label->getId())
            ->generateUrl();
    }
}
