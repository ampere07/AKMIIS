<?php

namespace App\Http\Controllers;

use App\Models\AgentInvoice;
use App\Models\User;
use App\Services\AgentInvoicePdfService;
use App\Services\AgentInvoiceService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;

/**
 * Reads and serves the weekly agent referral invoices.
 *
 * Every query is scoped before it runs, never filtered afterwards:
 *
 *   • an agent in a team sees that team's invoices;
 *   • an agent with no team sees only their own;
 *   • an administrator sees their organisation's;
 *   • a superadmin (role 7) sees everything.
 *
 * The scope is applied inside a single method used by every endpoint, so a new
 * endpoint cannot forget it.
 */
class AgentInvoiceController extends Controller
{
    private const ADMIN_ROLES = ['admin', 'administrator', 'billing', 'superadmin'];

    /** GET /api/agent-invoices */
    public function index(Request $request)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $query = $this->scopedQuery($user)->with(['customers' => fn ($q) => $q->orderBy('id')]);

            // ── Filters, mirroring the billing invoice page ──────────────────
            if ($search = trim((string) $request->input('search', ''))) {
                $query->where(function ($q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                      ->orWhere('team_name', 'like', "%{$search}%")
                      ->orWhere('agent_name', 'like', "%{$search}%");
                });
            }

            if ($status = trim((string) $request->input('status', ''))) {
                $query->where('status', $status);
            }

            if ($type = trim((string) $request->input('type', ''))) {
                $query->where('invoice_type', $type);
            }

            if ($from = $request->input('date_from')) {
                $query->whereDate('invoice_date', '>=', Carbon::parse($from)->format('Y-m-d'));
            }

            if ($to = $request->input('date_to')) {
                $query->whereDate('invoice_date', '<=', Carbon::parse($to)->format('Y-m-d'));
            }

            $perPage = min(max((int) $request->input('per_page', 25), 1), 200);

            $invoices = $query->orderByDesc('invoice_date')
                              ->orderByDesc('id')
                              ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data'    => $invoices->getCollection()->map(fn ($i) => $this->present($i))->all(),
                'meta'    => [
                    'current_page' => $invoices->currentPage(),
                    'last_page'    => $invoices->lastPage(),
                    'per_page'     => $invoices->perPage(),
                    'total'        => $invoices->total(),
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('[AGENT INVOICES] index failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch agent invoices',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /** GET /api/agent-invoices/{id} — the invoice with every customer on it. */
    public function show(Request $request, $id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $invoice = $this->scopedQuery($user)
                ->with(['customers' => fn ($q) => $q->orderBy('id')])
                ->find($id);

            if (!$invoice) {
                // Deliberately the same answer whether it does not exist or is
                // not theirs, so the endpoint cannot be used to discover which
                // invoice numbers are real.
                return response()->json(['success' => false, 'message' => 'Invoice not found'], 404);
            }

            return response()->json([
                'success' => true,
                'data'    => $this->present($invoice, true),
            ]);
        } catch (Throwable $e) {
            Log::error('[AGENT INVOICES] show failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch the invoice',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * GET /api/agent-invoices/{id}/pdf — stream the stored PDF.
     *
     * Serves the file already on disk. It is only rendered here if the stored
     * file has gone missing, so opening the page does not re-render every time.
     */
    public function pdf(Request $request, $id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            $invoice = $this->scopedQuery($user)->find($id);
            if (!$invoice) {
                return response()->json(['success' => false, 'message' => 'Invoice not found'], 404);
            }

            $path = $invoice->pdf_path ? storage_path('app/public/' . $invoice->pdf_path) : null;

            if (!$path || !is_file($path)) {
                // Rebuild from the stored rows rather than failing — the invoice
                // is the record, the file is only a rendering of it.
                $invoice->load(['customers' => fn ($q) => $q->orderBy('id')]);
                $relative = app(AgentInvoicePdfService::class)->render($invoice, true);
                $invoice->forceFill(['pdf_path' => $relative])->save();
                $path = storage_path('app/public/' . $relative);
            }

            if (!is_file($path)) {
                return response()->json(['success' => false, 'message' => 'The invoice PDF is unavailable'], 404);
            }

            $disposition = $request->boolean('download')
                ? BinaryFileResponse::DISPOSITION_ATTACHMENT
                : BinaryFileResponse::DISPOSITION_INLINE;

            return response()->file($path, [
                'Content-Type'        => 'application/pdf',
                'Content-Disposition' => $disposition . '; filename="' . $invoice->invoice_number . '.pdf"',
            ]);
        } catch (Throwable $e) {
            Log::error('[AGENT INVOICES] pdf failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to open the invoice PDF',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * POST /api/agent-invoices/generate — run the weekly generation by hand.
     *
     * Administrators only. Idempotent: an owner already invoiced for the week
     * is skipped, so this is safe to press twice.
     */
    public function generate(Request $request, AgentInvoiceService $service)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            if (!$this->isAdminUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only an administrator can generate agent invoices.',
                ], 403);
            }

            // Treated as the generation date: the seven days BEFORE it are
            // billed, matching what the Monday cron would have produced that
            // day. `week` is kept as the field name for the existing callers;
            // `as_of` is the clearer name and wins when both are sent.
            $asOfInput = $request->input('as_of') ?: $request->input('week');
            $asOf = $asOfInput ? Carbon::parse($asOfInput) : null;

            $summary = $service->generateForWeek($asOf);

            return response()->json([
                'success' => true,
                'message' => "{$summary['invoices_created']} invoice(s) generated for "
                    . "{$summary['period_start']} to {$summary['period_end']}.",
                'data'    => $summary,
            ]);
        } catch (Throwable $e) {
            Log::error('[AGENT INVOICES] manual generate failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to generate agent invoices',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    /** PATCH /api/agent-invoices/{id}/status — administrators only. */
    public function updateStatus(Request $request, $id)
    {
        try {
            $user = auth()->user();
            if (!$user) {
                return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
            }

            if (!$this->isAdminUser($user)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Only an administrator can change an invoice status.',
                ], 403);
            }

            $validated = $request->validate([
                'status' => 'required|string|in:Generated,Sent,Paid,Cancelled',
            ]);

            $invoice = $this->scopedQuery($user)->find($id);
            if (!$invoice) {
                return response()->json(['success' => false, 'message' => 'Invoice not found'], 404);
            }

            $invoice->forceFill([
                'status'     => $validated['status'],
                'updated_by' => $user->email_address ?? $user->email ?? 'unknown',
            ])->save();

            return response()->json([
                'success' => true,
                'message' => 'Invoice status updated',
                'data'    => $this->present($invoice),
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors'  => $e->errors(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('[AGENT INVOICES] status update failed: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to update the invoice status',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    // ── Scoping ─────────────────────────────────────────────────────────────

    /**
     * The invoices this user is allowed to see, as a query.
     *
     * Applied before anything else on every endpoint, so an agent cannot reach
     * another team's invoice by guessing an id.
     */
    private function scopedQuery($user)
    {
        $query = AgentInvoice::query();

        if ($this->isAdminUser($user)) {
            // A superadmin sees everything; an organisation admin sees theirs.
            $roleId = $user->role_id ?? null;
            $orgId  = $user->organization_id ?? null;

            if ($roleId != 7 && $orgId) {
                $query->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                });
            }

            return $query;
        }

        // An agent: their team's invoices, or their own if they have no team.
        $teamId = $user->agent_id ?? null;

        if ($teamId !== null && $teamId !== '') {
            return $query->where('owner_key', AgentInvoice::ownerKeyForTeam($teamId));
        }

        return $query->where('owner_key', AgentInvoice::ownerKeyForAgent($user->id));
    }

    private function isAdminUser($user): bool
    {
        if (($user->role_id ?? null) == 7) {
            return true;
        }

        return in_array(strtolower($user->role->role_name ?? ''), self::ADMIN_ROLES, true);
    }

    /** One invoice as the page renders it. */
    private function present(AgentInvoice $invoice, bool $withCustomers = false): array
    {
        $payload = [
            'id'              => $invoice->id,
            'invoice_number'  => $invoice->invoice_number,
            'invoice_type'    => $invoice->invoice_type,
            'team_id'         => $invoice->team_id,
            'team_name'       => $invoice->team_name,
            'agent_id'        => $invoice->agent_id,
            'agent_name'      => $invoice->agent_name,
            'billed_to'       => $invoice->billed_to,
            'invoice_date'    => optional($invoice->invoice_date)->format('Y-m-d'),
            'period_start'    => optional($invoice->period_start)->format('Y-m-d'),
            'period_end'      => optional($invoice->period_end)->format('Y-m-d'),
            'total_customers' => (int) $invoice->total_customers,
            'unit_price'      => (float) $invoice->unit_price,
            'installation_fee'=> (float) $invoice->installation_fee,
            'total_amount'    => (float) $invoice->total_amount,
            'commission'      => (float) $invoice->commission,
            'subtotal'        => (float) $invoice->subtotal,
            'status'          => $invoice->status,
            'has_pdf'         => (bool) $invoice->pdf_path,
            'created_at'      => optional($invoice->created_at)->toIso8601String(),
        ];

        if ($withCustomers || $invoice->relationLoaded('customers')) {
            $payload['customers'] = $invoice->customers->map(fn ($c) => [
                'id'                   => $c->id,
                'application_id'       => $c->application_id,
                'job_order_id'         => $c->job_order_id,
                'customer_name'        => $c->customer_name,
                'referred_by_agent_id' => $c->referred_by_agent_id,
                'referred_by_name'     => $c->referred_by_name,
                'installed_date'       => optional($c->installed_date)->format('Y-m-d'),
                'unit_price'           => (float) $c->unit_price,
                'quantity'             => (int) $c->quantity,
                'total'                => (float) $c->total,
            ])->all();
        }

        return $payload;
    }
}
