import { Controller } from '@hotwired/stimulus';

const COOKIE = 'seydeco_consent';
const MAX_AGE = 180 * 24 * 3600; // 6 mois au plus (cookie essentiel : il ne garde que le choix)

let audienceLoaded = false;

/*
 * Mesure d'audience : outil pas encore choisi. Son script se branche ici, et seulement ici :
 * il n'est chargé qu'après « Accepter » ou la case « Mesure d'audience » cochée.
 */
function loadAudience() {
    // Retrait du consentement : l'outil choisi devra aussi effacer ses cookies (le script chargé reste jusqu'au rechargement).
}

/** Choix enregistré {audience, youtube}, ou null tant qu'aucun choix n'est fait. */
function readChoice() {
    const raw = document.cookie.split('; ').find((c) => c.startsWith(`${COOKIE}=`));
    if (!raw) return null;
    const params = new URLSearchParams(raw.slice(COOKIE.length + 1));

    return { audience: params.get('audience') === '1', youtube: params.get('youtube') === '1' };
}

/*
 * Consentement (story 2.11), seul contrôleur à charger un script tiers. Posé sur <body> :
 * Bandeau cookies (affiché tant qu'aucun choix n'est fait) et formulaire de la page Gestion des cookies.
 * Les Façades vidéo YouTube restent sans iframe jusqu'au clic, qui vaut consentement ponctuel.
 */
export default class extends Controller {
    static targets = ['banner', 'form', 'status'];

    connect() {
        const choice = readChoice();
        if (this.hasBannerTarget) this.bannerTarget.hidden = choice !== null;
        if (choice) this.apply(choice);
    }

    // Page Gestion des cookies : formulaire utilisable seulement avec JavaScript, cases au choix enregistré
    formTargetConnected(form) {
        const choice = readChoice();
        form.elements.audience.checked = choice?.audience ?? false;
        form.elements.youtube.checked = choice?.youtube ?? false;
        form.hidden = false;
    }

    accept() {
        this.save({ audience: true, youtube: true });
    }

    refuse() {
        this.save({ audience: false, youtube: false });
    }

    submit(event) {
        event.preventDefault();
        const { audience, youtube } = event.target.elements;
        this.save({ audience: audience.checked, youtube: youtube.checked });
        if (this.hasStatusTarget) this.statusTarget.textContent = 'Votre choix est enregistré.';
    }

    save(choice) {
        // « = » et « & » sont permis dans la valeur d'un cookie : lue telle quelle par URLSearchParams
        const value = `audience=${choice.audience ? 1 : 0}&youtube=${choice.youtube ? 1 : 0}`;
        const secure = window.location.protocol === 'https:' ? '; secure' : '';
        document.cookie = `${COOKIE}=${value}; max-age=${MAX_AGE}; path=/; samesite=lax${secure}`;
        if (this.hasBannerTarget) this.bannerTarget.hidden = true;
        // Bandeau et formulaire tenus à jour s'ils sont tous deux sur la page
        if (this.hasFormTarget) this.formTargetConnected(this.formTarget);
        this.apply(choice);
    }

    apply(choice) {
        if (choice.audience && !audienceLoaded) {
            audienceLoaded = true;
            loadAudience();
        }
    }
}
