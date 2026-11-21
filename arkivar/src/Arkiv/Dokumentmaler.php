<?php

declare(strict_types=1);

namespace ArkIvar\Arkiv;

use Bivrost\Kjerne\Database\Felt;

/**
 * Dokumentmalene studenten kan skrive et dokument fra (arkivar/data/dokumentmaler.json): utgående brev, foreløpig
 * svar, internt notat, møtereferat og et blankt ark for et inngående brev. Malen bestemmer hvilke
 * journalposttyper dokumentet kan ha (den første er forslaget), hva slags dokument det er (kanalen), og om
 * avsenderen er kommunen eller skrives av studenten. Emnet og teksten er forslag som studenten skriver om.
 * I teksten står «{{sak}}» for saksnummeret og «{{saksbehandler}}» og «{{stilling}}» for den som har saken;
 * grensesnittet setter dem inn når malen velges. Innholdet er norsk og oversettes ikke.
 *
 * @phpstan-type Dokumentmal array{kode: string, navn: string, beskrivelse: string, typer: list<string>, kanal: string, avsender: string, emne: string, tekst: list<string>}
 */
final class Dokumentmaler
{
    /** Avsenderen er kommunen (brevhodet fylles ut), eller arket er blankt og studenten skriver avsenderen. */
    public const KOMMUNEN = 'kommunen';
    public const BLANK = 'blank';

    /** @var list<Dokumentmal>|null */
    private ?array $maler = null;

    public function __construct(private readonly ?string $fil = null)
    {
    }

    /** @return list<Dokumentmal> */
    public function alle(): array
    {
        if ($this->maler !== null) {
            return $this->maler;
        }
        $ut = [];
        foreach (Grunndata::rader(Grunndata::les($this->fil ?? dirname(__DIR__, 2) . '/data/dokumentmaler.json'), 'maler') as $m) {
            $ut[] = [
                'kode' => Felt::tekst($m['kode'] ?? null),
                'navn' => Felt::tekst($m['navn'] ?? null),
                'beskrivelse' => Felt::tekst($m['beskrivelse'] ?? null),
                'typer' => Grunndata::tekster($m['typer'] ?? null),
                'kanal' => Felt::tekst($m['kanal'] ?? null, 'brev'),
                'avsender' => Felt::tekst($m['avsender'] ?? null, self::KOMMUNEN),
                'emne' => Felt::tekst($m['emne'] ?? null),
                'tekst' => Grunndata::tekster($m['tekst'] ?? null),
            ];
        }

        return $this->maler = $ut;
    }

    /** @return Dokumentmal|null */
    public function hent(string $kode): ?array
    {
        foreach ($this->alle() as $mal) {
            if ($mal['kode'] === $kode) {
                return $mal;
            }
        }

        return null;
    }
}
