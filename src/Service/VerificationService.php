<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class VerificationService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    public function generateTokenFor(User $user): string
    {
        $token = bin2hex(random_bytes(32));
        $user->setVerificationToken($token);
        $user->setIsVerified(false);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $token;
    }

    public function sendVerificationEmail(User $user): void
    {
        if (!$user->getEmail() || !$user->getVerificationToken()) {
            return;
        }

        $verifyUrl = $this->urlGenerator->generate('app_verify_email', [
            'token' => $user->getVerificationToken(),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        $email = (new TemplatedEmail())
            ->from($_ENV['MAILER_FROM_ADDRESS'] ?? 'no-reply@skadoosh.local')
            ->to($user->getEmail())
            ->subject('Verify your SKADOOSH account')
            ->htmlTemplate('emails/verify_email.html.twig')
            ->context([
                'username' => $user->getUserIdentifier(),
                'verifyUrl' => $verifyUrl,
            ]);

        try {
            $this->mailer->send($email);
        } catch (TransportExceptionInterface) {
            // In demo/deployment environments, registration should still succeed
            // even if SMTP is disabled or not yet configured.
        }
    }

    public function verifyByToken(string $token): ?User
    {
        $user = $this->entityManager->getRepository(User::class)->findOneBy([
            'verificationToken' => $token,
        ]);

        if (!$user) {
            return null;
        }

        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }
}

