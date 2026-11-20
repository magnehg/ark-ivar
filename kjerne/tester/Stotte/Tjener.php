<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

/** Starter PHPs innebygde webserver for en test og stopper den igjen. */
final class Tjener
{
    /** @var resource */
    private $prosess;
    public readonly int $port;

    /** @param array<string, string> $miljo */
    public function __construct(string $ruter, array $miljo, ?string $dokumentrot = null)
    {
        $s = stream_socket_server('tcp://127.0.0.1:0');
        if ($s === false) {
            throw new \RuntimeException('Fant ingen ledig port.');
        }
        $navn = (string) stream_socket_get_name($s, false);
        fclose($s);
        $this->port = (int) substr($navn, (int) strrpos($navn, ':') + 1);
        $kommando = [PHP_BINARY, '-S', '127.0.0.1:' . $this->port];
        if ($dokumentrot !== null) {
            $kommando[] = '-t';
            $kommando[] = $dokumentrot;
        }
        $kommando[] = $ruter;
        $miljo += ['PATH' => (string) getenv('PATH')];
        $p = proc_open($kommando, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, null, $miljo);
        if (!is_resource($p)) {
            throw new \RuntimeException('Fikk ikke startet webserveren.');
        }
        $this->prosess = $p;
        for ($i = 0; $i < 100; $i++) {
            $c = @fsockopen('127.0.0.1', $this->port, $en, $to, 0.1);
            if ($c !== false) {
                fclose($c);

                return;
            }
            usleep(50000);
        }
        throw new \RuntimeException('Webserveren svarte ikke.');
    }

    public function stopp(): void
    {
        proc_terminate($this->prosess);
        proc_close($this->prosess);
    }
}
