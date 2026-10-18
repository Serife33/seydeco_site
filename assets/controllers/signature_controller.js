import { Controller } from '@hotwired/stimulus';

/* Signature en ligne (story 3.16) : tracé au doigt ou à la souris sur un canvas natif, sans bibliothèque.
   Le tracé part en PNG (data URL) dans le champ caché ; vide tant que rien n'est tracé. Focus sur la première erreur. */
export default class extends Controller {
    static targets = ['canvas', 'input'];

    connect() {
        const canvas = this.canvasTarget;
        this.setup();
        this.down = this.down.bind(this);
        this.move = this.move.bind(this);
        this.up = this.up.bind(this);
        canvas.addEventListener('pointerdown', this.down);
        canvas.addEventListener('pointermove', this.move);
        canvas.addEventListener('pointerup', this.up);
        canvas.addEventListener('pointercancel', this.up);

        this.element.querySelector('[aria-invalid="true"]')?.focus();
    }

    // Taille du dessin = taille affichée ; une largeur qui change (rotation du téléphone) efface le tracé, sinon il serait décalé
    setup() {
        const canvas = this.canvasTarget;
        const ratio = Math.min(window.devicePixelRatio || 1, 2);
        this.width = canvas.clientWidth;
        canvas.width = canvas.clientWidth * ratio;
        canvas.height = canvas.clientHeight * ratio;
        this.context = canvas.getContext('2d');
        this.context.scale(ratio, ratio);
        this.context.lineWidth = 2.5;
        this.context.lineCap = 'round';
        this.context.lineJoin = 'round';
        this.context.strokeStyle = '#1F1D1A';
        this.inputTarget.value = '';
    }

    resize() {
        if (this.canvasTarget.clientWidth !== this.width) this.setup();
    }

    disconnect() {
        const canvas = this.canvasTarget;
        canvas.removeEventListener('pointerdown', this.down);
        canvas.removeEventListener('pointermove', this.move);
        canvas.removeEventListener('pointerup', this.up);
        canvas.removeEventListener('pointercancel', this.up);
    }

    point(event) {
        const box = this.canvasTarget.getBoundingClientRect();
        return [event.clientX - box.left, event.clientY - box.top];
    }

    down(event) {
        event.preventDefault();
        this.canvasTarget.setPointerCapture(event.pointerId);
        this.drawing = true;
        const [x, y] = this.point(event);
        this.context.beginPath();
        this.context.moveTo(x, y);
        // Un simple point est aussi un tracé
        this.context.lineTo(x + 0.1, y + 0.1);
        this.context.stroke();
    }

    move(event) {
        if (!this.drawing) return;
        const [x, y] = this.point(event);
        this.context.lineTo(x, y);
        this.context.stroke();
    }

    up() {
        if (!this.drawing) return;
        this.drawing = false;
        this.inputTarget.value = this.canvasTarget.toDataURL('image/png');
    }

    clear() {
        this.context.clearRect(0, 0, this.canvasTarget.width, this.canvasTarget.height);
        this.inputTarget.value = '';
        this.canvasTarget.focus();
    }
}
