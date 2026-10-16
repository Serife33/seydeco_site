<?php

namespace App\Controller\Admin;

use Scheb\TwoFactorBundle\Model\Email\TwoFactorInterface;
use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Email\Generator\CodeGeneratorInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\InvalidCsrfTokenException;
use Symfony\Component\Security\Core\Exception\TooManyLoginAttemptsAuthenticationException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

final class SecurityController extends AbstractController
{
    #[Route('/admin/connexion', name: 'admin_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->isGranted('IS_AUTHENTICATED_FULLY')) {
            return $this->redirectToRoute('admin_dashboard');
        }

        $error = $authenticationUtils->getLastAuthenticationError();

        return $this->render('admin/security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => match (true) {
                null === $error => null,
                $error instanceof TooManyLoginAttemptsAuthenticationException => 'Trop de tentatives. Réessayez dans 15 minutes.',
                $error instanceof InvalidCsrfTokenException => 'Session expirée, réessayez.',
                default => 'Identifiant ou mot de passe incorrect.',
            },
        ]);
    }

    #[Route('/admin/deconnexion', name: 'admin_logout', methods: ['GET'])]
    public function logout(): never
    {
        throw new \LogicException('Intercepté par le pare-feu (logout).');
    }

    #[Route('/admin/code/renvoyer', name: 'admin_2fa_resend', methods: ['POST'])]
    public function resendCode(Request $request, CodeGeneratorInterface $codeGenerator, RateLimiterFactoryInterface $resendCodeLimiter): Response
    {
        $user = $this->getUser();
        if (!$this->isCsrfTokenValid('admin_2fa_resend', $request->getPayload()->getString('_token'))) {
            $this->addFlash('error', 'Session expirée, réessayez.');
        } elseif ($user instanceof TwoFactorInterface) {
            if (!$resendCodeLimiter->create($user->getUserIdentifier())->consume()->isAccepted()) {
                $this->addFlash('error', 'Trop de demandes de code. Réessayez dans 15 minutes.');
            } else {
                $codeGenerator->generateAndSend($user);
                // AuthCodeMailer ajoute lui-même un message d'erreur si l'envoi échoue
                if (!$request->getSession()->getFlashBag()->has('error')) {
                    $this->addFlash('success', 'Un nouveau code vous a été envoyé.');
                }
            }
        }

        return $this->redirectToRoute('2fa_login');
    }
}
