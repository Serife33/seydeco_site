<?php

namespace App\Tests\Service;

use App\Service\SimpleMarkdown;
use PHPUnit\Framework\TestCase;

/** Texte mis en forme simple (AD-17) : titres, paragraphes, listes, liens, gras ; jamais de HTML libre ni de script. */
final class SimpleMarkdownTest extends TestCase
{
    private function render(string $text): string
    {
        return (new SimpleMarkdown())->render($text);
    }

    public function testHeadingsParagraphsListsLinksAndBold(): void
    {
        $text = "# Qui sommes-nous ?\nSeydeco loue du **matériel**\nà Bordeaux.\n\n### Nos services\n- Chaises\n* Tables\nVoir [le catalogue](/location) ou [Instagram](https://instagram.com/seydeco?a=1&b=2).\n\n[Écrire](mailto:contact@seydeco.fr) · [Appeler](tel:+33612345678) · [FAQ](#faq)";

        self::assertSame(
            "<h2>Qui sommes-nous ?</h2>\n"
            ."<p>Seydeco loue du <strong>matériel</strong><br>\nà Bordeaux.</p>\n"
            ."<h3>Nos services</h3>\n"
            ."<ul>\n<li>Chaises</li>\n<li>Tables</li>\n</ul>\n"
            .'<p>Voir <a href="/location">le catalogue</a> ou <a href="https://instagram.com/seydeco?a=1&amp;b=2">Instagram</a>.</p>'."\n"
            .'<p><a href="mailto:contact@seydeco.fr">Écrire</a> · <a href="tel:+33612345678">Appeler</a> · <a href="#faq">FAQ</a></p>',
            $this->render($text),
        );
    }

    public function testHtmlIsEscapedAndDangerousLinksAreNotRendered(): void
    {
        self::assertSame('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', $this->render('<script>alert(1)</script>'));
        self::assertSame('<p>&lt;img src=x onerror=alert(1)&gt;</p>', $this->render('<img src=x onerror=alert(1)>'));
        // Lien javascript:, data:, protocole relatif : seul le texte reste
        self::assertSame('<p>x y z</p>', $this->render('[x](javascript:alert) [y](JaVaScript:alert) [z](//evil.example)'));
        self::assertSame('<p>x</p>', $this->render('[x](data:text/html;base64,PHNjcmlwdD4=)'));
        // Un guillemet ne peut pas sortir de l'attribut href
        self::assertSame('<p><a href="https://a.example/&quot;onmouseover=&quot;alert">x</a></p>', $this->render('[x](https://a.example/"onmouseover="alert)'));
    }

    public function testEmptyText(): void
    {
        self::assertSame('', $this->render(''));
        self::assertSame('', $this->render("\n\n  \n"));
    }
}
