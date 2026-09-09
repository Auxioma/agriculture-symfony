<?php

namespace App\Form\Admin;

use App\Entity\Catalog\ProductTranslation;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\CallbackTransformer;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

// * Pas de champ "locale" : voir le commentaire équivalent sur CategoryTranslationType.
class ProductTranslationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // * empty_data: '' obligatoire -- voir le commentaire équivalent sur CategoryTranslationType
            // * (ProductTranslation::$name est aussi un string non nullable).
            ->add('name', TextType::class, ['required' => false, 'empty_data' => ''])
            ->add('description', TextareaType::class, ['required' => false])
            // * keywords est un simple_array Doctrine (?array côté PHP) : un TextType a besoin d'un
            // * transformateur pour aller-retourner entre la chaîne saisie et le tableau persisté.
            ->add('keywords', TextType::class, [
                'required' => false,
                'label' => 'Mots-clés SEO (séparés par des virgules)',
            ]);

        $builder->get('keywords')->addModelTransformer(new CallbackTransformer(
            static fn (?array $keywords): string => null !== $keywords ? implode(', ', $keywords) : '',
            static fn (?string $keywords): ?array => match (true) {
                null === $keywords, '' === trim($keywords) => null,
                default => array_values(array_filter(array_map('trim', explode(',', $keywords)))),
            },
        ));
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => ProductTranslation::class]);
    }
}
