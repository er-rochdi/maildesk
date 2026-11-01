<?php

namespace Tests\Feature;

use App\Services\SafeEmailHtml;
use Tests\TestCase;

class SafeEmailHtmlTest extends TestCase
{
    public function test_removes_active_content_links_and_remote_resources(): void
    {
        $html = '<meta http-equiv="refresh" content="0;url=https://attacker.test/"><script>alert(1)</script><iframe src="https://attacker.test/"></iframe><a href="https://attacker.test/" onclick="alert(1)">Bonjour</a><img src="https://attacker.test/pixel" onerror="alert(1)"><svg><script>alert(1)</script></svg><img src="data:image/png;base64,YQ==">';
        $clean = (new SafeEmailHtml)->clean($html);
        $this->assertStringContainsString('Bonjour', $clean);
        $this->assertStringContainsString('data:image/png;base64,YQ==', $clean);
        foreach (['attacker.test', '<script', '<iframe', '<meta', '<svg', 'onclick', 'onerror', 'href='] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, strtolower($clean));
        }
    }
}
