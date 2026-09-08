<?php

namespace App\Form\Admin;

use App\Entity\Catalog\CategoryTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// * Pas de champ "locale" ici : contrairement à la tentative précédente (CollectionField), la locale est
// * fixée par la clé du bloc parent (voir CategoryCrudController::editTranslations()), jamais saisie par
// * l'admin -- l'entité liée (CategoryTranslation, clé composite category+locale) n'est donc jamais
// * introspectée par EasyAdmin : ce formulaire est du Symfony Form pur, indépendant de CollectionField.
class CategoryTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // * empty_data: '' obligatoire ici -- required:false fait de null la valeur par défaut de TextType
            // * quand le champ est soumis vide, mais CategoryTranslation::$name est un string non nullable
            // * (setName(string $name)) : sans ce override, une locale laissée vide plante en TypeError.
            ->add('name', TextType::class, ['required' => false, 'empty_data' => ''])
            ->add('description', TextareaType::class, ['required' => false])
            ->add('seoTitle', TextType::class, ['required' => false, 'label' => 'Titre SEO'])
            ->add('seoDescription', TextareaType::class, ['required' => false, 'label' => 'Meta description SEO']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => CategoryTranslation::class]);
    }
}
