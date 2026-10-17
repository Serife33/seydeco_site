<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\ProductPhoto;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Stories 1.6 + 1.7 : paliers, casse/perte & consignes, photos, coloris, vidéo. */
final class ProductPricingMediaTest extends CatalogTestCase
{
    private int $chairsId;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $chairs = $this->createCategory('Chaises', 1, PricingType::Chairs);
        $em = $this->em();
        $chairs = $em->find(Category::class, $chairs->getId());
        $chairs->setBreakagePrice(4500);
        $chairs->setLossPrice(6000);
        $chairs->setReturnInstructions('Empiler par 10.');
        $em->flush();
        $this->chairsId = $chairs->getId();
        $this->tmp = sys_get_temp_dir().'/seydeco-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tmp.'/*'));
        rmdir($this->tmp);
        parent::tearDown();
    }

    /**
     * Soumet le formulaire produit (création, ou modification si $id) ; les clés de $product remplacent celles du formulaire.
     *
     * @param array<string, mixed> $product
     * @param array<string, mixed> $files
     */
    private function post(array $product, array $files = [], ?int $id = null): void
    {
        $uri = $id ? "/admin/produits/$id/modifier" : '/admin/produits/ajouter';
        $values = $this->client->request('GET', $uri)->selectButton('Enregistrer')->form()->getPhpValues();
        $defaults = $id ? [] : [
            'name' => 'Chaise Napoléon',
            'category' => (string) $this->chairsId,
            'minQty' => '15',
            'stock' => '800',
            'priceTiers' => [['minQty' => '15', 'price' => '2,50']],
        ];
        $values['product'] = $product + $defaults + $values['product'];
        $this->client->request('POST', $uri, $values, $files ? ['product' => $files] : []);
    }

    private function product(): Product
    {
        return $this->em()->getRepository(Product::class)->findOneBy([]);
    }

    private function image(string $name = 'photo.jpg'): UploadedFile
    {
        $path = "$this->tmp/$name";
        imagejpeg(imagecreatetruecolor(20, 20), $path);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function photos(array $rows): array
    {
        return ['photos' => array_map(fn ($r) => ['alt' => $r[0], 'position' => (string) ($r[1] ?? 0)], $rows)];
    }

    private function uploadDir(): string
    {
        return static::getContainer()->getParameter('app.upload_dir');
    }

    // --- Prix & dégressif ---

    public function testValidTiersAreStoredInCentsAndListShowsFromPrice(): void
    {
        $this->post(['priceTiers' => [
            ['minQty' => '15', 'price' => '2,50'],
            ['minQty' => '50', 'price' => '2,20'],
            ['minQty' => '100', 'price' => '1,90'],
        ]]);

        self::assertResponseRedirects('/admin/produits');
        $tiers = $this->product()->getPriceTiers()->map(fn ($t) => [$t->getMinQty(), $t->getPrice()])->getValues();
        self::assertSame([[15, 250], [50, 220], [100, 190]], $tiers);
        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-list__meta', "dès 1,90\u{A0}€");
    }

    public function testTierRowsCanBeRemoved(): void
    {
        $this->post(['priceTiers' => [['minQty' => '15', 'price' => '2,50'], ['minQty' => '50', 'price' => '2,20']]]);
        $id = $this->product()->getId();

        $this->post(['priceTiers' => [['minQty' => '15', 'price' => '3,00']]], [], $id);

        self::assertResponseRedirects('/admin/produits');
        self::assertSame([[15, 300]], $this->product()->getPriceTiers()->map(fn ($t) => [$t->getMinQty(), $t->getPrice()])->getValues());
    }

    /** @return iterable<string, array{array<int, array<string, string>>, string}> */
    public static function invalidTiers(): iterable
    {
        yield '1er palier ≠ minimum' => [[['minQty' => '10', 'price' => '2,50']], 'Le premier palier doit commencer à 15, la quantité minimale.'];
        yield 'non croissants' => [[['minQty' => '15', 'price' => '2,50'], ['minQty' => '50', 'price' => '2,20'], ['minQty' => '20', 'price' => '2,00']], 'doivent aller en croissant'];
        yield 'aucun palier' => [[], 'Ajouter au moins un palier de prix.'];
    }

    /** @param array<int, array<string, string>> $tiers */
    #[DataProvider('invalidTiers')]
    public function testInvalidTiersAreRefusedUnderTheTable(array $tiers, string $message): void
    {
        $this->post(['priceTiers' => $tiers]);

        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        // Message sous le tableau, relié à la liste qui reçoit le focus
        $group = $crawler->filter('[data-invalid-group]');
        self::assertCount(1, $group);
        self::assertStringContainsString($message, $crawler->filter('#'.$group->attr('aria-describedby'))->text());
        self::assertSame(0, $this->em()->getRepository(Product::class)->count());
    }

    public function testZeroPriceIsRefused(): void
    {
        $this->post(['priceTiers' => [['minQty' => '15', 'price' => '0']]]);

        self::assertResponseStatusCodeSame(422);
        $field = $this->client->getCrawler()->filter('[aria-invalid="true"]')->first();
        self::assertSame('product_priceTiers_0_price', $field->attr('id'));
        self::assertSelectorTextContains('#product_priceTiers_0_price_error1', 'Le prix doit être supérieur à 0.');
    }

    // --- Casse/perte & consignes ---

    public function testCategoryDefaultsAreCopiedAtCreation(): void
    {
        $this->post([]);

        $product = $this->product();
        self::assertSame(4500, $product->getBreakagePrice());
        self::assertSame(6000, $product->getLossPrice());
        self::assertSame('Empiler par 10.', $product->getReturnInstructions());
        $this->client->request('GET', '/admin/produits/'.$product->getId().'/modifier');
        self::assertSame('45,00', $this->client->getCrawler()->filter('#product_breakagePrice')->attr('value'));
    }

    public function testCreateFormCarriesCategoryDefaultsForPrefill(): void
    {
        $crawler = $this->client->request('GET', '/admin/produits/ajouter');

        // Le contrôleur Stimulus « category-defaults » recopie ces valeurs dans les champs vides au choix de la catégorie
        self::assertStringContainsString('category-defaults', $crawler->filter('form[name="product"]')->attr('data-controller'));
        $option = $crawler->filter('#product_category option[value="'.$this->chairsId.'"]');
        self::assertSame(['45,00', '60,00', 'Empiler par 10.'], [$option->attr('data-breakage'), $option->attr('data-loss'), $option->attr('data-instructions')]);
        self::assertSame('category-defaults#fill', $crawler->filter('#product_category')->attr('data-action'));
        self::assertSame('breakage', $crawler->filter('#product_breakagePrice')->attr('data-category-defaults-target'));
        self::assertSame('instructions', $crawler->filter('#product_returnInstructions')->attr('data-category-defaults-target'));

        // Modification : jamais de recopie
        $this->post([]);
        $crawler = $this->client->request('GET', '/admin/produits/'.$this->product()->getId().'/modifier');
        self::assertStringNotContainsString('category-defaults', $crawler->filter('form[name="product"]')->attr('data-controller'));
    }

    public function testValueTypedAtCreationWinsOverCategory(): void
    {
        $this->post(['breakagePrice' => '30,00']);

        self::assertSame(3000, $this->product()->getBreakagePrice());
        self::assertSame(6000, $this->product()->getLossPrice());
    }

    public function testChangingCategoryKeepsBreakageLossAndInstructions(): void
    {
        $this->post([]);
        $id = $this->product()->getId();
        $other = $this->createCategory('Tables', 2, PricingType::Tables);

        $this->post(['category' => (string) $other->getId()], [], $id);

        self::assertResponseRedirects('/admin/produits');
        $product = $this->product();
        self::assertSame($other->getId(), $product->getCategory()->getId());
        self::assertSame([4500, 6000, 'Empiler par 10.'], [$product->getBreakagePrice(), $product->getLossPrice(), $product->getReturnInstructions()]);
    }

    // --- Photos ---

    public function testPhotoUploadReorderAndRemove(): void
    {
        $this->post($this->photos([['Chaise de face', 0], ['Chaise de dos', 1]]), ['photos' => [['file' => $this->image('a.jpg')], ['file' => $this->image('b.png')]]]);

        self::assertResponseRedirects('/admin/produits');
        $product = $this->product();
        $photos = $product->getPhotos();
        self::assertSame(['Chaise de face', 'Chaise de dos'], $photos->map(fn (ProductPhoto $p) => $p->getAlt())->getValues());
        foreach ($photos as $photo) {
            // Original rangé hors de public/, sous un nom aléatoire
            self::assertFileExists($this->uploadDir().'/'.$photo->getFilename());
            self::assertStringNotContainsString('/public/', $this->uploadDir());
        }
        [$front, $back] = $photos->getValues();

        // Liste : miniature de la photo principale, dimensions déclarées
        $crawler = $this->client->request('GET', '/admin/produits');
        $thumb = $crawler->filter('.admin-list__row img.admin-thumb');
        self::assertStringContainsString('/admin_thumb/'.$front->getFilename(), $thumb->attr('src'));
        self::assertSame(['48', '48'], [$thumb->attr('width'), $thumb->attr('height')]);

        // « Monter » la photo de dos (positions renumérotées) : elle devient la principale
        $id = $product->getId();
        $this->post(['photos' => [['alt' => 'Chaise de face', 'position' => '1'], ['alt' => 'Chaise de dos', 'position' => '0']]], [], $id);
        self::assertSame($back->getId(), $this->product()->getPhotos()->first()->getId());

        // Retirer la photo de face (désormais 2e ligne) : le fichier est effacé
        $this->post(['photos' => [0 => ['alt' => 'Chaise de dos', 'position' => '0']]], [], $id);
        self::assertSame([$back->getId()], $this->product()->getPhotos()->map(fn ($p) => $p->getId())->getValues());
        self::assertFileDoesNotExist($this->uploadDir().'/'.$front->getFilename());
    }

    public function testOutOfRangePhotoPositionIsRefused(): void
    {
        $this->post($this->photos([['Chaise', 99999999]]), ['photos' => [['file' => $this->image()]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_photos_0_position_error1', 'Ordre de photo invalide');
    }

    public function testUnwritableUploadFolderShowsErrorAndSavesNothing(): void
    {
        // Un fichier à la place du dossier d'envoi : l'enregistrement du fichier échoue
        file_put_contents($this->uploadDir(), '');
        try {
            $this->post($this->photos([['Chaise']]), ['photos' => [['file' => $this->image()]]]);
        } finally {
            unlink($this->uploadDir());
        }

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Impossible d\'enregistrer le fichier, réessayez.');
        self::assertSame(0, $this->em()->getRepository(Product::class)->count());
    }

    public function testPhotoWithoutAltIsRefusedWithFocus(): void
    {
        $this->post($this->photos([['  ']]), ['photos' => [['file' => $this->image()]]]);

        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        self::assertSame('product_photos_0_alt', $crawler->filter('[aria-invalid="true"]')->first()->attr('id'));
        self::assertSelectorTextContains('#product_photos_0_alt_error1', 'Décrivez la photo (ex. : chaise Napoléon blanche de face).');
        self::assertSame(0, $this->em()->getRepository(Product::class)->count());
    }

    public function testPhotoRowWithoutFileIsRefused(): void
    {
        $this->post($this->photos([['Chaise']]));

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_photos_0_file_error1', 'Choisir une photo.');
    }

    public function testWrongPhotoFileIsRefused(): void
    {
        file_put_contents("$this->tmp/devis.pdf", "%PDF-1.4\n%âãÏÓ\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $pdf = new UploadedFile("$this->tmp/devis.pdf", 'devis.jpg', null, null, true);
        $big = "$this->tmp/grande.jpg";
        $handle = fopen($big, 'w');
        ftruncate($handle, 12 * 1024 * 1024);
        fclose($handle);

        $this->post($this->photos([['PDF'], ['Grande']]), ['photos' => [['file' => $pdf], ['file' => new UploadedFile($big, 'grande.jpg', null, null, true)]]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_photos_0_file_error1', 'Format non accepté');
        self::assertSelectorTextContains('#product_photos_1_file_error1', 'Photo trop lourde (10 Mo maximum).');
    }

    // --- Coloris ---

    public function testColorsCanBeAttachedToPhotos(): void
    {
        $this->post($this->photos([['Chaise blanche']]) + ['colors' => [['name' => 'Blanc', 'hex' => '#ffffff'], ['name' => 'Doré', 'hex' => '#d9c49a']]], ['photos' => [['file' => $this->image()]]]);
        self::assertResponseRedirects('/admin/produits');
        $product = $this->product();
        self::assertSame([['Blanc', '#ffffff'], ['Doré', '#d9c49a']], $product->getColors()->map(fn ($c) => [$c->getName(), $c->getHex()])->getValues());
        $white = $product->getColors()->first();

        $this->post(['photos' => [['alt' => 'Chaise blanche', 'position' => '0', 'color' => (string) $white->getId()]]], [], $product->getId());
        self::assertSame($white->getId(), $this->product()->getPhotos()->first()->getColor()->getId());

        // Retirer un coloris détache les photos qui le montraient
        $this->post(['colors' => [1 => ['name' => 'Doré', 'hex' => '#d9c49a']], 'photos' => [['alt' => 'Chaise blanche', 'position' => '0', 'color' => (string) $white->getId()]]], [], $product->getId());
        self::assertResponseRedirects('/admin/produits');
        self::assertNull($this->product()->getPhotos()->first()->getColor());
        self::assertSame(['Doré'], $this->product()->getColors()->map(fn ($c) => $c->getName())->getValues());
    }

    public function testColorNeedsName(): void
    {
        $this->post(['colors' => [['name' => '', 'hex' => '#ffffff']]]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_colors_0_name_error1', 'Saisir le nom du coloris.');
    }

    // --- Vidéo ---

    /** @return iterable<string, array{string, bool}> */
    public static function videoLinks(): iterable
    {
        yield 'vimeo' => ['https://vimeo.com/123456', false];
        yield 'http' => ['http://www.youtube.com/watch?v=abc', false];
        yield 'faux domaine' => ['https://youtube.com.evil.example/x', false];
        yield 'youtube' => ['https://www.youtube.com/watch?v=abc', true];
        yield 'youtu.be' => ['https://youtu.be/abc', true];
        yield 'instagram' => ['https://www.instagram.com/reel/abc/', true];
        yield 'tiktok' => ['https://www.tiktok.com/@seydeco/video/123', true];
    }

    #[DataProvider('videoLinks')]
    public function testVideoLinkHosts(string $url, bool $accepted): void
    {
        $this->post(['videoUrl' => $url]);

        if ($accepted) {
            self::assertResponseRedirects('/admin/produits');
            self::assertSame($url, $this->product()->getVideoUrl());
        } else {
            self::assertResponseStatusCodeSame(422);
            self::assertSelectorTextContains('#product_videoUrl_error1', 'Seuls les liens YouTube, Instagram ou TikTok sont acceptés');
        }
    }

    public function testMp4VideoWithThumbnailThenRemoved(): void
    {
        $mp4 = "$this->tmp/clip.mp4";
        file_put_contents($mp4, "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom".str_repeat("\x00", 64));
        $this->post([], ['videoUpload' => new UploadedFile($mp4, 'clip.mp4', null, null, true), 'videoThumbnailUpload' => $this->image()]);

        self::assertResponseRedirects('/admin/produits');
        $product = $this->product();
        self::assertStringEndsWith('.mp4', $product->getVideoFile());
        self::assertFileExists($this->uploadDir().'/'.$product->getVideoFile());
        self::assertNotNull($product->getVideoThumbnail());

        // Fichier + lien : refusé
        $this->post(['videoUrl' => 'https://youtu.be/abc'], [], $product->getId());
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_videoUrl_error1', 'Choisir une vidéo MP4 ou un lien, pas les deux.');

        // Retirer la vidéo et la miniature : fichiers effacés
        $this->post(['removeVideoFile' => '1', 'removeVideoThumbnail' => '1', 'videoUrl' => 'https://youtu.be/abc'], [], $product->getId());
        self::assertResponseRedirects('/admin/produits');
        self::assertNull($this->product()->getVideoFile());
        self::assertNull($this->product()->getVideoThumbnail());
        self::assertFileDoesNotExist($this->uploadDir().'/'.$product->getVideoFile());
        self::assertFileDoesNotExist($this->uploadDir().'/'.$product->getVideoThumbnail());
    }

    public function testNonMp4VideoIsRefused(): void
    {
        $this->post([], ['videoUpload' => $this->image('clip.jpg')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('#product_videoUpload_error1', 'Format non accepté : choisir une vidéo MP4.');
    }
}
