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
        more.disabled = true; status.textContent = 'جارٍ تحميل الخيارات…';
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

// Consistent labels, pending feedback and destructive-action confirmation.
if (document.body.classList.contains('management-shell')) {
    const labels = {
        verification_status: 'حالة التوثيق', role: 'الدور', q: 'بحث',
        verification: 'حالة التوثيق', branch: 'الفرع', person_id: 'الابن',
        father_id: 'الأب', root_person_id: 'الجذر / المؤسس', parent_branch_id: 'الفرع الأب',
        status: 'الحالة', gender: 'الجنس', is_living: 'على قيد الحياة'
    };
    document.querySelectorAll('main input:not([type="hidden"]):not([type="checkbox"]), main select, main textarea').forEach((field, index) => {
        if (field.closest('label') || (field.id && document.querySelector('label[for="' + field.id + '"]'))) return;
        field.id ||= 'admin-field-' + index;
        const label = document.createElement('label');
        label.htmlFor = field.id;
        label.className = 'form-label';
        label.textContent = labels[field.name] || field.placeholder || field.options?.[0]?.text || 'القيمة';
        const wrapper = document.createElement('div');
        wrapper.className = 'field-wrapper';
        field.before(wrapper);
        wrapper.append(label, field);
    });
    document.querySelectorAll('form').forEach(form => {
        form.addEventListener('submit', event => {
            if (form.method.toLowerCase() !== 'post') return;
            if (form.dataset.pending === 'true') { event.preventDefault(); return; }
            const deleting = form.querySelector('input[name="_method"]')?.value.toUpperCase() === 'DELETE';
            if ((deleting || form.dataset.confirm) && !window.confirm(form.dataset.confirm || 'هل أنت متأكد من حذف أو إلغاء هذا السجل؟')) { event.preventDefault(); return; }
            form.dataset.pending = 'true';
            form.setAttribute('aria-busy', 'true');
            const submit = event.submitter;
            if (submit) { submit.dataset.originalText = submit.textContent; submit.textContent = 'جارٍ الحفظ…'; submit.setAttribute('aria-disabled', 'true'); }
        });
    });
    document.querySelectorAll('.management-sidebar nav a').forEach(link => {
        if (new URL(link.href).pathname === location.pathname) link.setAttribute('aria-current', 'page');
    });
    document.querySelector('.alert-danger[role="alert"]')?.focus();
    window.addEventListener('pageshow', () => {
        document.querySelectorAll('form[data-pending]').forEach(form => { delete form.dataset.pending; form.removeAttribute('aria-busy'); });
        document.querySelectorAll('[data-original-text]').forEach(button => { button.removeAttribute('aria-disabled'); button.textContent = button.dataset.originalText; });
    });
}
