<?php

declare(strict_types=1);

namespace ArkIvar\Epost;

/**
 * Sender med PHPs mail(), som er det et vanlig webhotell tilbyr. Brevene er ren tekst i UTF-8.
 *
 * Mottakeren, emnet og avsenderen settes inn i hodene, så linjeskift i dem ville gitt en angriper egne hoder
 * (header injection). Adressene er kontrollert før de kommer hit, og her avvises alt med linjeskift uansett.
 */
final class MailEpost implements Epostsender
{
    public function __construct(private readonly string $fra, private readonly string $franavn)
    {
        if (filter_var($fra, FILTER_VALIDATE_EMAIL) === false) {
            throw new EpostFeil('ugyldig_avsender');
        }
    }

    public function send(string $til, string $emne, string $tekst): void
    {
        foreach ([$til, $emne, $this->fra, $this->franavn] as $verdi) {
            if (preg_match('/[\r\n]/', $verdi) === 1) {
                throw new EpostFeil('linjeskift_i_hode');
            }
        }
        if (filter_var($til, FILTER_VALIDATE_EMAIL) === false) {
            throw new EpostFeil('ugyldig_mottaker');
        }
        $hoder = [
            'From' => mb_encode_mimeheader($this->franavn, 'UTF-8', 'B', "\r\n") . ' <' . $this->fra . '>',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'base64',
            'Auto-Submitted' => 'auto-generated',
        ];
        $kropp = chunk_split(base64_encode(str_replace(["\r\n", "\r"], "\n", $tekst)), 76, "\r\n");
        if (!mail($til, mb_encode_mimeheader($emne, 'UTF-8', 'B', "\r\n"), $kropp, $hoder, '-f' . $this->fra)) {
            throw new EpostFeil('mail_avvist');
        }
    }
}
