<?php

declare(strict_types=1);

/*
 * Ark-Ivars oppsett for Bivrost Kjerne (AppKonfig, se kjerne/README.md). Installasjonsroten er mappen over denne:
 * den har .env, drift/ og Kjerne (bivrost/kjerne/ i en installasjon, kjerne/ i en utviklingskopi).
 *
 * Rollene er et hierarki: en lærer kan alt en student kan, og en administrator kan alt en lærer kan. En bruker har
 * én rolle i databasen og får URN-ene for den og rollene under (ArkIvar\Brukere\Roller).
 */

return [
    'app' => 'arkivar',
    'appnavn' => 'Ark-Ivar',
    'rot' => dirname(__DIR__),
    'webrot' => __DIR__ . '/public',
    'hovedsprak' => 'nb',
    'roller' => [
        [
            'navn' => 'student',
            'tekst' => ['nb' => 'Student', 'nn' => 'Student', 'en' => 'Student'],
            'beskrivelse' => [
                'nb' => 'Øver i sitt eget arkiv for Sirevåg kommune.',
                'nn' => 'Øver i sitt eige arkiv for Sirevåg kommune.',
                'en' => 'Practises in their own archive for Sirevåg municipality.',
            ],
        ],
        [
            'navn' => 'laerer',
            'tekst' => ['nb' => 'Lærer', 'nn' => 'Lærar', 'en' => 'Teacher'],
            'beskrivelse' => [
                'nb' => 'Oppretter klasser og følger studentene.',
                'nn' => 'Opprettar klassar og følgjer studentane.',
                'en' => 'Creates classes and follows the students.',
            ],
        ],
        [
            'navn' => 'administrator',
            'tekst' => ['nb' => 'Administrator', 'nn' => 'Administrator', 'en' => 'Administrator'],
            'beskrivelse' => [
                'nb' => 'Styrer brukere, roller og godkjente e-postdomener.',
                'nn' => 'Styrer brukarar, roller og godkjende e-postdomene.',
                'en' => 'Manages users, roles and approved email domains.',
            ],
        ],
    ],
    'migreringer' => __DIR__ . '/migrasjoner',
    'tabellprefikser' => ['arkivar_'],
    'i18n' => __DIR__ . '/i18n',
    'pakke' => ['repo' => 'magnehg/ark-ivar', 'tagprefiks' => 'ark-ivar-v', 'mappe' => 'arkivar/'],
    'kjerne_krav' => '^0.2',
    'okt' => ['inaktiv_minutter' => 120, 'maks_timer' => 12],
    'logg' => ['oppbevaring_dager' => 365],
];
