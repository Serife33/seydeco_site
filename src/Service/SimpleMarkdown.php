<?php

namespace App\Service;

/**
 * Texte mis en forme simple des pages et de la FAQ (AD-17) : « # » / « ## » titre, « ### » sous-titre, « - » liste,
 * **gras**, [texte](lien) ; ligne vide = nouveau paragraphe. Tout HTML saisi est échappé ;
 * un lien autre que http(s), mailto, tel ou interne (/…, #…) n'est pas rendu (seul son texte reste).
 */
final class SimpleMarkdown
{
    public function render(?string $text): string
    {
        $html = $paragraph = $list = [];
        $flush = function () use (&$html, &$paragraph, &$list): void {
            if ($paragraph) {
                $html[] = '<p>'.implode("<br>\n", $paragraph).'</p>';
            }
            if ($list) {
                $html[] = "<ul>\n".implode("\n", $list)."\n</ul>";
            }
            $paragraph = $list = [];
        };
        foreach (preg_split('/\R/u', (string) $text) as $line) {
            $line = trim($line);
            if (preg_match('/^(#{1,3})\s+(.+)$/u', $line, $m)) {
                $flush();
                $level = 3 === \strlen($m[1]) ? 3 : 2;
                $html[] = "<h$level>".self::inline($m[2])."</h$level>";
            } elseif (preg_match('/^[-*]\s+(.+)$/u', $line, $m)) {
                if ($paragraph) {
                    $flush();
                }
                $list[] = '<li>'.self::inline($m[1]).'</li>';
            } elseif ('' === $line || $list) {
                $flush();
                if ('' !== $line) {
                    $paragraph[] = self::inline($line);
                }
            } else {
                $paragraph[] = self::inline($line);
            }
        }
        $flush();

        return implode("\n", $html);
    }

    private static function inline(string $text): string
    {
        $text = htmlspecialchars($text, \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML5, 'UTF-8');
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/u', static fn (array $m) => preg_match('~^(https?://|mailto:|tel:|/(?!/)|#)~i', $m[2])
            ? '<a href="'.$m[2].'">'.$m[1].'</a>'
            : $m[1], $text);

        return preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $text);
    }
}
