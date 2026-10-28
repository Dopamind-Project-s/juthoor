(() => {
    'use strict';
    const category = document.querySelector('[data-news-category]');
    const sensitive = document.querySelector('[data-death-fields]');
    if (category && sensitive) {
        const update = () => { sensitive.hidden = !['death', 'condolence'].includes(category.value); };
        category.addEventListener('change', update);
        update();
    }
    document.querySelector('[data-publication-preview]')?.addEventListener('click', (event) => {
        const form = event.currentTarget.closest('form');
        const panel = document.querySelector('[data-publication-preview-panel]');
        if (!form || !panel) return;
        for (const field of ['title', 'summary', 'body']) {
            panel.querySelector(`[data-preview-${field}]`).textContent = form.elements.namedItem(field === 'body' && !form.elements.namedItem('body') ? 'description' : field)?.value || '';
        }
        panel.hidden = false;
        panel.scrollIntoView({behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth', block: 'nearest'});
    });
    document.querySelector('[data-publication-share]')?.addEventListener('click', async () => {
        const status = document.querySelector('[data-share-status]');
        const url = new URL(window.location.href);
        url.search = ''; // Never share a manager preview token or filter state.
        try {
            if (navigator.share) await navigator.share({title: document.title, url: url.href});
            else if (navigator.clipboard) { await navigator.clipboard.writeText(url.href); status.textContent = 'تم نسخ الرابط'; }
            else { status.textContent = url.href; }
        } catch (error) { if (error.name !== 'AbortError') status.textContent = 'تعذرت المشاركة؛ انسخ الرابط من شريط العنوان.'; }
    });
})();
