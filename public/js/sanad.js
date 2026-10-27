/* Progressive enhancement: the entire form and directory remain usable without JavaScript. */
(() => {
    const search = document.querySelector('[data-sanad-search]');
    search?.addEventListener('submit', () => {
        search.setAttribute('aria-busy', 'true');
        search.querySelector('[data-sanad-loading]').hidden = false;
        search.querySelector('[data-sanad-search-submit]').textContent = 'جارٍ البحث…';
    });
    const form = document.querySelector('[data-sanad-wizard]');
    if (!form) return;
    const steps = [...form.querySelectorAll('[data-sanad-step]')];
    const nav = [...form.querySelectorAll('[data-sanad-go]')];
    const previous = form.querySelector('[data-sanad-previous]'), next = form.querySelector('[data-sanad-next]');
    let current = 0;
    const preview = () => {
        const output = form.querySelector('[data-sanad-preview]');
        output.replaceChildren();
        const title = document.createElement('strong'); title.textContent = form.elements.display_name.value || 'اسمك المعروض'; output.append(title);
        const note = document.createElement('p'); note.textContent = form.elements.audience.value === 'public' ? 'معاينة ما يراه الزائر العام وفق خياراتك الحالية:' : 'ملفك لأعضاء العائلة فقط؛ لا يظهر للزائر العام.'; output.append(note);
        const list = document.createElement('ul');
        const audience = form.elements.audience.value;
        for (const row of form.querySelectorAll('.directory-row')) {
            const visibility = row.querySelector('[name^="visibility["]');
            if (!visibility || visibility.value === 'hidden' || (audience === 'public' && visibility.value !== 'public')) continue;
            const values = [...row.querySelectorAll('input:not([type=file]):not([type=checkbox]), textarea, select:not([name^="visibility["])')]
                .filter(input => input.name !== 'entity_id') // affiliation waits for the existing verification workflow
                .map(input => input.tagName === 'SELECT' ? (input.value ? input.selectedOptions[0]?.textContent : '') : input.value).filter(Boolean);
            if (!values.length) continue;
            const item = document.createElement('li'); item.textContent = values.join(' — '); list.append(item);
        }
        output.append(list);
        const status = document.createElement('p'); status.textContent = form.querySelector('[name="is_listed"][type="checkbox"]').checked ? 'سيظهر الملف بعد الحفظ وفق هذه الخيارات، ما لم يكن موقوفًا.' : 'سيُحفظ الملف دون الظهور في الدليل.'; output.append(status);
    };
    const show = index => {
        current = index;
        steps.forEach((step, i) => { step.hidden = i !== index; });
        nav.forEach((button, i) => { if (i === index) button.setAttribute('aria-current', 'step'); else button.removeAttribute('aria-current'); });
        previous.hidden = index === 0; next.hidden = index === steps.length - 1;
        if (index === steps.length - 1) preview();
        steps[index].querySelector('legend')?.focus();
    };
    nav.forEach((button, i) => button.addEventListener('click', () => show(i)));
    previous.addEventListener('click', () => show(current - 1)); next.addEventListener('click', () => show(current + 1));
    form.querySelector('[data-sanad-controls]').hidden = false;
    form.querySelector('[data-sanad-step-nav]').hidden = false;
    form.addEventListener('invalid', event => { const index = steps.findIndex(step => step.contains(event.target)); if (index >= 0) show(index); }, true);
    form.addEventListener('input', () => {
        const names = ['display_name', 'profession', 'specialization', 'skills', 'city', 'collaboration'];
        const complete = names.filter(name => form.elements[name].value.trim()).length;
        const progress = form.querySelector('[data-sanad-completeness]'); progress.value = complete;
        form.querySelector('[data-sanad-completeness-text]').textContent = `${complete} من ${names.length} معلومات اختيارية وأساسية مكتملة`;
        if (current === steps.length - 1) preview();
    });
    form.dispatchEvent(new Event('input')); show(0);
})();
