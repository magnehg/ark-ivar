<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

/** Midlertidige mapper for testene (under sys_get_temp_dir(), prefikset bivrost-test-). */
final class Mappe
{
    public static function ny(): string
    {
        $m = sys_get_temp_dir() . '/bivrost-test-' . bin2hex(random_bytes(6));
        mkdir($m, 0750, true);

        return (string) realpath($m);
    }

    public static function slett(string $mappe): void
    {
        if (!str_contains($mappe, '/bivrost-test-') || !is_dir($mappe)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($mappe, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            if ($f instanceof \SplFileInfo) {
                $f->isDir() && !$f->isLink() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
        }
        rmdir($mappe);
    }

    /** Skriver en fil (med mapper). */
    public static function skriv(string $fil, string $innhold): void
    {
        if (!is_dir(dirname($fil))) {
            mkdir(dirname($fil), 0755, true);
        }
        file_put_contents($fil, $innhold);
    }
}
