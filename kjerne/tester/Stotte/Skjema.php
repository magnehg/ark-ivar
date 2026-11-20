<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Tester\Stotte;

use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator;

/** Validerer data mot JSON Schema 2020-12-filene i skjema/ (opis/json-schema, bare i testene). */
final class Skjema
{
    private static ?Validator $v = null;

    private static function validator(): Validator
    {
        if (self::$v === null) {
            $v = new Validator();
            $v->setMaxErrors(5);
            $v->parser()->setOption('defaultDraft', '2020-12');
            $filer = glob(__DIR__ . '/../../skjema/*.schema.json');
            foreach ($filer === false ? [] : $filer as $fil) {
                $j = json_decode((string) file_get_contents($fil));
                if (is_object($j) && isset($j->{'$id'}) && is_string($j->{'$id'})) {
                    $v->resolver()?->registerRaw($j, $j->{'$id'});
                }
            }
            self::$v = $v;
        }

        return self::$v;
    }

    /**
     * Gir en tom liste når data passer, ellers feilene.
     *
     * @return list<string>
     */
    public static function feil(string $navn, mixed $data): array
    {
        return self::feilJson($navn, (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Som feil(), men for JSON-teksten slik den er (tomme objekter er objekter).
     *
     * @return list<string>
     */
    public static function feilJson(string $navn, string $json): array
    {
        $d = json_decode($json);
        $r = self::validator()->validate($d, 'https://login.magnehg.no/skjema/' . $navn . '.schema.json');
        $feil = $r->error();
        if ($r->isValid() || $feil === null) {
            return [];
        }
        $ut = [];
        foreach ((new ErrorFormatter())->format($feil, false) as $sti => $melding) {
            $ut[] = $sti . ': ' . (is_string($melding) ? $melding : (string) json_encode($melding));
        }

        return $ut;
    }
}
