<?php

declare(strict_types=1);

/*
 * Kjerne ved siden av en app med eldre versjoner av de samme bibliotekene (BIV-B-024): kollisjonsapp/ har php-jwt 6,
 * Guzzle 7 og en eldre polyfill, Kjerne har php-jwt 7 og Guzzle 8 med prefikset Bivrost\Vendor\. Appen lastes først,
 * som i en app som tar med Kjerne etter sin egen vendor/autoload.php.
 *
 * Bruk: php skript/scoper/sjekk-kollisjon.php <kollisjonsapp> <kjerne-mappe>
 */
// En app med eldre versjoner av de samme bibliotekene lastes FØR Kjerne.
// Mappene som virkelige stier (CI sender f.eks. …/kjerne/../skript/…), så de kan sammenlignes med filstiene.
$app = realpath($argv[1] ?? '');
$kjerne = realpath($argv[2] ?? '');
if ($app === false || $kjerne === false) {
    fwrite(STDERR, "Bruk: php skript/scoper/sjekk-kollisjon.php <kollisjonsapp> <kjerne-mappe>\n");
    exit(2);
}
require $app . '/vendor/autoload.php';
$appJwt = (new ReflectionClass(Firebase\JWT\JWT::class))->getFileName();
$appGuzzle = GuzzleHttp\ClientInterface::MAJOR_VERSION;
$installertFor = [Composer\InstalledVersions::getVersion('guzzlehttp/guzzle'), Composer\InstalledVersions::getRootPackage()['name'], Composer\InstalledVersions::isInstalled('composer/semver')];
trigger_deprecation('test/app', '1.0', 'appens funksjon virker');
require $kjerne . '/autoload.php';
$feil = 0;
$sjekk = function (bool $ok, string $tekst) use (&$feil): void { echo ($ok ? 'OK   ' : 'FEIL ') . $tekst . "\n"; $feil += $ok ? 0 : 1; };
$sjekk(str_starts_with((string) $appJwt, $app . '/'), "appens Firebase\\JWT er appens egen ($appJwt)");
$sjekk($appGuzzle === 7, "appens Guzzle er versjon $appGuzzle");
$installertEtter = [Composer\InstalledVersions::getVersion('guzzlehttp/guzzle'), Composer\InstalledVersions::getRootPackage()['name'], Composer\InstalledVersions::isInstalled('composer/semver')];
$sjekk($installertFor === $installertEtter, 'appens InstalledVersions svarer likt etter at Kjerne er lastet (' . json_encode($installertEtter) . ')');
// otphp uten klokke skal kalle Kjernens trigger_deprecation, ikke appens globale.
$kalt = [];
set_error_handler(static function () use (&$kalt): bool {
    foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $ramme) {
        if (str_ends_with($ramme['function'], 'trigger_deprecation')) {
            $kalt[] = $ramme['function'];
        }
    }

    return true;
}, E_USER_DEPRECATED);
new Bivrost\Vendor\OTPHP\TOTP('JBSWY3DPEHPK3PXP');
restore_error_handler();
$sjekk($kalt === ['Bivrost\\Vendor\\trigger_deprecation'], 'otphp uten klokke kaller Kjernens trigger_deprecation, ikke appens (' . implode(', ', $kalt) . ')');
$sjekk(Bivrost\Vendor\GuzzleHttp\ClientInterface::MAJOR_VERSION === 8, 'Kjernens Guzzle er versjon ' . Bivrost\Vendor\GuzzleHttp\ClientInterface::MAJOR_VERSION);
$sjekk(function_exists('Bivrost\Vendor\trigger_deprecation'), 'Kjernens trigger_deprecation er lastet selv om appen har sin');
// TOTP, semver, normalisering og JWT gjennom Kjernens egen kode.
$klokke = new Bivrost\Kjerne\Klokke();
$totp = new Bivrost\Kjerne\Innlogging\Totp($klokke);
$h = $totp->nyHemmelighet();
$sjekk($totp->kontroller($h, $totp->kode($h), null) !== null, 'TOTP (otphp) gjennom Kjerne');
$sjekk(Bivrost\Kjerne\Innlogging\LokalBrukere::normaliser('ｄｒｉｆｔ') === 'drift', 'Normalizer (polyfill eller intl)');
$sjekk(Bivrost\Vendor\Composer\Semver\Semver::satisfies('0.1.3', '^0.1'), 'composer/semver');
$k = sodium_crypto_sign_keypair();
$jwt = Bivrost\Vendor\Firebase\JWT\JWT::encode(['a' => 1], base64_encode(sodium_crypto_sign_secretkey($k)), 'EdDSA');
$d = Bivrost\Vendor\Firebase\JWT\JWT::decode($jwt, new Bivrost\Vendor\Firebase\JWT\Key(base64_encode(sodium_crypto_sign_publickey($k)), 'EdDSA'));
$sjekk(($d->a ?? null) === 1, 'php-jwt 7 (Kjernens) ved siden av php-jwt 6 (appens)');
$p = new Bivrost\Vendor\League\OAuth2\Client\Provider\GenericProvider(['urlAuthorize' => 'https://x/a', 'urlAccessToken' => 'https://x/t', 'urlResourceOwnerDetails' => 'https://x/u']);
$sjekk((new Bivrost\Vendor\League\OAuth2\Client\Grant\GrantFactory())->getGrant('authorization_code') instanceof Bivrost\Vendor\League\OAuth2\Client\Grant\AuthorizationCode, 'league/oauth2-client finner sin grant (patchen)');
foreach (get_declared_classes() as $c) {
    $f = (new ReflectionClass($c))->getFileName();
    if (is_string($f) && str_starts_with($f, $kjerne . '/vendor') && !str_starts_with($c, 'Bivrost\\Vendor\\') && !preg_match('/^(Composer\\\\|ComposerAutoloaderInit|Symfony\\\\Polyfill\\\\|Normalizer$|Psr\\\\Clock\\\\)/', $c)) {
        $sjekk(false, "uprefikset klasse fra Kjernens vendor: $c");
    }
}
echo $feil === 0 ? "Ingen kollisjoner.\n" : "$feil feil.\n";
exit($feil === 0 ? 0 : 1);
