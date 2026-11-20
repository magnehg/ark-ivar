<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

use Bivrost\Kjerne\Feil\BivrostFeil;
use Bivrost\Kjerne\Http\UtgaendeHttp;

/**
 * Versjonene fra GitHub Releases (REST API). Et token (lesetilgang til et privat repo) står bare i .env
 * (BIVROST_GITHUB_TOKEN) og sendes aldri videre til en annen vert ved nedlastingen (UtgaendeHttp).
 * GITHUB_API_URL kan peke på et etterlignet API i tester (bare http://127.0.0.1 eller https).
 */
final class GithubUtgivelser implements Utgivelseskilde
{
    private const MAKS_LISTE = 8 * 1024 * 1024;

    public function __construct(
        private readonly UtgaendeHttp $http,
        private readonly string $api = 'https://api.github.com',
        private readonly string $token = '',
    ) {
        if (!UtgaendeHttp::trygg($api)) {
            throw new BivrostFeil('bivrost.feil.http.utrygg_adresse');
        }
    }

    /** @return array<string, string> */
    private function hoder(string $accept): array
    {
        $h = ['Accept' => $accept, 'X-GitHub-Api-Version' => '2022-11-28'];
        if ($this->token !== '') {
            $h['Authorization'] = 'Bearer ' . $this->token;
        }

        return $h;
    }

    /**
     * Versjonene i repoet, nyeste først (utkast og tagger med feil prefiks tas ikke med). Et annet svar enn 200 gir
     * feilen bivrost.feil.oppdatering.github med statuskoden som verdi, så driftsbrukeren kan skille 401 (ugyldig
     * token), 403 (ingen tilgang eller grensen for antall kall) og 404 (privat repo uten token, eller feil repo).
     */
    public function utgivelser(Pakke $p): array
    {
        $s = $this->http->hent(rtrim($this->api, '/') . '/repos/' . $p->repo . '/releases?per_page=100', $this->hoder('application/vnd.github+json'), self::MAKS_LISTE);
        if ($s['status'] !== 200) {
            throw new BivrostFeil('bivrost.feil.oppdatering.github', ['status' => $s['status']]);
        }
        $liste = json_decode($s['kropp'], true);
        if (!is_array($liste)) {
            throw new BivrostFeil('bivrost.feil.oppdatering.github_liste');
        }
        $ut = [];
        foreach ($liste as $r) {
            if (!is_array($r) || ($r['draft'] ?? true) !== false || !is_string($r['tag_name'] ?? null)) {
                continue;
            }
            $versjon = $p->versjonFraTag($r['tag_name']);
            if ($versjon === null) {
                continue;
            }
            $ressurser = [];
            $digest = null;
            foreach (is_array($r['assets'] ?? null) ? $r['assets'] : [] as $a) {
                if (is_array($a) && is_string($a['name'] ?? null) && is_int($a['id'] ?? null)) {
                    $ressurser[$a['name']] = $a['id'];
                    if ($a['name'] === $r['tag_name'] . '.zip' && is_string($a['digest'] ?? null)) {
                        $digest = $a['digest'];
                    }
                }
            }
            $ut[] = new Utgivelse(
                $r['tag_name'],
                $versjon,
                ($r['prerelease'] ?? false) === true,
                $ressurser,
                $digest,
                is_string($r['published_at'] ?? null) ? $r['published_at'] : null
            );
        }
        usort($ut, static fn (Utgivelse $a, Utgivelse $b): int => version_compare($b->versjon, $a->versjon));

        return $ut;
    }

    public function lastNed(Pakke $p, Utgivelse $u, string $ressurs, string $tilFil, int $maks): void
    {
        $id = $u->ressurser[$ressurs] ?? null;
        if ($id === null) {
            throw new BivrostFeil('bivrost.feil.oppdatering.ressurs_mangler', ['ressurs' => $ressurs]);
        }
        $s = $this->http->hent(rtrim($this->api, '/') . '/repos/' . $p->repo . '/releases/assets/' . $id, $this->hoder('application/octet-stream'), $maks, $tilFil);
        if ($s['status'] !== 200) {
            @unlink($tilFil);
            throw new BivrostFeil('bivrost.feil.oppdatering.github', ['status' => $s['status']]);
        }
    }
}
