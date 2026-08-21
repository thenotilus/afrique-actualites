<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Connexion/déconnexion (§3.4, prompt de conception parcours 1.H). Email/mot de passe uniquement
 * dans ce lot : la connexion sociale Facebook évoquée dans le prompt de conception UX reste liée
 * au partage réseaux sociaux, explicitement différé à une phase ultérieure (§3.8, §11.2 phase 9).
 * L'inscription reste facultative pour consulter le site — voir `RegistrationController` pour le
 * cadrage complet.
 */
class SecurityController extends AbstractController
{
    #[Route('/connexion', name: 'app_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('public/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Route nécessaire à la configuration `logout` du firewall : le listener de sécurité
     * intercepte ce chemin avant que le contrôleur ne s'exécute.
     */
    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être exécutée : interceptée par le firewall.');
    }
}
