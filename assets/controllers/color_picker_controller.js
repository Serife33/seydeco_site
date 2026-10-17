import { Controller } from '@hotwired/stimulus';

/* Pastilles de coloris : une seule pressée, nom écrit à côté ; prévient la galerie (événement color-picker:change). */
export default class extends Controller {
    static targets = ['swatch', 'name'];

    connect() {
        const pressed = this.swatchTargets.find((swatch) => swatch.getAttribute('aria-pressed') === 'true');
        // Après la connexion de la galerie (même élément), pour qu'elle reçoive le coloris initial
        if (pressed) queueMicrotask(() => this.select(pressed));
    }

    choose(event) {
        this.select(event.currentTarget);
    }

    select(swatch) {
        this.swatchTargets.forEach((other) => other.setAttribute('aria-pressed', String(other === swatch)));
        this.nameTarget.textContent = swatch.dataset.colorName;
        // Mémorisé dans la page pour l'ajout au devis (story 1.13)
        this.element.dataset.selectedColor = swatch.dataset.colorId;
        this.dispatch('change', { detail: { id: swatch.dataset.colorId } });
    }
}
