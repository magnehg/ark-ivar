#!/usr/bin/env bash
# Lager versjonspakken for Bivrost Kjerne (BIV-DRF-05–07, DRF-09): bivrost-kjerne-vX.Y.Z.zip med vendor/ (uten
# utviklingsavhengigheter), designpakken, versjon.json, manifest.json og SBOM (CycloneDX), pluss sjekksum og
# <tag>.versjon.json. Signeringen gjøres i en egen jobb i release.yml (eller her når BIVROST_SIGNERINGSNOKKEL er satt).
#
# Bruk: bash skript/lag-versjonspakke.sh <versjon> <utmappe> [<commit>]
#   Innholdet hentes fra commiten (git archive), aldri fra arbeidskopien, så pakken svarer til det som er i git (L-013).
set -euo pipefail

versjon="${1:?Bruk: lag-versjonspakke.sh <versjon> <utmappe> [<commit>]}"
ut="${2:?Bruk: lag-versjonspakke.sh <versjon> <utmappe> [<commit>]}"
commit="${3:-HEAD}"
[[ "$versjon" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || { echo "Versjonen må være X.Y.Z." >&2; exit 1; }

repo="$(cd "$(dirname "$0")/.." && pwd)"
sha="$(git -C "$repo" rev-parse --verify "$commit^{commit}")"
i_commit="$(git -C "$repo" show "$sha:kjerne/VERSJON" | tr -d '[:space:]')"
[ "$i_commit" = "$versjon" ] || { echo "kjerne/VERSJON i commiten er $i_commit, ikke $versjon." >&2; exit 1; }
tag="bivrost-kjerne-v$versjon"

arbeid="$(mktemp -d)"
trap 'rm -rf -- "$arbeid"' EXIT
mkdir -p "$arbeid/kilde" "$ut"
# Også byggeskriptene (prefiks.sh, pakke.php, scoper-oppsettet) er commitens, så et bygg av en eldre commit eller en
# arbeidskopi med endringer gir samme pakke som commiten selv. LICENSE, CHANGELOG.md og SECURITY.md fra rota følger med
# pakken (pakke.php legger dem i bivrost/kjerne/, BIV-B-035 klasse a).
git -C "$repo" archive --format=tar "$sha" kjerne grensesnitt skript/scoper skript/prefiks.sh skript/pakke.php LICENSE CHANGELOG.md SECURITY.md | tar -x -C "$arbeid/kilde"

# Avhengighetene uten utviklingsverktøy, låst av composer.lock og prefikset med Bivrost\Vendor\ (BIV-B-024), i vendor/.
# Verktøyet og oppsettet er commitens (skript/scoper). Ingen skript eller utvidelser fra pakkene kjøres.
bash "$arbeid/kilde/skript/prefiks.sh" "$arbeid/kilde/kjerne/vendor" "$arbeid/kilde"
php "$arbeid/kilde/skript/scoper/sjekk-pakke.php" "$arbeid/kilde/kjerne"
rm -rf -- "$arbeid/kilde/skript/scoper/vendor"

dato="$(git -C "$repo" show -s --format=%cI "$sha")"
epoke="$(git -C "$repo" show -s --format=%ct "$sha")"
php "$arbeid/kilde/skript/pakke.php" "$arbeid/kilde" "$ut" "$versjon" "$tag" "$sha" "$dato" "$epoke"

if [ -n "${BIVROST_SIGNERINGSNOKKEL:-}" ]; then
  KILDE="$arbeid/kilde" ZIP="$ut/$tag.zip" TAG="$tag" php -r '
    require getenv("KILDE") . "/kjerne/autoload.php";
    $sha = hash_file("sha256", getenv("ZIP"));
    echo Bivrost\Kjerne\Oppdatering\Signatur::signer((string) getenv("BIVROST_SIGNERINGSNOKKEL"), "bivrost-kjerne", getenv("TAG"), $sha), "\n";
  ' > "$ut/$tag.zip.sig"
  echo "Signert: $ut/$tag.zip.sig"
fi
echo "Laget $ut/$tag.zip"
