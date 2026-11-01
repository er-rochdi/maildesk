<?php

namespace App\Http\Controllers;

use App\Services\ResendDeliveryTracker;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class ResendWebhookController extends Controller
{
    public function __invoke(Request $request, ResendDeliveryTracker $tracker)
    {
        $secret = (string) config('services.resend.webhook_secret');
        $key = str_starts_with($secret, 'whsec_') ? base64_decode(substr($secret, 6), true) : false;
        if ($key === false || strlen($key) < 16) {
            return response()->json(['message' => 'Webhook Resend non configuré.'], 503);
        }
        $body = $request->getContent();
        abort_if(strlen($body) > 65536, 413, 'Webhook trop volumineux.');
        $id = (string) $request->header('svix-id');
        $timestamp = (string) $request->header('svix-timestamp');
        $signatures = (string) $request->header('svix-signature');
        $valid = false;
        if ($id !== '' && strlen($id) <= 255 && ctype_digit($timestamp) && abs(now()->timestamp - (int) $timestamp) <= 300 && strlen($signatures) <= 2048) {
            $expected = base64_encode(hash_hmac('sha256', $id.'.'.$timestamp.'.'.$body, $key, true));
            foreach (preg_split('/\s+/', trim($signatures)) as $signature) {
                if (str_starts_with($signature, 'v1,') && hash_equals($expected, substr($signature, 3))) {
                    $valid = true;
                }
            }
        }
        if (! $valid) {
            return response()->json(['message' => 'Signature du webhook invalide.'], 401);
        }
        $event = json_decode($body, true);
        if (! is_array($event)) {
            return response()->json(['message' => 'Webhook JSON invalide.'], 422);
        }
        if (! in_array($event['type'] ?? null, ResendDeliveryTracker::TYPES, true)) {
            return response()->json(['received' => true, 'matched' => false]);
        }
        $validated = Validator::make($event, [
            'type' => 'required|string', 'created_at' => 'required|date',
            'data' => 'required|array', 'data.email_id' => 'required|uuid', 'data.from' => 'required|string|max:1024',
            'data.to' => 'required|array|min:1|max:60', 'data.to.*' => 'required|email:rfc|max:255',
            'data.tags' => 'sometimes|nullable|array', 'data.tags.maildesk_request_id' => 'sometimes|uuid',
        ])->validate();

        return response()->json(['received' => true, 'matched' => $tracker->record($id, $validated)]);
    }
}
