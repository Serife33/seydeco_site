import { Controller } from '@hotwired/stimulus';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled])';
const MOBILE = '(max-width: 1023px)';

// Le cadre Turbo remplace le panneau à chaque filtre : on le rouvre sur le champ qui avait le focus
let reopenOn = null;

/*
 * Filtres du catalogue : soumission automatique via le cadre Turbo (sans rechargement complet),
 * panneau plein écran < 1024 px (focus piégé, Échap). Sans JS : formulaire classique + « Appliquer ».
 */
export default class extends Controller {
    static targets = ['form', 'panel', 'toggle'];
    static values = { count: String };

    connect() {
        this.element.classList.add('is-enhanced');
        this.onKeydown = this.keydown.bind(this);
        // Passage à ≥ 1024 px panneau ouvert : il redevient une colonne, on lève le piège
        this.mobile = window.matchMedia(MOBILE);
        this.onMediaChange = () => { if (!this.mobile.matches) this.close(); };
        this.mobile.addEventListener('change', this.onMediaChange);
        // Nombre de résultats annoncé par la région aria-live restée hors du cadre
        const live = document.getElementById('catalog-count');
        if (live && live.textContent !== this.countValue) {
            live.textContent = this.countValue;
        }
        if (reopenOn && this.mobile.matches) {
            this.open();
            document.getElementById(reopenOn)?.focus();
        }
        reopenOn = null;
    }

    disconnect() {
        this.mobile.removeEventListener('change', this.onMediaChange);
        document.removeEventListener('keydown', this.onKeydown);
        document.body.classList.remove('has-open-panel');
    }

    submit(event) {
        reopenOn = this.isOpen() ? event.target.id : null;
        this.formTarget.requestSubmit();
    }

    open() {
        this.panelTarget.classList.add('is-open');
        this.panelTarget.setAttribute('role', 'dialog');
        this.panelTarget.setAttribute('aria-modal', 'true');
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        document.body.classList.add('has-open-panel');
        document.addEventListener('keydown', this.onKeydown);
        this.focusables()[0]?.focus();
    }

    close() {
        if (!this.isOpen()) {
            return;
        }
        this.panelTarget.classList.remove('is-open');
        this.panelTarget.removeAttribute('role');
        this.panelTarget.removeAttribute('aria-modal');
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('has-open-panel');
        document.removeEventListener('keydown', this.onKeydown);
        if (this.mobile.matches) {
            this.toggleTarget.focus();
        }
    }

    isOpen() {
        return this.panelTarget.classList.contains('is-open');
    }

    focusables() {
        return [...this.panelTarget.querySelectorAll(FOCUSABLE)].filter((el) => el.offsetParent !== null);
    }

    keydown(event) {
        if (event.key === 'Escape') {
            this.close();
        } else if (event.key === 'Tab') {
            const items = this.focusables();
            if (items.length === 0) {
                event.preventDefault();
                return;
            }
            const first = items[0];
            const last = items[items.length - 1];
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            } else if (!this.panelTarget.contains(document.activeElement)) {
                event.preventDefault();
                first.focus();
            }
        }
    }
}
