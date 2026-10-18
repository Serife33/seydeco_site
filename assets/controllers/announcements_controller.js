import { Controller } from '@hotwired/stimulus';

/* Bandeau d'annonces : pause / lecture du défilement (WCAG 2.2.2) ; en mouvement réduit, précédent / suivant. */
export default class extends Controller {
    static targets = ['item', 'pause'];

    toggle() {
        this.pauseTarget.setAttribute('aria-pressed', String(this.element.classList.toggle('is-paused')));
    }

    previous() {
        this.show(-1);
    }

    next() {
        this.show(1);
    }

    show(step) {
        const items = this.itemTargets;
        const current = Math.max(0, items.findIndex((item) => item.classList.contains('is-current')));
        items[current].classList.remove('is-current');
        items[(current + step + items.length) % items.length].classList.add('is-current');
    }
}
