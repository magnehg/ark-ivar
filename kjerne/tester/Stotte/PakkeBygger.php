<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

use Bivrost\Kjerne\Oppdatering\Signatur;

/**
 * Lager versjonspakker som release-skriptet gjør (versjon.json, manifest.json, zip, sha256, signatur og
 * <tag>.versjon.json), til testene av oppdateringen.
 */
final class PakkeBygger
{
    /**
     * @param array<string, string> $filer Sti relativt til pakkens mappe → innhold.
     * @param array<string, string> $krever
     * @param array<string, string> $ekstra Råe oppføringer (full sti → innhold) som ikke kommer i manifestet.
     * @param string|null $oppdateresFra Mellomstopp: «oppdateres_fra» i versjon.json (null = feltet mangler).
     * @return string Taggen.
     */
    public static function lag(string $ut, string $pakke, string $tagprefiks, string $mappe, string $versjon, array $filer, array $krever = [], ?string $hemmeligNokkel = null, array $ekstra = [], ?string $oppdateresFra = null): string
    {
        $tag = $tagprefiks . $versjon;
        $info = ['pakke' => $pakke, 'versjon' => $versjon, 'tag' => $tag, 'commit' => str_repeat('a', 40), 'dato' => '2026-11-13T10:00:00Z', 'krever' => $krever === [] ? new \stdClass() : $krever];
        if ($oppdateresFra !== null) {
            $info['oppdateres_fra'] = $oppdateresFra;
        }
        $alle = [];
        foreach ($filer as $rel => $innhold) {
            $alle[$mappe . $rel] = $innhold;
        }
        $alle[$mappe . 'versjon.json'] = (string) json_encode($info, JSON_UNESCAPED_SLASHES);
        $manifest = [];
        foreach ($alle as $rel => $innhold) {
            $manifest[$rel] = hash('sha256', $innhold);
        }
        ksort($manifest);
        $alle[$mappe . 'manifest.json'] = (string) json_encode(['filer' => $manifest], JSON_UNESCAPED_SLASHES);
        $zip = "$ut/$tag.zip";
        $z = new \ZipArchive();
        $z->open($zip, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        foreach ($alle + $ekstra as $rel => $innhold) {
            $z->addFromString($rel, $innhold);
        }
        $z->close();
        $sha = (string) hash_file('sha256', $zip);
        file_put_contents("$zip.sha256", "$sha  $tag.zip\n");
        file_put_contents("$ut/$tag.versjon.json", json_encode($info, JSON_UNESCAPED_SLASHES));
        if ($hemmeligNokkel !== null) {
            file_put_contents("$zip.sig", Signatur::signer($hemmeligNokkel, $pakke, $tag, $sha) . "\n");
        }

        return $tag;
    }
}
