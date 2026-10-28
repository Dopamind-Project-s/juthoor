(() => {
    'use strict';
    const shell = document.querySelector('.admin-shell');
    if (!shell) return;
    const highlightNavigation = () => {
        shell.querySelectorAll('.management-sidebar nav a').forEach(link => {
            const url = new URL(link.href);
            const active = url.pathname === location.pathname && (url.hash ? url.hash === location.hash : !location.hash);
            if (active) link.setAttribute('aria-current', 'page');
            else link.removeAttribute('aria-current');
        });
    };
    document.addEventListener('DOMContentLoaded', highlightNavigation);
    window.addEventListener('hashchange', highlightNavigation);
    const sidebar = shell.querySelector('.management-sidebar');
    const toggle = shell.querySelector('[data-sidebar-toggle]');
    const backdrop = shell.querySelector('[data-sidebar-backdrop]');
    const mobile = window.matchMedia('(max-width: 991.98px)');
    let wasOpen = false;
    const sync = () => {
        const open = sidebar.classList.contains('is-open');
        sidebar.inert = mobile.matches && !open;
        shell.querySelector('.management-main').inert = mobile.matches && open;
        document.body.classList.toggle('admin-drawer-open', mobile.matches && open);
        if (mobile.matches && open && !sidebar.contains(document.activeElement)) sidebar.querySelector('[data-sidebar-close]')?.focus();
        else if (mobile.matches && !open && wasOpen) toggle?.focus();
        wasOpen = open;
    };
    new MutationObserver(sync).observe(sidebar, {attributes: true, attributeFilter: ['class']});
    mobile.addEventListener('change', sync);
    sync();
    sidebar.querySelector('[data-sidebar-close]')?.addEventListener('click', () => backdrop.click());
    document.addEventListener('keydown', event => {
        if (event.key !== 'Tab' || !mobile.matches || !sidebar.classList.contains('is-open')) return;
        const elements = [...sidebar.querySelectorAll('a[href],button:not([disabled]),select:not([disabled])')].filter(el => el.getClientRects().length);
        const first = elements[0], last = elements.at(-1);
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
    });
    shell.querySelector('[data-family-switch-form]')?.addEventListener('submit', event => {
        event.preventDefault();
        const select = event.currentTarget.querySelector('[data-family-switch]');
        const target = select.selectedOptions[0]?.value;
        if (!target) return;
        const url = new URL(target, location.origin);
        if (url.origin === location.origin) location.assign(url.href);
    });
    shell.querySelectorAll('table').forEach(table => {
        if (!table.closest('.table-responsive,.data-table-wrap')) {
            const wrapper = document.createElement('div');
            wrapper.className = 'table-responsive';
            table.before(wrapper); wrapper.append(table);
        }
        const wrapper = table.parentElement;
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        wrapper.setAttribute('aria-label', table.caption?.textContent || 'جدول السجلات — قابل للتمرير');
    });
})();
