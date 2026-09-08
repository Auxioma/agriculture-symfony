<?php

namespace App\Controller\Admin;

use App\Entity\Catalog\Category;
use App\Entity\Catalog\CategoryTranslation;
use App\Form\Admin\CategoryTranslationType;
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
 * Module "Catégories et produits" du back-office (cahier_des_charges_fonctionnel_trouvemoi_agri.pdf §13 :
 * "Catégories, sous-catégories, produits, unités, saisons, traductions, SEO").
 *
 * Traductions (categoryTranslations) éditées via editTranslations() ci-dessous, PAS via un CollectionField
 * EasyAdmin : CategoryTranslation a une clé primaire composite (category+locale) — cf.
 * trouvemoi-agri-make-entity-guide.md §1.2, décision délibérée du MLD/MPD, verrouillée par
 * tests/Functional/Database/EntityPersistenceTest::testCategoryTranslationUsesACompositePrimaryKey.
 * EasyAdmin refuse catégoriquement toute entité composite dès qu'il l'introspecte (CollectionField y compris
 * en usage indirect via setEntryType()) ; editTranslations() est un formulaire Symfony pur, sans CollectionField,
 * qui ne déclenche jamais cette introspection.
 */
class CategoryCrudController extends AbstractCrudController
{
    private const LOCALES = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español', 'it' => 'Italiano', 'de' => 'Deutsch'];

    public static function getEntityFqcn(): string
    {
        return Category::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Catégorie')
            ->setEntityLabelInPlural('Catégories')
            ->setDefaultSort(['position' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        yield IdField::new('id')->hideOnForm();
        // * choice_label obligatoire : Category n'a pas de __toString(), et le <select> du formulaire
        // * (Symfony EntityType) plante sinon en tentant de caster l'entité en chaîne pour l'option affichée.
        yield AssociationField::new('parent')->setLabel('Catégorie parente')->setFormTypeOption('choice_label', 'name');
        yield TextField::new('name');
        yield TextField::new('slug');
        yield TextField::new('icon')->hideOnIndex();
        yield TextField::new('imageUrl')->hideOnIndex();
        yield IntegerField::new('position');
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
        $category = $context->getEntity()->getInstance();

        $existing = [];
        foreach ($category->getCategoryTranslations() as $translation) {
            $existing[$translation->getLocale()] = $translation;
        }

        // * setName('') explicite sur les nouvelles instances : CategoryTranslation::$name est un string non
        // * nullable jamais initialisé ici -- sans valeur de départ, le champ "name" de ce bloc de locale
        // * n'a pas d'attribut value dans le HTML rendu, Crawler\Form omet alors sa clé du tableau soumis, et
        // * Symfony Form traite une clé absente comme null pour ce sous-formulaire -> TypeError sur setName().
        $initialData = [];
        foreach (self::LOCALES as $code => $label) {
            $initialData[$code] = $existing[$code] ?? (new CategoryTranslation())->setCategory($category)->setLocale($code)->setName('');
        }

        $formBuilder = $this->createFormBuilder($initialData);
        foreach (self::LOCALES as $code => $label) {
            $formBuilder->add($code, CategoryTranslationType::class, ['label' => $label, 'required' => false]);
        }
        $form = $formBuilder->getForm();
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $submitted = $form->getData();
            foreach (self::LOCALES as $code => $label) {
                /** @var CategoryTranslation $translation */
                $translation = $submitted[$code];
                $hasContent = '' !== trim((string) $translation->getName());

                if ($hasContent) {
                    if (!isset($existing[$code])) {
                        $category->addCategoryTranslation($translation);
                    }
                    $em->persist($translation);
                } elseif (isset($existing[$code])) {
                    $category->removeCategoryTranslation($existing[$code]);
                }
            }
            $em->flush();
            $this->addFlash('success', 'Traductions enregistrées.');

            return $this->redirectToDetail($category);
        }

        return $this->render('admin/translations.html.twig', [
            'form' => $form,
            'entityLabel' => $category->getName(),
            'backUrl' => $this->urlToDetail($category),
        ]);
    }

    private function redirectToDetail(Category $category): Response
    {
        return $this->redirect($this->urlToDetail($category));
    }

    private function urlToDetail(Category $category): string
    {
        return $this->container->get(AdminUrlGenerator::class)
            ->setController(self::class)
            ->setAction(Action::DETAIL)
            ->setEntityId($category->getId())
            ->generateUrl();
    }
}
