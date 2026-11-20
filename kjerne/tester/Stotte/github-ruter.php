<?php

/**
 * Etterlignet GitHub Releases-API for testene av oppdateringen (bare PHPs innebygde webserver på denne maskinen).
 *
 * Start: MOCK_PAKKER=<mappe> MOCK_LOGG=<fil> php -S 127.0.0.1:<port> github-ruter.php
 *   MOCK_PAKKER/<eier>/<repo>/  versjonspakkene: <tag>.zip, <tag>.zip.sha256, <tag>.zip.sig (valgfri), <tag>.versjon.json
 *                               og eventuelt utkast.txt (taggene som er utkast) og forhand.txt (forhåndsversjoner)
 *   MOCK_LOGG                   én JSON-linje per forespørsel med Authorization-hodet (for kontrollen av at tokenet
 *                               ikke sendes videre til en annen vert)
 *   MOCK_ANNEN_VERT             vert for nedlastingen etter omdirigeringen (f.eks. localhost:<port>), som hos GitHub
 *
 * Rutene: GET /repos/{eier}/{repo}/releases, GET /repos/{eier}/{repo}/releases/assets/{id} (302 til /last-ned/…),
 * GET /last-ned/{eier}/{repo}/{navn}.
 *
 * Som hos GitHub: tokenet «Bearer ugyldig» gir 401, og et repo uten mappe under MOCK_PAKKER gir 404 (det samme svaret
 * som for et privat repo uten token).
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}
$pakker = (string) getenv('MOCK_PAKKER');
$logg = (string) getenv('MOCK_LOGG');
$uri = $_SERVER['REQUEST_URI'] ?? '/';
$sti = (string) parse_url(is_string($uri) ? $uri : '/', PHP_URL_PATH);
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
if ($logg !== '') {
    file_put_contents($logg, json_encode(['sti' => $sti, 'vert' => $_SERVER['HTTP_HOST'] ?? '', 'auth' => $auth]) . "\n", FILE_APPEND | LOCK_EX);
}
$json = static function (mixed $d, int $s = 200): never {
    http_response_code($s);
    header('Content-Type: application/json');
    echo json_encode($d, JSON_UNESCAPED_SLASHES);
    exit;
};
$id = static fn (string $repo, string $navn): int => (int) sprintf('%u', crc32("$repo/$navn")) % 100000000 + 1;

if (str_starts_with($sti, '/repos/') && $auth === 'Bearer ugyldig') {
    $json(['message' => 'Bad credentials'], 401);
}
if (preg_match('#^/repos/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/releases$#', $sti, $m) === 1) {
    $mappe = "$pakker/$m[1]/$m[2]";
    if (!is_dir($mappe)) {
        $json(['message' => 'Not Found'], 404);
    }
    $les = static function (string $f): array {
        $l = is_file($f) ? file($f, FILE_IGNORE_NEW_LINES) : false;

        return $l === false ? [] : array_map('trim', $l);
    };
    $utkast = $les("$mappe/utkast.txt");
    $forhand = $les("$mappe/forhand.txt");
    $ut = [];
    $zipper = glob("$mappe/*.zip");
    foreach ($zipper === false ? [] : $zipper as $zip) {
        $tag = basename($zip, '.zip');
        $assets = [];
        foreach ([".zip", ".zip.sha256", ".zip.sig", ".versjon.json"] as $e) {
            if (is_file("$mappe/$tag$e")) {
                $a = ['id' => $id("$m[1]/$m[2]", "$tag$e"), 'name' => "$tag$e"];
                if ($e === '.zip') {
                    $a['digest'] = 'sha256:' . hash_file('sha256', "$mappe/$tag$e");
                }
                $assets[] = $a;
            }
        }
        $ut[] = ['tag_name' => $tag, 'draft' => in_array($tag, $utkast, true), 'prerelease' => in_array($tag, $forhand, true),
            'published_at' => '2026-11-13T10:00:00Z', 'assets' => $assets];
    }
    $json($ut);
}
if (preg_match('#^/repos/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/releases/assets/(\d+)$#', $sti, $m) === 1) {
    $alle = glob("$pakker/$m[1]/$m[2]/*");
    foreach ($alle === false ? [] : $alle as $f) {
        if ($id("$m[1]/$m[2]", basename($f)) === (int) $m[3]) {
            $annen = getenv('MOCK_ANNEN_VERT');
            $host = $_SERVER['HTTP_HOST'] ?? '';
            $vert = is_string($annen) && $annen !== '' ? $annen : (is_string($host) ? $host : '');
            header("Location: http://$vert/last-ned/$m[1]/$m[2]/" . rawurlencode(basename($f)), true, 302);
            exit;
        }
    }
    $json(['message' => 'Not Found'], 404);
}
if (preg_match('#^/last-ned/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.%-]+)$#', $sti, $m) === 1) {
    $f = "$pakker/$m[1]/$m[2]/" . basename(rawurldecode($m[3]));
    if (is_file($f)) {
        header('Content-Type: application/octet-stream');
        readfile($f);
        exit;
    }
}
$json(['message' => 'Not Found'], 404);
