<section class="entity-aside" id="section-review" aria-labelledby="entity-status" data-entity-completeness>
    <h2 class="h6 fw-bold" id="entity-status">المراجعة والتصحيح</h2>
    <p class="small mb-2">آخر مراجعة: <strong>{{ $entity->reviewed_at ? $entity->reviewed_at->format('Y-m-d') : 'لم تُراجع بعد' }}</strong></p>
    <label class="small d-block" for="entity-completeness">اكتمال صفحة التعريف: {{ $completeness['done'] }} من {{ $completeness['total'] }}</label>
    <progress id="entity-completeness" class="entity-progress" max="{{ $completeness['total'] }}" value="{{ $completeness['done'] }}">{{ $completeness['done'] }}/{{ $completeness['total'] }}</progress>
    @if($completeness['missing'])<p class="small text-muted mb-2">ينقصها: {{ implode('، ', $completeness['missing']) }}.</p>@endif
    <p class="small text-muted mb-0">المؤشر يقيس اكتمال التعريف لا صحة المعلومات. لاقتراح تصحيح أو تقديم وثائق، تواصل مع إدارة {{ $family->name_ar }} أو قدّم طلب تغيير من حساب عضويتك.</p>
</section>
