import { Controller } from '@hotwired/stimulus';

const KEY = 'seydeco.selection.v1';
const role = (element, name) => element.querySelector(`[data-role="${name}"]`);
// Ligne de la page Mon devis ↔ ligne stockée : même produit, même coloris
const sameLine = (item, line) => Number(item.dataset.productId) === line.productId && (Number(item.dataset.colorId) || null) === (line.colorId ?? null);
// Mêmes bornes que le serveur (QuoteController, EstimateController)
const MAX_LINES = 100;
const MAX_QTY = 100000;
const MAX_SHARE = 4000;

// Lien de partage (story 3.8) : « produit.coloris.quantité » séparés par des virgules, en base64url ; aucune donnée personnelle
const encodeShare = (lines) => btoa(lines.map((l) => `${l.productId}.${l.colorId ?? ''}.${l.qty}`).join(','))
    .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

// Parties illisibles (lien tronqué, modifié, trop long, quantité invalide) ignorées et signalées par `damaged`
function decodeShare(value) {
    let b64 = value.replace(/-/g, '+').replace(/_/g, '/');
    let damaged = false;
    if (value.length > MAX_SHARE) return { lines: [], damaged: true };
    if (b64.length % 4 === 1) {
        b64 = b64.slice(0, -1);
        damaged = true;
    }
    let text;
    try {
        text = atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4));
    } catch (e) {
        return { lines: [], damaged: true };
    }
    const lines = [];
    text.split(',').forEach((part) => {
        const m = /^([1-9]\d{0,9})\.([1-9]\d{0,9})?\.([1-9]\d{0,5})$/.exec(part);
        if (!m || Number(m[3]) > MAX_QTY) {
            damaged = true;
            return;
        }
        const line = { productId: Number(m[1]), qty: Number(m[3]) };
        if (m[2]) line.colorId = Number(m[2]);
        const same = lines.find((l) => l.productId === line.productId && (l.colorId ?? null) === (line.colorId ?? null));
        if (same) {
            same.qty = Math.min(MAX_QTY, same.qty + line.qty);
        } else if (lines.length < MAX_LINES) {
            lines.push(line);
        } else {
            damaged = true;
        }
    });
    return { lines, damaged };
}

/*
 * Sélection « Mon devis » (story 1.13), seul contrôleur à toucher sa clé (Finaliser a la sienne, contrôleur finalize).
 * Schéma : {v:1, lines:[{productId, colorId?, qty}]} ; aucun prix calculé ici.
 * Posé sur <body> : compteur de l'en-tête, formulaires d'ajout (fiche, cartes, pack), page /mon-devis.
 * Mon devis (story 3.6) : montants repris tels quels du JSON de POST /estimation (PricingCalculator).
 * Barre d'estimation, tables suggérées (story 3.7) et lien de partage (story 3.8) : mêmes règles, aucun prix calculé ici.
 */
export default class extends Controller {
    static targets = ['counter', 'lines', 'storageError', 'summary', 'bar', 'shareChoice', 'shareError'];
    static values = { quoteUrl: String };

    connect() {
        // Autre onglet : compteur et page tenus à jour
        this.onStorage = (event) => { if (event.key === KEY) this.render(); };
        window.addEventListener('storage', this.onStorage);
        // Clavier virtuel ouvert (champ actif et écran visible réduit) : la Barre d'estimation se masque
        this.onViewport = () => requestAnimationFrame(() => {
            const field = document.activeElement?.matches('input:not([type="checkbox"]):not([type="radio"]), textarea, select');
            const viewport = window.visualViewport;
            if (!viewport) return;
            // Hauteur de référence = la plus grande vue pour cette largeur (Android réduit aussi innerHeight)
            if (viewport.width !== this.viewportWidth) {
                this.viewportWidth = viewport.width;
                this.fullHeight = 0;
            }
            this.fullHeight = Math.max(this.fullHeight, viewport.height);
            this.element.classList.toggle('keyboard-open', !!field && viewport.height < this.fullHeight * 0.75);
        });
        window.visualViewport?.addEventListener('resize', this.onViewport);
        this.element.addEventListener('focusin', this.onViewport);
        this.element.addEventListener('focusout', this.onViewport);
        // Hauteur des barres du bas (bandeau cookies + Barre) en scroll-padding : un élément focalisé n'est jamais caché dessous
        const bars = this.element.querySelector('.bottom-bars');
        if (bars && window.ResizeObserver) {
            this.barsObserver = new ResizeObserver(() => document.documentElement.style.setProperty('--bottom-bars-height', `${bars.offsetHeight}px`));
            this.barsObserver.observe(bars);
        }
    }

    disconnect() {
        window.removeEventListener('storage', this.onStorage);
        window.visualViewport?.removeEventListener('resize', this.onViewport);
        this.element.removeEventListener('focusin', this.onViewport);
        this.element.removeEventListener('focusout', this.onViewport);
        this.barsObserver?.disconnect();
    }

    // Cibles présentes au chargement ou arrivées avec Turbo : on les remplit
    counterTargetConnected() { this.render(); }
    barTargetConnected() { this.scheduleBar(); }
    linesTargetConnected() {
        this.openShared();
        this.render();
    }
    // Lignes arrivées dans le cadre : première estimation, sans annonce
    summaryTargetConnected() { this.estimate(false); }

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
        const lines = this.renderCounter();
        this.scheduleBar();
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

    renderCounter() {
        const lines = this.read();
        if (this.hasCounterTarget) {
            const n = lines.length;
            this.counterTarget.hidden = n === 0;
            this.counterTarget.textContent = n ? `Mon devis (${n})` : '';
            this.counterTarget.setAttribute('aria-label', `Mon devis, ${n} produit${n > 1 ? 's' : ''}`);
        }
        return lines;
    }

    // Compteur et Barre demandés ensemble au chargement : une seule estimation
    scheduleBar() {
        if (!this.hasBarTarget) return;
        clearTimeout(this.barTimer);
        this.barTimer = setTimeout(() => this.renderBar(), 50);
    }

    // Barre d'estimation : absente si la sélection est vide (ou le stockage indisponible) ; total du serveur, sinon le seul compteur
    async renderBar() {
        if (!this.hasBarTarget) return;
        const bar = this.barTarget;
        const lines = this.read();
        const n = lines.length;
        bar.hidden = n === 0;
        if (!n) return;
        role(bar, 'count').textContent = `Mon devis · ${n} produit${n > 1 ? 's' : ''}`;
        this.barRequest?.abort();
        const request = this.barRequest = new AbortController();
        let data = null;
        try {
            data = await this.requestEstimate(bar.dataset.url, lines, request.signal);
        } catch (e) {
            if (e.name === 'AbortError') return;
        }
        if (request !== this.barRequest || !bar.isConnected) return;
        role(bar, 'total').textContent = data ? `Estimation ${data.totalText}` : '';
    }

    async requestEstimate(url, lines, signal) {
        const response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ lines: lines.map((l) => ({ productId: l.productId, quantity: l.qty })) }),
            signal,
        });
        return response.ok ? response.json() : null;
    }

    // Lien /mon-devis?s=… : sans sélection, chargé directement ; sinon choix inline ; un lien abîmé n'efface jamais la sélection
    openShared() {
        const value = new URL(window.location.href).searchParams.get('s');
        if (value === null) return;
        const { lines, damaged } = decodeShare(value);
        if (this.hasShareErrorTarget) this.shareErrorTarget.hidden = !damaged;
        const current = this.read();
        if (lines.length && current.length && encodeShare(current) !== encodeShare(lines) && this.hasShareChoiceTarget) {
            // Paramètre gardé jusqu'au choix : un rechargement repropose le choix
            this.shared = lines;
            this.shareChoiceTarget.hidden = false;
            return;
        }
        if (lines.length && !current.length) this.write(lines);
        this.clearShareParam();
    }

    replaceShared() {
        this.write(this.shared ?? this.read());
        this.sharedDone();
    }

    // Quantités additionnées (même produit, même coloris)
    addShared() {
        const lines = this.read();
        (this.shared ?? []).forEach((l) => this.merge(lines, l.productId, l.colorId, l.qty));
        this.write(lines);
        this.sharedDone();
    }

    sharedDone() {
        this.shared = null;
        this.shareChoiceTarget.hidden = true;
        this.clearShareParam();
        this.render();
        this.element.querySelector('h1')?.focus();
    }

    clearShareParam() {
        const url = new URL(window.location.href);
        url.searchParams.delete('s');
        history.replaceState(history.state, '', url.pathname + url.search + url.hash);
    }

    // « Partager ma sélection » : partage natif s'il existe, sinon copie ; « Lien copié. » inline
    async share({ currentTarget }) {
        const status = currentTarget.parentElement.querySelector('[data-role="share-status"]');
        const url = new URL(this.quoteUrlValue, window.location.origin);
        url.searchParams.set('s', encodeShare(this.read()));
        status.textContent = '';
        if (navigator.share) {
            try {
                await navigator.share({ title: 'Ma sélection Seydeco', url: url.href });
                return;
            } catch (e) {
                // Partage annulé : rien à dire ; partage refusé : on copie
                if (e.name === 'AbortError') return;
            }
        }
        try {
            await navigator.clipboard.writeText(url.href);
            status.textContent = 'Lien copié.';
        } catch (e) {
            status.textContent = `Copiez ce lien : ${url.href}`;
        }
    }

    // « Ajouter 19 tables » : tables qui manquent ajoutées à la ligne de table, total recalculé par le serveur
    addTables() {
        const t = this.suggestedTables;
        if (!t) return;
        const lines = this.read();
        this.merge(lines, t.productId, t.colorId, t.missing);
        this.write(lines);
        const line = lines.find((l) => l.productId === t.productId && (l.colorId ?? null) === (t.colorId ?? null));
        const item = [...this.linesTarget.querySelectorAll('[data-quote-line]')].find((i) => sameLine(i, line));
        if (item) {
            const input = item.querySelector('input[name="qty"]');
            input.value = line.qty;
            input.focus();
        }
        this.renderCounter();
        this.scheduleEstimate();
    }

    // Le sélecteur de quantité appartient au formulaire de la fiche ou à une ligne de pack : l'élément portant data-min
    step({ currentTarget, params }) {
        const scope = currentTarget.closest('[data-min]');
        const input = scope.querySelector('input[name="qty"]');
        const min = Number(scope.dataset.min);
        const value = /^\d+$/.test(input.value) ? Number(input.value) : min;
        input.value = Math.max(min, value + params.step);
        this.changeLine(scope, this.validate(scope));
    }

    quantity({ currentTarget }) {
        const scope = currentTarget.closest('[data-min]');
        this.changeLine(scope, this.validate(scope));
    }

    // Ligne de Mon devis : quantité gardée tout de suite, total après 300 ms ; quantité refusée : rien n'est gardé
    changeLine(scope, qty) {
        if (!('quoteLine' in scope.dataset) || qty === null) return;
        const lines = this.read();
        const line = lines.find((l) => sameLine(scope, l));
        if (line) {
            line.qty = qty;
            this.write(lines);
        }
        this.scheduleEstimate();
    }

    // « Retirer » sur Mon devis : « Chaise retirée — Annuler » à la place de la ligne pendant 10 s (jamais de popup)
    removeLine({ currentTarget }) {
        const item = currentTarget.closest('[data-quote-line]');
        const lines = this.read();
        const index = lines.findIndex((l) => sameLine(item, l));
        const removed = index >= 0 ? lines.splice(index, 1)[0] : null;
        this.write(lines);
        this.renderCounter();
        item.hidden = true;
        const undo = document.createElement('li');
        undo.className = 'quote-line quote-line--removed';
        const text = document.createElement('p');
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'link-button';
        button.textContent = 'Annuler';
        text.append(`${item.dataset.removed} — `, button);
        undo.append(text);
        item.after(undo);
        button.focus();
        const timer = setTimeout(() => {
            const hadFocus = undo.contains(document.activeElement);
            undo.remove();
            item.remove();
            // Produit sorti de la sélection : sa casse et sa perte quittent l'Encadré Conditions
            if (!this.read().some((l) => l.productId === Number(item.dataset.productId))) {
                const damage = this.linesTarget.querySelector(`[data-damage-product="${Number(item.dataset.productId)}"]`);
                const list = damage?.parentElement;
                damage?.remove();
                if (list && !list.children.length) list.closest('li').remove();
            }
            // Plus aucune ligne : le cadre affiche « Votre devis est vide. »
            if (!this.linesTarget.querySelector('.quote-line')) {
                this.render();
            } else if (hadFocus && this.hasSummaryTarget) {
                this.summaryTarget.querySelector('h2').focus();
            }
        }, 10000);
        button.addEventListener('click', () => {
            clearTimeout(timer);
            const current = this.read();
            if (removed && !current.some((l) => sameLine(item, l))) {
                current.splice(Math.min(index, current.length), 0, removed);
                this.write(current);
                item.querySelector('input[name="qty"]').value = removed.qty;
            }
            undo.remove();
            item.hidden = false;
            item.querySelector('[data-action="selection#removeLine"]').focus();
            this.renderCounter();
            this.scheduleEstimate();
        });
        this.scheduleEstimate();
    }

    scheduleEstimate() {
        clearTimeout(this.estimateTimer);
        this.estimateTimer = setTimeout(() => this.estimate(true), 300);
    }

    // Seul le serveur chiffre ; une réponse dépassée par une plus récente est abandonnée
    async estimate(announce) {
        if (!this.hasSummaryTarget) return;
        const summary = this.summaryTarget;
        const lines = this.read();
        this.estimateRequest?.abort();
        const request = this.estimateRequest = new AbortController();
        let data = null;
        try {
            data = await this.requestEstimate(summary.dataset.url, lines, request.signal);
        } catch (e) {
            if (e.name === 'AbortError') return;
        }
        if (request !== this.estimateRequest || !summary.isConnected) return;
        summary.removeAttribute('aria-busy');
        const status = role(summary, 'status');
        // Échec : la sélection reste, le dernier total est barré ; nouvel essai à la prochaine modification
        summary.classList.toggle('is-stale', !data);
        status.hidden = !!data;
        if (!data) {
            status.textContent = navigator.onLine === false ? 'Pas de connexion. Votre demande est gardée sur ce téléphone.' : 'Estimation momentanément indisponible';
            return;
        }
        const items = [...this.linesTarget.querySelectorAll('[data-quote-line]')];
        items.forEach((item) => { role(item, 'unit').textContent = ''; role(item, 'amount').textContent = ''; });
        summary.querySelectorAll('[data-role="extra"]').forEach((row) => row.remove());
        const totalRow = role(summary, 'total').parentElement;
        data.lines.forEach((l) => {
            if (l.type === 'product') {
                const item = items.find((i) => lines[l.line] && sameLine(i, lines[l.line]));
                if (item) {
                    role(item, 'unit').textContent = `${l.unitPriceText} l'unité`;
                    role(item, 'amount').textContent = l.amountText;
                }
                return;
            }
            // Remise (et plus tard livraison, installation…) : une ligne à part avant le total
            const row = document.createElement('div');
            row.className = 'quote-summary__row';
            row.dataset.role = 'extra';
            const dt = document.createElement('dt');
            dt.textContent = l.label;
            const dd = document.createElement('dd');
            dd.textContent = l.amountText;
            row.append(dt, dd);
            totalRow.before(row);
        });
        role(summary, 'total').textContent = data.totalText;
        role(summary, 'deposit-label').textContent = `Acompte ${data.depositPercent} %`;
        role(summary, 'deposit').textContent = data.depositText;
        role(summary, 'balance').textContent = data.balanceText;
        const reasons = role(summary, 'reasons');
        reasons.replaceChildren(...data.reasons.map((r) => Object.assign(document.createElement('li'), { textContent: r.message })));
        reasons.hidden = !data.reasons.length;
        this.showTables(data.tables && lines[data.tables.line] ? data.tables : null, lines);
        if (announce) {
            clearTimeout(this.announceTimer);
            this.announceTimer = setTimeout(() => {
                role(summary, 'announce').textContent = `Total estimé ${data.totalText}, acompte ${data.depositText}.`;
            }, 1000);
        }
    }

    // Souvent loué avec : ligne des tables suggérées ; section masquée sans suggestion ni associé
    showTables(tables, lines) {
        const related = this.linesTarget.querySelector('[data-role="related"]');
        if (!related) return;
        const box = role(related, 'tables');
        box.hidden = !tables;
        related.hidden = !tables && !related.querySelector('.product-grid');
        this.suggestedTables = tables && { productId: lines[tables.line].productId, colorId: lines[tables.line].colorId, missing: tables.missing };
        if (!tables) return;
        role(box, 'tables-text').textContent = tables.text;
        const name = Object.assign(document.createElement('span'), { className: 'visually-hidden', textContent: ` : ${tables.productName}` });
        box.querySelector('button').replaceChildren(tables.buttonText, name);
    }

    // Quantité saisie (fiche, ligne de pack) ou minimum (carte) ; null si refusée, avec le message sous le champ
    validate(scope) {
        const { min, one, many } = scope.dataset;
        const input = scope.querySelector('input[name="qty"]');
        this.clearConfirmations();
        if (!input) return Number(min);
        let error = '';
        if (!/^\d+$/.test(input.value)) {
            error = 'Saisissez une quantité en chiffres.';
        } else if (Number(input.value) < Number(min)) {
            error = `Minimum ${min} ${Number(min) > 1 ? many : one}.`;
        }
        const message = scope.querySelector('[data-role="error"]');
        message.textContent = error;
        message.hidden = !error;
        input.setAttribute('aria-invalid', String(!!error));
        const qty = error ? null : Number(input.value);
        if (scope.dataset.tiers) {
            this.showPackPrice(scope, qty);
            this.packWhatsapp(scope.closest('form'));
        } else {
            this.showTier(qty);
            const whatsapp = scope.querySelector('[data-role="whatsapp"]');
            if (whatsapp && qty) {
                whatsapp.href = `https://wa.me/${whatsapp.dataset.number}?text=${encodeURIComponent(whatsapp.dataset.message.replace('{qty}', qty))}`;
            }
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

    // Ligne de pack : prix du palier repris des paliers rendus par le serveur ([de, à, « 3,50 € »]), aucun calcul ici
    showPackPrice(item, qty) {
        const price = item.querySelector('[data-role="price"]');
        const tier = qty === null ? null : JSON.parse(item.dataset.tiers).find(([from, to]) => qty >= from && (to === null || qty <= to));
        price.hidden = !tier;
        if (tier) price.textContent = `${tier[2]} l'unité`;
    }

    // Devis en ligne désactivé : le message WhatsApp reprend le contenu ajusté du pack
    packWhatsapp(form) {
        const whatsapp = form.querySelector('[data-role="whatsapp"]');
        if (!whatsapp) return;
        const items = [...form.querySelectorAll('[data-role="pack-item"]')].map((item) => {
            const value = item.querySelector('input[name="qty"]').value;
            return /^\d+$/.test(value) && Number(value) >= Number(item.dataset.min) ? `${item.dataset.name} × ${value}` : null;
        });
        // Quantité refusée : pas de lien avec d'anciennes quantités
        if (items.includes(null)) {
            whatsapp.removeAttribute('href');
            return;
        }
        whatsapp.href = `https://wa.me/${whatsapp.dataset.number}?text=${encodeURIComponent(whatsapp.dataset.message.replace('{items}', items.join(', ')))}`;
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
        const lines = this.read();
        this.merge(lines, productId, color > 0 ? color : undefined, qty);
        if (!this.write(lines)) {
            confirm.textContent = 'Votre navigateur ne peut pas garder votre sélection.';
            return;
        }
        const { one, many } = form.dataset;
        this.confirm(confirm, `${qty} ${qty > 1 ? many : one} ajoutée${qty > 1 ? 's' : ''} à votre devis `, form);
        this.render();
    }

    // Pack éclaté en lignes produit (sans coloris), les quantités s'ajoutant aux lignes déjà présentes
    addPack(event) {
        event.preventDefault();
        const form = event.currentTarget;
        const confirm = form.querySelector('[data-role="confirm"]');
        const items = [...form.querySelectorAll('[data-role="pack-item"]')];
        const quantities = items.map((item) => this.validate(item));
        const invalid = quantities.indexOf(null);
        if (invalid >= 0) {
            items[invalid].querySelector('input[name="qty"]').focus();
            return;
        }
        if (!items.length || !confirm) return;
        const lines = this.read();
        items.forEach((item, i) => this.merge(lines, Number(item.dataset.productId), undefined, quantities[i]));
        if (!this.write(lines)) {
            confirm.textContent = 'Votre navigateur ne peut pas garder votre sélection.';
            return;
        }
        const n = items.length;
        this.confirm(confirm, `${form.dataset.packName} ajouté à votre devis (${n} produit${n > 1 ? 's' : ''}) `, form);
        this.render();
    }

    // « Retirer » avant l'ajout : la ligne quitte le pack ; le focus passe à la ligne voisine
    removePackItem({ currentTarget }) {
        const item = currentTarget.closest('[data-role="pack-item"]');
        const form = item.closest('form');
        const next = item.nextElementSibling ?? item.previousElementSibling;
        item.remove();
        this.clearConfirmations();
        this.packWhatsapp(form);
        if (next) {
            next.querySelector('[data-role="remove"]').focus();
            return;
        }
        // Plus aucun produit : rien à ajouter, lien pour revenir au pack proposé
        form.querySelector('[data-role="pack-action"]').hidden = true;
        const empty = form.querySelector('[data-role="pack-empty"]');
        empty.hidden = false;
        empty.querySelector('a').focus();
    }

    // Même produit + même coloris : quantité cumulée
    merge(lines, productId, colorId, qty) {
        const same = lines.find((l) => l.productId === productId && (l.colorId ?? null) === (colorId ?? null));
        if (same) {
            same.qty += qty;
        } else {
            lines.push(colorId ? { productId, colorId, qty } : { productId, qty });
        }
    }

    // Confirmation d'ajout inline : coche, texte, « Voir mon devis », puis « Souvent loué avec : » s'il y a lieu (jamais de popup)
    confirm(element, text, form) {
        const link = document.createElement('a');
        link.href = this.quoteUrlValue;
        link.textContent = 'Voir mon devis';
        link.dataset.turboFrame = '_top';
        const check = document.createElement('span');
        check.className = 'add-confirm__check';
        check.setAttribute('aria-hidden', 'true');
        element.replaceChildren(check, text, link);
        const related = form.querySelector('template[data-role="related"]');
        if (related) element.append(' ', related.content.cloneNode(true));
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
