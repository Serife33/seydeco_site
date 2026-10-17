<?php

namespace App\Twig;

use App\Entity\Page;
use App\Repository\PageRepository;
use App\Service\SimpleMarkdown;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class ContentExtension
{
    /** @var array<string, string>|null */
    private ?array $pages = null;

    public function __construct(private readonly SimpleMarkdown $markdown, private readonly PageRepository $pageRepository)
    {
    }

    /** Texte mis en forme simple → HTML sûr (HTML saisi échappé, liens dangereux retirés). */
    #[AsTwigFilter('simple_markdown', isSafe: ['html'])]
    public function simpleMarkdown(?string $text): string
    {
        return $this->markdown->render($text);
    }

    /** Texte brut (description pour Google construite depuis le contenu). */
    #[AsTwigFilter('simple_markdown_text')]
    public function simpleMarkdownText(?string $text): string
    {
        return html_entity_decode(strip_tags($this->markdown->render($text)), \ENT_QUOTES | \ENT_HTML5, 'UTF-8');
    }

    /**
     * Pages visibles (slug → titre) dans l'ordre réglé par Serife, lues une fois par requête :
     * navigation et pied de page n'affichent que les pages existantes (aucun lien mort).
     *
     * @return array<string, string>
     */
    #[AsTwigFunction('site_pages')]
    public function sitePages(): array
    {
        return $this->pages ??= array_combine(
            array_map(fn (Page $p) => $p->getSlug(), $pages = $this->pageRepository->findOrdered(visibleOnly: true)),
            array_map(fn (Page $p) => $p->getTitle(), $pages),
        );
    }
}
