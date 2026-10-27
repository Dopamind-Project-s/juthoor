<section class="portal-section portal-contribute" id="portal-contribute" aria-labelledby="portal-contribute-title">
    <div class="portal-shell portal-contribute-card">
        <div><h2 id="portal-contribute-title">ساهم في حفظ النسب</h2>
            <p>هل لديك تصحيح أو وثيقة أو اسم ناقص؟ يرسل أعضاء العائلة المقترحات من حساباتهم، ويراجعها مسؤولو الأنساب قبل أي تغيير.</p></div>
        <div class="portal-contribute-actions">
            @auth<a class="btn portal-btn-primary" href="{{ route('management.changes.create', $family->slug) }}">اقترح تصحيحًا</a>@else<a class="btn portal-btn-primary" href="{{ route('login') }}">سجّل الدخول للمساهمة</a>@endauth
            @if($branding['public_email'])<span class="small">أو راسل العائلة: <span dir="ltr">{{ $branding['public_email'] }}</span></span>@endif
        </div>
    </div>
</section>
