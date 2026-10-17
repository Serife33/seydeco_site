import { Controller } from '@hotwired/stimulus';

/*
 * Photos d'iPhone (HEIC) : converties en JPEG dans le navigateur avant l'envoi,
 * le serveur ne reçoit que du JPEG, PNG ou WebP. Posé sur le <body> de l'admin.
 */
const isHeic = (file) => /image\/hei[cf]/i.test(file.type) || /\.hei[cf]$/i.test(file.name);

export default class extends Controller {
    connect() {
        this.onChange = (event) => this.convert(event.target);
        this.element.addEventListener('change', this.onChange);
    }

    disconnect() {
        this.element.removeEventListener('change', this.onChange);
    }

    async convert(input) {
        if (input.type !== 'file' || ![...input.files].some(isHeic)) {
            return;
        }
        const submits = [...(input.form?.querySelectorAll('button[type="submit"], button:not([type])') ?? [])];
        const status = this.status(input);
        status.textContent = 'Conversion de la photo d\'iPhone…';
        submits.forEach((b) => { b.disabled = true; });
        try {
            const { default: heic2any } = await import('heic2any');
            const files = new DataTransfer();
            for (const file of input.files) {
                if (!isHeic(file)) {
                    files.items.add(file);
                    continue;
                }
                const blob = await heic2any({ blob: file, toType: 'image/jpeg', quality: 0.9 });
                files.items.add(new File([Array.isArray(blob) ? blob[0] : blob], file.name.replace(/\.hei[cf]$/i, '.jpg'), { type: 'image/jpeg' }));
            }
            input.files = files.files;
            status.textContent = 'Photo prête.';
        } catch {
            input.value = '';
            status.textContent = 'Cette photo n\'a pas pu être convertie : exportez-la en JPEG depuis Photos, puis réessayez.';
        } finally {
            submits.forEach((b) => { b.disabled = false; });
        }
    }

    // Message sous le champ, lu par les lecteurs d'écran
    status(input) {
        let status = input.parentElement.querySelector('[data-heic-status]');
        if (!status) {
            status = document.createElement('p');
            status.className = 'admin-field__help';
            status.setAttribute('data-heic-status', '');
            status.setAttribute('aria-live', 'polite');
            input.after(status);
        }
        return status;
    }
}
