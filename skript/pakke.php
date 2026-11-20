<?php

declare(strict_types=1);

/**
 * Pakker en Bivrost Kjerne-versjon (kalles av lag-versjonspakke.sh): legger designpakken i ressurser/grensesnitt og
 * LICENSE, CHANGELOG.md og SECURITY.md fra rota i bivrost/kjerne/, fjerner det som ikke skal til serverne (tester, verktøyoppsett, .git), stopper ved hemmeligheter, skriver
 * versjon.json, sbom.cdx.json og manifest.json, og lager en reproduserbar zip (sorterte filer, fast tid fra commiten).
 *
 * php skript/pakke.php <kilde> <utmappe> <versjon> <tag> <commit> <dato> <epoke>
 */

[, $kilde, $ut, $versjon, $tag, $commit, $dato, $epoke] = $argv + array_fill(0, 8, '');
$k = "$kilde/kjerne";
$stopp = static function (string $m): never {
    fwrite(STDERR, "Stopp: $m\n");
    exit(1);
};
if (!is_dir("$k/vendor")) {
    $stopp('vendor/ mangler.');
}

// Designpakken følger med Kjerne og leveres fra appens egen server (BIV-GRS-02).
foreach (['stil', 'fonter', 'ikoner', 'tokens'] as $m) {
    $fra = "$kilde/grensesnitt/$m";
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fra, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $f) {
        $mal = "$k/ressurser/grensesnitt/$m/" . substr($f->getPathname(), strlen($fra) + 1);
        @mkdir(dirname($mal), 0755, true);
        copy($f->getPathname(), $mal);
    }
}

// Lisensen, endringsloggen og veien for å melde sårbarheter følger med pakken (BIV-B-035 klasse a). De ligger i rota av
// repoet og kopieres inn; finnes en fil med samme navn i kjerne/, stopper pakkingen i stedet for å velge en av dem.
foreach (['LICENSE', 'CHANGELOG.md', 'SECURITY.md'] as $rotfil) {
    if (!is_file("$kilde/$rotfil")) {
        $stopp("$rotfil mangler i rota.");
    }
    if (file_exists("$k/$rotfil")) {
        $stopp("kjerne/$rotfil finnes; $rotfil skal bare ligge i rota.");
    }
    copy("$kilde/$rotfil", "$k/$rotfil");
}

// Bare det som skal til serverne.
$utelat = ['tester/', 'phpunit.xml', 'phpstan.neon', 'phpcs.xml', '.phpstan-cache/', '.phpunit.cache/', '.env.eksempel'];
$filer = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($k, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if ($f->isLink()) {
        $stopp('symbolsk lenke: ' . $f->getPathname());
    }
    if (!$f->isFile()) {
        continue;
    }
    $rel = substr($f->getPathname(), strlen($k) + 1);
    // Dokumentasjon i vendor/ (README, CHANGELOG …) trengs ikke på serverne; lisensfilene beholdes.
    $hopp = str_contains("/$rel", '/.git/') || (str_starts_with($rel, 'vendor/') && preg_match('/\.(md|markdown|rst)$/i', $rel) === 1);
    foreach ($utelat as $u) {
        $hopp = $hopp || $rel === $u || str_starts_with($rel, $u);
    }
    if (!$hopp) {
        $filer[$rel] = $f->getPathname();
    }
}
ksort($filer, SORT_STRING);

// Ingen hemmeligheter i pakken (BIV-SIK-03): filnavn og innhold.
foreach ($filer as $rel => $sti) {
    $navn = strtolower(basename($rel));
    if ($navn === '.env' || str_starts_with($navn, '.env.') || preg_match('/\.(pem|key|p12|pfx)$/', $navn) === 1 || in_array($navn, ['id_rsa', 'id_ed25519'], true)) {
        $stopp("filen $rel ser ut som en hemmelighet.");
    }
    $innhold = (string) file_get_contents($sti);
    // Private nøkler og tokener i Bivrosts egne filer (tredjepartskode i vendor/ har eksempelnøkler i dokumentasjonen).
    if (!str_starts_with($rel, 'vendor/') && preg_match('/-----BEGIN (RSA |EC |OPENSSH |ENCRYPTED )?PRIVATE KEY-----|\bgh[pousr]_[A-Za-z0-9]{36}\b|\bsk-ant-[A-Za-z0-9_-]{20,}/', $innhold) === 1) {
        $stopp("$rel inneholder noe som ser ut som en hemmelighet.");
    }
    $hemmelig = getenv('BIVROST_SIGNERINGSNOKKEL');
    if (is_string($hemmelig) && $hemmelig !== '' && str_contains($innhold, $hemmelig)) {
        $stopp("$rel inneholder signeringsnøkkelen.");
    }
}

// SBOM (CycloneDX 1.6, BIV-STD-09): avhengighetene i vendor/ fra composer.lock, og byggverktøyene for designpakken.
$lock = json_decode((string) file_get_contents("$k/composer.lock"), true);
$komponenter = [];
foreach ($lock['packages'] ?? [] as $p) {
    $v = ltrim((string) $p['version'], 'v');
    $komponenter[] = [
        'type' => 'library', 'bom-ref' => "pkg:composer/{$p['name']}@$v", 'name' => $p['name'], 'version' => $v,
        'purl' => "pkg:composer/{$p['name']}@$v", 'scope' => 'required',
        'licenses' => array_map(static fn (string $l): array => ['license' => ['id' => $l]], (array) ($p['license'] ?? [])),
        'hashes' => isset($p['dist']['shasum']) && $p['dist']['shasum'] !== '' ? [['alg' => 'SHA-1', 'content' => $p['dist']['shasum']]] : [],
        'externalReferences' => isset($p['source']['url']) ? [['type' => 'vcs', 'url' => $p['source']['url']]] : [],
    ];
}
// Byggeverktøyet som prefikser avhengighetene (BIV-B-024), låst i skript/scoper/composer.lock.
$scoper = json_decode((string) @file_get_contents("$kilde/skript/scoper/composer.lock"), true);
foreach (is_array($scoper) ? $scoper['packages'] ?? [] : [] as $p) {
    $v = ltrim((string) $p['version'], 'v');
    $komponenter[] = [
        'type' => 'library', 'bom-ref' => "pkg:composer/{$p['name']}@$v#bygg", 'name' => $p['name'], 'version' => $v,
        'purl' => "pkg:composer/{$p['name']}@$v", 'scope' => 'excluded',
        'description' => 'Byggeverktøy: prefikser avhengighetene med Bivrost\\Vendor\\ (ikke på serveren)',
        'licenses' => array_map(static fn (string $l): array => ['license' => ['id' => $l]], (array) ($p['license'] ?? [])),
    ];
}
$npm = json_decode((string) @file_get_contents("$kilde/grensesnitt/package-lock.json"), true);
foreach (($npm['packages'] ?? []) as $sti => $p) {
    if ($sti === '' || !is_array($p)) {
        continue;
    }
    $navn = substr($sti, strrpos($sti, 'node_modules/') + 13);
    $komponenter[] = ['type' => 'library', 'bom-ref' => "pkg:npm/$navn@{$p['version']}", 'name' => $navn, 'version' => $p['version'],
        'purl' => 'pkg:npm/' . str_replace('@', '%40', $navn) . '@' . $p['version'], 'scope' => 'excluded',
        'description' => 'Brukes bare når designtokenene lages (ikke på serveren)',
        'licenses' => isset($p['license']) ? [['license' => ['id' => $p['license']]]] : []];
}
$sbom = [
    'bomFormat' => 'CycloneDX', 'specVersion' => '1.6', 'serialNumber' => 'urn:uuid:' . substr(hash('sha256', $tag . $commit), 0, 8) . '-' . substr(hash('sha256', $tag . $commit), 8, 4) . '-5' . substr(hash('sha256', $tag . $commit), 13, 3) . '-8' . substr(hash('sha256', $tag . $commit), 17, 3) . '-' . substr(hash('sha256', $tag . $commit), 20, 12),
    'version' => 1,
    'metadata' => ['timestamp' => $dato, 'component' => ['type' => 'application', 'bom-ref' => "bivrost-kjerne@$versjon", 'name' => 'bivrost-kjerne', 'version' => $versjon,
        'licenses' => [['license' => ['name' => 'Alle rettigheter forbeholdt']]]]],
    'components' => $komponenter,
];
$sbomJson = json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$filer['sbom.cdx.json'] = null;

$info = ['pakke' => 'bivrost-kjerne', 'versjon' => $versjon, 'tag' => $tag, 'commit' => $commit, 'dato' => $dato, 'krever' => ['php' => '>=8.2']];
// Mellomstopp (BIV-DRF-16): kjerne/OPPDATERES_FRA (valgfri, én linje med en nedre grense, «>=1.4.0») sier hvilke
// installerte versjoner denne versjonen kan oppdateres fra. Kravet kontrolleres her, så en skrivefeil stopper bygget i
// stedet for å stenge alle installasjonene ute.
if (is_file("$k/OPPDATERES_FRA")) {
    $fra = trim((string) file_get_contents("$k/OPPDATERES_FRA"));
    if ($fra === '') {
        $stopp('kjerne/OPPDATERES_FRA er tom. Slett filen hvis versjonen kan oppdateres fra alle eldre versjoner.');
    }
    // Bare én nedre grense (Pakke::OPPDATERES_FRA i Kjerne), og den må være lavere enn versjonen selv.
    if (preg_match('/^>=(\d+\.\d+\.\d+)$/D', $fra, $m) !== 1 || version_compare($m[1], $versjon, '>=')) {
        $stopp('kjerne/OPPDATERES_FRA må ha formen «>=X.Y.Z» med en versjon lavere enn denne (f.eks. «>=1.4.0» for 2.0.0).');
    }
    $info['oppdateres_fra'] = $fra;
}
$infoJson = json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
$filer['versjon.json'] = null;
ksort($filer, SORT_STRING);
$innhold = static fn (string $rel) => match ($rel) {
    'sbom.cdx.json' => $sbomJson,
    'versjon.json' => $infoJson,
    default => (string) file_get_contents($filer[$rel]),
};
$manifest = [];
foreach (array_keys($filer) as $rel) {
    $manifest["bivrost/kjerne/$rel"] = hash('sha256', $innhold($rel));
}
$manifestJson = json_encode(['filer' => $manifest], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$zipFil = "$ut/$tag.zip";
@unlink($zipFil);
$z = new ZipArchive();
if ($z->open($zipFil, ZipArchive::CREATE | ZipArchive::EXCL) !== true) {
    $stopp('fikk ikke laget zip-filen.');
}
$tid = (int) $epoke;
$alle = array_merge(array_keys($filer), ['manifest.json']);
sort($alle, SORT_STRING);
foreach ($alle as $rel) {
    $navn = "bivrost/kjerne/$rel";
    $z->addFromString($navn, $rel === 'manifest.json' ? $manifestJson : $innhold($rel));
    $z->setMtimeName($navn, $tid);
    $z->setExternalAttributesName($navn, ZipArchive::OPSYS_UNIX, 0100644 << 16);
}
$z->close();
$sum = hash_file('sha256', $zipFil);
file_put_contents("$zipFil.sha256", "$sum  $tag.zip\n");
file_put_contents("$ut/$tag.versjon.json", $infoJson);
file_put_contents("$ut/$tag.sbom.cdx.json", $sbomJson);
fwrite(STDOUT, count($alle) . " filer, sha256 $sum\n");
