<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Krypto;

use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Kryptering av hemmeligheter som må kunne leses igjen (f.eks. TOTP-hemmeligheten), med libsodium
 * (XSalsa20-Poly1305, sodium_crypto_secretbox). Nøkkelen ligger i .env utenfor webroten (BIVROST_KRYPTERINGSNOKKEL,
 * 32 byte som base64), aldri i databasen. Formatet er «v1:<base64(nonce || chiffertekst)>», så nøkkelen kan byttes
 * senere med en ny versjon. Tilleggsdata ($formaal) bindes inn med en avledet nøkkel per formål, så en verdi ikke kan
 * flyttes til et annet felt.
 */
final class Hemmelighetsboks
{
    private const PREFIKS = 'v1:';

    private function __construct(private readonly string $nokkel)
    {
    }

    public static function fraBase64(string $nokkelBase64): self
    {
        $n = base64_decode(trim($nokkelBase64), true);
        if ($n === false || strlen($n) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new BivrostFeil('bivrost.feil.krypto.nokkel');
        }

        return new self($n);
    }

    /** En ny tilfeldig nøkkel som base64, til .env. */
    public static function nyNokkel(): string
    {
        return base64_encode(sodium_crypto_secretbox_keygen());
    }

    private function nokkelFor(string $formaal): string
    {
        return sodium_crypto_generichash('bivrost-hemmelighetsboks:' . $formaal, $this->nokkel, SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public function krypter(string $klartekst, string $formaal): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return self::PREFIKS . base64_encode($nonce . sodium_crypto_secretbox($klartekst, $nonce, $this->nokkelFor($formaal)));
    }

    public function dekrypter(string $kryptert, string $formaal): string
    {
        if (!str_starts_with($kryptert, self::PREFIKS)) {
            throw new BivrostFeil('bivrost.feil.krypto.format');
        }
        $data = base64_decode(substr($kryptert, strlen(self::PREFIKS)), true);
        if ($data === false || strlen($data) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new BivrostFeil('bivrost.feil.krypto.format');
        }
        $nonce = substr($data, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $klar = sodium_crypto_secretbox_open(substr($data, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $nonce, $this->nokkelFor($formaal));
        if ($klar === false) {
            throw new BivrostFeil('bivrost.feil.krypto.apne');
        }

        return $klar;
    }
}
