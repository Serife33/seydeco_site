<?php

namespace App\Tests\Functional\Admin;

use App\Entity\FaqItem;
use Doctrine\DBAL\Connection;

/** Story 2.4 côté admin : Plus → Contenus → FAQ. */
final class FaqAdminTest extends CatalogTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        static::getContainer()->get(Connection::class)->executeStatement('TRUNCATE TABLE faq_item');
    }

    public function testCreateQuestionsFromMoreContents(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        $this->client->click($crawler->selectLink('FAQ')->link());
        self::assertSelectorTextSame('h1', 'FAQ');
        self::assertSelectorTextContains('main', 'Aucune question.');
        self::assertSelectorExists('.admin-nav a[aria-current="page"][href="/admin/plus"]');

        $this->create('Livrez-vous le dimanche ?', 'Oui, **sur demande**.', ' Livraison ');
        self::assertResponseRedirects('/admin/faq');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.admin-notice', 'Question enregistrée.');
        $this->create('Comment réserver ?', 'Envoyez votre sélection.', '');

        $items = $this->em()->getRepository(FaqItem::class)->findBy([], ['position' => 'ASC']);
        self::assertSame(
            [['Livrez-vous le dimanche ?', 'Oui, **sur demande**.', 'Livraison', 1, true], ['Comment réserver ?', 'Envoyez votre sélection.', null, 2, true]],
            array_map(fn (FaqItem $i) => [$i->getQuestion(), $i->getAnswer(), $i->getTheme(), $i->getPosition(), $i->isVisible()], $items),
        );
        $crawler = $this->client->request('GET', '/admin/faq/'.$items[0]->getId().'/modifier');
        self::assertCount(4, $crawler->filter('[data-controller="markdown-toolbar"] [role="toolbar"] button'));
    }

    public function testEmptyQuestionOrAnswerIsRefusedAndInputsKept(): void
    {
        $this->create('', 'Une réponse gardée', 'Caution');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#faq_item_question ~ .admin-field__error', 'Saisir la question.');
        self::assertSelectorTextSame('#faq_item_answer', 'Une réponse gardée');
        self::assertInputValueSame('faq_item[theme]', 'Caution');

        $this->create('Une question gardée', '   ', '');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#faq_item_answer_error1', 'Saisir la réponse.');
        self::assertInputValueSame('faq_item[question]', 'Une question gardée');
        self::assertSame(0, $this->em()->getRepository(FaqItem::class)->count([]));
    }

    public function testHideShowAndReorderWithoutDelete(): void
    {
        $this->create('Première ?', 'Oui.', '');
        $this->create('Deuxième ?', 'Oui.', '');
        $crawler = $this->client->request('GET', '/admin/faq');
        self::assertStringNotContainsStringIgnoringCase('supprimer', $crawler->filter('main')->text());

        $this->client->submit($crawler->filter('button[aria-label="Descendre Première ?"]')->form());
        self::assertResponseRedirects('/admin/faq');
        $crawler = $this->client->followRedirect();
        self::assertSame(['Deuxième ?', 'Première ?'], $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()));

        $this->client->submit($crawler->filter('button[aria-label="Masquer Première ?"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Masquée', $crawler->filter('.admin-list__row')->last()->text());
        $crawler = $this->client->request('GET', '/questions-frequentes');
        self::assertSame(['Deuxième ?'], $crawler->filter('.faq-item__question')->each(fn ($n) => $n->text()));
    }

    private function create(string $question, string $answer, string $theme): void
    {
        $this->client->request('GET', '/admin/faq/ajouter');
        $this->client->submitForm('Enregistrer', ['faq_item[question]' => $question, 'faq_item[answer]' => $answer, 'faq_item[theme]' => $theme]);
    }
}
