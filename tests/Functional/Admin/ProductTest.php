<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\Style;
use PHPUnit\Framework\Attributes\DataProvider;

final class ProductTest extends CatalogTestCase
{
    private int $chairsId;

    protected function setUp(): void
    {
        parent::setUp();
        $chairs = $this->createCategory('Chaises', 1, PricingType::Chairs);
        $this->chairsId = $chairs->getId();
    }

    /** @param array<string, mixed> $values */
    private function submitNew(array $values): void
    {
        $this->client->request('GET', '/admin/produits/ajouter');
        $this->client->submitForm('Enregistrer', $values + [
            'product[name]' => 'Chaise Napoléon',
            'product[category]' => (string) $this->chairsId,
            'product[minQty]' => '15',
            'product[stock]' => '800',
            'product[priceTiers][0][minQty]' => '15',
            'product[priceTiers][0][price]' => '2,50',
        ]);
    }

    public function testCreateProduct(): void
    {
        $crawler = $this->client->request('GET', '/admin/produits/ajouter');
        self::assertSame(['Photos & vidéo', 'Infos', 'Prix & dégressif', 'Quantité minimale', 'Casse/perte & consignes', 'Stock', 'Souvent loué avec', 'Visibilité'], $crawler->filter('form h2')->each(fn ($h) => $h->text()));
        // Délai de remise en état de la catégorie, en lecture seule
        self::assertStringContainsString('remise en état : 0 h', $crawler->filter('#product_category option[value="'.$this->chairsId.'"]')->text());

        $this->submitNew(['product[dimensions]' => '40 × 45 × 92 cm', 'product[seats]' => '']);

        self::assertResponseRedirects('/admin/produits');
        $product = $this->em()->getRepository(Product::class)->findOneBy(['name' => 'Chaise Napoléon']);
        self::assertSame('chaise-napoleon', $product->getSlug());
        self::assertSame(15, $product->getMinQty());
        self::assertSame(800, $product->getStock());
        self::assertSame($this->chairsId, $product->getCategory()->getId());
        self::assertNull($product->getSeats());
        self::assertTrue($product->isVisible());
        self::assertEqualsWithDelta(time(), $product->getCreatedAt()->getTimestamp(), 60);

        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-notice', 'Produit enregistré.');
        // Le brouillon local du formulaire est effacé côté navigateur
        self::assertSame('seydeco.draft.product.new', $crawler->filter('[data-form-draft-clear-value="true"]')->attr('data-form-draft-key-value'));
    }

    public function testRenameKeepsSlug(): void
    {
        $this->submitNew([]);
        $id = $this->em()->getRepository(Product::class)->findOneBy([])->getId();

        $this->client->request('GET', "/admin/produits/$id/modifier");
        $this->client->submitForm('Enregistrer', ['product[name]' => 'Chaise Napoléon III']);

        self::assertSame('chaise-napoleon', $this->em()->find(Product::class, $id)->getSlug());
    }

    public function testHideAndShowFromListKeepsFilters(): void
    {
        $this->submitNew([]);
        $id = $this->em()->getRepository(Product::class)->findOneBy([])->getId();

        $crawler = $this->client->request('GET', '/admin/produits?q=napo');
        $this->client->submit($crawler->selectButton('Masquer')->form());
        self::assertResponseRedirects('/admin/produits?q=napo');
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-list__meta', 'Masqué');
        self::assertFalse($this->em()->find(Product::class, $id)->isVisible());

        $this->client->submit($crawler->selectButton('Afficher à nouveau')->form());
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('.admin-list__meta', 'Masqué');
        self::assertTrue($this->em()->find(Product::class, $id)->isVisible());
    }

    public function testHideFromEditPage(): void
    {
        $this->submitNew([]);
        $id = $this->em()->getRepository(Product::class)->findOneBy([])->getId();

        $this->client->request('GET', "/admin/produits/$id/modifier");
        $this->client->submitForm('Masquer');

        self::assertResponseRedirects("/admin/produits/$id/modifier");
        $this->client->followRedirect();
        self::assertSelectorExists('button:contains("Afficher à nouveau")');
        self::assertFalse($this->em()->find(Product::class, $id)->isVisible());
    }

    public function testToggleWithInvalidTokenChangesNothing(): void
    {
        $this->submitNew([]);
        $id = $this->em()->getRepository(Product::class)->findOneBy([])->getId();

        $this->client->request('POST', "/admin/produits/$id/visibilite", ['_token' => 'invalide']);

        self::assertResponseRedirects('/admin/produits');
        self::assertTrue($this->em()->find(Product::class, $id)->isVisible());
    }

    public function testExpiredFormTokenShowsMessage(): void
    {
        $crawler = $this->client->request('GET', '/admin/produits/ajouter');
        $form = $crawler->selectButton('Enregistrer')->form([
            'product[name]' => 'Chaise',
            'product[category]' => (string) $this->chairsId,
        ]);
        $values = $form->getPhpValues();
        $values['product']['_token'] = 'x';
        $this->client->request('POST', '/admin/produits/ajouter', $values);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Session expirée, réessayez.');
        self::assertSame(0, $this->em()->getRepository(Product::class)->count());
    }

    public function testLongNameGivesSlugThatFits(): void
    {
        $this->submitNew(['product[name]' => str_repeat('Chaise œ ', 16)]);

        self::assertResponseRedirects('/admin/produits');
        self::assertLessThanOrEqual(170, \strlen($this->em()->getRepository(Product::class)->findOneBy([])->getSlug()));
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function invalidProducts(): iterable
    {
        yield 'sans nom' => [['product[name]' => ''], 'product_name', 'Saisir un nom.'];
        yield 'sans catégorie' => [['product[category]' => ''], 'product_category', 'Choisir une catégorie.'];
        yield 'minimum 0' => [['product[minQty]' => '0'], 'product_minQty', 'La quantité minimale doit être au moins 1.'];
        yield 'stock négatif' => [['product[stock]' => '-3'], 'product_stock', 'Le stock ne peut pas être négatif.'];
        yield 'stock décimal' => [['product[stock]' => '1,5'], 'product_stock', 'Saisir un nombre entier'];
        yield 'stock énorme' => [['product[stock]' => '3000000000'], 'product_stock', 'Le stock ne peut pas dépasser 1 000 000.'];
        yield 'stock hors entier' => [['product[stock]' => '99999999999999999999'], 'product_stock', 'Saisir un nombre entier'];
        yield 'places énormes' => [['product[seats]' => '3000000000'], 'product_seats', 'Le nombre de places ne peut pas dépasser'];
        yield 'places en fourchette' => [['product[seats]' => '8-10'], 'product_seats', 'Saisir un seul nombre, par exemple 10'];
    }

    /** @param array<string, string> $override */
    #[DataProvider('invalidProducts')]
    public function testInvalidProductIsRefused(array $override, string $fieldId, string $message): void
    {
        $this->submitNew($override + ['product[dimensions]' => '40 cm']);

        self::assertResponseStatusCodeSame(422);
        $crawler = $this->client->getCrawler();
        // Le premier champ en erreur (focus posé par unsaved-changes) est bien celui attendu
        self::assertSame($fieldId, $crawler->filter('[aria-invalid="true"]')->first()->attr('id'));
        $describedBy = explode(' ', $crawler->filter("#$fieldId")->attr('aria-describedby'));
        self::assertStringContainsString($message, $crawler->filter('#'.end($describedBy))->text());
        self::assertSame('40 cm', $crawler->filter('#product_dimensions')->attr('value'));
        self::assertSame(0, $this->em()->getRepository(Product::class)->count());
        // Récapitulatif en haut du formulaire : l'erreur se voit même loin du champ
        self::assertStringContainsString($message, $crawler->filter('.admin-error[role="alert"]')->text());
    }

    public function testFirstInvalidFieldComesFirst(): void
    {
        $this->submitNew(['product[name]' => '', 'product[category]' => '', 'product[minQty]' => '0', 'product[stock]' => '-3']);

        self::assertSame(['product_name', 'product_category', 'product_minQty', 'product_stock'], $this->client->getCrawler()->filter('[aria-invalid="true"]')->each(fn ($f) => $f->attr('id')));
    }

    public function testNewStyleIsCreatedThenReused(): void
    {
        $this->submitNew(['product[newStyle]' => 'Bohème', 'product[newEventType]' => 'Mariage']);
        self::assertResponseRedirects('/admin/produits');

        // Proposé comme case à cocher ; un doublon saisi réutilise l'existant
        $crawler = $this->client->request('GET', '/admin/produits/ajouter');
        self::assertSelectorTextContains('#product_styles', 'Bohème');
        $this->client->submit($crawler->selectButton('Enregistrer')->form([
            'product[name]' => 'Table ronde',
            'product[category]' => (string) $this->chairsId,
            'product[minQty]' => '1',
            'product[stock]' => '20',
            'product[priceTiers][0][minQty]' => '1',
            'product[priceTiers][0][price]' => '15',
            'product[newStyle]' => ' bohème ',
        ]));

        $em = $this->em();
        self::assertSame(1, $em->getRepository(Style::class)->count());
        $style = $em->getRepository(Style::class)->findOneBy([]);
        foreach ($em->getRepository(Product::class)->findAll() as $product) {
            self::assertSame([$style->getId()], $product->getStyles()->map(fn (Style $s) => $s->getId())->getValues());
        }
        self::assertSame(['Mariage'], $em->getRepository(Product::class)->findOneBy(['name' => 'Chaise Napoléon'])->getEventTypes()->map(fn ($t) => $t->getName())->getValues());
    }

    public function testListSearchFilterAndPagination(): void
    {
        $tables = $this->createCategory('Tables', 2, PricingType::Tables);
        $em = $this->em();
        $chairs = $em->find(Category::class, $this->chairsId);
        $tables = $em->find(Category::class, $tables->getId());
        for ($i = 1; $i <= 30; ++$i) {
            $product = new Product();
            $product->setName(\sprintf('%s %02d', 0 === $i % 2 ? 'Chaise Napoléon' : 'Table', $i));
            $product->initSlug("p-$i");
            $product->setCategory($i <= 25 ? $chairs : $tables);
            $em->persist($product);
        }
        $em->flush();

        $crawler = $this->client->request('GET', '/admin/produits');
        self::assertCount(20, $crawler->filter('.admin-list__row'));
        self::assertCount(20, $crawler->filter('.admin-thumb'));
        $crawler = $this->client->clickLink('Suivant');
        self::assertCount(10, $crawler->filter('.admin-list__row'));

        // « napo » trouve « Napoléon » (accents et casse ignorés)
        $crawler = $this->client->request('GET', '/admin/produits?q=napo');
        self::assertCount(15, $crawler->filter('.admin-list__row'));

        $crawler = $this->client->request('GET', '/admin/produits?q=napo&categorie='.$tables->getId());
        self::assertSame(['Chaise Napoléon 26', 'Chaise Napoléon 28', 'Chaise Napoléon 30'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));

        // Page trop grande : ramenée à la dernière
        $crawler = $this->client->request('GET', '/admin/produits?page=99999999999');
        self::assertSelectorTextContains('.admin-pagination', 'Page 2 sur 2');
        self::assertCount(10, $crawler->filter('.admin-list__row'));

        $this->client->request('GET', '/admin/produits?q=introuvable');
        self::assertSelectorTextContains('main', 'Aucun produit.');
    }

    public function testFiltersSurvivePagination(): void
    {
        $em = $this->em();
        $chairs = $em->find(Category::class, $this->chairsId);
        for ($i = 1; $i <= 22; ++$i) {
            $product = new Product();
            $product->setName("Chaise $i");
            $product->initSlug("c-$i");
            $product->setCategory($chairs);
            $em->persist($product);
        }
        $em->flush();

        $this->client->request('GET', '/admin/produits?q=chaise');
        $this->client->clickLink('Suivant');

        self::assertSame(['q' => 'chaise', 'page' => '2'], $this->client->getRequest()->query->all());
        self::assertCount(2, $this->client->getCrawler()->filter('.admin-list__row'));
    }
}
