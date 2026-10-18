import { Controller } from '@hotwired/stimulus';

/* Bouton « Copier » (story 3.15) : copie le texte du champ cible, « Lien copié. » annoncé inline. */
export default class extends Controller {
    static targets = ['source', 'status'];

    async copy() {
        const text = this.sourceTarget.value;
        try {
            await navigator.clipboard.writeText(text);
            this.statusTarget.textContent = 'Lien copié.';
        } catch (e) {
            this.sourceTarget.select();
            this.statusTarget.textContent = 'Sélectionnez le lien pour le copier.';
        }
    }
}
