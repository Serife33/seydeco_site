import { Controller } from '@hotwired/stimulus';

/*
 * Lignes ajoutables / retirables d'une collection de formulaire (paliers, coloris, photos),
 * avec « Monter / Descendre » quand les lignes portent un champ [data-collection-position].
 */
export default class extends Controller {
    static targets = ['list'];
    static values = { prototype: String, index: Number };

    add() {
        const html = this.prototypeValue.replace(/__name__/g, String(this.indexValue++));
        this.listTarget.insertAdjacentHTML('beforeend', html);
        this.renumber();
        this.listTarget.lastElementChild?.querySelector('input, select, textarea')?.focus();
        this.changed();
    }

    remove(event) {
        const row = event.target.closest('[data-collection-row]');
        // Le focus passe à la ligne suivante, sinon précédente, sinon au bouton « Ajouter »
        const next = row.nextElementSibling ?? row.previousElementSibling;
        row.remove();
        (next?.querySelector('[data-action="collection#remove"]') ?? this.element.querySelector('[data-action="collection#add"]'))?.focus();
        this.renumber();
        this.changed();
    }

    up(event) {
        const row = event.target.closest('[data-collection-row]');
        row.previousElementSibling?.before(row);
        this.moved(event);
    }

    down(event) {
        const row = event.target.closest('[data-collection-row]');
        row.nextElementSibling?.after(row);
        this.moved(event);
    }

    moved(event) {
        event.target.focus();
        this.renumber();
        this.changed();
    }

    renumber() {
        this.listTarget.querySelectorAll('[data-collection-row]').forEach((row, i) => {
            const position = row.querySelector('[data-collection-position]');
            if (position) {
                position.value = i;
            }
        });
    }

    // Prévient l'avertissement « modifications non enregistrées »
    changed() {
        this.element.dispatchEvent(new Event('change', { bubbles: true }));
    }
}
