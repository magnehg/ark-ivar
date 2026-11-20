<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Sikkerhetskopi;

use Bivrost\Kjerne\Feil\BivrostFeil;

/**
 * Sikkerhetskopi av filene i pakkenes mapper (BIV-DRF-09) som zip, før en oppdatering. Stiene i zip-filen er
 * relative til installasjonsroten. Symbolske lenker følges ikke. Kopien kontrolleres (antall filer) før navnet gis.
 */
final class Filkopi
{
    public function __construct(private readonly string $rot, private readonly Kopilager $lager)
    {
    }

    /** @param list<string> $mapper Relative mapper med / til slutt, f.eks. «bivrost/kjerne/». */
    public function ta(array $mapper): string
    {
        @set_time_limit(300);
        $navn = $this->lager->nyttNavn('filer', '.zip');
        $fil = $this->lager->mappe() . '/' . $navn;
        $z = new \ZipArchive();
        if ($z->open($fil, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            throw new BivrostFeil('bivrost.feil.kopi.skriv');
        }
        $antall = 0;
        try {
            foreach ($mapper as $m) {
                $full = $this->rot . '/' . rtrim($m, '/');
                if (!is_dir($full)) {
                    continue;
                }
                $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($it as $f) {
                    if (!$f instanceof \SplFileInfo || $f->isLink() || !$f->isFile()) {
                        continue;
                    }
                    $rel = substr($f->getPathname(), strlen($this->rot) + 1);
                    if (!$z->addFile($f->getPathname(), str_replace('\\', '/', $rel))) {
                        throw new BivrostFeil('bivrost.feil.kopi.skriv');
                    }
                    $antall++;
                }
            }
            $z->setArchiveComment('bivrost-filkopi');
        } catch (\Throwable $e) {
            $z->close();
            @unlink($fil);
            throw $e;
        }
        if (!$z->close()) {
            @unlink($fil);
            throw new BivrostFeil('bivrost.feil.kopi.skriv');
        }
        $k = new \ZipArchive();
        if ($antall > 0 && ($k->open($fil, \ZipArchive::RDONLY) !== true || $k->numFiles !== $antall)) {
            @unlink($fil);
            throw new BivrostFeil('bivrost.feil.kopi.kontroll');
        }
        if ($antall === 0 && !is_file($fil)) {
            // ZipArchive lager ingen fil uten innhold: lag en tom, gyldig zip, så punktet alltid har en filkopi.
            file_put_contents($fil, "PK\x05\x06" . str_repeat("\0", 18));
        }
        @chmod($fil, 0640);

        return $navn;
    }

    /**
     * Legger filene tilbake: i hver mappe fjernes filer som ikke er i kopien, og filene i kopien skrives.
     *
     * @param list<string> $mapper
     * @return list<string> Filer som ikke kunne skrives eller fjernes (tom når alt gikk).
     */
    public function leggTilbake(string $navn, array $mapper): array
    {
        $fil = $this->lager->sti($navn);
        $z = new \ZipArchive();
        if ($z->open($fil, \ZipArchive::RDONLY) !== true) {
            throw new BivrostFeil('bivrost.feil.kopi.les');
        }
        $iKopien = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n = $z->getNameIndex($i);
            if (is_string($n)) {
                $iKopien[$n] = $i;
            }
        }
        $avvik = [];
        foreach ($mapper as $m) {
            $full = $this->rot . '/' . rtrim($m, '/');
            if (!is_dir($full)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($full, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $f) {
                if (!$f instanceof \SplFileInfo) {
                    continue;
                }
                $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($this->rot) + 1));
                if ($f->isDir() && !$f->isLink()) {
                    @rmdir($f->getPathname());
                } elseif (!isset($iKopien[$rel]) && !@unlink($f->getPathname())) {
                    $avvik[] = $rel;
                }
            }
        }
        foreach ($iKopien as $rel => $i) {
            if (str_contains($rel, '..') || str_starts_with($rel, '/')) {
                continue;
            }
            $innenfor = false;
            foreach ($mapper as $m) {
                $innenfor = $innenfor || str_starts_with($rel, $m);
            }
            if (!$innenfor) {
                continue;
            }
            $mal = $this->rot . '/' . $rel;
            $data = $z->getFromIndex($i);
            if (!is_dir(dirname($mal))) {
                @mkdir(dirname($mal), 0755, true);
            }
            $tmp = $mal . '.tilbake-' . bin2hex(random_bytes(4));
            if ($data === false || @file_put_contents($tmp, $data) !== strlen($data) || !@rename($tmp, $mal)) {
                @unlink($tmp);
                $avvik[] = $rel;
            }
        }
        $z->close();

        return $avvik;
    }
}
