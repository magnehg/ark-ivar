<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * En hendelse som CloudEvents 1.0 i JSON-formatet (BIV-STD-05), til hendelser mellom Bivrost og appene og til
 * videresending. Hendelsen i Bivrosts format står uendret i «data»; korrelasjonen er utvidelsen «bivrostkorrelasjon»
 * og OCSF-klassen «ocsfclassuid» (navnene på utvidelser må være små bokstaver og tall, CloudEvents § 3.1.1).
 */
final class CloudEvent
{
    /** @return array<string, mixed> */
    public static function fraHendelse(Hendelse $h, string $app): array
    {
        $ocsf = Ocsf::klasse($h->hva['urn']);

        return array_filter([
            'specversion' => '1.0',
            'id' => $h->id,
            'source' => 'urn:bivrost:app:' . $app,
            'type' => $h->hva['urn'],
            'time' => $h->tid,
            'subject' => $h->objekt['id'] ?? null,
            'datacontenttype' => 'application/json',
            'dataschema' => 'https://login.magnehg.no/skjema/logghendelse.schema.json',
            'bivrostkorrelasjon' => $h->korrelasjon,
            'ocsfclassuid' => $ocsf['class_uid'] ?? null,
            'data' => $h->tilListe(),
        ], static fn ($v): bool => $v !== null);
    }
}
