<?php

declare(strict_types=1);

namespace ArkIvar\Epost;

/**
 * Skriver brevene til en fil i stedet for å sende dem (én JSON-linje per brev). Til tester og lokal utvikling, der
 * det ikke finnes noen e-posttjener: koden i brevet leses fra filen. Brukes aldri i produksjon.
 */
final class FilEpost implements Epostsender
{
    public function __construct(private readonly string $fil)
    {
    }

    public function send(string $til, string $emne, string $tekst): void
    {
        $linje = json_encode(['til' => $til, 'emne' => $emne, 'tekst' => $tekst], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $mappe = dirname($this->fil);
        if ($linje === false || (!is_dir($mappe) && !mkdir($mappe, 0700, true) && !is_dir($mappe)) || file_put_contents($this->fil, $linje . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new EpostFeil('fil_ikke_skrevet');
        }
    }

    /**
     * Brevene som er skrevet, eldste først.
     *
     * @return list<array{til: string, emne: string, tekst: string}>
     */
    public function brev(): array
    {
        $ut = [];
        foreach (is_file($this->fil) ? (array) file($this->fil, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $linje) {
            $brev = is_string($linje) ? json_decode($linje, true) : null;
            if (is_array($brev) && is_string($brev['til'] ?? null) && is_string($brev['emne'] ?? null) && is_string($brev['tekst'] ?? null)) {
                $ut[] = ['til' => $brev['til'], 'emne' => $brev['emne'], 'tekst' => $brev['tekst']];
            }
        }

        return $ut;
    }
}
