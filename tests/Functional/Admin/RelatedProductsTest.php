<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Category;
use App\Entity\PriceTier;
use App\Entity\PricingType;
use App\Entity\Product;
use App\Entity\RelatedProduct;

/** Story 1.15 côté admin : section « Souvent loué avec » du formulaire produit. */
final class RelatedProductsTest extends CatalogTestCase
{
    /** @var array<string, int> nom → id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $category = $this->createCategory('Arts de la table', 1, PricingType::Other);
        $em = $this->em();
        foreach (['Assiette', 'Couverts', 'Nappe', 'Serviette', 'Verre', 'Chemin de table'] as $name) {
            $product = new Product();
            $product->setName($name);
            $product->initSlug(mb_strtolower(str_replace(' ', '-', $name)));
            $product->setCategory($em->find(Category::class, $category->getId()));
            $product->addPriceTier(new PriceTier(1, 100));
            $em->persist($product);
            $em->flush();
            $this->ids[$name] = $product->getId();
        }
    }

    /** @param list<string> $names */
    private function postRelated(array $names): void
    {
        $uri = '/admin/produits/'.$this->ids['Assiette'].'/modifier';
        $values = $this->client->request('GET', $uri)->selectButton('Enregistrer')->form()->getPhpValues();
        $values['product']['relatedProducts'] = array_map(fn (string $n, int $i) => ['related' => (string) $this->ids[$n], 'position' => (string) $i], $names, array_keys($names));
        $this->client->request('POST', $uri, $values);
    }

    /** @return list<string> */
    private function related(): array
    {
        return $this->em()->find(Product::class, $this->ids['Assiette'])->getRelatedProducts()->map(fn (RelatedProduct $r) => $r->getRelated()->getName())->getValues();
    }

    public function testUpToFourProductsAreSavedInChosenOrder(): void
    {
        $this->postRelated(['Verre', 'Nappe', 'Couverts', 'Serviette']);

        self::assertResponseRedirects('/admin/produits');
        self::assertSame(['Verre', 'Nappe', 'Couverts', 'Serviette'], $this->related());

        // Monter / Descendre renumérote les positions : l'ordre suit
        $this->postRelated(['Nappe', 'Verre']);
        self::assertSame(['Nappe', 'Verre'], $this->related());

        $crawler = $this->client->request('GET', '/admin/produits/'.$this->ids['Assiette'].'/modifier');
        self::assertSame(['Monter', 'Descendre', 'Retirer'], $crawler->filter('#s-related ~ fieldset .admin-row')->first()->filter('button')->each(fn ($b) => $b->text()));
    }

    public function testFifthProductIsRefused(): void
    {
        $this->postRelated(['Verre', 'Nappe', 'Couverts', 'Serviette', 'Chemin de table']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#product_relatedProducts_error1', 'Choisir 4 produits associés au plus.');
        self::assertSame([], $this->related());
    }

    public function testProductItselfCannotBeChosenNorTwiceTheSame(): void
    {
        $crawler = $this->client->request('GET', '/admin/produits/'.$this->ids['Assiette'].'/modifier');
        $prototype = $crawler->filter('#s-related ~ fieldset')->attr('data-collection-prototype-value');
        self::assertStringNotContainsString('value="'.$this->ids['Assiette'].'"', $prototype);
        self::assertStringContainsString('value="'.$this->ids['Verre'].'"', $prototype);

        $this->postRelated(['Assiette']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#product_relatedProducts_0_related_error1', 'Choisir un produit de la liste.');

        $this->postRelated(['Verre', 'Verre']);
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#product_relatedProducts_1_related_error1', 'Ce produit est déjà choisi.');
        self::assertSame([], $this->related());
    }
}
