<?php

namespace App\Controller\Admin;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Contrôleur de sécurité du back-office.
 *
 * Gère l'affichage du formulaire de connexion pour la section d'administration
 * et fournit la route ciblée par le mécanisme de déconnexion de Symfony.
 */

class SecurityController extends AbstractController
{
    /**
     * Affiche la page de connexion à l'espace d'administration.
     *
     * Récupère le dernier nom d'utilisateur saisi ainsi que l'éventuelle erreur
     * d'authentification survenue lors de la tentative précédente pour les transmettre au template.
     *
     * @param AuthenticationUtils $authenticationUtils Service d'assistance à l'authentification Symfony.
     * 
     * @return Response Le rendu de la vue du formulaire de connexion (admin/login.html.twig).
     */

    #[Route('/admin/login', name: 'admin_login')]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        return $this->render('admin/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    /**
     * Point d'entrée pour la déconnexion de l'espace administration.
     *
     * sert uniquement de cible pour la route `admin_logout`.
     * Le traitement effectif de la déconnexion (invalidation de la session, suppression des cookies)
     * est entièrement intercepté par le composant Security de Symfony via le firewall.
     *
     * @throws \LogicException Si la requête n'a pas été interceptée en amont par le firewall.
     */
    #[Route('/admin/logout', name: 'admin_logout')]
    public function logout(): void
    {
        throw new \LogicException('Interceptée par le firewall (clé logout) avant d\'atteindre cette méthode.');
    }
}