import { Controller } from '@hotwired/stimulus';

const MESSAGE = 'Vos modifications ne sont pas enregistrées. Quitter quand même ?';

/* Avertit avant de quitter un formulaire modifié (lien Turbo, fermeture ou rechargement de l'onglet). */
export default class extends Controller {
    connect() {
        this.dirty = false;
        this.markDirty = () => { this.dirty = true; };
        this.markClean = () => { this.dirty = false; };
        this.onBeforeUnload = (event) => {
            if (this.dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        this.onBeforeVisit = (event) => {
            if (this.dirty && !window.confirm(MESSAGE)) {
                event.preventDefault();
            }
        };
        this.element.addEventListener('input', this.markDirty);
        this.element.addEventListener('change', this.markDirty);
        this.element.addEventListener('submit', this.markClean);
        // « Ignorer » du brouillon remet le formulaire aux valeurs enregistrées
        this.element.addEventListener('reset', this.markClean);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        document.addEventListener('turbo:before-visit', this.onBeforeVisit);

        // Formulaire refusé par le serveur : focus sur le premier champ en erreur
        this.element.querySelector('[aria-invalid="true"]')?.focus();
    }

    disconnect() {
        this.element.removeEventListener('input', this.markDirty);
        this.element.removeEventListener('change', this.markDirty);
        this.element.removeEventListener('submit', this.markClean);
        this.element.removeEventListener('reset', this.markClean);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit);
    }
}
