<?php

namespace App\Tests\Security;

use App\Entity\AdminUser;
use App\Security\AuthCodeMailer;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\RawMessage;

final class AuthCodeMailerTest extends TestCase
{
    public function testSmtpFailureShowsFrenchMessageAndLogsWithoutCode(): void
    {
        $mailer = new class implements MailerInterface {
            public function send(RawMessage $message, ?Envelope $envelope = null): void
            {
                throw new TransportException('Connexion SMTP refusée');
            }
        };
        $logger = new class extends AbstractLogger {
            public array $logs = [];

            public function log($level, \Stringable|string $message, array $context = []): void
            {
                $this->logs[] = $message.json_encode($context);
            }
        };
        $session = new Session(new MockArraySessionStorage());
        $request = new Request();
        $request->setSession($session);
        $requestStack = new RequestStack([$request]);

        $user = new AdminUser('serife@example.com', 'hash');
        $user->setEmailAuthCode('482913');

        (new AuthCodeMailer($mailer, $requestStack, $logger, 'Seydeco <test@example.com>'))->sendAuthCode($user);

        self::assertSame([AuthCodeMailer::SEND_FAILED], $session->getFlashBag()->get('error'));
        self::assertCount(1, $logger->logs);
        self::assertStringNotContainsString('482913', $logger->logs[0]);
    }
}
