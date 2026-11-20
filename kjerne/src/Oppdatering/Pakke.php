<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Oppdatering;

/**
 * En pakke som oppdateres fra GitHub (BIV-DRF-05): Bivrost Kjerne, appen og senere andre pakker. Hver pakke har sin
 * egen mappe i installasjonen (og skriver bare der), sin egen tag-serie, sitt eget manifest og sin egen
 * migreringsrekke.
 *
 *   tag        <tagprefiks><versjon>, f.eks. bivrost-kjerne-v0.1.0
 *   ressurser  <tag>.zip, <tag>.zip.sha256, <tag>.zip.sig (Ed25519, valgfri) og <tag>.versjon.json
 *   i pakken   <mappe>versjon.json og <mappe>manifest.json (stier relative til installasjonsroten), og eventuelt
 *              <mappe>signeringsnokkel.offentlig: utgiverens offentlige nøkkel, som installasjonen klarerer første
 *              gang den ser den (BIV-DRF-14)
 */
final class Pakke
{
    /** @param list<string> $oppsettfiler Serveroppsett (.htaccess o.l.) pakken får skrive, med stien. */
    public function __construct(
        public readonly string $id,
        public readonly string $navn,
        public readonly string $repo,
        public readonly string $tagprefiks,
        public readonly string $mappe,
        public readonly ?string $migreringer = null,
        public readonly array $oppsettfiler = [],
    ) {
        if (
            preg_match('/^[a-z][a-z0-9-]{0,39}$/D', $id) !== 1 || preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#D', $repo) !== 1
            || preg_match('/^[a-z][a-z0-9-]*-v$/D', $tagprefiks) !== 1 || preg_match('#^[a-z0-9][a-z0-9_-]*(/[a-z0-9][a-z0-9_-]*)*/$#D', $mappe) !== 1
        ) {
            throw new \InvalidArgumentException('Ugyldig pakkedefinisjon.');
        }
    }

    public function versjonsfil(): string
    {
        return $this->mappe . 'versjon.json';
    }

    public function manifest(): string
    {
        return $this->mappe . 'manifest.json';
    }

    /** Utgiverens offentlige nøkkel (Ed25519, base64) i pakken og i installasjonen, når pakken har en. */
    public function nokkelfil(): string
    {
        return $this->mappe . 'signeringsnokkel.offentlig';
    }

    public function tag(string $versjon): string
    {
        return $this->tagprefiks . $versjon;
    }

    /** Versjonen i en tag for denne pakken, eller null. */
    public function versjonFraTag(string $tag): ?string
    {
        if (!str_starts_with($tag, $this->tagprefiks)) {
            return null;
        }
        $v = substr($tag, strlen($this->tagprefiks));

        return preg_match('/^\d+\.\d+\.\d+(-[0-9A-Za-z.-]+)?$/D', $v) === 1 ? $v : null;
    }

    /** Formen på «oppdateres_fra» i versjon.json: bare én nedre grense, «>=X.Y.Z» (BIV-DRF-16). */
    public const OPPDATERES_FRA = '/^>=(\d+\.\d+\.\d+)$/D';

    /**
     * Om en versjon med kravet $krav («oppdateres_fra») kan legges inn over den installerte versjonen $installert.
     * Uten krav (null): ja. Et krav som ikke har formen «>=X.Y.Z»: nei, så en feil i en versjon aldri fører til at et
     * mellomstopp hoppes over.
     *
     * Kravet er bare én nedre grense, så et stopp aldri kan være en blindvei. Sammenligningen følger semver: først
     * X.Y.Z, og en forhåndsversjon (alt etter «-», som 1.4.0-rc.1 eller 1.4.0-pre.1) er lavere enn X.Y.Z selv.
     * Verken Composer («>=1.4.0» leses som «>=1.4.0-dev») eller version_compare() (som regner «-p1» og «-pre» som
     * høyere enn 1.4.0) gjør det riktig for alle formene taggene kan ha.
     */
    public static function kanOppdateresFra(string $installert, mixed $krav): bool
    {
        if ($krav === null) {
            return true;
        }
        if (!is_string($krav) || preg_match(self::OPPDATERES_FRA, $krav, $m) !== 1) {
            return false;
        }
        if (preg_match('/^(\d+\.\d+\.\d+)(-.+)?$/D', $installert, $v) !== 1) {
            return false;
        }
        $sammenlignet = version_compare($v[1], $m[1]);

        return $sammenlignet > 0 || ($sammenlignet === 0 && !isset($v[2]));
    }
}
