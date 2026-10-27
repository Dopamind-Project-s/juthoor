(() => {
    'use strict';
    const statusLabels = {verified: 'موثق', sourced: 'مسند إلى مصدر', pending_review: 'قيد المراجعة', unresolved: 'غير محسوم'};
    const livingLabels = {'': 'غير معروفة', '0': 'متوفى', '1': 'على قيد الحياة'};

    // Guided creation: steps, live duplicate check and a summary before saving. The server repeats every check.
    const form = document.querySelector('[data-guided-person]');
    if (form) {
        const steps = [...form.querySelectorAll('[data-step]')];
        const nav = [...document.querySelectorAll('[data-step-target]')];
        const actions = form.querySelector('[data-step-actions]');
        const field = name => form.elements[name];
        const candidatesBox = form.querySelector('[data-candidates]'), candidatesStatus = form.querySelector('[data-candidates-status]');
        const confirmLabel = form.querySelector('[data-confirm-distinct]');
        let current = 1, lastQuery = '', blocking = false, checking = false;
        form.classList.add('is-enhanced'); actions.hidden = false;

        const fatherSelect = () => form.querySelector('[data-father-select]');
        const fatherName = () => {
            const option = fatherSelect()?.selectedOptions[0];
            return option && option.value ? option.textContent.trim() : '';
        };
        const show = step => {
            current = Math.min(Math.max(step, 1), steps.length);
            steps.forEach(fieldset => { fieldset.hidden = Number(fieldset.dataset.step) !== current; });
            nav.forEach(button => button.setAttribute('aria-current', Number(button.dataset.stepTarget) === current ? 'step' : 'false'));
            form.querySelector('[data-step-prev]').hidden = current === 1;
            form.querySelector('[data-step-next]').hidden = current === steps.length;
            if (current === 2) checkDuplicates();
            if (current === 4) summarize();
            steps[current - 1].querySelector('legend')?.scrollIntoView({block: 'nearest'});
        };
        const identityValid = () => {
            for (const name of ['first_name_ar', 'full_name_ar']) {
                if (!field(name).value.trim()) { field(name).reportValidity?.(); field(name).focus(); return false; }
            }
            return true;
        };
        const checkDuplicates = async () => {
            const name = field('full_name_ar').value.trim(), father = fatherSelect()?.value || '';
            const query = `${name}|${father}`; if (query === lastQuery) return; lastQuery = query;
            // A previous name's result never carries over to the new one.
            blocking = false; confirmLabel.hidden = true; checking = true;
            candidatesBox.replaceChildren(); candidatesStatus.textContent = 'جارٍ البحث عن السجلات…';
            try {
                const url = new URL(form.dataset.candidatesUrl, location.href);
                url.searchParams.set('full_name_ar', name); if (father) url.searchParams.set('father_id', father);
                const response = await fetch(url, {headers: {Accept: 'application/json'}});
                if (!response.ok) throw new Error('candidates');
                const payload = await response.json();
                blocking = payload.data.some(candidate => candidate.blocking);
                candidatesStatus.textContent = payload.data.length
                    ? (blocking ? 'يوجد سجل مطابق: افتحه بدل إنشاء نسخة، أو أكّد أنه شخص مختلف.' : 'سجلات مشابهة للاطلاع؛ لا تمنع الحفظ.')
                    : 'لا توجد سجلات مشابهة في هذه الشجرة.';
                payload.data.forEach(candidate => {
                    const row = document.createElement('article'); row.classList.toggle('is-blocking', candidate.blocking);
                    const text = document.createElement('div');
                    const strong = document.createElement('strong'); strong.textContent = candidate.name;
                    const small = document.createElement('small'); small.className = 'd-block text-muted'; small.textContent = `${candidate.lineage || 'دون أب مسجل'} · ${candidate.reason} · ${statusLabels[candidate.verification] || candidate.verification}`;
                    text.append(strong, small);
                    const open = document.createElement('a'); open.className = 'btn btn-sm btn-outline-primary'; open.href = candidate.url; open.textContent = 'هذا هو الشخص — افتح السجل';
                    row.append(text, open); candidatesBox.append(row);
                });
                confirmLabel.hidden = !blocking;
            } catch (cause) {
                lastQuery = ''; candidatesStatus.textContent = 'تعذر البحث الآن؛ سيُفحص التكرار عند الحفظ.';
            } finally {
                checking = false;
            }
        };
        const summarize = () => {
            const box = form.querySelector('[data-review-summary]');
            const verification = field('verification_status')?.value || 'pending_review';
            const living = field('is_living')?.value ?? '';
            const status = field('status')?.value;
            const willBePublic = form.dataset.canPublish === '1' && ['verified', 'sourced'].includes(verification) && status === 'historical' && living === '0';
            const rows = [
                ['الاسم', field('full_name_ar').value.trim() || '—'],
                ['الاسم الأول', field('first_name_ar').value.trim() || '—'],
                ['الأب', fatherName() || 'غير معروف / غير مرتبط بعد (يبقى للمراجعة دون أن يصبح جذرًا)'],
                ['أثر على الشجرة', fatherName() ? `يُضاف عقدة جديدة تحت «${fatherName()}» دون تغيير أي علاقة قائمة` : 'سجل جديد غير مرتبط بالشجرة بعد'],
                ['حالة الحياة', livingLabels[living] ?? living],
                ['التوثيق', statusLabels[verification] || verification],
                ['النشر', willBePublic ? 'سيظهر للعامة بعد الحفظ (صلاحيتك تسمح بالنشر)' : 'لن يظهر للعامة؛ يبقى خاصًا حتى المراجعة والاعتماد'],
                ['التكرار', checking ? 'التحقق جارٍ؛ يُعاد الفحص عند الحفظ' : blocking ? (form.elements.confirm_distinct?.checked ? 'أكدت أنه شخص مختلف' : 'يوجد تطابق لم يُؤكد بعد — سيُرفض الحفظ') : 'لا تطابق مانع'],
            ];
            const list = document.createElement('dl'); list.className = 'row mb-0';
            rows.forEach(([label, value]) => {
                const term = document.createElement('dt'); term.className = 'col-sm-3'; term.textContent = label;
                const detail = document.createElement('dd'); detail.className = 'col-sm-9'; detail.textContent = value;
                list.append(term, detail);
            });
            box.replaceChildren(list);
        };

        // The first name follows the first word of the full name until the user edits it.
        let firstEdited = !!field('first_name_ar').value;
        field('first_name_ar').addEventListener('input', () => { firstEdited = true; });
        field('full_name_ar').addEventListener('input', () => {
            lastQuery = '';
            if (!firstEdited) field('first_name_ar').value = field('full_name_ar').value.trim().split(/\s+/)[0] || '';
        });
        form.querySelector('[data-change-father]')?.addEventListener('click', () => {
            const picker = form.querySelector('[data-father-picker]'); if (picker) picker.hidden = false;
            form.querySelector('[data-placement-card]')?.remove();
        });
        fatherSelect()?.addEventListener('change', () => { lastQuery = ''; });
        form.querySelector('[data-step-next]').addEventListener('click', () => { if (current === 1 && !identityValid()) return; show(current + 1); });
        form.querySelector('[data-step-prev]').addEventListener('click', () => show(current - 1));
        nav.forEach(button => button.addEventListener('click', () => { if (Number(button.dataset.stepTarget) > 1 && !identityValid()) return; show(Number(button.dataset.stepTarget)); }));
        form.addEventListener('submit', event => { if (!identityValid()) { event.preventDefault(); show(1); } });
        // After a server-side duplicate refusal, open the duplicate step directly.
        show(form.querySelector('[data-confirm-distinct]:not([hidden])') ? 2 : 1);
    }

    // Editing: show the effect of changing an approved father before submission.
    const edit = document.querySelector('[data-person-edit]');
    if (edit) {
        const select = edit.querySelector('[data-father-select]'), note = edit.querySelector('[data-father-impact]');
        const original = edit.dataset.originalFather || '';
        select?.addEventListener('change', () => { if (note) note.hidden = (select.value || '') === original; });
    }
})();
