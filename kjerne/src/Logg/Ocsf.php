<?php

declare(strict_types=1);

namespace Bivrost\Kjerne\Logg;

/**
 * Tilordning av Bivrosts hendelsestyper til OCSF (Open Cybersecurity Schema Framework, 1.x) der det passer
 * (BIV-STD-06, docs/standarder.md). Brukes i CloudEvents og syslog, så et SIEM-verktøy kan sortere hendelsene.
 * Hendelser uten en naturlig OCSF-klasse gir null.
 */
final class Ocsf
{
    /** @var array<string, array{class_uid: int, class_name: string, activity_id: int, status_id: int}> */
    private const KART = [
        'urn:bivrost:hendelse:innlogging:lykkes' => ['class_uid' => 3002, 'class_name' => 'Authentication', 'activity_id' => 1, 'status_id' => 1],
        'urn:bivrost:hendelse:innlogging:avvist' => ['class_uid' => 3002, 'class_name' => 'Authentication', 'activity_id' => 1, 'status_id' => 2],
        'urn:bivrost:hendelse:utlogging:lykkes' => ['class_uid' => 3002, 'class_name' => 'Authentication', 'activity_id' => 2, 'status_id' => 1],
        'urn:bivrost:hendelse:utlogging:backchannel' => ['class_uid' => 3002, 'class_name' => 'Authentication', 'activity_id' => 2, 'status_id' => 1],
        'urn:bivrost:hendelse:utlogging:avvist' => ['class_uid' => 3002, 'class_name' => 'Authentication', 'activity_id' => 2, 'status_id' => 2],
        'urn:bivrost:hendelse:innlogging:passord_byttet' => ['class_uid' => 3001, 'class_name' => 'Account Change', 'activity_id' => 3, 'status_id' => 1],
        'urn:bivrost:hendelse:innlogging:passord_avvist' => ['class_uid' => 3001, 'class_name' => 'Account Change', 'activity_id' => 3, 'status_id' => 2],
        'urn:bivrost:hendelse:innlogging:totp_byttet' => ['class_uid' => 3001, 'class_name' => 'Account Change', 'activity_id' => 10, 'status_id' => 1],
        'urn:bivrost:hendelse:innlogging:totp_avvist' => ['class_uid' => 3001, 'class_name' => 'Account Change', 'activity_id' => 10, 'status_id' => 2],
        'urn:bivrost:hendelse:api:avvist' => ['class_uid' => 6003, 'class_name' => 'API Activity', 'activity_id' => 99, 'status_id' => 2],
        'urn:bivrost:hendelse:api:nokkel_laget' => ['class_uid' => 3001, 'class_name' => 'Account Change', 'activity_id' => 1, 'status_id' => 1],
        'urn:bivrost:hendelse:api:nokkel_trukket' => ['class_uid' => 3001, 'class_name' => 'Account Change', 'activity_id' => 4, 'status_id' => 1],
        'urn:bivrost:hendelse:tilgang:separasjon_brudd' => ['class_uid' => 3005, 'class_name' => 'User Access Management', 'activity_id' => 99, 'status_id' => 2],
        'urn:bivrost:hendelse:rolleregister:ny' => ['class_uid' => 3006, 'class_name' => 'Group Management', 'activity_id' => 1, 'status_id' => 1],
        'urn:bivrost:hendelse:rolleregister:endret' => ['class_uid' => 3006, 'class_name' => 'Group Management', 'activity_id' => 99, 'status_id' => 1],
        'urn:bivrost:hendelse:rolleregister:deaktivert' => ['class_uid' => 3006, 'class_name' => 'Group Management', 'activity_id' => 4, 'status_id' => 1],
        'urn:bivrost:hendelse:rolleregister:gjenopprettet' => ['class_uid' => 3006, 'class_name' => 'Group Management', 'activity_id' => 99, 'status_id' => 1],
    ];

    /** @return array{class_uid: int, class_name: string, activity_id: int, status_id: int}|null */
    public static function klasse(string $urn): ?array
    {
        return self::KART[$urn] ?? null;
    }
}
