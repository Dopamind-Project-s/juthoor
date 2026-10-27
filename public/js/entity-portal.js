// Entity portal: accessible tabs over server-rendered sections (all sections render without JavaScript), deep links
// (#history, #section-history, #children…), the focused tree loaded only when its tab opens, and client-side filters.
(() => {
    const list = document.querySelector('[data-tabs]');
    if (list) {
        const tabs = [...list.querySelectorAll('[data-tab]')];
        const panels = [...document.querySelectorAll('[data-panel]')];
        const byKey = new Map(panels.map(panel => [panel.dataset.panel, panel]));
        const resolve = hash => {
            const id = decodeURIComponent((hash || '').replace(/^#/, ''));
            if (!id) return null;
            if (byKey.has(id)) return id;
            for (const panel of panels) {
                if (panel.dataset.aliases.split(' ').includes(id) || panel.querySelector('#' + CSS.escape(id))) return panel.dataset.panel;
            }
            return null;
        };
        const activate = (key, {focus = false, push = false} = {}) => {
            tabs.forEach(tab => { const on = tab.dataset.tab === key; tab.setAttribute('aria-selected', String(on)); tab.tabIndex = on ? 0 : -1; });
            panels.forEach(panel => { panel.hidden = panel.dataset.panel !== key; });
            byKey.get(key)?.querySelectorAll('[data-embed-src]').forEach(holder => {
                const frame = document.createElement('iframe');
                frame.src = holder.dataset.embedSrc; frame.title = holder.dataset.embedTitle || ''; frame.loading = 'lazy';
                holder.replaceWith(frame);
            });
            if (push) history.replaceState(null, '', '#' + key);
            if (focus) tabs.find(tab => tab.dataset.tab === key)?.focus();
        };
        tabs.forEach(tab => tab.addEventListener('click', event => {
            event.preventDefault();
            activate(tab.dataset.tab, {push: true});
            const nav = list.closest('nav');
            if (nav && nav.getBoundingClientRect().top < 0) window.scrollTo({top: nav.offsetTop, behavior: 'smooth'});
        }));
        // Right-to-left: ArrowLeft moves to the next tab, ArrowRight to the previous one.
        list.addEventListener('keydown', event => {
            const index = tabs.indexOf(document.activeElement);
            if (index < 0) return;
            const next = {ArrowLeft: index + 1, ArrowRight: index - 1, Home: 0, End: tabs.length - 1}[event.key];
            if (next === undefined) return;
            event.preventDefault();
            activate(tabs[(next + tabs.length) % tabs.length].dataset.tab, {focus: true, push: true});
        });
        const initial = resolve(location.hash) || 'overview';
        activate(initial);
        const target = location.hash ? document.getElementById(decodeURIComponent(location.hash.slice(1))) : null;
        if (target && !byKey.has(location.hash.slice(1))) target.scrollIntoView();
        window.addEventListener('hashchange', () => { const key = resolve(location.hash); if (key) activate(key); });
        document.addEventListener('click', event => {
            const link = event.target.closest('a[href^="#"]');
            if (!link || link.matches('[data-tab]')) return;
            const key = resolve(link.getAttribute('href'));
            if (key) activate(key);
        });
    }

    // Filters: a [data-filter-scope] holds chips [data-filter="tag"] ("" = all), an optional [data-filter-text] input,
    // and items [data-filter-item] with data-filter-tags. Hidden items stay in the page (no data is fetched).
    const normalize = text => String(text).replace(/[ً-ٰٟ]/g, '').replace(/[أإآٱ]/g, 'ا').replace(/ـ/g, '').toLowerCase();
    document.querySelectorAll('[data-filter-scope]').forEach(scope => {
        const chips = [...scope.querySelectorAll('[data-filter]')], input = scope.querySelector('[data-filter-text]');
        const items = [...scope.querySelectorAll('[data-filter-item]')], status = scope.querySelector('[data-filter-status]');
        let tag = '';
        const apply = () => {
            const needle = normalize(input?.value.trim() || '');
            let shown = 0;
            items.forEach(item => {
                const ok = (!tag || item.dataset.filterTags.split(' ').includes(tag)) && (!needle || normalize(item.textContent).includes(needle));
                item.hidden = !ok; shown += ok ? 1 : 0;
            });
            if (status) status.textContent = shown === items.length ? '' : `${shown} من ${items.length}`;
        };
        chips.forEach(chip => chip.addEventListener('click', () => {
            tag = chip.dataset.filter;
            chips.forEach(other => other.setAttribute('aria-pressed', String(other === chip)));
            apply();
        }));
        input?.addEventListener('input', apply);
    });
})();
