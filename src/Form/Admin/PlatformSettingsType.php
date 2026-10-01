<?php

namespace App\Form\Admin;

use App\Service\Platform\PlatformSettings;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Choice;
use Symfony\Component\Validator\Constraints\Email;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Range;

/**
 * Formulaire "Paramètres de la plateforme" : un champ par clé de PlatformSettings. Les devises proposées
 * ($options['currencies'], code => libellé) sont fournies par l'appelant pour ne lister que les devises actives.
 */
class PlatformSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add(PlatformSettings::NAME, TextType::class, [
                'label' => 'Nom de la plateforme',
                'attr' => ['class' => 'form-control', 'maxlength' => 120],
                'constraints' => [new NotBlank(), new Length(max: 120)],
            ])
            ->add(PlatformSettings::SUPPORT_EMAIL, EmailType::class, [
                'label' => 'Email de support',
                'attr' => ['class' => 'form-control'],
                'constraints' => [new NotBlank(), new Email()],
            ])
            ->add(PlatformSettings::DEFAULT_LOCALE, ChoiceType::class, [
                'label' => 'Langue par défaut',
                'choices' => array_flip(PlatformSettings::LOCALES),
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank(), new Choice(choices: array_keys(PlatformSettings::LOCALES))],
            ])
            ->add(PlatformSettings::DEFAULT_CURRENCY, ChoiceType::class, [
                'label' => 'Devise par défaut',
                'choices' => array_flip($options['currencies']),
                'attr' => ['class' => 'form-select'],
                'constraints' => [new NotBlank(), new Choice(choices: array_keys($options['currencies']))],
            ])
            ->add(PlatformSettings::SESSION_MINUTES, IntegerType::class, [
                'label' => 'Durée de session (minutes)',
                'attr' => ['class' => 'form-control', 'min' => 5, 'max' => 1440],
                'constraints' => [new NotBlank(), new Range(min: 5, max: 1440)],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['currencies' => ['EUR' => 'EUR (€)']]);
        $resolver->setAllowedTypes('currencies', 'array');
    }
}
