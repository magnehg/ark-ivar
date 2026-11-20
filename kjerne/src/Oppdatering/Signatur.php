<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

/**
 * Signaturen på en versjonspakke (BIV-DRF-07): Ed25519 (libsodium, ingen egen kryptografi) over
 * «bivrost-versjonspakke\n<pakke>\n<tag>\n<sha256>\n». Pakkenavnet er med, så en signatur for appen ikke kan brukes
 * på Kjerne. Den offentlige nøkkelen følger med pakken (<mappe>signeringsnokkel.offentlig) og klareres av
 * installasjonen første gang (Utgivernokler, BIV-DRF-14); i .env (BIVROST_OFFENTLIG_NOKKEL for Kjerne,
 * <APP>_OFFENTLIG_NOKKEL for appen) går den foran. Den hemmelige finnes bare som hemmelighet i GitHub Actions.
 */
final class Signatur
{
    public static function melding(string $pakke, string $tag, string $sha256): string
    {
        return "bivrost-versjonspakke\n$pakke\n$tag\n$sha256\n";
    }

    public static function stemmer(string $offentligB64, string $pakke, string $tag, string $sha256, string $signaturB64): bool
    {
        $pk = base64_decode(trim($offentligB64), true);
        $sig = base64_decode(trim($signaturB64), true);
        if ($pk === false || $sig === false || strlen($pk) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES || strlen($sig) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return false;
        }

        return sodium_crypto_sign_verify_detached($sig, self::melding($pakke, $tag, $sha256), $pk);
    }

    public static function gyldigNokkel(string $offentligB64): bool
    {
        $pk = base64_decode(trim($offentligB64), true);

        return $pk !== false && strlen($pk) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES;
    }

    /** Lager signaturen (til release-skriptet og testene). */
    public static function signer(string $hemmeligB64, string $pakke, string $tag, string $sha256): string
    {
        $sk = base64_decode(trim($hemmeligB64), true);
        if ($sk === false || strlen($sk) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \InvalidArgumentException('Ugyldig signeringsnøkkel.');
        }

        return base64_encode(sodium_crypto_sign_detached(self::melding($pakke, $tag, $sha256), $sk));
    }

    /** @return array{offentlig: string, hemmelig: string} base64 */
    public static function nyttNokkelpar(): array
    {
        $par = sodium_crypto_sign_keypair();

        return [
            'offentlig' => base64_encode(sodium_crypto_sign_publickey($par)),
            'hemmelig' => base64_encode(sodium_crypto_sign_secretkey($par)),
        ];
    }
}
