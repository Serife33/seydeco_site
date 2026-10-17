import { Controller } from '@hotwired/stimulus';

/* Barre « Titre · Gras · Liste · Lien » : insère la syntaxe du texte mis en forme simple autour de la sélection. */
export default class extends Controller {
    static targets = ['input'];

    heading() { this.prefixLines('## '); }

    list() { this.prefixLines('- '); }

    bold() { this.wrap('**', '**', 'texte en gras'); }

    link() { this.wrap('[', '](https://)', 'texte du lien'); }

    wrap(before, after, placeholder) {
        const input = this.inputTarget;
        const { selectionStart: start, selectionEnd: end, value } = input;
        const text = value.slice(start, end) || placeholder;
        // Mise en forme ligne par ligne : le rendu ne lit pas un gras ou un lien coupé par un retour à la ligne
        const wrapped = text.split('\n').map((line) => (line ? before + line + after : line)).join('\n');
        this.replace(start, end, wrapped, start + before.length, start + wrapped.length - after.length);
    }

    prefixLines(prefix) {
        const input = this.inputTarget;
        const { value } = input;
        // Lignes entières touchées par la sélection
        const start = input.selectionStart === 0 ? 0 : value.lastIndexOf('\n', input.selectionStart - 1) + 1;
        // Lignes entières sélectionnées (triple clic) : la ligne suivante n'est pas touchée
        const selEnd = input.selectionEnd > input.selectionStart && value[input.selectionEnd - 1] === '\n' ? input.selectionEnd - 1 : input.selectionEnd;
        const end = value.indexOf('\n', selEnd) === -1 ? value.length : value.indexOf('\n', selEnd);
        // Un titre ou une puce déjà présent est remplacé, jamais empilé
        const lines = value.slice(start, end).split('\n').map((line) => prefix + line.replace(/^(#{1,3}\s+|[-*]\s+)/, ''));
        const text = lines.join('\n');
        this.replace(start, end, text, start + text.length, start + text.length);
    }

    replace(start, end, text, selectFrom, selectTo) {
        const input = this.inputTarget;
        input.focus();
        input.setRangeText(text, start, end);
        input.setSelectionRange(selectFrom, selectTo);
        // Prévient « modifications non enregistrées »
        input.dispatchEvent(new Event('input', { bubbles: true }));
    }
}
