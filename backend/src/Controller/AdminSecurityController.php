<?php

namespace App\Controller;

use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin')]
class AdminSecurityController extends AbstractController
{
    #[Route('/login', name: 'admin_login')]
    public function login(): Response
    {
        return $this->render('admin/login.html.twig');
    }

    #[Route('/login/google', name: 'admin_login_google')]
    public function connectGoogle(ClientRegistry $clientRegistry): Response
    {
        return $clientRegistry->getClient('google')->redirect(['email', 'profile']);
    }

    /**
     * Never actually executes — GoogleAuthenticator::supports() intercepts
     * this route before the controller runs.
     */
    #[Route('/oauth/google/check', name: 'admin_oauth_check')]
    public function check(): never
    {
        throw new \LogicException('Intercepted by GoogleAuthenticator.');
    }

    /**
     * Never actually executes — handled by the firewall's logout key.
     */
    #[Route('/logout', name: 'admin_logout')]
    public function logout(): never
    {
        throw new \LogicException('Intercepted by the firewall logout listener.');
    }
}
