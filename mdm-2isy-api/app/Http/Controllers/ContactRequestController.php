<?php

namespace App\Http\Controllers;

use App\Models\ContactRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactRequestController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'company' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'contact' => ['required', 'string', 'max:80'],
            'issue' => ['required', 'string', 'min:10', 'max:5000'],
        ]);

        $contactRequest = ContactRequest::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Votre message a bien été transmis au super administrateur.',
            'data' => ['public_id' => $contactRequest->public_id],
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $status = $request->validate([
            'status' => ['nullable', Rule::in(['new', 'read', 'resolved'])],
        ])['status'] ?? null;

        $requests = ContactRequest::query()
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest()
            ->get();

        return response()->json(['success' => true, 'data' => $requests]);
    }

    public function update(Request $request, ContactRequest $contactRequest): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['read', 'resolved'])],
        ]);

        $now = now();
        $contactRequest->forceFill([
            'status' => $data['status'],
            'read_at' => $contactRequest->read_at ?? $now,
            'resolved_at' => $data['status'] === 'resolved' ? $now : null,
        ])->save();

        return response()->json([
            'success' => true,
            'message' => $data['status'] === 'resolved' ? 'Demande résolue.' : 'Demande marquée comme lue.',
            'data' => $contactRequest->fresh(),
        ]);
    }
}
