import { Controller } from '@hotwired/stimulus';

// Saisies de Finaliser (stories 3.9, 3.10) : clé à part de la sélection, sans donnée de paiement ; à vider après l'envoi (story 3.12)
const KEY = 'seydeco.finaliser.v1';
const CHOICES = ['pickupSlot', 'returnSlot', 'deliveryDate', 'collectDate'];
// Raisons affichées sous l'option livraison ou l'adresse, pas dans l'estimation
const DELIVERY_REASONS = ['delivery_min_chairs', 'delivery_max_km', 'distance_unknown'];
const DATE_TOO_SOON = 'Choisissez une date au moins 24 h à l\'avance.';
// Coordonnées (étape 3) gardées avec les saisies jusqu'à l'envoi ; les cases à cocher ne sont jamais gardées
const CONTACT = ['firstName', 'lastName', 'phone', 'email', 'companyName', 'siret', 'billingAddress', 'access', 'source'];
const CONSENTS = ['acceptTerms', 'acceptPrivacy'];
const OFFLINE = 'Pas de connexion. Votre demande est gardée sur ce téléphone.';
const FAILED = 'Le devis n\'a pas pu être créé. Réessayez, ou écrivez-nous sur WhatsApp.';
// « demande[firstName] » → « firstName »
const formName = (element) => /^demande\[(\w+)\]$/.exec(element.name ?? '')?.[1];
// Date du jour en heure de Paris, « AAAA-MM-JJ »
const parisToday = () => new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Paris' }).format(new Date());
const role = (element, name) => element.querySelector(`[data-role="${name}"]`);
const offline = () => navigator.onLine === false;

/*
 * « Finaliser ma demande » : une étape visible à la fois. La sélection est lue par le contrôleur selection (outlet),
 * seul à toucher sa clé. Aucun prix, créneau ni contrôle de période calculé ici : tout vient de POST /estimation.
 */
export default class extends Controller {
    static targets = ['form', 'step', 'stepLabel', 'progress', 'errors', 'pickupNote', 'delivery', 'deliveryReason', 'distance',
        'installPrices', 'cleaningBox', 'cleaningNote', 'summary', 'slotGroup', 'noSlot', 'slotsStatus', 'addressRecap', 'recap',
        'draft', 'send', 'sendStatus'];
    static outlets = ['selection'];
    static values = { quoteUrl: String, estimateUrl: String, whatsapp: String };

    selectionOutletConnected() {
        this.lines = this.selectionOutlet.read();
        // Sélection vide (ou stockage indisponible) : retour à Mon devis
        if (!this.lines.length) {
            window.location.replace(this.quoteUrlValue);
            return;
        }
        this.entries = this.load();
        const fields = this.formTarget.elements;
        // Au plus tôt demain, heure de Paris (revérifié par le serveur)
        const tomorrow = new Date(`${parisToday()}T12:00:00Z`);
        tomorrow.setUTCDate(tomorrow.getUTCDate() + 1);
        fields.date.min = tomorrow.toISOString().slice(0, 10);
        fields.date.value = this.entries.date ?? '';
        fields.address.value = this.entries.address ?? '';
        ['collect', 'installation', 'cleaning'].forEach((name) => { if (fields[name]) fields[name].checked = !!this.entries[name]; });
        CONTACT.forEach((name) => { if (this.entries.contact?.[name]) fields[`demande[${name}]`].value = this.entries.contact[name]; });
        this.checkMode(this.entries.mode);
        this.showStep(0, false);
        this.toggle();
        this.refresh();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.request?.abort();
    }

    load() {
        try {
            const data = JSON.parse(localStorage.getItem(KEY));
            if (data && data.v === 1) return data;
        } catch (e) { /* stockage illisible ou indisponible : on repart de zéro */ }
        return { v: 1 };
    }

    save() {
        try {
            localStorage.setItem(KEY, JSON.stringify(this.entries));
        } catch (e) { /* stockage plein ou bloqué : les saisies restent dans la page */ }
    }

    checkMode(mode) {
        this.formTarget.querySelectorAll('input[name="mode"]').forEach((radio) => { radio.checked = radio.value === mode; });
    }

    // Toute saisie est gardée tout de suite ; l'estimation suit après 300 ms (l'adresse, seulement à la sortie du champ)
    change(event) {
        // Coordonnées et cases : gardées (sauf les cases), sans nouvelle estimation
        const name = formName(event.target);
        if (name) {
            if (CONTACT.includes(name)) {
                this.entries.contact = { ...this.entries.contact, [name]: event.target.value };
                this.save();
            }
            this.fieldError(name, '');
            return;
        }
        const fields = this.formTarget.elements;
        const date = fields.date.value;
        // Nouvelle date : les créneaux sont à choisir de nouveau
        if (date !== (this.entries.date ?? '')) CHOICES.forEach((name) => delete this.entries[name]);
        Object.assign(this.entries, {
            date,
            mode: fields.mode.value,
            address: fields.address.value.trim(),
            collect: fields.collect.checked,
            installation: fields.installation.checked,
            cleaning: !!fields.cleaning?.checked,
        });
        this.save();
        if (event.target.name) this.fieldError(event.target.name, '');
        this.toggle();
        if (event.type === 'input' && event.target.name === 'address') return;
        this.schedule();
    }

    toggle() {
        const { mode, cleaning } = this.entries;
        this.pickupNoteTarget.hidden = mode !== 'pickup';
        this.deliveryTarget.hidden = mode !== 'delivery';
        if (this.hasCleaningNoteTarget) this.cleaningNoteTarget.hidden = !!cleaning;
        this.renderSlots(this.result?.slots);
    }

    schedule() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.refresh(), 300);
    }

    refresh() {
        clearTimeout(this.timer);
        this.timer = null;
        this.pending = this.estimate();
        return this.pending;
    }

    // Attend la réponse qui correspond aux dernières saisies
    async settle() {
        if (this.timer) this.refresh();
        let pending;
        do {
            pending = this.pending;
            await pending;
        } while (pending !== this.pending);
    }

    // Demande telle que le serveur la recalcule : estimation et envoi (story 3.12)
    payload() {
        const d = this.entries;
        const delivery = d.mode === 'delivery';
        return {
            lines: this.lines.map((l) => ({ productId: l.productId, quantity: l.qty, ...(l.colorId ? { colorId: l.colorId } : {}) })),
            date: d.date || null,
            mode: d.mode || null,
            address: delivery ? d.address || '' : '',
            // `pickup` = récupération après la livraison ; installation seulement en livraison
            options: { pickup: delivery && !!d.collect, installation: delivery && !!d.installation, cleaning: !!d.cleaning },
            period: Object.fromEntries(CHOICES.filter((name) => d[name]).map((name) => [name, d[name]])),
        };
    }

    async estimate() {
        const d = this.entries;
        if (d.mode === 'delivery' && d.address) this.distanceTarget.textContent = 'Calcul de la distance…';
        this.request?.abort();
        const request = this.request = new AbortController();
        let data = null;
        try {
            const response = await fetch(this.estimateUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify(this.payload()),
                signal: request.signal,
            });
            data = response.ok ? await response.json() : null;
        } catch (e) {
            if (e.name === 'AbortError') return;
        }
        if (request !== this.request) return;
        this.result = data;
        this.render(data);
    }

    render(data) {
        const d = this.entries;
        const delivery = d.mode === 'delivery';
        this.renderSummary(data);
        this.slotsStatusTarget.textContent = data ? '' : (offline() ? 'Pas de connexion. Votre demande est gardée sur ce téléphone.' : 'Créneaux momentanément indisponibles. Réessayez dans un instant.');
        if (!data) {
            this.distanceTarget.textContent = '';
            return;
        }
        // Livraison impossible : raison chiffrée sous l'option ; sous le minimum de chaises, l'option est inactive
        const reasons = data.deliveryReasons;
        const radio = this.formTarget.querySelector('input[name="mode"][value="delivery"]');
        this.deliveryReasonTarget.textContent = reasons.map((r) => r.message).join(' ');
        this.deliveryReasonTarget.hidden = !reasons.length;
        radio.disabled = reasons.some((r) => r.type === 'delivery_min_chairs');
        if (radio.disabled && delivery) {
            d.mode = '';
            this.checkMode('');
            this.save();
            this.toggle();
            this.schedule();
        }
        // Distance par la route, ou adresse introuvable (frais confirmés par Serife)
        const line = data.lines.find((l) => l.type === 'delivery');
        const unknown = data.reasons.find((r) => r.type === 'distance_unknown');
        this.distanceTarget.textContent = !delivery || !d.address ? '' : (unknown ? unknown.message : (line?.km ? `Distance par la route : ${line.km} km.` : ''));
        this.installPricesTarget.replaceChildren(...data.installation.map((i) => Object.assign(document.createElement('li'), { textContent: `${i.label} : ${i.text}` })));
        if (this.hasCleaningBoxTarget) this.cleaningBoxTarget.hidden = !(data.chairs > 0);
        if (data.dateError) this.fieldError('date', data.dateError);
        this.renderSlots(data.slots);
    }

    renderSummary(data) {
        const summary = this.summaryTarget;
        summary.removeAttribute('aria-busy');
        const status = role(summary, 'status');
        // Échec : le dernier total est barré ; nouvel essai à la prochaine saisie
        summary.classList.toggle('is-stale', !data);
        status.hidden = !!data;
        if (!data) {
            status.textContent = offline() ? 'Pas de connexion. Votre demande est gardée sur ce téléphone.' : 'Estimation momentanément indisponible';
            return;
        }
        summary.querySelectorAll('[data-role="extra"]').forEach((row) => row.remove());
        const totalRow = role(summary, 'total').parentElement;
        data.lines.filter((l) => l.type !== 'product').forEach((l) => {
            const row = document.createElement('div');
            row.className = 'quote-summary__row';
            row.dataset.role = 'extra';
            const dt = document.createElement('dt');
            dt.textContent = l.km ? `${l.label} (${l.km} km)` : l.label;
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
        const shown = data.reasons.filter((r) => !DELIVERY_REASONS.includes(r.type));
        reasons.replaceChildren(...shown.map((r) => Object.assign(document.createElement('li'), { textContent: r.message })));
        reasons.hidden = !shown.length;
        role(summary, 'note').hidden = !(this.entries.mode === 'delivery' && data.excludesDelivery);
    }

    // Grilles de l'étape Créneaux : retrait puis retour au dépôt ; en livraison, dates seules (et retour au dépôt sans récupération)
    renderSlots(slots) {
        const d = this.entries;
        const visible = d.mode === 'delivery' ? ['deliveryDate', d.collect ? 'collectDate' : 'returnSlot'] : ['pickupSlot', 'returnSlot'];
        const shown = this.slotGroupTargets.filter((group) => slots && visible.includes(group.dataset.name));
        const empty = shown.some((group) => !slots[group.dataset.list].length);
        this.noSlotTarget.hidden = !empty;
        this.slotGroupTargets.forEach((group) => {
            group.hidden = empty || !shown.includes(group);
            if (group.hidden) return;
            const name = group.dataset.name;
            const days = slots[group.dataset.list];
            const buttons = 'days' in group.dataset
                ? days.map((day) => this.slotButton(name, day.date, [day.label]))
                : days.flatMap((day) => day.slots.map((slot) => this.slotButton(name, slot.value, [day.label, slot.label])));
            group.querySelector('.slot-grid').replaceChildren(...buttons);
        });
    }

    slotButton(name, value, texts) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'button-secondary slot';
        button.setAttribute('aria-pressed', String(this.entries[name] === value));
        button.dataset.action = 'finalize#pick';
        button.dataset.name = name;
        button.dataset.value = value;
        button.append(...texts.map((text) => Object.assign(document.createElement('span'), { textContent: text })));
        return button;
    }

    pick({ currentTarget }) {
        const { name, value } = currentTarget.dataset;
        this.entries[name] = value;
        this.save();
        currentTarget.parentElement.querySelectorAll('button').forEach((b) => b.setAttribute('aria-pressed', String(b === currentTarget)));
        this.fieldError(name, '');
        // Période revérifiée par le serveur
        this.schedule();
    }

    async next(event) {
        event.preventDefault();
        if (this.sending) return;
        await this.settle();
        if (this.index === 3) {
            this.sendStatusTarget.replaceChildren();
            // Case non cochée : message sous la case et focus dessus, rien n'est envoyé
            const missing = CONSENTS.map((name) => this.formTarget.elements[`demande[${name}]`]).filter((box) => !box.checked);
            this.showErrors([]);
            missing.forEach((box) => this.fieldError(formName(box), box.dataset.missing));
            if (missing.length) missing[0].focus();
            else this.send();
            return;
        }
        const errors = [this.checkDateAndMode, this.checkSlots, this.checkContact][this.index].call(this);
        this.showErrors(errors);
        if (!errors.length) this.showStep(this.index + 1);
    }

    back() {
        this.showStep(Math.max(0, this.index - 1));
    }

    // « Modifier » du récapitulatif ou de l'adresse
    goTo({ params: { step } }) {
        this.showStep(step);
    }

    // Champs obligatoires, email, téléphone et SIRET : messages du serveur portés par les champs (data-missing, data-invalid)
    checkContact() {
        const errors = [];
        this.stepTargets[2].querySelectorAll('input, select, textarea').forEach((field) => {
            const name = formName(field);
            if (!name) return;
            if (field.required && !field.value.trim()) errors.push([name, field.dataset.missing]);
            else if (field.value.trim() && (field.validity.typeMismatch || field.validity.patternMismatch) && field.dataset.invalid) errors.push([name, field.dataset.invalid]);
        });
        return errors;
    }

    // Envoi (story 3.12) : bouton inactif pendant l'envoi, tout est revérifié et recalculé par le serveur
    async send() {
        this.sending = true;
        this.sendTarget.disabled = true;
        this.sendTarget.textContent = 'Création du devis…';
        this.draftTarget.value = JSON.stringify(this.payload());
        let response = null;
        let data = null;
        try {
            response = await fetch(this.formTarget.action, { method: 'POST', body: new FormData(this.formTarget), headers: { Accept: 'application/json' } });
            data = await response.json();
        } catch (e) { /* hors ligne ou réponse illisible */ }
        if (data?.redirect) {
            // Sélection et saisies vidées du navigateur
            this.selectionOutlet.clear();
            try { localStorage.removeItem(KEY); } catch (e) { /* stockage indisponible */ }
            window.location.assign(data.redirect);
            return;
        }
        this.sending = false;
        this.sendTarget.disabled = false;
        this.sendTarget.textContent = 'Obtenir mon devis';
        if (data?.errors) {
            this.serverErrors(data.errors);
            return;
        }
        // Saisie conservée ; message avec WhatsApp
        const message = !response && offline() ? OFFLINE : (data?.error ?? FAILED);
        const parts = [message];
        if (message !== OFFLINE && this.whatsappValue) {
            parts.push(' ', Object.assign(document.createElement('a'), { href: this.whatsappValue, target: '_blank', rel: 'noopener', textContent: 'Écrire sur WhatsApp' }));
        }
        this.sendStatusTarget.replaceChildren(...parts);
    }

    // Erreurs du serveur : étape du premier champ en cause, sinon l'étape en cours
    serverErrors(errors) {
        const stepOf = (name) => {
            const p = this.element.querySelector(`[data-error-for="${name}"]`);
            return p ? this.stepTargets.indexOf(p.closest('[data-finalize-target="step"]')) : -1;
        };
        const step = errors.map(([name]) => stepOf(name)).find((i) => i >= 0) ?? this.index;
        this.showStep(step, false);
        this.showErrors(errors.filter(([name]) => [step, -1].includes(stepOf(name))));
    }

    // [champ, message] ; date et mode revérifiés par le serveur (date, raisons de livraison)
    checkDateAndMode() {
        const d = this.entries;
        const r = this.result;
        const errors = [];
        if (!d.date) errors.push(['date', 'Indiquez la date de votre événement.']);
        else if (d.date <= parisToday()) errors.push(['date', DATE_TOO_SOON]);
        else if (r?.dateError) errors.push(['date', r.dateError]);
        if (!d.mode) {
            errors.push(['mode', 'Choisissez le retrait au dépôt ou la livraison.']);
        } else if (d.mode === 'delivery' && !d.address) {
            errors.push(['address', 'Indiquez l\'adresse du lieu de l\'événement.']);
        } else if (d.mode === 'delivery' && r?.deliveryReasons.length) {
            errors.push(['mode', `${r.deliveryReasons.map((reason) => reason.message).join(' ')} Le retrait au dépôt reste possible.`]);
        }
        return errors;
    }

    // Le serveur revérifie la période contre les créneaux ouverts et donne le premier point à corriger
    checkSlots() {
        const r = this.result;
        if (!r || !r.slots) return [['slots', this.slotsStatusTarget.textContent || 'Créneaux momentanément indisponibles. Réessayez dans un instant.']];
        if (!this.noSlotTarget.hidden) return [['slots', 'Aucun créneau d\'ouverture autour de cette date. Écrivez-nous sur WhatsApp.']];
        return r.period?.error ? [[r.period.field, r.period.error]] : [];
    }

    showStep(index, focus = true) {
        this.index = index;
        this.stepTargets.forEach((step, i) => { step.hidden = i !== index; });
        this.stepLabelTarget.textContent = `Étape ${index + 1} sur 4 · ${this.stepTargets[index].dataset.title}`;
        this.progressTarget.style.width = `${(index + 1) * 25}%`;
        this.showErrors([]);
        if (index === 2) this.renderAddress();
        if (index === 3) this.renderRecap();
        if (focus) this.stepTargets[index].querySelector('h2').focus();
    }

    // Résumé en tête d'étape (liens vers les champs) + message sous chaque champ, relié par aria-describedby
    showErrors(errors) {
        this.element.querySelectorAll('[data-error-for]').forEach((p) => this.fieldError(p.dataset.errorFor, ''));
        const list = this.errorsTarget.querySelector('ul');
        list.replaceChildren(...errors.map(([name, message]) => {
            this.fieldError(name, message);
            const item = document.createElement('li');
            const field = this.fieldId(name);
            // Champ facultatif replié : ouvert pour être corrigé
            if (field) document.getElementById(field).closest('details')?.setAttribute('open', '');
            if (!field) {
                item.textContent = message;
                return item;
            }
            item.append(Object.assign(document.createElement('a'), { href: `#${field}`, textContent: message }));
            return item;
        }));
        this.errorsTarget.hidden = !errors.length;
        if (errors.length) this.errorsTarget.focus();
    }

    fieldError(name, message) {
        const p = this.element.querySelector(`[data-error-for="${name}"]`);
        if (!p) return;
        p.textContent = message;
        p.hidden = !message;
        document.getElementById(this.fieldId(name))?.setAttribute('aria-invalid', String(!!message));
    }

    // Identifiant du champ à atteindre depuis le résumé ; null si l'erreur ne porte sur aucun champ (sélection, page expirée)
    fieldId(name) {
        if (name === 'mode') return 'f-mode-pickup';
        return [`f-${name}`, `demande_${name}`].find((id) => document.getElementById(id)) ?? null;
    }

    focusField(event) {
        const link = event.target.closest('a[href^="#"]');
        if (!link) return;
        event.preventDefault();
        document.getElementById(link.hash.slice(1))?.focus();
    }

    // Étape 3 : rappel de l'adresse du lieu saisie en livraison
    renderAddress() {
        const d = this.entries;
        this.addressRecapTarget.hidden = !(d.mode === 'delivery' && d.address);
        role(this.addressRecapTarget, 'address').textContent = d.address ?? '';
    }

    // Étape 4 : lignes chiffrées par POST /estimation, date, mode, créneaux et coordonnées
    renderRecap() {
        const d = this.entries;
        const r = this.result;
        const recap = this.recapTarget;
        const item = (text) => Object.assign(document.createElement('li'), { textContent: text });
        const row = (label, amount, total = false) => {
            const div = document.createElement('div');
            div.className = `quote-summary__row${total ? ' quote-summary__row--total' : ''}`;
            div.append(Object.assign(document.createElement('dt'), { textContent: label }), Object.assign(document.createElement('dd'), { textContent: amount, className: total ? 'price-total' : '' }));
            return div;
        };
        role(recap, 'lines').replaceChildren(...(r ? [
            ...r.lines.map((l) => row(l.type === 'product' ? `${l.label} × ${l.quantity}` : (l.km ? `${l.label} (${l.km} km)` : l.label), l.amountText)),
            row(r.excludesDelivery && d.mode === 'delivery' ? 'Total hors livraison' : 'Total', r.totalText, true),
            row(`Acompte ${r.depositPercent} %`, r.depositText, true),
            row('Reste à payer', r.balanceText, true),
            // Produit masqué depuis l'ajout : hors de la demande
            ...(r.ignored.length ? [row(`${r.ignored.length > 1 ? 'Des produits ne sont' : 'Un produit n\'est'} plus proposé${r.ignored.length > 1 ? 's' : ''} : hors de la demande.`, '')] : []),
        ] : [row('Estimation momentanément indisponible', '')]));

        const delivery = d.mode === 'delivery';
        const date = d.date ? new Intl.DateTimeFormat('fr-FR', { dateStyle: 'full', timeZone: 'UTC' }).format(new Date(`${d.date}T12:00:00Z`)) : '';
        role(recap, 'when').replaceChildren(...[
            date && `Événement le ${date}`,
            delivery ? `Livraison en bas de porte : ${d.address}` : 'Retrait au dépôt',
            delivery && (d.collect ? 'Récupération par Serife' : 'Retour au dépôt'),
            delivery && d.installation && 'Installation sur place',
            d.cleaning && 'Chaises rendues sans nettoyage',
        ].filter(Boolean).map(item));

        // Libellé de chaque choix, tel que proposé par le serveur
        const choices = this.slotGroupTargets.filter((group) => !group.hidden && d[group.dataset.name]).map((group) => {
            const value = d[group.dataset.name];
            const days = r?.slots?.[group.dataset.list] ?? [];
            const day = days.find((x) => value.startsWith(x.date));
            const slot = day?.slots.find((x) => x.value === value);
            const label = day ? [day.label, 'days' in group.dataset ? '' : slot?.label].filter(Boolean).join(', ') : value;
            return item(`${group.querySelector('h3').textContent} : ${label}`);
        });
        role(recap, 'slots').replaceChildren(...choices);

        const c = d.contact ?? {};
        const source = this.formTarget.elements['demande[source]'];
        role(recap, 'contact').replaceChildren(...[
            [c.firstName, c.lastName].filter(Boolean).join(' '),
            c.phone,
            c.email,
            c.companyName,
            c.siret && `SIRET ${c.siret}`,
            c.billingAddress && `Facturation : ${c.billingAddress}`,
            c.access && `Accès : ${c.access}`,
            c.source && `Nous a connus par : ${source.selectedOptions[0]?.textContent}`,
        ].filter(Boolean).map(item));

        // Encadré Conditions : casse et perte des seuls produits choisis
        const ids = this.lines.map((l) => String(l.productId));
        const damage = this.element.querySelectorAll('[data-damage-product]');
        damage.forEach((li) => { li.hidden = !ids.includes(li.dataset.damageProduct); });
        const box = damage[0]?.closest('ul').closest('li');
        if (box) box.hidden = [...damage].every((li) => li.hidden);
    }
}
