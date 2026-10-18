<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\HomeHero;
use App\Entity\PriceTier;
use App\Entity\Product;
use Doctrine\DBAL\Connection;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Stories 2.9 + 2.10 côté admin : Haut de l'accueil (brouillon, aperçu, publier), Les incontournables, L'entreprise. */
final class HomeAdminTest extends CatalogTestCase
{
    private string $tmp;
    private string $uploads;
    private Connection $db;
    /** @var array<string, string> */
    private array $settings;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/seydeco-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
        $this->uploads = static::getContainer()->getParameter('app.upload_dir');
        $this->db = static::getContainer()->get(Connection::class);
        $this->settings = $this->db->fetchAllKeyValue('SELECT name, value FROM setting');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*'));
        rmdir($this->tmp);
        foreach ($this->settings as $name => $value) {
            $this->db->executeStatement('UPDATE setting SET value = ? WHERE name = ?', [$value, $name]);
        }
        parent::tearDown();
    }

    private function image(string $name): UploadedFile
    {
        imagejpeg(imagecreatetruecolor(20, 20), "$this->tmp/$name");

        return new UploadedFile("$this->tmp/$name", $name, null, null, true);
    }

    /**
     * @param array<string, mixed> $values
     * @param array<string, mixed> $files
     */
    private function postHero(array $values, array $files = []): Crawler
    {
        $form = $this->client->request('GET', '/admin/haut-accueil')->selectButton('Enregistrer le brouillon')->form()->getPhpValues();
        $form['home_hero'] = $values + $form['home_hero'];

        return $this->client->request('POST', '/admin/haut-accueil', $form, $files ? ['home_hero' => $files] : []);
    }

    private function button(string $label): void
    {
        $this->client->submit($this->client->request('GET', '/admin/haut-accueil')->selectButton($label)->form());
    }

    private function hero(bool $draft): ?HomeHero
    {
        return $this->em()->getRepository(HomeHero::class)->findOneBy(['draft' => $draft]);
    }

    private function product(string $name): Product
    {
        $em = $this->em();
        $category = $em->getRepository(Category::class)->findOneBy([]) ?? $em->find(Category::class, $this->createCategory('Chaises', 1)->getId());
        $product = new Product();
        $product->setName($name);
        $product->initSlug((string) static::getContainer()->get('slugger')->slug($name)->lower());
        $product->setCategory($category);
        $product->addPriceTier(new PriceTier(1, 300));
        $em->persist($product);
        $em->flush();

        return $product;
    }

    private function feature(Product $product): void
    {
        $this->client->request('GET', '/admin/produits/'.$product->getId().'/modifier');
        $this->client->submitForm('Enregistrer', ['product[featured]' => true]);
    }

    public function testReachedFromMoreContents(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        self::assertSame('/admin/haut-accueil', $crawler->selectLink('Haut de l\'accueil')->attr('href'));
        self::assertSame('/admin/incontournables', $crawler->selectLink('Les incontournables')->attr('href'));
        self::assertSame('/admin/entreprise', $crawler->selectLink('L\'entreprise')->attr('href'));

        foreach (['/admin/haut-accueil' => 'Haut de l\'accueil', '/admin/incontournables' => 'Les incontournables', '/admin/entreprise' => 'L\'entreprise'] as $url => $title) {
            $this->client->request('GET', $url);
            self::assertSelectorTextSame('h1', $title);
            self::assertSelectorExists('.admin-nav a[aria-current="page"][href="/admin/plus"]');
        }
        // Textes par défaut proposés
        $crawler = $this->client->request('GET', '/admin/haut-accueil');
        self::assertSame(['Location de matériel de réception', 'Voir le catalogue', '/location'], [
            $crawler->filter('#home_hero_title')->attr('value'), $crawler->filter('#home_hero_buttonLabel')->attr('value'), $crawler->filter('#home_hero_buttonLink')->attr('value'),
        ]);
    }

    public function testInvalidFieldsRefusedUnderTheField(): void
    {
        foreach (['https://exemple.com/promo', '//exemple.com', '/\\exemple.com', 'location'] as $link) {
            $crawler = $this->postHero(['title' => str_repeat('a', 61), 'text' => str_repeat('b', 161), 'buttonLabel' => ' ', 'buttonLink' => $link, 'photoAlt' => '']);

            self::assertResponseStatusCodeSame(422);
            $error = fn (string $field) => $crawler->filter("#home_hero_$field")->ancestors()->filter('.admin-field')->first()->filter('.admin-field__error')->text('');
            self::assertSame('Le titre ne doit pas dépasser 60 caractères.', $error('title'));
            self::assertSame('Le texte ne doit pas dépasser 160 caractères.', $error('text'));
            self::assertSame('Saisir le texte du bouton.', $error('buttonLabel'));
            self::assertStringContainsString('pas de lien vers un autre site', $error('buttonLink'), $link);
            self::assertSame('Choisir une photo.', $error('photoUpload'));
            self::assertStringStartsWith('Décrivez la photo', $error('photoAlt'));
        }
        self::assertNull($this->hero(draft: true));
    }

    public function testDraftPreviewPublishAndCancel(): void
    {
        $this->postHero(['title' => 'Mariages d\'été', 'photoAlt' => 'Table d\'honneur'], ['photoUpload' => $this->image('a.jpg')]);
        self::assertResponseRedirects('/admin/haut-accueil');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-notice', 'Brouillon enregistré');
        self::assertSelectorTextSame('#s-draft', 'Brouillon en cours');
        $draft = $this->hero(draft: true);
        self::assertSame('Mariages d\'été', $draft->getTitle());
        self::assertFileExists($this->uploads.'/'.$draft->getPhoto());

        // Le site public garde la version publiée (textes par défaut)
        $this->client->request('GET', '/');
        self::assertSelectorTextSame('h1', 'Location de matériel de réception');

        // Aperçu : accueil complet avec le brouillon, non indexé, et version largeur mobile
        $this->client->click($crawler->selectLink('Aperçu')->link());
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Mariages d\'été');
        self::assertSelectorExists('.preview-banner');
        self::assertSelectorExists('meta[name="robots"][content="noindex"]');
        self::assertSelectorExists('.dark-band');
        $this->client->click($crawler->selectLink('Aperçu sur mobile')->link());
        self::assertSame('/admin/haut-accueil/apercu', $this->client->getCrawler()->filter('iframe')->attr('src'));

        $this->button('Publier');
        self::assertResponseRedirects('/admin/haut-accueil');
        self::assertNull($this->hero(draft: true));
        $published = $this->hero(draft: false);
        self::assertSame('Mariages d\'été', $published->getTitle());
        $this->client->request('GET', '/');
        self::assertSelectorTextSame('h1', 'Mariages d\'été');
        self::assertSelectorExists('.hero__photos img[alt="Table d\'honneur"]');

        // Nouveau brouillon : la photo publiée est partagée ; une photo ajoutée puis abandonnée est effacée
        $this->postHero(['title' => 'Noël', 'photo2Alt' => 'Sapin'], ['photo2Upload' => $this->image('b.jpg')]);
        $draft = $this->hero(draft: true);
        self::assertSame($published->getPhoto(), $draft->getPhoto());
        $this->button('Annuler le brouillon');
        self::assertNull($this->hero(draft: true));
        self::assertFileDoesNotExist($this->uploads.'/'.$draft->getPhoto2());
        self::assertFileExists($this->uploads.'/'.$published->getPhoto());
        $this->client->request('GET', '/');
        self::assertSelectorTextSame('h1', 'Mariages d\'été');

        // Photo remplacée dans le brouillon : l'ancienne reste tant que la version publiée s'en sert, puis part à la publication
        $this->postHero([], ['photoUpload' => $this->image('c.jpg')]);
        self::assertFileExists($this->uploads.'/'.$published->getPhoto());
        $this->button('Publier');
        self::assertFileDoesNotExist($this->uploads.'/'.$published->getPhoto());
        self::assertFileExists($this->uploads.'/'.$this->hero(draft: false)->getPhoto());
    }

    public function testPublishRefusedWithoutValidToken(): void
    {
        $this->postHero(['title' => 'Noël', 'photoAlt' => 'Sapin'], ['photoUpload' => $this->image('a.jpg')]);
        $this->client->request('POST', '/admin/haut-accueil/publier', ['_token' => 'faux']);
        $this->client->request('POST', '/admin/haut-accueil/annuler', ['_token' => 'faux']);

        self::assertNotNull($this->hero(draft: true));
        self::assertNull($this->hero(draft: false));
    }

    public function testFeaturedProductsEightMaxOrderedAndRemoved(): void
    {
        $products = array_map(fn (int $i) => $this->product("Produit $i"), range(1, 9));
        foreach (\array_slice($products, 0, 8) as $product) {
            $this->feature($product);
            self::assertResponseRedirects('/admin/produits');
        }
        $crawler = $this->client->request('GET', '/admin/produits/'.$products[8]->getId().'/modifier');
        $crawler = $this->client->submitForm('Enregistrer', ['product[featured]' => true]);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('8 produits sont déjà mis en avant', $crawler->filter('#product_featured')->ancestors()->first()->text());

        $names = fn () => $this->client->request('GET', '/admin/incontournables')->filter('.admin-list__name')->each(fn (Crawler $n) => $n->text());
        self::assertSame(array_map(fn (int $i) => "Produit $i", range(1, 8)), $names());
        $this->client->submit($this->client->getCrawler()->filter('button[aria-label="Monter Produit 2"]')->form());
        self::assertSame(['Produit 2', 'Produit 1'], \array_slice($names(), 0, 2));
        $this->client->submit($this->client->getCrawler()->filter('button[aria-label="Retirer de l\'accueil Produit 2"]')->form());
        self::assertResponseRedirects('/admin/incontournables');
        self::assertSame('Produit 1', $names()[0]);
        self::assertCount(7, $names());
        // Toujours au catalogue, et une place s'est libérée
        $this->feature($products[8]);
        self::assertResponseRedirects('/admin/produits');
        self::assertSame('Produit 9', $names()[7]);

        // Jeton invalide : rien ne bouge
        $this->client->request('POST', '/admin/incontournables/'.$products[0]->getId().'/retirer', ['_token' => 'faux']);
        self::assertCount(8, $names());
    }

    public function testCompanyTextAndFoundedYear(): void
    {
        $this->client->request('GET', '/admin/entreprise');
        $this->client->submitForm('Enregistrer', ['company_settings[company_text]' => ' Seydeco, à **Bassens**. ']);
        self::assertResponseRedirects('/admin/entreprise');
        self::assertSame('Seydeco, à **Bassens**.', $this->db->fetchOne("SELECT value FROM setting WHERE name = 'company_text'"));

        $this->client->request('GET', '/admin/coordonnees');
        $crawler = $this->client->submitForm('Enregistrer', ['contact_settings[founded_year]' => '26']);
        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Saisir une année', $crawler->filter('#contact_settings_founded_year')->ancestors()->first()->text());
        $this->client->submitForm('Enregistrer', ['contact_settings[founded_year]' => '2025']);
        self::assertResponseRedirects('/admin/coordonnees');

        $crawler = $this->client->request('GET', '/');
        self::assertSame('Bassens', $crawler->filter('.company-box strong')->text());
        self::assertSame('Depuis 2025', $crawler->filter('.commitments__list h3')->first()->text());
    }
}
