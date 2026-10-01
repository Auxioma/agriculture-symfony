<?php

namespace App\Form\Admin;

use App\Entity\Catalog\LabelTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// * Pas de champ "locale" : voir le commentaire équivalent sur CategoryTranslationType.
class LabelTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // * empty_data: '' obligatoire -- voir le commentaire équivalent sur CategoryTranslationType
            // * (LabelTranslation::$name est aussi un string non nullable).
            ->add('name', TextType::class, ['required' => false, 'empty_data' => ''])
            ->add('description', TextareaType::class, ['required' => false]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => LabelTranslation::class]);
    }
}
