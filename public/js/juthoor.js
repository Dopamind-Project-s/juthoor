document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-current-year]').forEach(element => element.textContent = new Date().getFullYear());
});

const sidebar = document.querySelector('.management-sidebar');
const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
const sidebarBackdrop = document.querySelector('[data-sidebar-backdrop]');
function toggleSidebar(open) {
    sidebar?.classList.toggle('is-open', open);
    sidebarBackdrop?.classList.toggle('is-open', open);
    sidebarToggle?.setAttribute('aria-expanded', String(open));
    if (open) sidebar?.querySelector('select, a')?.focus();
    else sidebarToggle?.focus();
}
sidebarToggle?.addEventListener('click', () => toggleSidebar(!sidebar.classList.contains('is-open')));
sidebarBackdrop?.addEventListener('click', () => toggleSidebar(false));
document.addEventListener('keydown', event => { if (event.key === 'Escape') toggleSidebar(false); });
sidebar?.querySelectorAll('nav a').forEach(link => link.addEventListener('click', () => toggleSidebar(false)));

document.querySelectorAll('[data-remote-select]').forEach(select => {
    const wrapper = document.createElement('div');
    select.before(wrapper); wrapper.append(select);
    const search = document.createElement('input');
    search.type = 'search'; search.className = 'form-control remote-select-search mb-1';
    search.placeholder = 'اكتب للبحث في الخيارات…'; search.setAttribute('aria-label', select.options[0]?.text || 'بحث الخيارات');
    select.before(search);
    const more = document.createElement('button'); more.type = 'button'; more.className = 'btn btn-sm btn-outline-primary mt-1';
    more.textContent = 'تحميل خيارات أخرى'; more.hidden = true; select.after(more);
    const status = document.createElement('small'); status.setAttribute('role', 'status'); more.after(status);
    let timer, controller, sequence = 0, page = 1;
    async function load(append = false) {
        controller?.abort(); controller = new AbortController(); const ticket = ++sequence;
        more.disabled = true;
        const url = new URL(select.dataset.remoteUrl, location.href);
        url.searchParams.set('q', search.value); url.searchParams.set('page', append ? page+1 : 1);
        try {
            const response = await fetch(url, {headers: {Accept: 'application/json'}, signal: controller.signal});
            if (!response.ok) throw new Error('lookup');
            const payload = await response.json(); if (ticket !== sequence) return;
            const current = select.value;
            if (!append) {
                const placeholder = select.options[0]?.cloneNode(true);
                const selected = [...select.options].find(option => option.value === current && current)?.cloneNode(true);
                select.replaceChildren(); if (placeholder) select.append(placeholder); if (selected) select.append(selected);
            }
            const ids = new Set([...select.options].map(option => option.value));
            payload.data.forEach(item => { if (!ids.has(String(item.id))) select.add(new Option(item.text, item.id, false, String(item.id) === current)); });
            page = payload.meta.current_page; more.hidden = page >= payload.meta.last_page;
            status.textContent = payload.meta.total ? '' : 'لا توجد خيارات.';
        } catch (cause) { if (ticket === sequence && cause.name !== 'AbortError') status.textContent = 'تعذر تحميل الخيارات. أعد البحث للمحاولة.'; }
        finally { if (ticket === sequence) more.disabled = false; }
    }
    search.addEventListener('input', () => { clearTimeout(timer); sequence++; controller?.abort(); timer = setTimeout(() => load(), 250); });
    more.addEventListener('click', () => load(true));
    load();
});
