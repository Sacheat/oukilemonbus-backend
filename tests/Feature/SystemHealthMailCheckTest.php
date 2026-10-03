<?php

namespace Tests\Feature;

use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Contrôle « mail » de SystemHealthService : connexion SMTP du mailer par défaut, sans envoi.
 */
class SystemHealthMailCheckTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake(); // ORS et GeoNames : pas d'appel réseau
    }

    public function test_no_smtp_check_when_the_mailer_does_not_send(): void
    {
        config(['mail.default' => 'array']);

        $mail = app(SystemHealthService::class)->check()['mail'];

        $this->assertSame('ok', $mail['status']);
        $this->assertSame('mailer=array : aucun envoi SMTP', $mail['details']);
    }

    public function test_unreachable_smtp_server_is_a_warning(): void
    {
        $port = $this->closedPort();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $port]);

        $mail = app(SystemHealthService::class)->check()['mail'];

        $this->assertSame('warn', $mail['status']);
        $this->assertStringStartsWith("SMTP 127.0.0.1:{$port} : ", $mail['details']);
    }

    public function test_refused_authentication_is_a_warning_without_the_username(): void
    {
        $server = $this->fakeSmtpServer(rejectAuth: true);
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => $server['port'],
            'mail.mailers.smtp.username' => 'compte-sandbox',
            'mail.mailers.smtp.password' => 'secret',
        ]);

        $mail = app(SystemHealthService::class)->check()['mail'];

        proc_terminate($server['process']);
        $this->assertSame('warn', $mail['status']);
        $this->assertStringContainsString('[masqué]', $mail['details']);
        $this->assertStringNotContainsString('compte-sandbox', $mail['details']);
    }

    public function test_reachable_smtp_server_is_ok(): void
    {
        $server = $this->fakeSmtpServer();
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $server['port']]);

        $mail = app(SystemHealthService::class)->check()['mail'];

        proc_terminate($server['process']);
        $this->assertSame('ok', $mail['status'], $mail['details']);
        $this->assertSame("SMTP 127.0.0.1:{$server['port']} joignable (EHLO)", $mail['details']);
    }

    private function closedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        fclose($socket);

        return $port;
    }

    /**
     * Serveur SMTP minimal dans un processus séparé : bannière 220, réponses à EHLO et QUIT.
     * Avec $rejectAuth, il annonce AUTH et refuse toute authentification (535, comme un quota Mailtrap épuisé).
     *
     * @return array{process: resource, port: int}
     */
    private function fakeSmtpServer(bool $rejectAuth = false): array
    {
        $port = $this->closedPort();
        $script = <<<'PHP'
            $server = stream_socket_server('tcp://127.0.0.1:'.$argv[1]);
            fwrite(STDOUT, "pret\n");
            $client = stream_socket_accept($server, 10);
            fwrite($client, "220 test ESMTP\r\n");
            while (($line = fgets($client)) !== false) {
                if (stripos($line, 'EHLO') === 0) { fwrite($client, $argv[2] === '1' ? "250-test\r\n250 AUTH PLAIN LOGIN\r\n" : "250-test\r\n250 OK\r\n"); }
                elseif (stripos($line, 'AUTH') === 0) { fwrite($client, "535 5.7.0 Quota atteint\r\n"); }
                elseif (stripos($line, 'QUIT') === 0) { fwrite($client, "221 Bye\r\n"); break; }
                else { fwrite($client, "250 OK\r\n"); }
            }
            PHP;

        $process = proc_open([PHP_BINARY, '-r', $script, (string) $port, $rejectAuth ? '1' : '0'], [1 => ['pipe', 'w']], $pipes);
        fgets($pipes[1]); // attend que le serveur écoute

        return ['process' => $process, 'port' => $port];
    }
}
