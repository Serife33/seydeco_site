<?php

namespace App\Tests\Functional\Admin;

use App\Entity\EventType;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\Realisation;
use App\Entity\Venue;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Stories 2.5 + 2.6 côté admin : Plus → Contenus → Réalisations et Lieux. */
final class RealisationAdminTest extends CatalogTestCase
{
    private string $tmp;
    private int $chairId;
    private int $mariageId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/seydeco-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $em = $this->em();
        $product = new Product();
        $product->setName('Chaise Napoléon');
        $product->initSlug('chaise-napoleon');
        $product->setCategory($em->find(\App\Entity\Category::class, $this->createCategory('Chaises', 1, PricingType::Chairs)->getId()));
        $product->addPriceTier(new PriceTier(1, 300));
        $mariage = new EventType('Mariage');
        $em->persist($product);
        $em->persist($mariage);
        $em->flush();
        $this->chairId = $product->getId();
        $this->mariageId = $mariage->getId();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*'));
        rmdir($this->tmp);
        parent::tearDown();
    }

    private function image(string $name = 'photo.jpg'): UploadedFile
    {
        $path = "$this->tmp/$name";
        imagejpeg(imagecreatetruecolor(20, 20), $path);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * Soumet le formulaire réalisation (création, ou modification si $id) ; les clés de $realisation remplacent celles du formulaire.
     *
     * @param array<string, mixed> $realisation
     * @param array<string, mixed> $files
     */
    private function postRealisation(array $realisation, array $files = [], ?int $id = null): Crawler
    {
        $uri = $id ? "/admin/realisations/$id/modifier" : '/admin/realisations/ajouter';
        $values = $this->client->request('GET', $uri)->selectButton('Enregistrer')->form()->getPhpValues();
        $defaults = $id ? [] : [
            'title' => 'Mariage de Léa et Karim',
            'eventDate' => '2026-06-13',
            'eventType' => (string) $this->mariageId,
            'venueName' => 'Domaine des Tilleuls',
            'city' => 'Libourne',
            'text' => 'Une table d\'honneur blanche et or.',
        ];
        $values['realisation'] = $realisation + $defaults + $values['realisation'];

        return $this->client->request('POST', $uri, $values, $files ? ['realisation' => $files] : []);
    }

    /** @param array<string, mixed> $venue */
    private function postVenue(array $venue, bool $photo = true, ?int $id = null): void
    {
        $uri = $id ? "/admin/lieux/$id/modifier" : '/admin/lieux/ajouter';
        $values = $this->client->request('GET', $uri)->selectButton('Enregistrer')->form()->getPhpValues();
        $defaults = $id ? [] : ['name' => 'Château de la Rivière', 'city' => 'Fronsac', 'photoAlt' => 'Façade du château au coucher du soleil'];
        $values['venue'] = $venue + $defaults + $values['venue'];
        $this->client->request('POST', $uri, $values, $photo ? ['venue' => ['photoUpload' => $this->image('lieu.jpg')]] : []);
    }

    private function realisation(): Realisation
    {
        return $this->em()->getRepository(Realisation::class)->findOneBy([]);
    }

    private function venue(): Venue
    {
        return $this->em()->getRepository(Venue::class)->findOneBy([]);
    }

    public function testReachedFromMoreContents(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        self::assertSame('/admin/realisations', $crawler->selectLink('Réalisations')->attr('href'));
        self::assertSame('/admin/lieux', $crawler->selectLink('Lieux')->attr('href'));

        $this->client->request('GET', '/admin/realisations');
        self::assertSelectorTextSame('h1', 'Réalisations');
        self::assertSelectorTextContains('main', 'Aucune réalisation.');
        self::assertSame('page', $this->client->getCrawler()->filter('.admin-nav a')->last()->attr('aria-current'));
        $this->client->request('GET', '/admin/lieux');
        self::assertSelectorTextContains('main', 'Aucun lieu.');
    }

    public function testCreateRealisationWithPhotosVideoAndProducts(): void
    {
        $this->postRealisation([
            'photos' => [['alt' => 'Table d\'honneur', 'position' => '1'], ['alt' => 'Vue de la salle', 'position' => '0']],
            'videoUrl' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'products' => [(string) $this->chairId, ''],
        ], ['photos' => [['file' => $this->image('a.jpg')], ['file' => $this->image('b.jpg')]]]);

        self::assertResponseRedirects('/admin/realisations');
        $r = $this->realisation();
        self::assertSame(
            ['Mariage de Léa et Karim', 'mariage-de-lea-et-karim', '2026-06-13', 'Mariage', 'Domaine des Tilleuls', 'Libourne', 1, true, 'https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            [$r->getTitle(), $r->getSlug(), $r->getEventDate()->format('Y-m-d'), $r->getEventType()->getName(), $r->getVenueName(), $r->getCity(), $r->getPosition(), $r->isVisible(), $r->getVideoUrl()],
        );
        // Ordre choisi : la première photo est la principale ; fichiers rangés hors de public/
        self::assertSame(['Vue de la salle', 'Table d\'honneur'], $r->getPhotos()->map(fn ($p) => $p->getAlt())->getValues());
        self::assertFileExists(static::getContainer()->getParameter('app.upload_dir').'/'.$r->getPhotos()->first()->getFilename());
        // Ligne laissée sur « Choisir… » ignorée
        self::assertSame(['Chaise Napoléon'], $r->getProducts()->map(fn (Product $p) => $p->getName())->getValues());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-notice', 'Réalisation enregistrée.');
        self::assertSame('13/06/2026 · Domaine des Tilleuls, Libourne', $crawler->filter('.admin-list__meta')->text());

        // Titre modifié : adresse figée
        $this->postRealisation(['title' => 'Mariage au domaine'], id: $r->getId());
        self::assertResponseRedirects('/admin/realisations');
        self::assertSame(['Mariage au domaine', 'mariage-de-lea-et-karim'], [$this->realisation()->getTitle(), $this->realisation()->getSlug()]);
    }

    public function testPhotoWithoutAltIsRefusedAndOtherInputsKept(): void
    {
        $crawler = $this->postRealisation(['photos' => [['alt' => ' ', 'position' => '0']]], ['photos' => [['file' => $this->image()]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#realisation_photos_0_alt_error1', 'Décrivez la photo');
        self::assertSame(['Mariage de Léa et Karim', 'Libourne'], [$crawler->filter('#realisation_title')->attr('value'), $crawler->filter('#realisation_city')->attr('value')]);
        self::assertSame(0, $this->em()->getRepository(Realisation::class)->count());
    }

    /** @return iterable<string, array{array<string, mixed>, string, string}> */
    public static function invalidRealisations(): iterable
    {
        yield 'sans titre' => [['title' => ''], '#realisation_title_error1', 'Saisir un titre.'];
        yield 'sans date' => [['eventDate' => ''], '#realisation_eventDate_error1', 'Saisir la date de l\'événement.'];
        yield 'sans lieu' => [['venueName' => ''], '#realisation_venueName_error1', 'Saisir le nom du lieu, ou choisir un lieu partenaire.'];
        yield 'sans ville' => [['city' => ''], '#realisation_city_error1', 'Saisir la ville, ou choisir un lieu partenaire.'];
        yield 'lien vidéo d\'un autre site' => [['videoUrl' => 'https://vimeo.com/123'], '#realisation_videoUrl_error1', 'Seuls les liens YouTube, Instagram ou TikTok sont acceptés (https://…).'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidRealisations')]
    public function testInvalidRealisationIsRefused(array $override, string $errorId, string $message): void
    {
        $this->postRealisation($override);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame($errorId, $message);
        self::assertSame(0, $this->em()->getRepository(Realisation::class)->count());
    }

    public function testPartnerVenueFillsNameAndCity(): void
    {
        $this->postVenue([]);
        $venue = $this->venue();

        $this->postRealisation(['venue' => (string) $venue->getId(), 'venueName' => '', 'city' => '']);

        self::assertResponseRedirects('/admin/realisations');
        $r = $this->realisation();
        self::assertSame([$venue->getId(), 'Château de la Rivière', 'Fronsac'], [$r->getVenue()->getId(), $r->getVenueName(), $r->getCity()]);
    }

    public function testCreateVenue(): void
    {
        $this->postVenue(['address' => '1 allée du Château', 'text' => 'Un parc de 3 hectares.', 'website' => 'https://www.chateau-riviere.fr']);

        self::assertResponseRedirects('/admin/lieux');
        $v = $this->venue();
        self::assertSame(
            ['Château de la Rivière', 'chateau-de-la-riviere', 'Fronsac', '1 allée du Château', 'Un parc de 3 hectares.', 'https://www.chateau-riviere.fr', 'Façade du château au coucher du soleil', 1, true],
            [$v->getName(), $v->getSlug(), $v->getCity(), $v->getAddress(), $v->getText(), $v->getWebsite(), $v->getPhotoAlt(), $v->getPosition(), $v->isVisible()],
        );
        self::assertFileExists(static::getContainer()->getParameter('app.upload_dir').'/'.$v->getPhoto());

        $this->postVenue(['name' => 'Château Rivière'], photo: false, id: $v->getId());
        self::assertSame(['Château Rivière', 'chateau-de-la-riviere', $v->getPhoto()], [$this->venue()->getName(), $this->venue()->getSlug(), $this->venue()->getPhoto()]);
    }

    /** @return iterable<string, array{array<string, mixed>, bool, string, string}> */
    public static function invalidVenues(): iterable
    {
        yield 'sans nom' => [['name' => ''], true, '#venue_name_error1', 'Saisir un nom.'];
        yield 'sans ville' => [['city' => ''], true, '#venue_city_error1', 'Saisir la ville.'];
        yield 'sans photo' => [[], false, '#venue_photoUpload_error1', 'Choisir une photo.'];
        yield 'sans texte alternatif' => [['photoAlt' => ''], true, '#venue_photoAlt_error1', 'Décrivez la photo (ex. : salle de réception du château).'];
        yield 'lien invalide' => [['website' => 'chateau'], true, '#venue_website_error1', 'Saisir une adresse complète, par exemple https://www.chateau-exemple.fr.'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidVenues')]
    public function testInvalidVenueIsRefused(array $override, bool $photo, string $errorId, string $message): void
    {
        $this->postVenue($override, $photo);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame($errorId, $message);
        self::assertSame(0, $this->em()->getRepository(Venue::class)->count());
    }

    public function testHideShowAndReorderWithoutDelete(): void
    {
        foreach (['/admin/realisations' => ['title', 'Réalisation'], '/admin/lieux' => ['name', 'Lieu']] as $list => [$field, $label]) {
            $isVenue = '/admin/lieux' === $list;
            $isVenue ? $this->postVenue([$field => "$label A"]) : $this->postRealisation([$field => "$label A"]);
            $isVenue ? $this->postVenue([$field => "$label B"]) : $this->postRealisation([$field => "$label B"]);
            $b = $this->em()->getRepository($isVenue ? Venue::class : Realisation::class)->findOneBy([$field => "$label B"]);
            $token = fn () => $this->client->request('GET', $list)->filter("form[action=\"$list/{$b->getId()}/visibilite\"] input[name=\"_token\"]")->attr('value');

            $this->client->request('POST', "$list/{$b->getId()}/monter", ['_token' => $token(), 'page' => '1']);
            self::assertResponseRedirects("$list?page=1");
            $crawler = $this->client->followRedirect();
            self::assertSame(["$label B", "$label A"], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));

            $this->client->request('POST', "$list/{$b->getId()}/visibilite", ['_token' => $token()]);
            $crawler = $this->client->followRedirect();
            self::assertStringContainsString('Masqué', $crawler->filter('.admin-list__meta')->first()->text());
            self::assertSame("Afficher à nouveau $label B", $crawler->filter('.admin-list__row')->first()->filter('button')->last()->attr('aria-label'));

            // Jeton invalide : rien ne change
            $this->client->request('POST', "$list/{$b->getId()}/visibilite", ['_token' => 'faux']);
            self::assertFalse($this->em()->find($b::class, $b->getId())->isVisible());

            $this->client->request('POST', "$list/{$b->getId()}/visibilite", ['_token' => $token()]);
            self::assertTrue($this->em()->find($b::class, $b->getId())->isVisible());
            self::assertStringNotContainsString('Supprimer', (string) $this->client->request('GET', "$list/{$b->getId()}/modifier")->html());
        }
        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            self::assertStringNotContainsString('supprimer', $route->getPath(), $name);
        }
    }
}
