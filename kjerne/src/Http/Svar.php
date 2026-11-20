<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Http;

/**
 * Et HTTP-svar. Kroppen er en tekst eller en fil som strømmes (sikkerhetskopier og eksport), aldri lest inn i minnet.
 */
final class Svar
{
    /** @var array<string, string> */
    private array $hoder = [];

    /** @var list<string> */
    private array $kapsler = [];

    private ?string $fil = null;
    private bool $slettFil = false;

    /** @param array<string, string> $hoder */
    public function __construct(public int $status = 200, public string $kropp = '', array $hoder = [])
    {
        foreach ($hoder as $k => $v) {
            $this->settHode($k, $v);
        }
    }

    /** @param array<string, mixed>|list<mixed> $data */
    public static function json(array $data, int $status = 200): self
    {
        return new self($status, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Et feilsvar etter RFC 9457 (Problem Details for HTTP APIs), application/problem+json: «type» er en URN for
     * feilen (urn:bivrost:feil:<kode>), «title» og «detail» kommer fra språkfilene, «instance» er korrelasjons-ID-en
     * som URN. «kode» og «korrelasjon» er utvidelser (de samme verdiene, lettere å lese for maskiner).
     */
    public static function problem(int $status, string $kode, string $tittel, string $detalj, string $korrelasjon, string $sprak = 'nb'): self
    {
        $s = self::json([
            'type' => 'urn:bivrost:feil:' . $kode,
            'title' => $tittel,
            'status' => $status,
            'detail' => $detalj,
            'instance' => 'urn:uuid:' . $korrelasjon,
            'kode' => $kode,
            'korrelasjon' => $korrelasjon,
        ], $status);
        $s->settHode('Content-Type', 'application/problem+json; charset=utf-8');
        $s->settHode('Content-Language', $sprak);

        return $s;
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, $html, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store']);
    }

    public static function videre(string $adresse, int $status = 303): self
    {
        return new self($status, '', ['Location' => $adresse, 'Cache-Control' => 'no-store']);
    }

    /** Sender en fil som vedlegg. $slett: filen slettes etter sendingen (midlertidig eksport). */
    public static function fil(string $sti, string $filnavn, string $type, bool $slett = false): self
    {
        $s = new self(200, '', [
            'Content-Type' => $type,
            'Content-Disposition' => 'attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filnavn) . '"',
            'Content-Length' => (string) filesize($sti),
            'Cache-Control' => 'no-store',
        ]);
        $s->fil = $sti;
        $s->slettFil = $slett;

        return $s;
    }

    public function settHode(string $navn, string $verdi): self
    {
        if (preg_match('/[\r\n]/', $navn . $verdi) === 1) {
            throw new \InvalidArgumentException('Linjeskift i et svarhode.');
        }
        $this->hoder[$navn] = $verdi;

        return $this;
    }

    public function hode(string $navn): ?string
    {
        foreach ($this->hoder as $k => $v) {
            if (strcasecmp($k, $navn) === 0) {
                return $v;
            }
        }

        return null;
    }

    /** @return array<string, string> */
    public function hoder(): array
    {
        return $this->hoder;
    }

    /**
     * Legger til en informasjonskapsel (Set-Cookie), alltid HttpOnly og SameSite, og Secure over HTTPS.
     */
    public function settKapsel(string $navn, string $verdi, int $maksAlder, bool $https, string $samesite = 'Lax'): self
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $navn) !== 1 || preg_match('/^[A-Za-z0-9._~-]*$/D', $verdi) !== 1) {
            throw new \InvalidArgumentException('Ugyldig informasjonskapsel.');
        }
        $k = "$navn=$verdi; Path=/; HttpOnly; SameSite=$samesite";
        if ($maksAlder >= 0) {
            $k .= '; Max-Age=' . $maksAlder;
        }
        if ($https) {
            $k .= '; Secure';
        }
        $this->kapsler[] = $k;

        return $this;
    }

    /** @return list<string> */
    public function kapsler(): array
    {
        return $this->kapsler;
    }

    public function filsti(): ?string
    {
        return $this->fil;
    }

    /** Sender svaret til nettleseren (bare i webserveren). */
    public function send(): void
    {
        if (!headers_sent()) {
            header_remove('X-Powered-By');
            http_response_code($this->status);
            foreach ($this->hoder as $k => $v) {
                header("$k: $v");
            }
            foreach ($this->kapsler as $k) {
                header('Set-Cookie: ' . $k, false);
            }
        }
        if ($this->fil !== null) {
            try {
                $f = fopen($this->fil, 'rb');
                if ($f !== false) {
                    while (!feof($f)) {
                        $bit = fread($f, 65536);
                        if ($bit === false) {
                            break;
                        }
                        echo $bit;
                        flush();
                    }
                    fclose($f);
                }
            } finally {
                if ($this->slettFil) {
                    @unlink($this->fil);
                }
            }

            return;
        }
        echo $this->kropp;
    }
}
