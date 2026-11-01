<?php

namespace App\Services;

use App\Models\Message;

class DeliveryReport
{
    /** Only structured RFC 3464 reports are considered; a subject or quoted text is not evidence. */
    public function parse(string $contentType, string $boundary, string $body): ?array
    {
        if (! str_starts_with(strtolower($contentType), 'multipart/report') || $boundary === '') {
            return null;
        }
        $originalId = null;
        $failures = [];
        foreach (explode('--'.$boundary, $body) as $part) {
            $sections = preg_split('/\r?\n\r?\n/', ltrim($part, "\r\n"), 2);
            if (count($sections) !== 2) {
                continue;
            }
            [$headers, $content] = $sections;
            if (preg_match('/^Content-Type:\s*(?:message\/rfc822|text\/rfc822-headers)\b/im', $headers)) {
                $originalHeaders = preg_split('/\r?\n\r?\n/', $content, 2)[0];
                if (preg_match('/^Message-ID:\s*<([^<>\s]+)>/im', $originalHeaders, $match)) {
                    $originalId = $match[1];
                }
            }
            if (! preg_match('/^Content-Type:\s*message\/delivery-status\b/im', $headers)) {
                continue;
            }
            foreach (preg_split('/\r?\n\r?\n/', $content) as $recipient) {
                if (preg_match('/^Action:\s*failed\s*$/im', $recipient)
                    && preg_match('/^Final-Recipient:\s*rfc822;\s*([^\s<>]+@[^\s<>]+)\s*$/im', $recipient, $address)
                    && preg_match('/^Status:\s*(5\.\d{1,3}\.\d{1,3})\s*$/m', $recipient, $status)) {
                    $failures[] = ['recipient' => strtolower($address[1]), 'status' => $status[1]];
                }
            }
        }

        return $originalId && $failures ? ['message_id' => $originalId, 'failures' => $failures] : null;
    }

    public function record(Message $report, ?array $data): void
    {
        if (! $data) {
            return;
        }
        $original = Message::where('mailbox_id', $report->mailbox_id)->where('direction', 'outbound')
            ->whereIn('status', ['sent', 'uncertain'])->whereIn('internet_message_id', [$data['message_id'], '<'.$data['message_id'].'>'])->first();
        if (! $original) {
            return;
        }
        $recipients = array_map('strtolower', array_merge($original->to, $original->cc ?? [], $original->bcc ?? []));
        $failed = array_filter($data['failures'], fn ($failure) => in_array($failure['recipient'], $recipients, true));
        if ($failed) {
            $visible = array_map('strtolower', array_merge($original->to, $original->cc ?? []));
            $summary = implode(', ', array_map(fn ($failure) => (in_array($failure['recipient'], $visible, true) ? $failure['recipient'] : 'destinataire en copie cachée').' ('.$failure['status'].')', $failed));
            $original->update(['delivery_error' => mb_substr('Non-remise signalée : '.$summary, 0, 255), 'delivery_report_id' => $report->id]);
        }
    }
}
