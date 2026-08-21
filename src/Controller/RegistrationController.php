<?php

namespace App\Controller;

use App\User\Entity\User;
use App\User\Form\RegistrationType;
use App\User\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Inscription minimale (§12, prompt de conception parcours 1.H) : voir le docblock de
 * `RegistrationType`. Ne connecte pas automatiquement l'utilisateur après inscription — un
 * aller-retour explicite par le formulaire de connexion évite d'avoir à intégrer un
 * authenticator personnalisé pour ce seul cas, pour un gain d'ergonomie marginal.
 */
class RegistrationController extends AbstractController
{
    #[Route('/inscription', name: 'app_register')]
    public function register(
        Request $request,
        EntityManagerInterface $entityManager,
        UserRepository $userRepository,
        UserPasswordHasherInterface $passwordHasher,
    ): Response {
        $user = new User('');
        $form = $this->createForm(RegistrationType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (null !== $userRepository->findOneBy(['email' => $user->getEmail()])) {
                $form->get('email')->addError(new FormError('registration.form.email_already_used'));
            } else {
                $user->setPassword($passwordHasher->hashPassword($user, (string) $form->get('plainPassword')->getData()));
                $entityManager->persist($user);
                $entityManager->flush();

                $this->addFlash('success', 'registration.flash.success');

                return $this->redirectToRoute('app_login');
            }
        }

        return $this->render('public/security/register.html.twig', [
            'form' => $form,
        ]);
    }
}
