<?php

namespace App\Twig;

use App\Entity\Announcement;
use App\Entity\Page;
use App\Entity\Review;
use App\Repository\AnnouncementRepository;
use App\Repository\PageRepository;
use App\Repository\ReviewRepository;
use App\Service\SimpleMarkdown;
use Twig\Attribute\AsTwigFilter;
use Twig\Attribute\AsTwigFunction;

final class ContentExtension
{
    /** @var array<string, string>|null */
    private ?array $pages = null;
    /** @var list<Review>|null lus une fois par requête (Bloc avis + JSON-LD) */
    private ?array $visibleReviews = null;

    public function __construct(
        private readonly SimpleMarkdown $markdown,
        private readonly PageRepository $pageRepository,
        private readonly AnnouncementRepository $announcements,
        private readonly ReviewRepository $reviews,
    ) {
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

    /**
     * Annonces du bandeau en cours aujourd'hui (Europe/Paris) : une annonce terminée disparaît d'elle-même le lendemain.
     *
     * @return list<Announcement>
     */
    #[AsTwigFunction('active_announcements')]
    public function activeAnnouncements(): array
    {
        return $this->announcements->findActive(new \DateTimeImmutable('today'));
    }

    /** @return list<Review> avis visibles du Bloc avis, dans l'ordre de Serife */
    #[AsTwigFunction('visible_reviews')]
    public function visibleReviews(): array
    {
        return $this->visibleReviews ??= $this->reviews->findOrdered(visibleOnly: true);
    }
}
