import { Controller } from '@hotwired/stimulus';

const FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled])';
const MOBILE = '(max-width: 1023px)';

/*
 * En-tête public : menu plein écran < 1024 px (focus piégé, Échap / « Fermer » rendent le focus à « Menu »),
 * et en-tête masqué en descendant / réaffiché en remontant sur mobile (non collant sur desktop).
 */
export default class extends Controller {
    static targets = ['toggle', 'panel'];

    connect() {
        this.onKeydown = this.keydown.bind(this);
        this.lastY = window.scrollY;
        this.mobile = window.matchMedia(MOBILE);
        this.onMediaChange = () => { if (!this.mobile.matches) this.close(); };
        this.mobile.addEventListener('change', this.onMediaChange);
    }

    disconnect() {
        // Turbo garde la page en cache à la navigation : jamais avec le menu ouvert
        this.close();
        this.mobile.removeEventListener('change', this.onMediaChange);
        document.removeEventListener('keydown', this.onKeydown);
        document.body.classList.remove('has-open-panel');
    }

    open() {
        this.element.classList.remove('is-hidden');
        this.panelTarget.hidden = false;
        this.panelTarget.setAttribute('role', 'dialog');
        this.panelTarget.setAttribute('aria-modal', 'true');
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        document.body.classList.add('has-open-panel');
        document.addEventListener('keydown', this.onKeydown);
        this.focusables()[0]?.focus();
    }

    close() {
        if (this.panelTarget.hidden) {
            return;
        }
        this.panelTarget.hidden = true;
        this.panelTarget.removeAttribute('role');
        this.panelTarget.removeAttribute('aria-modal');
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('has-open-panel');
        document.removeEventListener('keydown', this.onKeydown);
        if (this.mobile.matches) {
            this.toggleTarget.focus();
        }
    }

    scroll() {
        const y = window.scrollY;
        const hide = this.mobile.matches && this.panelTarget.hidden && y > this.lastY && y > this.element.offsetHeight
            && !this.element.contains(document.activeElement);
        this.element.classList.toggle('is-hidden', hide);
        this.lastY = y;
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
