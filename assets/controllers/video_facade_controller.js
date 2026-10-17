import { Controller } from '@hotwired/stimulus';

/* Façade vidéo YouTube : le lecteur youtube-nocookie.com n'est chargé qu'au clic, puis démarre. */
export default class extends Controller {
    static values = { id: String, title: String };

    play(event) {
        event.preventDefault();
        const iframe = document.createElement('iframe');
        iframe.src = `https://www.youtube-nocookie.com/embed/${encodeURIComponent(this.idValue)}?autoplay=1`;
        iframe.title = this.titleValue;
        iframe.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen';
        iframe.allowFullscreen = true;
        iframe.className = 'video-facade video-facade--playing';
        this.element.replaceWith(iframe);
        iframe.focus();
    }
}
