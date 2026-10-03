document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-current-year]').forEach((element) => {
        element.textContent = new Date().getFullYear();
    });
});

document.querySelector('[data-sidebar-toggle]')?.addEventListener('click', () => {
    document.querySelector('.management-sidebar')?.classList.toggle('is-open');
    document.querySelector('[data-sidebar-backdrop]')?.classList.toggle('is-open');
});
document.querySelector('[data-sidebar-backdrop]')?.addEventListener('click', () => {
    document.querySelector('.management-sidebar')?.classList.remove('is-open');
    document.querySelector('[data-sidebar-backdrop]')?.classList.remove('is-open');
});

document.querySelectorAll('[data-remote-select]').forEach((select) => {
    const search = document.createElement('input');
    search.className = 'form-control remote-select-search';
    search.placeholder = 'اكتب للبحث في الخيارات…';
    select.before(search);
    let timer;
    search.addEventListener('input', () => {
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const response = await fetch(`${select.dataset.remoteUrl}?q=${encodeURIComponent(search.value)}`, {headers: {'Accept':'application/json'}});
            if (!response.ok) return;
            const payload = await response.json();
            const current = select.value;
            const placeholder = select.options[0]?.cloneNode(true);
            const selectedOption = [...select.options].find((option) => option.value === current)?.cloneNode(true);
            select.replaceChildren();
            if (placeholder) select.append(placeholder);
            if (selectedOption && selectedOption.value && selectedOption.value !== placeholder?.value) {
                selectedOption.selected = true;
                select.append(selectedOption);
            }
            payload.data.forEach((item) => {
                if (String(item.id) === current) return;
                const option = new Option(item.text, item.id, false, String(item.id) === current);
                select.add(option);
            });
        }, 250);
    });
});
