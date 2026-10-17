<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\Pack;
use App\Entity\PackItem;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Story 1.14 côté admin : Plus → Contenus → Packs. */
final class PackTest extends CatalogTestCase
{
    private int $chairId;
    private int $tableId;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $chairs = $this->createCategory('Chaises', 1, PricingType::Chairs);
        $tables = $this->createCategory('Tables', 2, PricingType::Tables);
        $this->chairId = $this->createProduct($chairs, 'Chaise Napoléon', 15);
        $this->tableId = $this->createProduct($tables, 'Table ronde', 1);
        $this->tmp = sys_get_temp_dir().'/seydeco-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*'));
        rmdir($this->tmp);
        parent::tearDown();
    }

    private function createProduct(Category $category, string $name, int $minQty): int
    {
        $em = $this->em();
        $product = new Product();
        $product->setName($name);
        $product->initSlug(mb_strtolower(str_replace(' ', '-', $name)));
        $product->setCategory($em->find(Category::class, $category->getId()));
        $product->setMinQty($minQty);
        $product->addPriceTier(new PriceTier($minQty, 300));
        $em->persist($product);
        $em->flush();

        return $product->getId();
    }

    private function image(): UploadedFile
    {
        $path = "$this->tmp/pack.jpg";
        imagejpeg(imagecreatetruecolor(20, 20), $path);

        return new UploadedFile($path, 'pack.jpg', null, null, true);
    }

    /**
     * Soumet le formulaire pack (création, ou modification si $id) ; les clés de $pack remplacent celles du formulaire.
     *
     * @param array<string, mixed> $pack
     */
    private function post(array $pack, bool $photo = true, ?int $id = null): void
    {
        $uri = $id ? "/admin/packs/$id/modifier" : '/admin/packs/ajouter';
        $values = $this->client->request('GET', $uri)->selectButton('Enregistrer')->form()->getPhpValues();
        $defaults = $id ? [] : [
            'name' => 'Pack 100 invités',
            'photoAlt' => 'Tables dressées pour 100 invités',
            'items' => [
                ['product' => (string) $this->chairId, 'quantity' => '100'],
                ['product' => (string) $this->tableId, 'quantity' => '12'],
            ],
        ];
        $values['pack'] = $pack + $defaults + $values['pack'];
        $this->client->request('POST', $uri, $values, $photo ? ['pack' => ['photoUpload' => $this->image()]] : []);
    }

    private function pack(): Pack
    {
        return $this->em()->getRepository(Pack::class)->findOneBy([]);
    }

    public function testPacksAreReachedFromMoreContents(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        self::assertSame('Contenus', $crawler->filter('h2.admin-title')->text());
        self::assertSame('/admin/packs', $crawler->selectLink('Packs')->attr('href'));

        $this->client->request('GET', '/admin/packs');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextSame('h1', 'Packs');
        self::assertSelectorTextContains('main', 'Aucun pack.');
    }

    public function testCreatePack(): void
    {
        $this->post(['description' => 'Pour une réception de 100 personnes.']);

        self::assertResponseRedirects('/admin/packs');
        $pack = $this->pack();
        self::assertSame(['Pack 100 invités', 'pack-100-invites', 'Pour une réception de 100 personnes.', 'Tables dressées pour 100 invités', 1, true],
            [$pack->getName(), $pack->getSlug(), $pack->getDescription(), $pack->getPhotoAlt(), $pack->getPosition(), $pack->isVisible()]);
        self::assertSame([['Chaise Napoléon', 100], ['Table ronde', 12]], $pack->getItems()->map(fn (PackItem $i) => [$i->getProduct()->getName(), $i->getQuantity()])->getValues());
        // Photo rangée hors de public/
        self::assertFileExists(static::getContainer()->getParameter('app.upload_dir').'/'.$pack->getPhoto());

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-notice', 'Pack enregistré.');
        self::assertSame(['Pack 100 invités', '2 produits'], [$crawler->filter('.admin-list__name')->text(), $crawler->filter('.admin-list__meta')->text()]);
    }

    public function testRenameKeepsSlugAndPhoto(): void
    {
        $this->post([]);
        $pack = $this->pack();

        $this->post(['name' => 'Grand pack'], photo: false, id: $pack->getId());

        self::assertResponseRedirects('/admin/packs');
        $renamed = $this->pack();
        self::assertSame(['Grand pack', 'pack-100-invites', $pack->getPhoto()], [$renamed->getName(), $renamed->getSlug(), $renamed->getPhoto()]);
    }

    public function testQuantityBelowProductMinimumIsRefused(): void
    {
        $this->post(['items' => [['product' => (string) $this->chairId, 'quantity' => '10']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#pack_items_0_quantity_error1', 'Minimum 15 pour « Chaise Napoléon » (sa quantité minimale).');
        self::assertNull($this->em()->getRepository(Pack::class)->findOneBy([]));
    }

    /** @return iterable<string, array{array<string, mixed>, bool, string, string}> */
    public static function invalidPacks(): iterable
    {
        yield 'sans nom' => [['name' => ''], true, '#pack_name_error1', 'Saisir un nom.'];
        yield 'sans photo' => [[], false, '#pack_photoUpload_error1', 'Choisir une photo.'];
        yield 'sans texte alternatif' => [['photoAlt' => ''], true, '#pack_photoAlt_error1', 'Décrivez la photo (ex. : table dressée pour 100 invités).'];
        yield 'sans produit' => [['items' => []], true, '#pack_items_error1', 'Ajouter au moins un produit.'];
        yield 'produit non choisi' => [['items' => [['product' => '', 'quantity' => '3']]], true, '#pack_items_0_product_error1', 'Choisir un produit.'];
    }

    /** @param array<string, mixed> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidPacks')]
    public function testInvalidPackIsRefused(array $override, bool $photo, string $errorId, string $message): void
    {
        $this->post($override, $photo);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame($errorId, $message);
        self::assertNull($this->em()->getRepository(Pack::class)->findOneBy([]));
    }

    public function testSameProductTwiceIsRefused(): void
    {
        $this->post(['items' => [
            ['product' => (string) $this->tableId, 'quantity' => '10'],
            ['product' => (string) $this->tableId, 'quantity' => '2'],
        ]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#pack_items_1_product_error1', 'Ce produit est déjà dans le pack : modifier sa quantité plutôt.');
    }

    public function testItemsCanBeRemoved(): void
    {
        $this->post([]);
        $pack = $this->pack();
        $values = $this->client->request('GET', '/admin/packs/'.$pack->getId().'/modifier')->selectButton('Enregistrer')->form()->getPhpValues();
        unset($values['pack']['items'][0]);
        $this->client->request('POST', '/admin/packs/'.$pack->getId().'/modifier', $values);

        self::assertResponseRedirects('/admin/packs');
        self::assertSame(['Table ronde'], $this->pack()->getItems()->map(fn (PackItem $i) => $i->getProduct()->getName())->getValues());
    }

    public function testHideShowAndReorderWithoutDelete(): void
    {
        $this->post(['name' => 'Pack A']);
        $this->post(['name' => 'Pack B']);
        $b = $this->em()->getRepository(Pack::class)->findOneBy(['name' => 'Pack B']);
        $token = fn () => $this->client->request('GET', '/admin/packs')->filter('form[action="/admin/packs/'.$b->getId().'/visibilite"] input[name="_token"]')->attr('value');

        $this->client->request('POST', '/admin/packs/'.$b->getId().'/monter', ['_token' => $token()]);
        $crawler = $this->client->followRedirect();
        self::assertSame(['Pack B', 'Pack A'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));

        $this->client->request('POST', '/admin/packs/'.$b->getId().'/visibilite', ['_token' => $token()]);
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Masqué', $crawler->filter('.admin-list__meta')->first()->text());
        self::assertSame('Afficher à nouveau Pack B', $crawler->filter('.admin-list__row')->first()->filter('button')->last()->attr('aria-label'));

        // Jeton invalide : rien ne change
        $this->client->request('POST', '/admin/packs/'.$b->getId().'/visibilite', ['_token' => 'faux']);
        self::assertFalse($this->em()->find(Pack::class, $b->getId())->isVisible());

        $this->client->request('POST', '/admin/packs/'.$b->getId().'/visibilite', ['_token' => $token()]);
        self::assertTrue($this->em()->find(Pack::class, $b->getId())->isVisible());

        foreach (static::getContainer()->get('router')->getRouteCollection() as $name => $route) {
            self::assertStringNotContainsString('supprimer', $route->getPath(), $name);
        }
        self::assertStringNotContainsString('Supprimer', (string) $this->client->request('GET', '/admin/packs/'.$b->getId().'/modifier')->html());
    }

    public function testHiddenProductIsFlaggedInAdmin(): void
    {
        $this->post([]);
        $em = $this->em();
        $em->find(Product::class, $this->chairId)->setVisible(false);
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/packs');
        self::assertSame('2 produits · 1 produit masqué', $crawler->filter('.admin-list__meta')->text());

        $crawler = $this->client->request('GET', '/admin/packs/'.$this->pack()->getId().'/modifier');
        $rows = $crawler->filter('.admin-row');
        self::assertSame("Produit masqué : il n'apparaît pas sur la page du pack.", $rows->first()->filter('.admin-notice')->text());
        self::assertCount(0, $rows->last()->filter('.admin-notice'));
        self::assertStringEndsWith('— masqué', $crawler->filter('#pack_items_0_product option[selected]')->text());
    }
}
