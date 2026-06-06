<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Mail\SupportContactMail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SupportContactController extends Controller
{
    /**
     * POST /api/v1/support/contact
     * body: { "email": "user@example.com", "message": "...", "subject": "optional" }
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'subject' => ['nullable', 'string', 'max:255'],
        ]);

        $adminEmail = config('services.support.email');
        if (empty($adminEmail)) {
            Log::error('Support contact failed: SUPPORT_ADMIN_EMAIL is not configured.');

            return response()->json([
                'message' => api_trans('support_contact_unavailable'),
            ], 503);
        }

        $user = $request->user();
        $userName = null;
        if ($user) {
            $userName = trim((string) $user->first_name.' '.(string) $user->last_name) ?: null;
        }

        try {
            Mail::to($adminEmail)->send(new SupportContactMail(
                contactEmail: $data['email'],
                messageText: $data['message'],
                subjectLine: $data['subject'] ?? null,
                userName: $userName,
                userId: $user?->id,
            ));
        } catch (\Throwable $e) {
            Log::error('Support contact email failed', [
                'email' => $data['email'],
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => api_trans('support_contact_failed'),
            ], 500);
        }

        return response()->json([
            'message' => api_trans('support_contact_sent'),
        ]);
    }
}
