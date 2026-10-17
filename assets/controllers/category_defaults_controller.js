import { Controller } from '@hotwired/stimulus';

/*
 * Nouveau produit : au choix de la catégorie, les champs casse / perte / consignes encore vides
 * reprennent les valeurs par défaut de la catégorie (attributs data-* de l'option choisie).
 * Ce qui est déjà saisi n'est jamais écrasé ; le serveur applique la même règle à l'enregistrement.
 */
export default class extends Controller {
    static targets = ['breakage', 'loss', 'instructions'];

    connect() {
        this.fill();
    }

    fill() {
        const option = this.element.querySelector('select[data-action~="category-defaults#fill"]')?.selectedOptions[0];
        if (!option) {
            return;
        }
        for (const name of ['breakage', 'loss', 'instructions']) {
            const field = this[`${name}Target`];
            const value = option.dataset[name];
            if (field && value && field.value.trim() === '') {
                field.value = value;
            }
        }
    }
}
