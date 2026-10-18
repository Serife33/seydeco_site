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
        // Envoi coupé (réseau) : la saisie reste à l'écran, non enregistrée (UX-DR73)
        this.onSubmitEnd = (event) => {
            const failed = Boolean(event.detail.error);
            this.dirty ||= failed;
            this.element.querySelector('[data-save-error]')?.toggleAttribute('hidden', !failed);
        };
        this.element.addEventListener('input', this.markDirty);
        this.element.addEventListener('change', this.markDirty);
        this.element.addEventListener('submit', this.markClean);
        // « Ignorer » du brouillon remet le formulaire aux valeurs enregistrées
        this.element.addEventListener('reset', this.markClean);
        this.element.addEventListener('turbo:submit-end', this.onSubmitEnd);
        window.addEventListener('beforeunload', this.onBeforeUnload);
        document.addEventListener('turbo:before-visit', this.onBeforeVisit);

        // Formulaire refusé par le serveur : focus sur le premier champ en erreur
        // (ou sur une liste en erreur, ex. paliers de prix : message sous le tableau)
        this.element.querySelector('[aria-invalid="true"], [data-invalid-group]')?.focus();
    }

    disconnect() {
        this.element.removeEventListener('input', this.markDirty);
        this.element.removeEventListener('change', this.markDirty);
        this.element.removeEventListener('submit', this.markClean);
        this.element.removeEventListener('reset', this.markClean);
        this.element.removeEventListener('turbo:submit-end', this.onSubmitEnd);
        window.removeEventListener('beforeunload', this.onBeforeUnload);
        document.removeEventListener('turbo:before-visit', this.onBeforeVisit);
    }
}
