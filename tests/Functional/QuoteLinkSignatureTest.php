<?php

namespace App\Tests\Functional;

use App\Controller\QuoteLinkController;
use App\Entity\QuoteLine;
use App\Entity\QuoteLineType;
use App\Entity\QuoteRequest;
use App\Entity\QuoteVersion;
use App\Entity\Signature;
use App\Form\QuoteSignatureType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Stories 3.15 + 3.16 : page du lien secret (statut, récapitulatif, PDF, 404 neutre, en-têtes, limiteur)
 * et signature en ligne (états permis, erreurs, version périmée, double envoi, PDF signé + SHA-256).
 * La validation réelle arrive en Epic 4 : la demande de test passe à `validee` par la transition du workflow.
 */
final class QuoteLinkSignatureTest extends WebTestCase
{
    private const string IBAN = 'FR7630006000011234567890189';
    private const array SETTINGS = ['whatsapp_phone' => '06 12 34 56 78', 'iban' => self::IBAN, 'bic' => 'AGRIFRPP', 'bank_holder' => 'Titulaire Banque'];

    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private Connection $db;
    /** @var array<string, string> */
    private array $saved = [];

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $container = static::getContainer();
        $this->db = $container->get(Connection::class);
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['signature', 'quote_line', 'quote_version', 'quote_request', 'stat_event'] as $table) {
            $this->db->executeStatement("TRUNCATE TABLE $table");
        }
        $this->db->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        foreach (self::SETTINGS as $name => $value) {
            $this->saved[$name] = (string) $this->db->fetchOne('SELECT value FROM setting WHERE name = ?', [$name]);
            $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
        }
        $container->get('cache.rate_limiter_dbal')->clear();
        (new Filesystem())->mkdir($container->getParameter('app.upload_dir'));
        $this->em = $container->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
        }
        parent::tearDown();
    }

    public function testNewQuotePageShowsStatusRecapAndPaperReturn(): void
    {
        $quote = $this->quote();
        $crawler = $this->client->request('GET', '/devis/'.$quote->getToken());

        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertSame('noindex', $response->headers->get('X-Robots-Tag'));
        self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));

        self::assertSelectorTextSame('h1', 'Devis SDDEV-202610001');
        self::assertSelectorTextSame('.quote-link .label-caps', 'Sous réserve de disponibilité');
        self::assertSelectorTextSame('.quote-link__status', 'Statut : En cours de vérification par Serife');
        $text = $crawler->filter('main')->text();
        self::assertStringContainsString('Imprimez le devis, signez-le, puis envoyez-en une photo à Serife sur WhatsApp, avec votre acompte une fois le devis validé.', $text);
        self::assertStringContainsString('Retrait au dépôt Le '.(new \DateTimeImmutable('today +20 days'))->format('d/m/Y').', à partir de 9 h 30', $text);
        self::assertSame(["Chaise Napoléon × 100 350,00\u{A0}€", "Total 350,00\u{A0}€", "Acompte 50 % 175,00\u{A0}€", "Reste à payer 175,00\u{A0}€"], $this->rows($crawler));
        self::assertSame('/devis/'.$quote->getToken().'/pdf', $crawler->filter('a.button-primary')->attr('href'));
        self::assertSame('Télécharger mon devis (PDF)', $crawler->filter('a.button-primary')->text());
        self::assertStringStartsWith('https://wa.me/', $crawler->filter('.quote-link__actions a')->first()->attr('href'));
        // État `nouvelle` : pas de signature en ligne, aucune coordonnée bancaire
        self::assertCount(0, $crawler->filterXPath('//a[normalize-space()="Signer en ligne"]'));
        foreach ([self::IBAN, 'AGRIFRPP', 'Titulaire Banque', 'IBAN', 'virement'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, (string) $response->getContent());
        }
        // Le numéro n'est jamais dans l'URL ; la page n'est pas dans le sitemap
        $this->client->request('GET', '/sitemap.xml');
        self::assertStringNotContainsString('/devis/', (string) $this->client->getResponse()->getContent());
    }

    public function testUnknownMalformedOrDeletedTokenGivesTheSameNeutral404(): void
    {
        $quote = $this->quote();
        $token = $quote->getToken();
        $id = $quote->getId();
        $this->db->executeStatement('DELETE FROM quote_request WHERE id = ?', [$id]);

        foreach ([$token, str_repeat('0', 32), 'pas-un-jeton', (string) $id, 'SDDEV-202610001', $token.'/pdf', $token.'/signer', 'abc/pdf'] as $path) {
            $crawler = $this->client->request('GET', '/devis/'.$path);
            self::assertResponseStatusCodeSame(404, $path);
            self::assertSame('Ce devis n\'est plus disponible.', $crawler->filter('h1')->text());
            self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
            self::assertSame('noindex', $crawler->filter('meta[name="robots"]')->attr('content'));
            self::assertCount(1, $crawler->filter('main .contact-buttons a[href^="https://wa.me/"]'));
            self::assertCount(1, $crawler->filter('main .contact-buttons a[href^="tel:"]'));
        }
    }

    public function testTokenGuessingIsRateLimited(): void
    {
        $quote = $this->quote();
        $this->client->request('GET', '/devis/'.$quote->getToken());
        self::assertResponseIsSuccessful();
        for ($i = 1; $i < 30; ++$i) {
            $this->client->request('GET', '/devis/'.bin2hex(random_bytes(16)));
            self::assertResponseStatusCodeSame(404);
        }
        // 31ᵉ essai dans la minute : refusé, même avec le bon jeton
        $this->client->request('GET', '/devis/'.$quote->getToken());
        self::assertResponseStatusCodeSame(429);
        self::assertSelectorTextSame('h1', 'Trop d\'essais. Réessayez dans une minute.');
    }

    public function testNewQuoteCannotBeSignedOnline(): void
    {
        $quote = $this->quote();
        $this->client->request('GET', '/devis/'.$quote->getToken().'/signer');
        self::assertResponseRedirects('/devis/'.$quote->getToken(), 303);
        $this->client->request('POST', '/devis/'.$quote->getToken().'/signer', ['signature' => ['versionId' => (string) $quote->getCurrentVersion()->getId(), 'image' => $this->png(), 'acceptTerms' => '1']]);
        self::assertResponseRedirects('/devis/'.$quote->getToken(), 303);
        self::assertSame(0, $this->signatures());
    }

    public function testValidatedQuoteIsSignedOnceWithStoredSignedPdf(): void
    {
        $quote = $this->quote(validated: true);
        $crawler = $this->client->request('GET', '/devis/'.$quote->getToken());
        self::assertSelectorTextSame('.quote-link__status', 'Statut : Validé par Serife');
        // Papier d'abord, « Signer en ligne » en bouton secondaire
        $actions = $crawler->filter('.quote-link__actions a');
        self::assertSame(['Envoyer sur WhatsApp (nouvel onglet)', 'Signer en ligne'], $actions->each(fn (Crawler $a) => $a->text()));
        self::assertSame('button-secondary', $actions->last()->attr('class'));
        self::assertCount(0, $crawler->filter('.quote-link .label-caps'));

        $crawler = $this->client->click($actions->last()->link());
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $form = $crawler->selectButton('Signer mon devis')->form();
        self::assertSame((string) $quote->getCurrentVersion()->getId(), $form['signature[versionId]']->getValue());
        $form['signature[image]']->setValue($this->png());
        $form['signature[acceptTerms]']->tick();
        $before = new \DateTimeImmutable('-1 second');
        $this->client->submit($form, [], ['REMOTE_ADDR' => '203.0.113.7']);
        self::assertResponseRedirects('/devis/'.$quote->getToken(), 303);

        $signature = $this->em->getRepository(Signature::class)->findOneBy([]);
        self::assertNotNull($signature);
        self::assertSame(Signature::TYPE_ONLINE, $signature->getType());
        self::assertSame('203.0.113.7', $signature->getIp());
        self::assertSame($quote->getCurrentVersion()->getId(), $signature->getVersion()->getId());
        self::assertGreaterThanOrEqual($before, $signature->getSignedAt());
        self::assertSame(substr($this->png(), \strlen('data:image/png;base64,')), $signature->getImage());
        // PDF signé hors de public/, SHA-256 du fichier
        $file = static::getContainer()->getParameter('app.upload_dir').'/'.$signature->getPdfPath();
        self::assertFileExists($file);
        self::assertStringNotContainsString('/public/', realpath($file));
        self::assertSame(hash_file('sha256', $file), $signature->getPdfSha256());
        self::assertStringStartsWith('%PDF-', (string) file_get_contents($file));

        // Page : signé, plus de bouton, PDF signé à télécharger
        $crawler = $this->client->request('GET', '/devis/'.$quote->getToken());
        self::assertSelectorTextSame('.quote-link__signed', 'Devis signé le '.$signature->getSignedAt()->format('d/m/Y \à G \h i'));
        self::assertCount(0, $crawler->filterXPath('//a[normalize-space()="Signer en ligne"]'));
        self::assertSame('Télécharger mon devis signé (PDF)', $crawler->filter('a.button-primary')->text());
        $this->client->request('GET', '/devis/'.$quote->getToken().'/pdf');
        self::assertResponseIsSuccessful();
        $response = $this->client->getResponse();
        self::assertSame('attachment; filename=SDDEV-202610001-signe.pdf', $response->headers->get('Content-Disposition'));
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame($signature->getPdfSha256(), hash_file('sha256', $file));

        // Second envoi refusé : rien de plus
        $this->client->request('GET', '/devis/'.$quote->getToken().'/signer');
        self::assertResponseRedirects('/devis/'.$quote->getToken(), 303);
        $this->client->request('POST', '/devis/'.$quote->getToken().'/signer', ['signature' => ['versionId' => (string) $quote->getCurrentVersion()->getId(), 'image' => $this->png(), 'acceptTerms' => '1']]);
        $this->client->followRedirect();
        self::assertSelectorTextSame('.quote-link__notice', QuoteLinkController::ALREADY_SIGNED);
        self::assertSame(1, $this->signatures());
        unlink($file);
    }

    public function testEmptyTraceOrUncheckedBoxShowsInlineErrors(): void
    {
        $quote = $this->quote(validated: true);
        $url = '/devis/'.$quote->getToken().'/signer';

        foreach ([['', false], ['data:image/png;base64,'.base64_encode('pas une image'), true], [$this->png(), false]] as [$image, $checked]) {
            $crawler = $this->client->request('GET', $url);
            $form = $crawler->selectButton('Signer mon devis')->form();
            $form['signature[image]']->setValue($image);
            $checked ? $form['signature[acceptTerms]']->tick() : $form['signature[acceptTerms]']->untick();
            $crawler = $this->client->submit($form);
            self::assertResponseStatusCodeSame(422);

            if ($this->png() !== $image) {
                self::assertSame(QuoteSignatureType::IMAGE_MISSING, $crawler->filter('#signature-erreur')->text());
                self::assertSame('true', $crawler->filter('canvas')->attr('aria-invalid'));
                self::assertStringContainsString('signature-erreur', $crawler->filter('canvas')->attr('aria-describedby'));
            }
            if (!$checked) {
                self::assertSame(QuoteSignatureType::TERMS_MISSING, $crawler->filter('#signature_acceptTerms-erreur')->text());
                self::assertSame('true', $crawler->filter('#signature_acceptTerms')->attr('aria-invalid'));
                self::assertSame('signature_acceptTerms-erreur', $crawler->filter('#signature_acceptTerms')->attr('aria-describedby'));
            }
        }
        self::assertSame(0, $this->signatures());
    }

    public function testOutdatedVersionIsRefused(): void
    {
        $quote = $this->quote(validated: true);
        $crawler = $this->client->request('GET', '/devis/'.$quote->getToken().'/signer');
        $form = $crawler->selectButton('Signer mon devis')->form();
        $form['signature[image]']->setValue($this->png());
        $form['signature[acceptTerms]']->tick();

        // Serife crée une nouvelle version entre l'ouverture et la validation
        $quote = $this->em->getRepository(QuoteRequest::class)->find($quote->getId());
        $this->version($quote, 2, 40000);
        $this->em->flush();

        $this->client->submit($form);
        self::assertResponseRedirects('/devis/'.$quote->getToken(), 303);
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextSame('.quote-link__notice', QuoteLinkController::UPDATED);
        self::assertSame('alert', $crawler->filter('.quote-link__notice')->attr('role'));
        self::assertContains("Total 400,00\u{A0}€", $this->rows($crawler));
        self::assertSame(0, $this->signatures());
    }

    private function quote(bool $validated = false): QuoteRequest
    {
        $quote = new QuoteRequest();
        $quote->setFirstName('Inès');
        $quote->setLastName('Martin');
        $quote->setPhone('06 12 34 56 78');
        $quote->setEmail('ines@example.com');
        $quote->acceptConsents(new \DateTimeImmutable());
        $quote->assignNumber('SDDEV-202610001');
        $this->version($quote, 1, 35000);
        $this->em->persist($quote);
        $workflow = static::getContainer()->get('state_machine.quote_request');
        \assert($workflow instanceof WorkflowInterface);
        $workflow->getMarking($quote);
        if ($validated) {
            $workflow->apply($quote, 'valider');
        }
        $this->em->flush();

        return $quote;
    }

    private function version(QuoteRequest $quote, int $number, int $total): void
    {
        $day = new \DateTimeImmutable('today +20 days');
        $version = new QuoteVersion($quote, $number, $day, $day->setTime(9, 30), $day->modify('+1 day')->setTime(12, 0), QuoteVersion::MODE_PICKUP, false, false, false, null, ['depositBasisPoints' => 5000, 'depositAmount' => 30000], $total, intdiv($total, 2), false);
        $line = new QuoteLine($version, 1, QuoteLineType::Product, 'Chaise Napoléon', $total, 100, intdiv($total, 100));
        $version->addLine($line);
        $quote->addVersion($version);
        $this->em->persist($version);
        $this->em->persist($line);
    }

    /** Tracé PNG tel que l'envoie le canvas. */
    private function png(): string
    {
        static $png;
        if (null === $png) {
            $image = imagecreatetruecolor(300, 100);
            imageline($image, 10, 50, 290, 60, imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagepng($image);
            $png = 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
        }

        return $png;
    }

    /** @return list<string> lignes du récapitulatif, « libellé montant » */
    private function rows(Crawler $crawler): array
    {
        return $crawler->filter('.quote-summary__row')->each(fn (Crawler $r) => $r->filter('dt')->text().' '.$r->filter('dd')->text());
    }

    private function signatures(): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) FROM signature');
    }
}
