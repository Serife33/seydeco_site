import { Controller } from '@hotwired/stimulus';

/*
 * Brouillon du formulaire dans ce navigateur (localStorage) : si la session expire pendant la saisie,
 * la saisie revient après reconnexion. Effacé une fois le produit enregistré (clear: true).
 */
export default class extends Controller {
    static values = { key: String, clear: Boolean, submitted: Boolean };

    connect() {
        if (this.clearValue) {
            this.write(null);
            return;
        }
        this.save = () => this.write(JSON.stringify(this.entries()));
        // Formulaire refusé puis réaffiché : le serveur montre déjà la dernière saisie, le brouillon la suit
        if (this.submittedValue) {
            this.save();
        } else {
            this.restore();
        }
        this.element.addEventListener('input', this.save);
        this.element.addEventListener('change', this.save);
    }

    disconnect() {
        if (this.save) {
            this.element.removeEventListener('input', this.save);
            this.element.removeEventListener('change', this.save);
        }
    }

    /** Bouton « Ignorer » : revenir aux valeurs enregistrées. */
    discard() {
        this.write(null);
        this.element.reset();
        this.notice()?.setAttribute('hidden', '');
    }

    restore() {
        let saved;
        try {
            saved = localStorage.getItem(this.keyValue);
        } catch {
            return;
        }
        if (!saved || saved === JSON.stringify(this.entries())) {
            return;
        }
        let entries;
        try {
            entries = JSON.parse(saved);
        } catch {
            return;
        }
        if (!Array.isArray(entries)) {
            return;
        }
        const before = JSON.stringify(this.entries());
        for (const field of this.element.elements) {
            if (field.type === 'checkbox' || field.type === 'radio') {
                field.checked = false;
            }
        }
        for (const [name, value] of entries) {
            for (const field of this.element.querySelectorAll(`[name="${CSS.escape(name)}"]`)) {
                if (field.type === 'checkbox' || field.type === 'radio') {
                    field.checked ||= field.value === value;
                } else {
                    field.value = value;
                }
            }
        }
        // Brouillon sans effet (ex. lignes de photo qui n'existent plus) : pas de message, brouillon remis à jour
        if (JSON.stringify(this.entries()) === before) {
            this.save();
            return;
        }
        this.notice()?.removeAttribute('hidden');
        // Saisie restaurée = saisie non enregistrée : l'avertissement de sortie s'applique
        this.element.dispatchEvent(new Event('input', { bubbles: true }));
    }

    entries() {
        return [...new FormData(this.element)].filter(([name, value]) => typeof value === 'string' && !name.endsWith('[_token]'));
    }

    notice() {
        return this.element.querySelector('[data-form-draft-notice]');
    }

    write(value) {
        try {
            value === null ? localStorage.removeItem(this.keyValue) : localStorage.setItem(this.keyValue, value);
        } catch {
            // localStorage indisponible (navigation privée…) : pas de brouillon, le formulaire marche quand même
        }
    }
}
