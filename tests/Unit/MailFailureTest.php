<?php

namespace Tests\Unit;

use App\Services\MailFailure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MailFailureTest extends TestCase
{
    public function test_diagnostics_distinguish_authentication_tls_network_and_generic_errors_without_exposing_secrets(): void
    {
        foreach ([
            'Authentication failed: secret-token' => 'Connexion IMAP refusée',
            'SSL certificate verify failed: secret-token' => 'Certificat IMAP invalide',
            'Connection timed out: secret-token' => 'Serveur IMAP inaccessible',
            'Unexpected parser exception: secret-token' => 'Synchronisation impossible',
        ] as $detail => $expected) {
            $message = MailFailure::sync(new RuntimeException($detail));
            $this->assertStringContainsString($expected, $message);
            $this->assertStringNotContainsString('secret-token', $message);
        }
    }
}
