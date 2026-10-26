<section class="entity-section" id="section-statistics" aria-labelledby="entity-statistics-title" data-statistics>
    <h2 class="h5 fw-bold" id="entity-statistics-title">الإحصاءات وتغطية البيانات</h2>
    <p class="alert alert-light small" role="note">الإحصاءات الواردة مبنية على المعلومات المسجلة والمتاحة في منصة جذور، وقد لا تشمل جميع أفراد الفخذ أو العشيرة، ولا تمثل تعدادًا رسميًا للسكان.</p>
    @if($statistics['anchored'])
        <dl class="entity-stats">
            <div data-stat="registered"><dt>المسجلون في جذور</dt><dd>{{ number_format($statistics['registered']) }}</dd><p>كل الأشخاص المسجلين في نسب هذا {{ $scope === 'direct' ? 'المستوى' : 'المستوى وفروعه' }} عبر كل الأزمنة، بما فيهم السجلات غير المنشورة؛ يُحسب كل شخص مرة واحدة.</p></div>
            <div data-stat="verified"><dt>الموثقون</dt><dd>{{ number_format($statistics['verified']) }}</dd><p>سجلات موثقة أو مسندة ومتصلة بالمؤسس بعلاقات أبوة موثقة.</p></div>
            <div data-stat="published"><dt>المنشورون للعامة</dt><dd>{{ number_format($statistics['published']) }}</dd><p>الأشخاص الظاهرون للزوار في الشجرة (متوفون وسجلاتهم منشورة).</p></div>
            @if($statistics['living'] !== null)<div data-stat="living"><dt>الأحياء المعروفون</dt><dd>{{ number_format($statistics['living']) }}</dd><p>يظهر لأعضاء العائلة فقط.</p></div>@endif
            <div data-stat="branches"><dt>الفروع النسبية المعروفة</dt><dd>{{ number_format($statistics['branches']) }}</dd><p>فروع مسجلة يقع مؤسسها ضمن هذا النسب.</p></div>
            <div data-stat="father-coverage"><dt>السجلات المرتبطة بأب موثق</dt><dd>{{ $statistics['fatherCoverage'] === null ? 'غير متاح' : $statistics['fatherCoverage'].'٪' }}</dd><p>نسبة من المسجلين (عدا المؤسسين) لهم علاقة أبوة موثقة؛ مقياس لجودة البيانات لا للاكتمال.</p></div>
        </dl>
    @else
        <p class="text-muted" role="status">لا يرتبط هذا النطاق بفرع أو شجرة منشورة، فلا تتوفر أعداد مبنية على السجلات.</p>
    @endif
    <dl class="entity-facts mb-0">
        <div data-stat="estimate"><dt>التعداد التقديري من مصدر مستقل</dt><dd>@if($statistics['estimate']){{ number_format($statistics['estimate']['value']) }}@if($statistics['estimate']['year']) (تقدير {{ $statistics['estimate']['year'] }})@endif — المصدر: {{ $statistics['estimate']['source']->title }}@else غير متوفر @endif</dd></div>
        <div data-stat="coverage"><dt>نسبة شمول السجلات للأفراد الفعليين</dt><dd>غير معروفة — لا يمكن قياسها من السجلات وحدها.</dd></div>
        <div><dt>تاريخ الإحصاء</dt><dd>{{ $statistics['computedAt']->format('Y-m-d') }} (محسوبة من السجلات الحالية)</dd></div>
    </dl>
</section>
