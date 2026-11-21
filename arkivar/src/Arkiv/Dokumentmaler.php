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
 * Et utgående dokument kan skrives som svar på et inngående. Da avskrives det inngående når svaret er ekspedert,
 * unntatt når malen har «avskriver» usann (foreløpig svar: restansen står til det endelige svaret er sendt).
 * «svar.tittel» i filen er forslaget til tittel på svaret, med «{{tittel}}» for tittelen på det som besvares.
 *
 * @phpstan-type Dokumentmal array{kode: string, navn: string, beskrivelse: string, typer: list<string>, kanal: string, avsender: string, emne: string, tekst: list<string>, avskriver: bool}
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
                'avskriver' => ($m['avskriver'] ?? true) !== false,
            ];
        }

        return $this->maler = $ut;
    }

    /**
     * Forslaget til tittel på et svar på dokumentet med denne tittelen: «Svar på klage over …». Den første bokstaven
     * i tittelen som besvares, blir liten, unntatt når det første ordet er en forkortelse eller har tall.
     */
    public function svartittel(string $tittel): string
    {
        $data = Grunndata::les($this->fil ?? dirname(__DIR__, 2) . '/data/dokumentmaler.json');
        $svar = is_array($data['svar'] ?? null) ? $data['svar'] : [];
        $monster = Felt::tekst($svar['tittel'] ?? null, '{{tittel}}');
        $forste = explode(' ', $tittel, 2)[0];
        if (preg_match('/\d/u', $forste) !== 1 && $forste !== mb_strtoupper($forste, 'UTF-8')) {
            $tittel = mb_strtolower(mb_substr($tittel, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($tittel, 1, null, 'UTF-8');
        }

        return mb_substr(str_replace('{{tittel}}', $tittel, $monster), 0, 500, 'UTF-8');
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
