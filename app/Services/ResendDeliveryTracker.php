<?php

namespace App\Services;

use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Address;

class ResendDeliveryTracker
{
    public const TYPES = ['email.sent', 'email.delivered', 'email.delivery_delayed', 'email.bounced', 'email.failed', 'email.suppressed'];

    public function record(string $eventId, array $event): bool
    {
        $data = $event['data'];
        try {
            $from = strtolower(Address::create($data['from'])->getAddress());
        } catch (\Throwable) {
            return false;
        }
        $recipients = array_values(array_unique(array_map('strtolower', $data['to'])));
        return DB::transaction(function () use ($eventId, $event, $data, $from, $recipients) {
            if (DB::table('message_delivery_events')->where('event_id', $eventId)->exists()) {
                return true;
            }
            $query = Message::where('direction', 'outbound')->where('provider', 'resend');
            $message = (clone $query)->where('provider_message_id', $data['email_id'])->lockForUpdate()->first();
            if (! $message && ($reference = $data['tags']['maildesk_request_id'] ?? null)) {
                // Never correlate using a subject, timestamp, or recipient alone.
                $matches = $query->where('request_id', $reference)->whereRaw('LOWER(from_address) = ?', [$from])->lockForUpdate()->limit(2)->get();
                if ($matches->count() === 1) {
                    $message = $matches->first();
                }
            }
            if (! $message || strtolower($message->from_address) !== $from
                || ($message->provider_message_id && $message->provider_message_id !== $data['email_id'])) {
                return false;
            }
            $expected = array_values(array_unique(array_map('strtolower', array_merge($message->to, $message->cc ?? []))));
            if (! $recipients || array_diff($recipients, $expected)) {
                return false;
            }
            $message->update(['provider_message_id' => $data['email_id']]);
            DB::table('message_delivery_events')->insertOrIgnore([
                'message_id' => $message->id, 'event_id' => $eventId, 'type' => $event['type'],
                'recipients' => json_encode($recipients), 'occurred_at' => Carbon::parse($event['created_at'])->utc(), 'created_at' => now(),
            ]);
            $outcomes = array_fill_keys($expected, 'pending');
            $rank = ['pending' => 0, 'delayed' => 1, 'delivered' => 2, 'failed' => 3];
            foreach (DB::table('message_delivery_events')->where('message_id', $message->id)->orderBy('occurred_at')->orderBy('id')->get() as $item) {
                $status = match ($item->type) {
                    'email.delivered' => 'delivered',
                    'email.delivery_delayed' => 'delayed',
                    'email.bounced', 'email.failed', 'email.suppressed' => 'failed',
                    default => 'pending',
                };
                foreach (json_decode($item->recipients, true) as $recipient) {
                    if ($rank[$status] > $rank[$outcomes[$recipient]]) {
                        $outcomes[$recipient] = $status;
                    }
                }
            }
            $failed = array_keys($outcomes, 'failed', true);
            $delivered = count(array_keys($outcomes, 'delivered', true));
            $status = $failed ? (count($failed) === count($expected) ? 'failed' : 'partial')
                : ($delivered === count($expected) ? 'delivered' : ($delivered ? 'partial' : (in_array('delayed', $outcomes, true) ? 'delayed' : 'pending')));
            $changes = ['delivery_status' => $status];
            if ($message->status === 'uncertain') {
                // A signed provider event resolves an HTTP timeout without sending again.
                $changes += ['status' => 'sent', 'sent_at' => $message->sent_at ?? now(), 'send_error' => null,
                    'archive_error' => $message->archive_error ?? 'Envoi confirmé par Resend. Copie dans le dossier Envoyés du serveur non confirmée.'];
            }
            if ($failed) {
                $changes['delivery_error'] = mb_substr('Refus signalé par Resend pour : '.implode(', ', $failed), 0, 255);
            }
            $message->update($changes);

            return true;
        });
    }
}
