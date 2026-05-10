/* Triptych — Classic editor multilingual metabox.
   Vanilla JS, no jQuery. Handles tab switching + AI translate button. */

(function () {
    'use strict';

    const cfg = window.TriptychEditor || {};

    function ready(fn) {
        if (document.readyState !== 'loading') return fn();
        document.addEventListener('DOMContentLoaded', fn);
    }

    function activate(metabox, lang) {
        metabox.querySelectorAll('.triptych-tab').forEach((tab) => {
            const isActive = tab.dataset.lang === lang;
            tab.classList.toggle('is-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        metabox.querySelectorAll('.triptych-pane').forEach((pane) => {
            pane.classList.toggle('hidden', pane.dataset.lang !== lang);
        });
    }

    function findInput(metabox, field, lang) {
        return metabox.querySelector(
            '.triptych-input[data-field="' + field + '"][data-lang="' + lang + '"]'
        );
    }

    async function translate(button, metabox) {
        const field = button.dataset.field;
        const from = button.dataset.from;
        const to = button.dataset.to;
        const status = button.parentElement.querySelector('.triptych-status');
        const target = findInput(metabox, field, to);
        const source = findInput(metabox, field, from);

        if (!source || !source.value.trim()) {
            status.textContent = cfg.i18n && cfg.i18n.empty ? cfg.i18n.empty : 'Source field is empty';
            status.classList.remove('is-success');
            status.classList.add('is-error');
            return;
        }

        button.disabled = true;
        status.classList.remove('is-error', 'is-success');
        status.textContent = cfg.i18n && cfg.i18n.translating ? cfg.i18n.translating : 'Translating…';

        try {
            const res = await fetch(cfg.restUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': cfg.nonce,
                },
                credentials: 'same-origin',
                body: JSON.stringify({ from: from, to: to, text: source.value, field: field }),
            });
            const json = await res.json();
            if (!res.ok) {
                throw new Error((json && json.message) || 'HTTP ' + res.status);
            }
            target.value = json.translated || '';
            target.dispatchEvent(new Event('input', { bubbles: true }));
            status.textContent = cfg.i18n && cfg.i18n.done ? cfg.i18n.done : 'Translated';
            status.classList.add('is-success');
        } catch (err) {
            status.textContent =
                (cfg.i18n && cfg.i18n.error ? cfg.i18n.error : 'Translation failed') +
                ': ' +
                (err && err.message ? err.message : err);
            status.classList.add('is-error');
        } finally {
            button.disabled = false;
        }
    }

    ready(function () {
        document.querySelectorAll('.triptych-mb').forEach(function (metabox) {
            metabox.addEventListener('click', function (e) {
                const tab = e.target.closest('.triptych-tab');
                if (tab && metabox.contains(tab)) {
                    e.preventDefault();
                    activate(metabox, tab.dataset.lang);
                    return;
                }
                const btn = e.target.closest('.triptych-translate');
                if (btn && metabox.contains(btn)) {
                    e.preventDefault();
                    translate(btn, metabox);
                }
            });
        });
    });
})();
