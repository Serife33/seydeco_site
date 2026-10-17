import { Controller } from '@hotwired/stimulus';

/*
 * Galerie de la fiche : vignettes → photo principale, plein écran dans un <dialog> modal
 * (focus piégé et Échap natifs), flèches clavier, glissement au doigt, filtre par coloris.
 * Sans JS : chaque photo est un simple lien vers l'image agrandie.
 */
export default class extends Controller {
    static targets = ['main', 'item', 'dialog', 'full', 'count', 'nav'];

    connect() {
        this.index = 0;
    }

    // Photos affichées (celles du coloris choisi) ; une seule photo sans vignettes : la principale
    visible() {
        return this.itemTargets.length ? this.itemTargets.filter((item) => !item.hidden) : [];
    }

    open(event) {
        event.preventDefault();
        if (!this.hasDialogTarget) return;
        const item = event.currentTarget.closest('[data-gallery-target="item"]');
        if (item) this.index = this.visible().indexOf(item);
        this.opener = event.currentTarget;
        this.dialogTarget.showModal();
        this.show();
    }

    close() {
        this.dialogTarget.close();
    }

    // Fermeture (bouton, Échap) : la photo vue devient la principale ; le focus revient à ce qui a ouvert
    // (photo principale, ou vignette de la photo vue si l'ouverture venait d'une vignette)
    closed() {
        const item = this.visible()[this.index];
        if (item) this.setMain(item);
        const fromThumb = item && this.opener !== this.mainTarget;
        (fromThumb ? item.querySelector('a') : this.opener)?.focus();
    }

    prev() {
        this.step(-1);
    }

    next() {
        this.step(1);
    }

    step(delta) {
        const count = Math.max(this.visible().length, 1);
        this.index = (this.index + delta + count) % count;
        this.show();
    }

    key(event) {
        if (event.key === 'ArrowLeft') this.prev();
        if (event.key === 'ArrowRight') this.next();
    }

    touchStart(event) {
        this.touchX = event.changedTouches[0].clientX;
    }

    touchEnd(event) {
        const dx = event.changedTouches[0].clientX - this.touchX;
        if (Math.abs(dx) > 50) this.step(dx < 0 ? 1 : -1);
    }

    // Image plein écran chargée seulement à l'ouverture
    show() {
        const items = this.visible();
        const item = items[this.index];
        const img = this.mainTarget.querySelector('img');
        this.fullTarget.src = item ? item.dataset.full : this.mainTarget.href;
        this.fullTarget.alt = item ? item.dataset.alt : img.alt;
        this.countTarget.textContent = items.length > 1 ? `${this.index + 1} / ${items.length}` : '';
        this.navTargets.forEach((button) => { button.hidden = items.length < 2; });
    }

    setMain(item) {
        const img = this.mainTarget.querySelector('img');
        img.src = item.dataset.src;
        img.srcset = item.dataset.srcset;
        img.alt = item.dataset.alt;
        this.mainTarget.href = item.dataset.full;
        this.itemTargets.forEach((other) => {
            const link = other.querySelector('a');
            if (other === item) link.setAttribute('aria-current', 'true');
            else link.removeAttribute('aria-current');
        });
    }

    // Coloris choisi : ses photos et celles sans coloris ; aucune photo de ce coloris → toutes
    filter(event) {
        const id = String(event.detail.id);
        const matches = (item) => item.dataset.color === id || item.dataset.color === '';
        const any = this.itemTargets.some((item) => item.dataset.color === id);
        this.itemTargets.forEach((item) => { item.hidden = any && !matches(item); });
        this.index = 0;
        const first = this.visible()[0];
        if (first) this.setMain(first);
    }
}
