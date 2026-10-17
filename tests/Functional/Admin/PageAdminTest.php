<?php

namespace App\Tests\Functional\Admin;

use App\Entity\Page;
use App\Tests\Functional\PagesSnapshot;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Routing\RouterInterface;

/**
 * Story 2.3 côté admin : Plus → Contenus → Pages.
 * Navigateur (vérification manuelle) : barre « Titre · Gras · Liste · Lien » au-dessus du contenu (insère ## , **…**, - , [texte](https://)).
 */
final class PageAdminTest extends CatalogTestCase
{
    use PagesSnapshot;

    private Connection $db;
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = static::getContainer()->get(Connection::class);
        $this->snapshotPages($this->db);
        $this->tmp = sys_get_temp_dir().'/seydeco-test-'.bin2hex(random_bytes(4));
        mkdir($this->tmp);
    }

    protected function tearDown(): void
    {
        $this->restorePages($this->db);
        array_map('unlink', glob($this->tmp.'/*'));
        rmdir($this->tmp);
        parent::tearDown();
    }

    public function testSixBasePagesListedWithoutDelete(): void
    {
        $crawler = $this->client->request('GET', '/admin/plus');
        $crawler = $this->client->click($crawler->selectLink('Pages')->link());

        self::assertSelectorTextSame('h1', 'Pages');
        self::assertSelectorExists('.admin-nav a[aria-current="page"][href="/admin/plus"]');
        self::assertSame(
            ['À propos', 'Contact', 'Mentions légales', 'Conditions générales de location', 'Politique de confidentialité', 'Gestion des cookies'],
            $crawler->filter('.admin-list__name')->each(fn ($n) => $n->text()),
        );
        self::assertStringNotContainsStringIgnoringCase('supprimer', $crawler->filter('main')->text());
        // Aucune route de suppression
        foreach (static::getContainer()->get(RouterInterface::class)->getRouteCollection() as $name => $route) {
            self::assertFalse(str_starts_with($name, 'admin_page') && (str_contains($route->getPath(), 'supprimer') || \in_array('DELETE', $route->getMethods(), true)), $name);
        }
    }

    public function testEditPageKeepsSlugAndShowsMarkdownToolbar(): void
    {
        $id = $this->pageId('a-propos');
        $crawler = $this->client->request('GET', "/admin/pages/$id/modifier");

        self::assertSelectorTextContains('main', 'Adresse : /a-propos (ne change pas)');
        self::assertSame(['Titre', 'Gras', 'Liste', 'Lien'], $crawler->filter('[data-controller="markdown-toolbar"] [role="toolbar"] button')->each(fn ($b) => $b->text()));
        self::assertCount(1, $crawler->filter('textarea#page_content[data-markdown-toolbar-target="input"]'));
        self::assertStringContainsString('**gras**', $crawler->filter('#page_content_help')->text());

        $this->client->submitForm('Enregistrer', [
            'page[title]' => 'Qui sommes-nous',
            'page[content]' => "## Serife\n\nBonjour <script>alert(1)</script>",
            'page[description]' => 'Serife et Seydeco.',
        ]);

        self::assertResponseRedirects('/admin/pages');
        $this->client->followRedirect();
        self::assertSelectorTextSame('.admin-notice', 'Page enregistrée.');
        $page = $this->em()->find(Page::class, $id);
        self::assertSame(['Qui sommes-nous', 'a-propos', 'Serife et Seydeco.'], [$page->getTitle(), $page->getSlug(), $page->getDescription()]);

        $crawler = $this->client->request('GET', '/a-propos');
        self::assertSame('Qui sommes-nous', $crawler->filter('main h1')->text());
        self::assertSame('Bonjour <script>alert(1)</script>', $crawler->filter('main .rich-text p')->text());
        self::assertCount(0, $crawler->filter('main script'));
    }

    public function testEmptyTitleOrContentIsRefused(): void
    {
        $this->client->request('GET', '/admin/pages/'.$this->pageId('contact').'/modifier');
        $this->client->submitForm('Enregistrer', ['page[title]' => '', 'page[content]' => '  ', 'page[description]' => 'Gardée']);

        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#page_title ~ .admin-field__error', 'Saisir un titre.');
        self::assertSelectorTextSame('#page_content_error1', 'Saisir le contenu de la page.');
        self::assertSelectorTextSame('#page_description', 'Gardée');
    }

    public function testPhotoRequiresAltTextThenCanBeRemoved(): void
    {
        $id = $this->pageId('a-propos');
        $this->postWithPhoto($id, '');
        self::assertResponseStatusCodeSame(422);
        self::assertSelectorTextSame('#page_photoAlt ~ .admin-field__error', 'Décrivez la photo (ex. : Serife installe une table de mariage).');
        self::assertNull($this->em()->find(Page::class, $id)->getPhoto());

        $this->postWithPhoto($id, 'Serife dresse une table');
        self::assertResponseRedirects('/admin/pages');
        $page = $this->em()->find(Page::class, $id);
        self::assertNotNull($photo = $page->getPhoto());
        self::assertFileExists(static::getContainer()->getParameter('app.upload_dir').'/'.$photo);

        // Photo gardée : texte alternatif toujours obligatoire
        $this->client->request('GET', "/admin/pages/$id/modifier");
        $this->client->submitForm('Enregistrer', ['page[photoAlt]' => '']);
        self::assertResponseStatusCodeSame(422);

        $this->client->request('GET', "/admin/pages/$id/modifier");
        $this->client->submitForm('Enregistrer', ['page[removePhoto]' => '1', 'page[photoAlt]' => '']);
        self::assertResponseRedirects('/admin/pages');
        self::assertNull($this->em()->find(Page::class, $id)->getPhoto());
        self::assertFileDoesNotExist(static::getContainer()->getParameter('app.upload_dir').'/'.$photo);
    }

    public function testCreatePageOnFreeSlug(): void
    {
        foreach (['Recherche', 'Contact'] as $title) {
            $this->client->request('GET', '/admin/pages/ajouter');
            $this->client->submitForm('Enregistrer', ['page[title]' => $title, 'page[content]' => 'Texte de la page.']);
            self::assertResponseRedirects('/admin/pages');
        }

        // « recherche » est une route du site, « contact » existe déjà
        self::assertSame(['recherche-2' => 7, 'contact-2' => 8], array_map('intval', $this->db->fetchAllKeyValue('SELECT slug, position FROM page WHERE id > 6 ORDER BY id')));
        $this->client->request('GET', '/recherche-2');
        self::assertSelectorTextSame('main h1', 'Recherche');
    }

    public function testHideShowAndReorder(): void
    {
        $contact = $this->pageId('contact');
        $crawler = $this->client->request('GET', '/admin/pages');
        $this->client->submit($crawler->filter('button[aria-label="Monter Contact"]')->form());
        self::assertResponseRedirects('/admin/pages');
        self::assertSame(['contact', 'a-propos'], $this->db->fetchFirstColumn('SELECT slug FROM page ORDER BY position LIMIT 2'));

        $crawler = $this->client->request('GET', '/admin/pages');
        self::assertCount(0, $crawler->filter('button[aria-label="Monter Contact"]'));
        self::assertCount(0, $crawler->filter('button[aria-label="Descendre Gestion des cookies"]'));
        $this->client->submit($crawler->filter('button[aria-label="Masquer Contact"]')->form());
        $crawler = $this->client->followRedirect();
        self::assertStringContainsString('Masquée', $crawler->filter('.admin-list__row')->first()->text());
        self::assertFalse($this->em()->find(Page::class, $contact)->isVisible());

        $this->client->submit($crawler->filter('button[aria-label="Afficher à nouveau Contact"]')->form());
        self::assertTrue($this->em()->find(Page::class, $contact)->isVisible());

        // Jeton invalide : rien ne change
        $this->client->request('POST', "/admin/pages/$contact/visibilite", ['_token' => 'faux']);
        self::assertTrue($this->em()->find(Page::class, $contact)->isVisible());
    }

    private function pageId(string $slug): int
    {
        return (int) $this->db->fetchOne('SELECT id FROM page WHERE slug = ?', [$slug]);
    }

    private function postWithPhoto(int $id, string $alt): void
    {
        $uri = "/admin/pages/$id/modifier";
        $values = $this->client->request('GET', $uri)->selectButton('Enregistrer')->form()->getPhpValues();
        $values['page']['photoAlt'] = $alt;
        $path = "$this->tmp/photo.jpg";
        imagejpeg(imagecreatetruecolor(20, 20), $path);
        $this->client->request('POST', $uri, $values, ['page' => ['photoUpload' => new UploadedFile($path, 'photo.jpg', null, null, true)]]);
    }
}
