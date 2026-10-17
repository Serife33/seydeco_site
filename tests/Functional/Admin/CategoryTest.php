<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use Symfony\Component\Routing\RouterInterface;

final class CategoryTest extends CatalogTestCase
{
    public function testEmptyList(): void
    {
        $this->client->request('GET', '/admin/plus');
        $this->client->clickLink('Catégories');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('main', 'Aucune catégorie.');
        self::assertSelectorExists('a:contains("Ajouter une catégorie")');
    }

    public function testCreateCategory(): void
    {
        $this->client->request('GET', '/admin/categories/ajouter');
        $this->client->submitForm('Enregistrer', [
            'category[name]' => 'Chaises',
            'category[pricingType]' => 'chairs',
            'category[refurbishDelay]' => '2',
            'category[breakagePrice]' => '45,00',
            'category[returnInstructions]' => 'Empiler par 6.',
        ]);

        self::assertResponseRedirects('/admin/categories');
        $category = $this->em()->getRepository(Category::class)->findOneBy(['name' => 'Chaises']);
        self::assertSame('chaises', $category->getSlug());
        self::assertSame(4500, $category->getBreakagePrice());
        self::assertNull($category->getInstallPrice());
        self::assertSame(2, $category->getRefurbishDelay());
        self::assertSame('chairs', $category->getPricingType()->value);
        self::assertTrue($category->isVisible());

        $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-notice', 'Catégorie enregistrée.');
        self::assertSelectorTextContains('.admin-list', 'Chaises');
    }

    /** @return iterable<string, array{array<string, string>, string, string}> */
    public static function invalidCategories(): iterable
    {
        yield 'nom vide' => [['category[name]' => ''], 'category_name', 'Saisir un nom.'];
        yield 'nom en double' => [['category[name]' => 'Tables'], 'category_name', 'Une catégorie porte déjà ce nom.'];
        yield 'délai négatif' => [['category[refurbishDelay]' => '-1'], 'category_refurbishDelay', 'Le délai ne peut pas être négatif.'];
        yield 'délai décimal' => [['category[refurbishDelay]' => '2,5'], 'category_refurbishDelay', 'Saisir un nombre entier d\'heures'];
        yield 'montant texte' => [['category[breakagePrice]' => 'abc'], 'category_breakagePrice', 'Saisir un montant en euros'];
        yield 'délai énorme' => [['category[refurbishDelay]' => '3000000000'], 'category_refurbishDelay', 'Le délai ne peut pas dépasser'];
        yield 'montant énorme' => [['category[breakagePrice]' => '50000000,00'], 'category_breakagePrice', 'Le montant ne peut pas dépasser'];
        yield 'montant négatif' => [['category[lossPrice]' => '-5,00'], 'category_lossPrice', 'Le montant ne peut pas être négatif.'];
    }

    /** @param array<string, string> $override */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCategories')]
    public function testInvalidCategoryIsRefused(array $override, string $fieldId, string $message): void
    {
        $this->createCategory('Tables', 1);
        $this->client->request('GET', '/admin/categories/ajouter');
        $crawler = $this->client->submitForm('Enregistrer', $override + [
            'category[name]' => 'Chaises',
            'category[pricingType]' => 'chairs',
            'category[refurbishDelay]' => '2',
            'category[installPrice]' => '30,00',
        ]);

        self::assertResponseStatusCodeSame(422);
        $field = $crawler->filter("#$fieldId");
        self::assertSame('true', $field->attr('aria-invalid'));
        self::assertStringContainsString($message, $crawler->filter('#'.$field->attr('aria-describedby'))->text());
        // Message sous le champ, et les autres saisies sont gardées
        self::assertSame(1, $crawler->filter('.admin-field')->reduce(fn ($row) => $row->filter("#$fieldId")->count() > 0)->filter('.admin-field__error')->count());
        self::assertSame('30,00', $crawler->filter('#category_installPrice')->attr('value'));
        self::assertSame(1, $this->em()->getRepository(Category::class)->count());
    }

    public function testRenameKeepsSlug(): void
    {
        $id = $this->createCategory('Chaises', 1)->getId();

        $this->client->request('GET', "/admin/categories/$id/modifier");
        $this->client->submitForm('Enregistrer', ['category[name]' => 'Chaises de réception']);

        self::assertResponseRedirects('/admin/categories');
        $category = $this->em()->find(Category::class, $id);
        self::assertSame('Chaises de réception', $category->getName());
        self::assertSame('chaises', $category->getSlug());
    }

    public function testSameSlugGetsSuffix(): void
    {
        $this->createCategory('Chaises', 1);
        $this->client->request('GET', '/admin/categories/ajouter');
        $this->client->submitForm('Enregistrer', ['category[name]' => 'Chaises !', 'category[pricingType]' => 'chairs', 'category[refurbishDelay]' => '0']);

        self::assertSame('chaises-2', $this->em()->getRepository(Category::class)->findOneBy(['name' => 'Chaises !'])->getSlug());
    }

    public function testLongNameGivesSlugThatFits(): void
    {
        $this->client->request('GET', '/admin/categories/ajouter');
        $this->client->submitForm('Enregistrer', ['category[name]' => str_repeat('&œ', 50), 'category[pricingType]' => 'other', 'category[refurbishDelay]' => '0']);

        self::assertResponseRedirects('/admin/categories');
        self::assertLessThanOrEqual(120, \strlen($this->em()->getRepository(Category::class)->findOneBy([])->getSlug()));
    }

    public function testExpiredFormTokenShowsMessage(): void
    {
        $this->client->request('POST', '/admin/categories/ajouter', ['category' => ['name' => 'Chaises', 'pricingType' => 'chairs', 'refurbishDelay' => '0', '_token' => 'x']]);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextContains('form [role="alert"]', 'Session expirée, réessayez.');
    }

    public function testMoveDownAndUp(): void
    {
        foreach (['Chaises', 'Tables', 'Nappes'] as $i => $name) {
            $this->createCategory($name, $i + 1);
        }

        $crawler = $this->client->request('GET', '/admin/categories');
        // La 1re ne peut pas monter, la dernière ne peut pas descendre
        self::assertCount(0, $crawler->filter('button[aria-label="Monter Chaises"]'));
        self::assertCount(0, $crawler->filter('button[aria-label="Descendre Nappes"]'));

        $this->client->submit($crawler->filter('button[aria-label="Descendre Chaises"]')->form());
        self::assertResponseRedirects();
        $crawler = $this->client->request('GET', '/admin/categories');
        self::assertSame(['Tables', 'Chaises', 'Nappes'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));

        $this->client->submit($crawler->filter('button[aria-label="Monter Nappes"]')->form());
        $crawler = $this->client->request('GET', '/admin/categories');
        self::assertSame(['Tables', 'Nappes', 'Chaises'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));
    }

    public function testFirstCannotMoveUpEvenByForgedRequest(): void
    {
        $first = $this->createCategory('Chaises', 1);
        $this->createCategory('Tables', 2);
        $crawler = $this->client->request('GET', '/admin/categories');
        $token = $crawler->filter('button[aria-label="Descendre Chaises"]')->form()->get('_token')->getValue();

        $this->client->request('POST', '/admin/categories/'.$first->getId().'/monter', ['_token' => $token]);

        $crawler = $this->client->request('GET', '/admin/categories');
        self::assertSame(['Chaises', 'Tables'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));
    }

    public function testHideThenShowAgain(): void
    {
        $id = $this->createCategory('Chaises', 1)->getId();

        $crawler = $this->client->request('GET', '/admin/categories');
        $this->client->submit($crawler->selectButton('Masquer')->form());
        $crawler = $this->client->followRedirect();
        self::assertSelectorTextContains('.admin-list__meta', 'Masquée');
        self::assertFalse($this->em()->find(Category::class, $id)->isVisible());

        $this->client->submit($crawler->selectButton('Afficher à nouveau')->form());
        $this->client->followRedirect();
        self::assertSelectorTextNotContains('.admin-list__meta', 'Masquée');
        self::assertTrue($this->em()->find(Category::class, $id)->isVisible());
    }

    public function testStateActionWithInvalidTokenChangesNothing(): void
    {
        $id = $this->createCategory('Chaises', 1)->getId();

        $this->client->request('POST', "/admin/categories/$id/visibilite", ['_token' => 'invalide']);

        self::assertResponseRedirects('/admin/categories?page=1');
        self::assertTrue($this->em()->find(Category::class, $id)->isVisible());
    }

    public function testListIsPaginated(): void
    {
        for ($i = 1; $i <= 21; ++$i) {
            $this->createCategory("Catégorie $i", $i);
        }

        $crawler = $this->client->request('GET', '/admin/categories');
        self::assertCount(20, $crawler->filter('.admin-list__row'));
        $crawler = $this->client->clickLink('Suivant');
        self::assertCount(1, $crawler->filter('.admin-list__row'));
        $this->client->request('GET', '/admin/categories?page=5');
        self::assertSelectorTextContains('.admin-pagination', 'Page 2 sur 2');
        // La dernière (globalement) ne peut pas descendre, mais peut monter d'une page à l'autre
        self::assertCount(0, $crawler->filter('button:contains("Descendre")'));
        self::assertCount(1, $crawler->filter('button:contains("Monter")'));
    }

    public function testNoDeleteRouteInAdmin(): void
    {
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            if (!str_starts_with($route->getPath(), '/admin')) {
                continue;
            }
            self::assertDoesNotMatchRegularExpression('/delete|supprim/i', $name.$route->getPath(), "Route de suppression : $name");
            self::assertNotContains('DELETE', $route->getMethods(), "Route DELETE : $name");
        }
    }
}
