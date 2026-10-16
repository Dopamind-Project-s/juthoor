<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\PersonAttribute;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PersonAttributeController extends Controller
{
    private function context(Request $request): ?Family
    {
        if ($slug = $request->route('family')) {
            $family = Family::query()->where('slug', $slug)->firstOrFail();
            Gate::authorize('manageMembers', $family);

            return $family;
        }
        abort_unless($request->user()->isSuperAdmin(), 403);

        return null;
    }

    private function target(Request $request, ?Family $family): PersonAttribute
    {
        return PersonAttribute::query()->where('family_id', $family?->id)->findOrFail($request->route('attribute'));
    }

    private function data(Request $request): array
    {
        return $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_active' => ['required', 'boolean'],
        ], ['color.regex' => 'اختر لونًا صالحًا بصيغة #RRGGBB.', 'label.required' => 'اسم الصفة مطلوب.']);
    }

    public function index(Request $request)
    {
        $family = $this->context($request);
        $request->validate(['q' => ['nullable', 'string', 'max:255'], 'active' => ['nullable', 'in:0,1']]);
        $attributes = PersonAttribute::query()
            ->when($family, fn ($query) => $query->availableFor($family->id), fn ($query) => $query->whereNull('family_id'))
            ->when($request->filled('q'), fn ($query) => $query->where('label', 'like', '%'.$request->query('q').'%'))
            ->when($request->filled('active'), fn ($query) => $query->where('is_active', $request->boolean('active')))
            ->orderBy('label')->paginate(15)->withQueryString();

        return view('management.attributes.index', compact('family', 'attributes'));
    }

    public function create(Request $request)
    {
        $family = $this->context($request);

        return view('management.attributes.form', ['family' => $family, 'attribute' => null]);
    }

    public function edit(Request $request)
    {
        $family = $this->context($request);
        $attribute = $this->target($request, $family);

        return view('management.attributes.form', compact('family', 'attribute'));
    }

    public function store(Request $request, AuditLogger $audit)
    {
        $family = $this->context($request);
        $data = $this->data($request);
        DB::transaction(function () use ($request, $family, $data, $audit): void {
            $attribute = PersonAttribute::query()->create($data + ['family_id' => $family?->id, 'key' => (string) Str::uuid()]);
            $audit->record($request->user(), $family, $attribute, 'created', null, $audit->snapshot($attribute));
        });

        return redirect()->route($family ? 'management.attributes.index' : 'site-admin.attributes.index', $family ? [$family->slug] : [])
            ->with('status', 'تمت إضافة الصفة.');
    }

    public function update(Request $request, AuditLogger $audit)
    {
        $family = $this->context($request);
        $attribute = $this->target($request, $family);
        $data = $this->data($request);
        DB::transaction(function () use ($request, $family, $attribute, $data, $audit): void {
            $attribute = PersonAttribute::query()->lockForUpdate()->findOrFail($attribute->id);
            $before = $audit->snapshot($attribute);
            $attribute->update($data);
            $audit->record($request->user(), $family, $attribute, 'updated', $before, $audit->snapshot($attribute));
        });

        return back()->with('status', 'تم تحديث الصفة. إيقافها يخفي شارتها دون حذف الارتباطات.');
    }
}
