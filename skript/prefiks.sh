#!/usr/bin/env bash
# Lager Kjernens avhengigheter med prefikset Bivrost\Vendor\ (BIV-B-024) fra kjerne/composer.lock, uten
# utviklingsavhengigheter, i kjerne/vendor-prefikset/ (eller mappen i første argument, f.eks. vendor/ i
# versjonspakken). Ingen skript eller utvidelser fra pakkene kjøres.
# Bruk: bash skript/prefiks.sh [<utmappe> [<rot>]]   (<rot> har kjerne/ og skript/scoper/, standard: dette repoet;
#       lag-versjonspakke.sh bruker filene fra commiten)
set -euo pipefail
repo="$(cd "${2:-$(dirname "$0")/..}" && pwd)"
ut="${1:-$repo/kjerne/vendor-prefikset}"
export COMPOSER_ALLOW_SUPERUSER=1
arbeid="$(mktemp -d)"
trap 'rm -rf -- "$arbeid"' EXIT

# Verktøyet, låst i skript/scoper/composer.lock.
composer install --working-dir="$repo/skript/scoper" --no-dev --prefer-dist --no-plugins --no-scripts \
  --no-interaction --no-progress --quiet

# Bare avhengighetene: composer.json uten Kjernens egen autoload (src/ prefikses ikke og lastes av kjerne/autoload.php).
mkdir -p "$arbeid/inn"
php -r '
  $j = json_decode((string) file_get_contents($argv[1]), false, 512, JSON_THROW_ON_ERROR);
  unset($j->autoload, $j->{"autoload-dev"}, $j->scripts);
  // Fast navn på Composers lasterklasse (ComposerAutoloaderInit…). Uten det får den navnet etter composer.lock, det
  // samme som Composers vanlige vendor/ i utviklingskopien, og PHP stopper når begge lastes.
  $j->config = (object) ((array) ($j->config ?? new stdClass()) + ["autoloader-suffix" => "BivrostKjerneVendor"]);
  file_put_contents($argv[2], json_encode($j, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
' "$repo/kjerne/composer.json" "$arbeid/inn/composer.json"
cp "$repo/kjerne/composer.lock" "$arbeid/inn/composer.lock"
(cd "$arbeid/inn" && composer install --no-dev --prefer-dist --no-autoloader --no-plugins --no-scripts \
  --no-interaction --no-progress --quiet)

(cd "$arbeid/inn" && "$repo/skript/scoper/vendor/bin/php-scoper" add-prefix --config="$repo/skript/scoper/scoper.inc.php" \
  --output-dir="$arbeid/ut" --no-interaction --force --quiet)
(cd "$arbeid/ut" && composer dump-autoload --classmap-authoritative --no-dev --no-plugins --no-scripts --quiet)
php "$repo/skript/scoper/etterbehandling.php" "$arbeid/ut/vendor"

rm -rf -- "$ut.ny"
rm -f -- "$arbeid/ut/vendor/scoper-autoload.php"  # globale alias, som Kjerne ikke vil ha
mv "$arbeid/ut/vendor" "$ut.ny"
rm -rf -- "$ut"
mv "$ut.ny" "$ut"
echo "Prefiksede avhengigheter: $ut"
