<?php

namespace App\CreditNotes;

use App\CreditNotes\CreditNoteStatus;
use App\Http\Controllers\Controller;
use App\CreditNotes\CreditNote;
use App\CreditNotes\CreditNoteService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CreditNoteController extends Controller
{
    public function __construct(private readonly CreditNoteService $creditNotes) {}

    /**
     * Credit note to complete / full or partial return: validated against
     * the return period and returnable quantity, priced proportionally,
     * and approved inline (manager PIN required above the configured
     * threshold).
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'sale_id' => ['required', 'string', 'exists:sales,id'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sale_line_id' => ['required', 'string', 'exists:sale_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0'],
            'lines.*.condition' => ['nullable', 'string'],
            'lines.*.disposition' => ['nullable', 'in:restock,quarantine,waste'],
            'reason' => ['required', 'string'],
            'refund_method' => ['nullable', 'in:cash,card,mobile_qr,store_credit'],
            'allow_alternate_method' => ['nullable', 'boolean'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $creditNote = $this->creditNotes->initiateReturn($data, $request->user());

        return response()->json(['credit_note' => $creditNote], 201);
    }

    /**
     * No-receipt return: identifies the customer and item directly, always
     * requires manager approval, and only ever pays out as store credit.
     */
    public function noReceipt(Request $request)
    {
        $data = $request->validate([
            'customer_query' => ['required', 'string'],
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string'],
            'condition' => ['nullable', 'string'],
            'disposition' => ['nullable', 'in:restock,quarantine,waste'],
            'manager_pin' => ['nullable', 'string'],
        ]);

        $creditNote = $this->creditNotes->initiateNoReceiptReturn($data, $request->user());

        return response()->json(['credit_note' => $creditNote], 201);
    }

    public function show(Request $request, CreditNote $creditNote)
    {
        $this->assertSameStore($request, $creditNote);

        return response()->json(['credit_note' => $creditNote->load('lines', 'originalSale', 'customer')]);
    }

    /**
     * Return rejection: denies the return with a reason, moving no
     * inventory or money.
     */
    public function reject(Request $request, CreditNote $creditNote)
    {
        $this->assertSameStore($request, $creditNote);

        $data = $request->validate(['reason' => ['required', 'string']]);

        $creditNote = $this->creditNotes->rejectReturn($creditNote, $data['reason'], $request->user());

        return response()->json(['credit_note' => $creditNote]);
    }

    /**
     * Process the refund for an approved return: cash, card/mobile (via the
     * same provider abstraction as payments) or store credit.
     */
    public function refund(Request $request, CreditNote $creditNote)
    {
        $this->assertSameStore($request, $creditNote);

        $data = $request->validate(['simulate' => ['nullable', 'in:decline,timeout']]);

        $creditNote = $this->creditNotes->processRefund($creditNote, $request->user(), $data['simulate'] ?? null);

        return $this->respond($creditNote);
    }

    /**
     * Refund failure/unknown: re-query the provider by the credit note's
     * refund reference.
     */
    public function queryRefund(Request $request, CreditNote $creditNote)
    {
        $this->assertSameStore($request, $creditNote);

        $data = $request->validate(['resolve' => ['nullable', 'in:approved,declined']]);

        $creditNote = $this->creditNotes->queryRefund($creditNote, $request->user(), $data['resolve'] ?? null);

        return $this->respond($creditNote);
    }

    /**
     * Credit note reprint: only available once completed, always marked
     * and audited.
     */
    public function reprint(Request $request, CreditNote $creditNote)
    {
        $this->assertSameStore($request, $creditNote);

        $data = $request->validate(['reason' => ['nullable', 'string']]);

        $creditNote = $this->creditNotes->reprintCreditNote($creditNote, $request->user(), $data['reason'] ?? null);

        return response()->json(['credit_note' => $creditNote]);
    }

    private function respond(CreditNote $creditNote)
    {
        $status = match ($creditNote->status) {
            CreditNoteStatus::Failed => 422,
            CreditNoteStatus::RefundUnknown => 202,
            default => 200,
        };

        return response()->json(['credit_note' => $creditNote->load('lines')], $status);
    }

    private function assertSameStore(Request $request, CreditNote $creditNote): void
    {
        if ($creditNote->store_id !== $request->user()->store_id) {
            throw ValidationException::withMessages(['credit_note' => ['This credit note belongs to a different store.']]);
        }
    }
}
