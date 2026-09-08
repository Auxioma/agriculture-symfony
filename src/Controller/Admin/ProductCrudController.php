<?php

namespace App\Controller\Admin;

use App\Entity\Catalog\Product;
use App\Entity\Catalog\ProductTranslation;
use App\Form\Admin\ProductTranslationType;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminRoute;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Module "Catégories et produits" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf :
 * "Catégories, sous-catégories, produits, unités, saisons, traductions, SEO").
 *
 * Traductions (productTranslations) éditées via editTranslations() ci-dessous, PAS via un CollectionField
 * EasyAdmin -- même raison que sur CategoryCrudController (ProductTranslation a une clé primaire composite
 * product+locale, décision délibérée verrouillée par trouvemoi-agri-make-entity-guide.md).
 */
class ProductCrudController extends AbstractCrudController
{
    private const LOCALES = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español', 'it' => 'Italiano', 'de' => 'Deutsch'];

    public static function getEntityFqcn(): string
    {
        return Product::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Produit')
            ->setEntityLabelInPlural('Produits')
            ->setDefaultSort(['name' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        // * choice_label obligatoire sur les deux : ni Category ni Unit n'ont de __toString(), le <select>
        // * du formulaire plante sinon en tentant de caster l'entité en chaîne pour l'option affichée.
        yield IdField::new('id')->hideOnForm();
        yield AssociationField::new('category')->setFormTypeOption('choice_label', 'name');
        yield TextField::new('name');
        yield TextField::new('slug');
        yield AssociationField::new('defaultUnit')
            ->setLabel('Unité par défaut')
            ->setFormTypeOption('choice_label', static fn ($unit) => $unit->getLabel() ?? $unit->getCode());
        yield IntegerField::new('seasonStartMonth')->setLabel('Début de saison (mois 1-12)')->hideOnIndex();
        yield IntegerField::new('seasonEndMonth')->setLabel('Fin de saison (mois 1-12)')->hideOnIndex();
        yield BooleanField::new('isActive');
    }

    public function configureActions(Actions $actions): Actions
    {
        $translations = Action::new('translations', 'Traductions')->linkToCrudAction('editTranslations');

        return $actions
            ->add(Crud::PAGE_INDEX, $translations)
            ->add(Crud::PAGE_DETAIL, $translations);
    }

    #[AdminRoute(path: '/{entityId}/translations', name: 'translations')]
    public function editTranslations(AdminContext $context, EntityManagerInterface $em, Request $request): Response
    {
        $product = $context->getEntity()->getInstance();

        $existing = [];
        foreach ($product->getProductTranslations() as $translation) {
            $existing[$translation->getLocale()] = $translation;
        }

        // * setName('') explicite : voir le commentaire équivalent sur CategoryCrudController::editTranslations().
        $initialData = [];
        foreach (self::LOCALES as $code => $label) {
            $initialData[$code] = $existing[$code] ?? (new ProductTranslation())->setProduct($product)->setLocale($code)->setName('');
        }

        $formBuilder = $this->createFormBuilder($initialData);
        foreach (self::LOCALES as $code => $label) {
            $formBuilder->add($code, ProductTranslationType::class, ['label' => $label, 'required' => false]);
        }
        $form = $formBuilder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = $form->getData();
            foreach (self::LOCALES as $code => $label) {
                /** @var ProductTranslation $translation */
                $translation = $submitted[$code];
                $hasContent = '' !== trim((string) $translation->getName());

                if ($hasContent) {
                    if (!isset($existing[$code])) {
                        $product->addProductTranslation($translation);
                    }
                    $em->persist($translation);
                } elseif (isset($existing[$code])) {
                    $product->removeProductTranslation($existing[$code]);
                }
            }
            $em->flush();
            $this->addFlash('success', 'Traductions enregistrées.');

            return $this->redirectToDetail($product);
        }

        return $this->render('admin/translations.html.twig', [
            'form' => $form,
            'entityLabel' => $product->getName(),
            'backUrl' => $this->urlToDetail($product),
        ]);
    }

    private function redirectToDetail(Product $product): Response
    {
        return $this->redirect($this->urlToDetail($product));
    }

    private function urlToDetail(Product $product): string
    {
        return $this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($product->getId())
            ->generateUrl();
    }
}
