import { Controller } from '@hotwired/stimulus';

const KEY = 'seydeco.selection.v1';

/*
 * Sélection « Mon devis » (story 1.13), seul contrôleur à toucher localStorage.
 * Schéma : {v:1, lines:[{productId, colorId?, qty}]} ; aucun prix calculé ici.
 * Posé sur <body> : compteur de l'en-tête, formulaires d'ajout (fiche et cartes), page /mon-devis.
 */
export default class extends Controller {
    static targets = ['counter', 'lines', 'storageError'];
    static values = { quoteUrl: String };

    connect() {
        // Autre onglet : compteur et page tenus à jour
        this.onStorage = (event) => { if (event.key === KEY) this.render(); };
        window.addEventListener('storage', this.onStorage);
    }

    disconnect() {
        window.removeEventListener('storage', this.onStorage);
    }

    // Cibles présentes au chargement ou arrivées avec Turbo : on les remplit
    counterTargetConnected() { this.render(); }
    linesTargetConnected() { this.render(); }

    read() {
        try {
            const data = JSON.parse(localStorage.getItem(KEY));
            if (data && data.v === 1 && Array.isArray(data.lines)) {
                return data.lines.filter((l) => Number.isInteger(l.productId) && l.productId > 0 && Number.isInteger(l.qty) && l.qty > 0);
            }
        } catch (e) { /* stockage illisible ou indisponible : sélection vide */ }
        return [];
    }

    write(lines) {
        try {
            localStorage.setItem(KEY, JSON.stringify({ v: 1, lines }));
            return true;
        } catch (e) {
            return false;
        }
    }

    render() {
        const lines = this.read();
        if (this.hasCounterTarget) {
            const n = lines.length;
            this.counterTarget.hidden = n === 0;
            this.counterTarget.textContent = n ? `Mon devis (${n})` : '';
            this.counterTarget.setAttribute('aria-label', `Mon devis, ${n} produit${n > 1 ? 's' : ''}`);
        }
        if (this.hasLinesTarget) {
            // Probe d'écriture : en navigation privée stricte, l'écriture échoue
            const writable = this.write(lines);
            if (this.hasStorageErrorTarget) this.storageErrorTarget.hidden = writable;
            const params = new URLSearchParams();
            lines.forEach((l) => params.append('l[]', `${l.productId}:${l.colorId ?? ''}:${l.qty}`));
            const query = params.toString();
            this.linesTarget.src = this.linesTarget.dataset.url + (query ? `?${query}` : '');
            this.linesTarget.removeAttribute('aria-busy');
        }
    }

    step({ currentTarget, params }) {
        const input = currentTarget.form.elements.qty;
        const value = /^\d+$/.test(input.value) ? Number(input.value) : Number(currentTarget.form.dataset.min);
        input.value = Math.max(Number(currentTarget.form.dataset.min), value + params.step);
        this.validate(currentTarget.form);
    }

    quantity({ currentTarget }) {
        this.validate(currentTarget.form);
    }

    // Quantité saisie (fiche) ou minimum (carte) ; null si refusée, avec le message sous le champ
    validate(form) {
        const { min, one, many } = form.dataset;
        const input = form.elements.qty;
        this.clearConfirmations();
        if (!input) return Number(min);
        let error = '';
        if (!/^\d+$/.test(input.value)) {
            error = 'Saisissez une quantité en chiffres.';
        } else if (Number(input.value) < Number(min)) {
            error = `Minimum ${min} ${Number(min) > 1 ? many : one}.`;
        }
        const message = form.querySelector('[data-role="error"]');
        message.textContent = error;
        message.hidden = !error;
        input.setAttribute('aria-invalid', String(!!error));
        const qty = error ? null : Number(input.value);
        this.showTier(qty);
        const whatsapp = form.querySelector('[data-role="whatsapp"]');
        if (whatsapp && qty) {
            whatsapp.href = `https://wa.me/${whatsapp.dataset.number}?text=${encodeURIComponent(whatsapp.dataset.message.replace('{qty}', qty))}`;
        }
        return qty;
    }

    // Surligne la ligne du tableau dégressif déjà rendue qui contient la quantité
    showTier(qty) {
        const label = document.querySelector('[data-role="tier"]');
        let reached = null;
        document.querySelectorAll('.price-tiers tbody tr').forEach((row) => {
            const match = qty !== null && qty >= Number(row.dataset.from) && (row.dataset.to === '' || qty <= Number(row.dataset.to));
            row.classList.toggle('is-reached', match);
            if (match) reached = row;
        });
        if (label) {
            label.hidden = !reached;
            if (reached) label.textContent = `Palier appliqué : ${reached.querySelector('th').textContent}`;
        }
    }

    add(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const confirm = form.querySelector('[data-role="confirm"]');
        const qty = this.validate(form);
        if (!qty || !confirm) {
            form.elements.qty?.focus();
            return;
        }
        const productId = Number(form.dataset.productId);
        // Coloris choisi sur la fiche (mémorisé par color-picker) ; aucun depuis une carte
        const color = form.elements.qty ? Number(form.closest('[data-selected-color]')?.dataset.selectedColor) : NaN;
        const colorId = color > 0 ? color : undefined;
        const lines = this.read();
        const same = lines.find((l) => l.productId === productId && (l.colorId ?? null) === (colorId ?? null));
        if (same) {
            same.qty += qty;
        } else {
            lines.push(colorId ? { productId, colorId, qty } : { productId, qty });
        }
        if (!this.write(lines)) {
            confirm.textContent = 'Votre navigateur ne peut pas garder votre sélection.';
            return;
        }
        const { one, many } = form.dataset;
        const link = document.createElement('a');
        link.href = this.quoteUrlValue;
        link.textContent = 'Voir mon devis';
        link.dataset.turboFrame = '_top';
        const check = document.createElement('span');
        check.className = 'add-confirm__check';
        check.setAttribute('aria-hidden', 'true');
        confirm.replaceChildren(check, `${qty} ${qty > 1 ? many : one} ajoutée${qty > 1 ? 's' : ''} à votre devis `, link);
        this.render();
    }

    remove({ params }) {
        const colorId = params.colorId || null;
        this.write(this.read().filter((l) => !(l.productId === params.productId && (l.colorId ?? null) === colorId)));
        this.render();
    }

    clearConfirmations() {
        document.querySelectorAll('[data-role="confirm"]').forEach((c) => c.replaceChildren());
    }
}
