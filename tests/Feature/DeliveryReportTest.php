<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Mailbox;
use App\Models\Message;
use App\Services\DeliveryReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_structured_bounce_matches_only_the_original_mailbox_and_recipient(): void
    {
        $parser = new DeliveryReport;
        $body = "--report\r\nContent-Type: text/plain\r\n\r\nDelivery failed.\r\n--report\r\nContent-Type: message/delivery-status\r\n\r\nReporting-MTA: dns; mail.example.test\r\n\r\nFinal-Recipient: rfc822; client@example.test\r\nAction: failed\r\nStatus: 5.7.26\r\n\r\n--report\r\nContent-Type: message/rfc822\r\n\r\nMessage-ID: <original@example.test>\r\nSubject: Test\r\n\r\nOriginal body\r\n--report--\r\n";
        $data = $parser->parse('multipart/report; report-type=delivery-status', 'report', $body);
        $mime = \Webklex\PHPIMAP\Message::fromString("From: Mailer-Daemon <mailer-daemon@example.test>\r\nTo: contact@example.test\r\nSubject: Delivery failure\r\nMIME-Version: 1.0\r\nContent-Type: multipart/report; report-type=delivery-status; boundary=report\r\n\r\n".$body);
        $this->assertSame($data, $parser->parse((string) $mime->getHeader()->get('content_type')->first(), $mime->getHeader()->getBoundary(), $mime->getRawBody()));
        $this->assertSame('original@example.test', $data['message_id']);
        $this->assertSame('5.7.26', $data['failures'][0]['status']);
        $this->assertNull($parser->parse('text/plain', 'report', $body));
        $this->assertNull($parser->parse('multipart/report', 'report', str_replace('Action: failed', 'Action: delayed', $body)));
        $company = Company::create(['name' => 'A']);
        $box = Mailbox::create(['company_id' => $company->id, 'name' => 'Contact', 'email' => 'contact@example.test', 'imap_host' => 'mail.example.test', 'smtp_host' => 'mail.example.test', 'password' => 'secret']);
        $original = Message::create(['mailbox_id' => $box->id, 'direction' => 'outbound', 'status' => 'sent', 'from_address' => $box->email, 'to' => ['client@example.test'], 'subject' => 'Test', 'body' => 'Test', 'message_at' => now(), 'internet_message_id' => 'original@example.test']);
        $report = Message::create(['mailbox_id' => $box->id, 'direction' => 'inbound', 'status' => 'received', 'from_address' => 'mailer-daemon@example.test', 'to' => [$box->email], 'subject' => 'Failed', 'body' => 'Report', 'message_at' => now()]);
        $wrong = $data;
        $wrong['failures'][0]['recipient'] = 'stranger@example.test';
        $parser->record($report, $wrong);
        $this->assertNull($original->fresh()->delivery_error);
        $report->mailbox_id = $box->id + 100;
        $parser->record($report, $data);
        $this->assertNull($original->fresh()->delivery_error);
        $report->mailbox_id = $box->id;
        $parser->record($report, $data);
        $parser->record($report, $data);
        $this->assertSame('sent', $original->fresh()->status);
        $this->assertSame($report->id, $original->fresh()->delivery_report_id);
        $this->assertStringContainsString('client@example.test (5.7.26)', $original->fresh()->delivery_error);
    }
}
