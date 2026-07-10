/*
 * Copy-to-clipboard for the template draft YAML preview.
 *
 * Presentation only: reads the YAML as plain text from the DOM and copies it.
 * Degrades gracefully - without JavaScript (or without clipboard permission)
 * the YAML stays selectable and the download link keeps working.
 */
const button = document.querySelector('[data-template-draft-copy]');
const source = document.querySelector('[data-template-draft-yaml]');

if (button && source) {
    // The button is server-rendered with "hidden": without JavaScript it would
    // be a dead control, so it only appears once this module is running.
    button.hidden = false;

    const defaultLabel = button.textContent;

    button.addEventListener('click', () => {
        const yaml = source.textContent || '';

        navigator.clipboard
            .writeText(yaml)
            .then(() => {
                button.textContent = 'Kopiert ✓';
                setTimeout(() => {
                    button.textContent = defaultLabel;
                }, 2000);
            })
            .catch(() => {
                button.textContent = 'Kopieren nicht möglich – bitte manuell markieren';
            });
    });
}
