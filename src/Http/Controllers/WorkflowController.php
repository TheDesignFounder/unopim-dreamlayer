<?php

namespace DreamLayer\UnoPIM\Http\Controllers;

use DreamLayer\UnoPIM\Http\Requests\WorkflowRequest as Request;
use DreamLayer\UnoPIM\Models\Batch;
use DreamLayer\UnoPIM\Models\Workflow;
use DreamLayer\UnoPIM\Services\ImageStore;
use DreamLayer\UnoPIM\Services\ProductImages;
use Illuminate\Routing\Controller;
use Webkul\Product\Models\Product;

class WorkflowController extends Controller
{
    private function owned(string $id): Workflow
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);

        return Workflow::whereKey($id)->where('user_id', auth()->guard('admin')->id())->firstOrFail();
    }

    public function index(Request $request)
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);
        $query = Workflow::where('user_id', auth()->guard('admin')->id());
        foreach (['conversation_id', 'request_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('q')) {
            $ids = Product::where('sku', 'like', addcslashes($request->input('q'), '%_\\').'%')->pluck('id')->map(fn ($id) => (string) $id);
            $query->whereIn('target->product_id', $ids);
        }
        match ($request->input('status')) {
            'processing' => $query->whereIn('status', ['queued', 'running']),
            'ready' => $query->where('status', 'completed')->whereNull('applied_at'),
            'applied' => $query->whereNotNull('applied_at'),
            'failed' => $query->where('status', 'failed'),
            'partial' => $query->where('status', 'failed')->whereJsonContains('progress', [['status' => 'completed']]),
            default => null,
        };
        $workflows = $query->latest('created_at')->paginate(20)->withQueryString();
        if ($request->expectsJson()) {
            $batchQuery = Batch::where('user_id', auth()->guard('admin')->id());
            foreach (['conversation_id', 'request_id'] as $field) {
                if ($request->filled($field)) {
                    $batchQuery->where($field, $request->input($field));
                }
            }
            $items = $workflows->map(fn ($w) => ['id' => $w->id, 'conversation_id' => $w->conversation_id, 'request_id' => $w->request_id, 'status' => $w->status, 'presentation' => ['type' => 'dreamlayer.workflow', 'id' => $w->id]])->values();
            foreach ($batchQuery->latest('created_at')->limit(20)->get() as $b) {
                $items->push(['id' => $b->id, 'conversation_id' => $b->conversation_id, 'request_id' => $b->request_id, 'status' => $b->status, 'presentation' => ['type' => 'dreamlayer.batch', 'id' => $b->id]]);
            }

            return response()->json(['workflows' => $items, 'next_page_url' => $workflows->nextPageUrl()])->header('Cache-Control', 'private, no-store');
        }

        $products = Product::whereIn('id', $workflows->pluck('target.product_id')->filter())->get()->keyBy('id');

        return response()->view('dreamlayer::results', compact('workflows', 'products'))->header('Cache-Control', 'private, no-store');
    }

    public function batch(string $id)
    {
        abort_unless(bouncer()->hasPermission('catalog.products.edit'), 403);
        $batch = Batch::whereKey($id)->where('user_id', auth()->guard('admin')->id())->firstOrFail();

        return response()->json(['id' => $batch->id, 'count' => count($batch->children), 'status' => $batch->status, 'error' => $batch->error,
            'children' => array_map(fn ($id) => ['id' => $id], $batch->children)])->header('Cache-Control', 'private, no-store');
    }

    public function show(string $id)
    {
        $w = $this->owned($id);

        $images = app(ImageStore::class);
        $damaged = $images->damaged($w);

        return response()->json(['id' => $w->id, 'status' => $w->status,
            'phase' => $w->phase(),
            'error_code' => $w->error_code,
            'billing_url' => $w->status === 'failed' && $w->error_code === 402 ? config('dreamlayer.billing_url') : null,
            // A successful status GET is not evidence that a worker is processing it.
            'delayed' => $w->delayed(),
            'detailed_progress' => $w->execution_path === 'workflow_v1',
            'steps' => array_map(fn ($s, $index) => ['label' => $s['description'], 'status' => $s['status'], 'image_url' => $images->valid($s['result_path'] ?? null) ? route('dreamlayer.image', ['id' => $w->id, 'step' => $index]) : null], $w->progress ?? [], array_keys($w->progress ?? [])),
            'partial_success' => $w->status === 'failed' && count(array_filter($w->progress ?? [], fn ($s) => $images->valid($s['result_path'] ?? null))) > 0,
            'billing_notice' => $w->status === 'failed' && count(array_filter($w->progress ?? [], fn ($s) => $s['status'] === 'completed')) > 0 ? trans('dreamlayer::app.message-913e5b0f8c') : null,
            'kind' => $w->steps[0]['operation'] === 'text_to_image' ? 'generate' : 'edit',
            'target' => $w->target ? array_diff_key($w->target, array_flip(['version', 'attribute_id'])) : null,
            'delivery_unavailable' => $damaged,
            'error' => $damaged ? trans('dreamlayer::app.message-6f279d2d4e') : $w->error, 'applied' => (bool) $w->applied_at,
            'image_url' => $w->status === 'completed' && $images->valid($w->result_path) ? route('dreamlayer.image', $w->id) : null,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function image(Request $request, string $id)
    {
        $w = $this->owned($id);
        $result = $w->status === 'completed' ? $w->result_path : null;
        if ($request->has('step')) {
            $step = ($w->progress ?? [])[(int) $request->input('step')] ?? [];
            $result = ($step['status'] ?? '') === 'completed' ? ($step['result_path'] ?? null) : null;
        }
        abort_unless($result, 404);
        try {
            $bytes = app(ImageStore::class)->read($result);
        } catch (\RuntimeException $e) {
            abort(503, trans('dreamlayer::app.message-e2db6b8836'));
        }

        return response($bytes, 200, ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff', 'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="dreamlayer-'.$w->id.'.png"']);
    }

    public function apply(string $id, ProductImages $images)
    {
        $this->owned($id);
        $images->apply($id, auth()->guard('admin')->id());

        return $this->show($id);
    }
}
