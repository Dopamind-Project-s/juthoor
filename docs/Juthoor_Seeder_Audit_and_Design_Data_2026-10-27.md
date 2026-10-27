# جذور | Juthoor — Seeder Audit, Data Governance & Seed-Driven UI Plan
**التاريخ:** 27 أكتوبر 2026  
**المستودع:** `Dopamind-Project-s/juthoor` — الفرع `main`، آخر Commit تم رصده عند المراجعة `53a81cff`  
**الحالة:** تقرير مراجعة مبني على قراءة كود GitHub؛ **ليس** نتيجة تشغيل Laravel أو قاعدة الإنتاج.  
**المراجع:** `Juthoor Master Project Requirements v2.0` المرجع الرسمي، و`Juthoor Experience v3.0` مرجع تجربة الاستخدام والبوابات العائلية المكمل له.

> **قاعدة حاسمة:** Seeders ووثائق الإدخال مصادر بيانات وأدلة على طريقة التنفيذ، لكنها ليست بذاتها إثباتًا تاريخيًا. لا يُعتمد نسب متنازع عليه، ولا يُنشر شخص مخفي، ولا تُنقل أرقام من صفحات ثابتة إلى الإنتاج باعتبارها تعدادًا حيًا.

## A. الجرد الفعلي

| المكوّن | الملف أو المصدر | وظيفة مثبتة بالكود |
|---|---|---|
| Seeder الرئيسي | `database/seeders/DatabaseSeeder.php` | ينفذ `AlZoubiGenealogySeeder` ثم `PersonAttributeSeeder`؛ ويضيف حسابات تجربة في `local/testing` فقط |
| تكوين العائلة | `FamilySeeder.php` | ينشئ/يحدّث `al-zoubi` |
| تكوين الشجرة | `FamilyTreeSeeder.php` | ينشئ/يحدّث الشجرة الأساسية `main` |
| الجذر المشترك | `GenealogyPeopleSeeder.php`, `GenealogyRelationshipSeeder.php` | يستورد **77** سجل `READY_ROOT` وروابط الأب عبر المفاتيح الثابتة |
| الفروع | `BranchSeeder.php` | يعرف ستة فروع: بكر، عيد، خميس، الرحايمة، العوتلات، القواسمة |
| المؤسسون | `BranchFounderSeeder.php` و`BranchFounderResolutionImporter.php` | يستورد مسارات المؤسسين المدعومة بمعرّفات مرجع داخل ملف البيانات؛ يحوّل الفروع المحلولة إلى فعالة |
| النسب البديل | `AlZoubiSharedAncestrySeeder.php` | استيراد اختياري لسلسلة مرفوعة للمراجعة، وليس جزءًا من `db:seed` الرئيسي |
| القواسمة | `AlQawasmehBranchSeeder.php` و`resources/data/al-qawasmeh/manifest.json` | استيراد اختياري لـ**34** شخصًا أبويتهم مقدمة ومصادرهم قيد المراجعة؛ لا ينشر الفرع تلقائيًا |
| اختلافات الجذر | `AlZoubiRootVariantSeeder.php` | يسجل فروق الأسماء في Assertions؛ **39** خلافًا لا تُحل آليًا |
| الأوصاف والألوان | `PersonAttributeSeeder.php` | **10** سمات عالمية مسماة وقيم ألوان؛ التعديل الإداري على الألوان القائمة لا يعاد استبداله بـ `firstOrCreate` |
| مصادر البحث | `AlZoubiResearchContextSeeder.php` | إضافة مراجع سياقية اختيارية لمناقشة حدود الأدلة، لا لتغيير الأنساب |
| مستخدمو التجربة | `DevelopmentAdminSeeder.php` | تجريبي حصريًا لبيئتي `local/testing`؛ يستخدم كلمة مرور ثابتة `password` |

**حساب مبدئي مشتق من الكود والتقارير السابقة، لا من قاعدة إنتاج:** عند تشغيل المسار الأساسي + القواسمة وفق الاختبارات، يوجد 119 سجل شخص، و118 علاقة أبوة، و6 فروع، وشجرة واحدة. منها 34 قواسمة غير معتمدين للنشر العام. هذه **أعداد سجلّات اختبار** وليست عدد أبناء الزعبي أو القواسمة الحقيقي.

## B. النتائج والمخاطر الموثقة

| ID | الخطورة | دليل الكود | الملاحظة | الإجراء المقترح |
|---|---|---|---|---|
| SD-01 | **عالٍ** | `FamilySeeder::updateOrCreate` | تكرار `db:seed` يعيد اسم العائلة ووصفها وموقعها إلى قيم Seeder، وقد يعيد `is_active=true` رغم تعطيلها إداريًا. | **إنشاء فقط** عند عدم وجودها؛ تحديث القيم القائمة عبر migration/command إداري واضح ومُراجع |
| SD-02 | **عالٍ** | `FamilyTreeSeeder::updateOrCreate` | يعيد `is_primary=true` و`is_active=true` والبيانات الوصفية، ويمكنه تجاوز قرارات الإدارة الحالية. | عدم الكتابة فوق شجرة موجودة؛ اختبارات preserve-admin-edits |
| SD-03 | **عالٍ** | `GenealogyPeopleSeeder::updateOrCreate` | يعيد الحالة إلى Historical و`verification_status=sourced` و`notes` لسجلات ROOT؛ قد يتداخل مع تصحيح ومراجعة بشرية لاحقة. | تحديث السجل عند الإنشاء فقط؛ للموجود تحقق من الهوية وألحق المرجع دون تعديل قرار مراجعة |
| SD-04 | **عالٍ** | `GenealogyRelationshipSeeder::updateOrCreate` | يعيد المصدر والتوثيق إلى `sourced` للعلاقة نفسها؛ قد يخفض تحققًا معتمدًا أو يلغي حالة نزاع لاحقة. | preflight وتبني `firstOrCreate` في الحالات المطابقة فقط؛ ارفض الأب المختلف ولا تستبدل المراجعة |
| SD-05 | **عالٍ** | `BranchSeeder::fill(...)->save()` | يعيد الاسم والتبعية والنوع والترتيب للفروع الموجودة عند كل تشغيل. | اختبارات تطابق البيانات مع Seeder أول تشغيل، وحفظ تعديلات الإدارة في التشغيل الثاني |
| SD-06 | **عالٍ** | `BranchFounderResolutionImporter` | يملأ الاسم والأبوة و`verified` والملاحظات، ويعيد ربط مؤسسي الفروع عند كل تشغيل؛ مراجعة تاريخية قد تُكتب فوقها. | تعامل مع Seeder كعملية bootstrap لا updater؛ للتحقق التاريخي استخدم citations/review مع منع الكتابة على record modified |
| SD-07 | **متوسط** | `AlZoubiGenealogySeeder` | استيراد الجذر داخل transaction، لكن `LivingStatusClassifier::classifyUnknown()` يعمل بعد انتهائها. | ضع التصنيف داخل المعاملة إذا كان جزءًا من import حتى يتراجع الكل عند الفشل |
| SD-08 | **عالٍ أمني/خصوصية** | `public/docs/juthoor_alzoubi_genealogy_dataset_v4.json` وملفات تحمل `(1)`، وملفات القواسمة العامة | توجد ملفات بيانات خام كبيرة ونسخ متكررة **تحت webroot**؛ الوصول العام ومحتوى PII لم يُفحصا. | تدقيق وصول HTTP ومحتوى الملفات ثم نقل الحسّاس إلى مصدر خاص؛ لا تحذف قبل تعديل مسارات الاستيراد وchecksum |
| SD-09 | **متوسط** | `DevelopmentAdminSeeder` | يحمي بيئات `local/testing`، لكنه يستخدم `password` الافتراضية؛ مشكلة إذا عُرضت بيئة dev للعامة أو أخطئ تصنيف APP_ENV. | كلمة مرور من env محلي أو مولدة، وتحذير/حظر تشغيل Seeder التجربة على مضيف عام |
| SD-10 | **بيانات** | `resources/data/al-zoubi/branches.php`, `genealogy.php` | تعرض الصفحات الثابتة أرقامًا مثل `1505` و`355/299/308/304/162` ليست نتائج استعلام حي من الجداول. | لا تعتمد في إحصاءات Landing؛ استخدم service شمار `published, verified, total_records` مع تاريخ |
| SD-11 | **وظيفي** | `DatabaseSeeder` | لا ينشئ مجتمع `CommunityEntity`، ولا الدواوين والمقالات والأخبار والدليل، فلا توجد بيانات حقيقية كافية لتعبئة كل أقسام التصميم. | Seeder اختياري للعرض **محليًا فقط** يربط كيانات بالفروع الحقيقية، مع وسائط ومحتوى تجريبي معلّم وغير منشور |
| SD-12 | **بيانات تاريخية** | `AlZoubiSharedAncestrySeeder`, `RootVariantImporter` | سلسلتان تاريخيتان مختلفتان؛ 39 اختلافًا مستمرًا؛ بعضها أقدم من عدنان ولا ينبغي اعتباره محققًا تاريخيًا لمجرد إضافته للسجل. | تبقى الفروق Assertions للمراجعة؛ اعرض provenance ودرجة الإثبات بوضوح في UI |
| SD-13 | **بيانات تاريخية** | `al-qawasmeh/manifest.json` | صور القواسمة تتضمن سردًا أبويًا **مختلفًا** عن الامتداد المدخل يدويًا وفيها عقد غير مسماة؛ الاستيراد الحالي يحتفظ بالادعاء المقدم ويترك المخالف unresolved. | اعرض حالة اختلاف الروايات في إدارة الشجرة؛ **لا تستنتج آباء من صورة ولا تدمج الأسماء المتطابقة** |
| SD-14 | **حوكمة** | `PersonAttributeSeeder` + `PersonAttribute` | الألوان تسند لسمات `prophet`, `companion`, `fakhd-founder` وغيرها. لا يكفي اللون وحده، ولا تعني السمة بذاتها تحقق النسب. | خصص tooltip/legend نصيًا والتمييز بين role badge وverification. حافظ على أي لون عدّله المدير |
| SD-15 | **UX/Architecture** | `resources/views/families/al-zoubi/*` مقارنة بـ `CommunityEntityPage` | صفحات الزعبي الحالية ثابتة وتتجاوز ديناميكية الصفحات الجديدة؛ خطر عرض معلومات وصور وأرقام قديمة. | تحويل تدريجي إلى FamilyPortal assembler من Family/Branch/Entity/Content مع fallback منضبط دون تكرار بيانات |

**الملاحظات أعلاه مبنية على قراءة المصدر؛ لا تثبت خللًا حدث فعلًا في قاعدة الإنتاج.** لا يوجد تنفيذ اختبارات Laravel أو فحص لقاعدة الإنتاج ضمن هذه المراجعة.

## C. الإصلاحات المقترحة ضمن دفعات آمنة

### D1 — حماية الإعدادات والسجلات المراجعة (P0)
1. أضف اختبارات أن تكرار `db:seed` لا يغير الحقول التي عدلها مسؤول العائلة: الاسم والوصف والحالة، حالة الشجرة، مراجع الأبوة، أسماء الأشخاص المعتمدة، وضع الخصوصية والألوان.
2. عدّل seeders التي تعيد الكتابة لتنشئ عند الغياب فقط، مع معالجة صريحة للتعارضات ومخرجات واضحة.
3. حافظ على canonical keys / person IDs / existing citations، ولا ترفع/تخفض التحقق أو الحالة عبر Seeder.
4. اختبر SQLite + PostgreSQL 16، و`db:seed` مرتين، مع ثم بدون استيراد القواسمة.
5. لا تغير ROOT-077 / ROOT-078 أو 39 اختلافًا ولا تؤكد نسبًا بقرار برمجي.

### D2 — Preflight & Transactional Imports (P0)
1. Command `genealogy:seed-audit --dry-run` يعرض counts, conflicts, potential overwrites, source file checksums.
2. تحقق من uniqueness, father consistency, valid root/founder and sources **قبل** mutation؛ لا auto-correct.
3. اجعل classification داخل استيراد atomic متى لزم.
4. قيّم سلوك parallel invocations وlock على family/tree.
5. استخدم immutable logs للحركات التي تُعالج، مع summary report.

### D3 — بيئة عرض مستندة إلى البيانات دون تزييفها (P1)
1. `JuthoorUiDemoSeeder` منفصل، `local/testing` فقط وopt-in، لا يعمل داخل `db:seed` في production.
2. أنشئ CommunityEntity للزعبي/الأفخاذ المطلوبة **مرتبطة بالسجلات الحالية** دون إنشاء شخص أو أب أو branch founder جديد.
3. أضف بيانات واجهة **تجريبية معلّمة** فقط للأخبار/الدواوين/الصور/الدليل عند غياب سجلات حقيقية، مع عدم نشرها.
4. المشجرات غير المعتمدة تبقى ظاهرة للمراجعين فقط.
5. الجداول العامة تُحمّل من services وقواعد النشر، لا من php arrays الثابتة.

### D4 — API/Presentation Contract للواجهات (P1)
- `FamilyPortalViewModel`: family branding, heritage hero, published counts as-of, sections.
- `TreePresentation`: full published graph, compact single-child chain, founder nodes, person attribute lookup, privacy badges.
- `FakhdPortalViewModel`: ancestor path + descendants, direct/inherited scope, unique counts, relevant diwans/news/archive.
- `AdminSeederReview`: dataset overview, 39 ROOT claims, QW pending persons, unresolved picture observations, branch founder review.
- `FixtureMeta`: `dataset_key`, `seed_version`, `snapshot_at`, `preview_only`, `evidence_status` (ليست حقول DB مفروضة؛ contract للعرض).
- لا تعلن أن بيانات Seeder منشورة أو موثقة بمجرد وجودها.

## D. مصفوفة شاشات قابلة للتصميم من البيانات الفعلية

| الشاشة | حقائق مصدر التصميم | عرض حالة المراجعة |
|---|---|---|
| Family Landing | Family `al-zoubi`، الشجرة `main`، أفخاذ بكر/الرحايمة/العوتلات/القواسمة | العدادات ديناميكية أو `—` إذا لم تُستعلم DB |
| Unified Tree | ROOT-001..ROOT-077، علاقات father، مؤسسو بكر/عيد/خميس/الرحايمة | لا تُظهر مسارًا متنازعًا عليه كمحقق تاريخيًا |
| Founder Node & Legend | `PersonAttributeSeeder` (10 سمات)، `BranchFounderResolution` | badge لا يساوي تحقق نسبي |
| Fakhd Index | 6 فروع، تبعية عيد وخميس لبكر | غير المنشور لا يظهر للزائر |
| Fakhd Page | Founder + ancestry path + published descendants + approved content | للمدير فقط تظهر معاينة Pending |
| Qawasmeh Admin Preview | `manifest.json`: قاسم QW-0003 و34 شخصًا | **pending_review** / conflicts panel |
| History & Archive | `CommunityContent`, `Media`, existing source library | لا تنشر مصادر أو مخطوطات تجريبية |
| Diwan Cards | real `Diwan` published rows only | empty state عند عدم وجود بيانات |
| Directory "دليل السند" | `MemberProfile` opt-in | لا تعرض أرقامًا أو جهات عمل دون إذن |
| News/Condolences | `news_posts`, `content_notices`, review states | لا تعتمد وفيات على Seeder أو عنوان دون مراجعة |
| Family Dashboard | audit/status counts, sources, branches, reviews | يعرض أسباب تعطل النشر وعمليات reconciliation |
| Seed Audit Dashboard | QW, ROOT claims, source checksums and dry-run | داخل الإدارة فقط ولا يغيّر الأنساب |

## E. ضوابط التنفيذ والاختبارات

- لا تعديل إنتاج، لا تثبيت `verified`, لا ملء أخبار أو وفيات أو وظائف تخيلية كمحتوى حقيقي.
- لا حذف نسخ raw datasets أو الصور قبل فحص الاستعمال وحقوق النشر والخصوصية.
- اختبارات Mandatory: idempotency, preserve admin edits, conflict no-op, root/father integrity, `living=unknown` excluded, cross-family isolation, snapshots same after repeated import, data access in public/docs, mobile RTL, badge legend.
- نفذ التعديل على فرع مراجعة منفصل؛ لا Merge أو Deploy تلقائي.
- سبب عدم تعديل GitHub في هذا التسليم: تكامل GitHub الحالي أعاد `403 Resource not accessible by integration` عند محاولة إنشاء فرع. الكتابة تتطلب صلاحيات جديدة.

## F. ربط المرجع الرسمي بالملحق الجديد

- **Normative baseline:** `Juthoor_Master_Project_Requirements_v2.0.md` (architecture, privacy, accepted requirements and status).
- **Approved UX direction / supplementary reference:** `Juthoor_Experience_v3_Requirements_and_Roadmap.md` (family domain, visual tree, portals and dashboards).
- **Seeder implementation evidence and work plan:** this document.
- عند التعارض: لا تعدل قاعدة النسب أو مصادرها أو خصوصيتها بموجب صورة تصميمية؛ يعرض التضارب للمراجعة في docs ويُطلب قرار حوكمة واضح.

## G. تحديث الأولويات المعتمد بتاريخ 27 أكتوبر 2026

**P0-A:** تحسين إضافة/تعديل/مراجعة الأشخاص مباشرة من الشجرة مع منع التكرار وحماية النسب.  
**P0-B:** تدقيق الأدوار والصلاحيات وSeeder الخاص بها، ومصفوفة RBAC بنطاق المنصة/العائلة/الفخذ، وفصل المحرر عن المراجع.  
**P0-C:** اعتماد Family Seed Packages موحدة ومكتملة، وحماية البيانات المراجعة وdry-run وprovenance وidempotency.  

التفاصيل ومعايير القبول وسجل قرارات المحادثة في `Juthoor_P0_People_Roles_Family_Seeders_2026-10-27.md`.
