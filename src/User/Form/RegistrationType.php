<?php

namespace App\User\Form;

use App\User\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Inscription minimale (§12, prompt de conception parcours 1.H) : email + mot de passe. Pas de
 * profil enrichi ici — l'inscription n'est qu'un préalable facultatif à la création de "Unes"
 * personnalisées, pas un mur d'inscription pour lire le site.
 */
final class RegistrationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'registration.form.email',
                'constraints' => [new NotBlank(), new Length(max: 180)],
            ])
            ->add('plainPassword', PasswordType::class, [
                'label' => 'registration.form.password',
                'mapped' => false,
                'constraints' => [new NotBlank(), new Length(min: 8, max: 4096)],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults(['data_class' => User::class]);
    }
}
