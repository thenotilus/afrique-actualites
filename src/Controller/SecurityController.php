<?php

namespace App\Controller;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Le formulaire de connexion public complet (email/mot de passe, connexion Facebook) est prévu
 * en phase 6 (§11.2) avec le reste des pages publiques. Cette route de déconnexion existe dès
 * maintenant : elle est nécessaire à la configuration `logout` du firewall (le listener de
 * sécurité intercepte ce chemin avant que le contrôleur ne s'exécute).
 */
class SecurityController
{
    #[Route('/logout', name: 'app_logout')]
    public function logout(): never
    {
        throw new \LogicException('Cette méthode ne doit jamais être exécutée : interceptée par le firewall.');
    }
}
