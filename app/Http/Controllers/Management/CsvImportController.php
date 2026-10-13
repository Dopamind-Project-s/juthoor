<?php

namespace App\Http\Controllers\Management;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\ImportBatch;
use App\Services\Genealogy\SelectedFamilyTree;
use App\Services\Imports\CsvImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CsvImportController extends Controller
{
    public function __construct(private SelectedFamilyTree $selection, private CsvImportService $imports) {}

    private function context(string $slug): array
    {
        $family = Family::query()->active()->where('slug', $slug)->firstOrFail();
        Gate::authorize('view', $family);

        return [$family, $this->selection->resolve($family)];
    }

    public function index(string $family): View
    {
        [$family,$tree] = $this->context($family);
        $batches = ImportBatch::query()->where('family_tree_id', $tree->id)->orderByDesc('id')->paginate(20)->withQueryString();

        return view('management.imports.index', compact('family', 'tree', 'batches'));
    }

    public function template(string $family): StreamedResponse
    {
        [$family,$tree] = $this->context($family);

        return response()->streamDownload(function (): void {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, CsvImportService::COLUMNS, ',', '"', '');
            fputcsv($handle, ['DEMO-ROOT', 'جذر', 'جذر اصطناعي', 'male', '1900', '1980', '0', 'historical', 'pending_review', '', 'demo-branch', 'فرع اصطناعي'], ',', '"', '');
            fputcsv($handle, ['DEMO-CHILD', 'ابن', 'ابن اصطناعي', 'male', '1930', '2000', '0', 'historical', 'pending_review', 'DEMO-ROOT', '', ''], ',', '"', '');
            fclose($handle);
        }, 'juthoor-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function store(Request $request, string $family): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:1024']]);
        $batch = $this->imports->upload($tree, $request->user(), $request->file('file'));

        return redirect()->route('management.imports.show', [$family->slug, $batch->id, 'tree' => $tree->slug])->with('status', $batch->status === 'committed' ? 'هذه البصمة معتمدة مسبقًا؛ لم تُنفذ دفعة مكررة.' : 'المعاينة جاهزة دون كتابة الأشخاص أو العلاقات. قد يُعاد استخدام دفعة لها البصمة نفسها.');
    }

    public function show(string $family, int $batch): View
    {
        [$family,$tree] = $this->context($family);
        $batch = ImportBatch::query()->where('family_tree_id', $tree->id)->findOrFail($batch);

        return view('management.imports.show', compact('family', 'tree', 'batch'));
    }

    public function revalidate(Request $request, string $family, int $batch): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('manageSources', $family);
        $batch = ImportBatch::query()->where('family_tree_id', $tree->id)->findOrFail($batch);
        $this->imports->revalidate($tree, $batch, $request->user());

        return back()->with('status', 'أُعيدت معاينة الملف مقابل الحالة الحالية للشجرة.');
    }

    public function commit(Request $request, string $family, int $batch): RedirectResponse
    {
        [$family,$tree] = $this->context($family);
        Gate::authorize('review', $family);
        $batch = ImportBatch::query()->where('family_tree_id', $tree->id)->findOrFail($batch);
        $data = $request->validate(['confirm' => ['required', 'accepted'], 'reason' => ['required', 'string', 'max:2000'], 'preview_token' => ['required', 'string', 'size:64'], 'allow_name_duplicates' => ['nullable', 'boolean'], 'duplicate_reason' => ['required_if:allow_name_duplicates,1', 'nullable', 'string', 'max:2000'], 'root_external_id' => ['nullable', 'string', 'max:255']]);
        $this->imports->commit($tree, $batch, $request->user(), $data['reason'], $request->boolean('allow_name_duplicates'), $data['duplicate_reason'] ?? '', $data['root_external_id'] ?? null, $data['preview_token']);

        return back()->with('status', 'اعتُمدت الدفعة وسُجلت نتائجها دون دمج تلقائي.');
    }
}
