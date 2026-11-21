// En liste med kontrolloppgaver: dokumenter til journalføring (inngående, interne eller utgående), restanser
// (inngående dokumenter uten avskrivning), eller mapper til kontroll eller til avslutning. Hver rad har hovedknappen
// som løser oppgaven og menyen med resten, og telleren i menyen følger med. Mappene til avslutning vises med
// sjekklisten: det som mangler før mappen kan avsluttes.
import { ReactNode, useState } from 'react';
import { Link as Ruterlenke, Navigate, useParams } from 'react-router-dom';
import { Text, makeStyles, tokens } from '@fluentui/react-components';
import { Journalpost, Sak, del, useStruktur } from '../../arkiv';
import { useDato, useKode, useSprak, useT } from '../../i18n';
import { Avskrivningsmerke, dokumentnummer } from '../../komponenter/Avskrivning';
import { Journalpostfiler, Mappefiler } from '../../komponenter/Dokumentfiler';
import { Hentefeil, Kort, Laster, Ok, Sidehode, useHent } from '../../komponenter/Felles';
import { useJournalposthandlinger } from '../../komponenter/Journalposthandlinger';
import { KONTROLLISTER, Kontrolliste } from '../../komponenter/Kontrolloppgaver';
import { useMappehandlinger } from '../../komponenter/Mappehandlinger';
import { Oversikt, Teller } from '../../komponenter/Oversikt';
import { Sjekkliste } from '../../komponenter/Sjekkliste';
import { Mappeikon, Status } from '../../komponenter/Status';
import { Kolonne } from '../../komponenter/Tabell';
import { useJournalpostvisning, useMappekolonner, useMappevisning } from './visninger';

const s = makeStyles({
  mappetittel: { display: 'flex', gap: '8px', alignItems: 'baseline', flexWrap: 'wrap', fontWeight: tokens.fontWeightSemibold },
  meta: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, margin: '2px 0 12px' },
  kvittering: { marginBottom: '12px' },
});

const erListe = (v: string): v is Kontrolliste => (KONTROLLISTER as readonly string[]).includes(v);

export default function Kontroll() {
  const { liste = '' } = useParams();
  if (!erListe(liste)) return <Navigate to="/arkiv/kontroll/inngaende" replace />;
  // Nøkkelen gir en ny liste (og ny sortering) når studenten bytter liste i menyen.
  if (liste === 'saker-avslutning') return <Avslutning key={liste} />;
  return liste === 'saker-kontroll' ? <Mapper key={liste} /> : <Dokumenter key={liste} liste={liste} />;
}

function Ramme({ liste, flater, children }: { liste: Kontrolliste; flater?: ReactNode; children: ReactNode }) {
  const t = useT();
  const nokkel = liste.replace('-', '_');
  return (
    <>
      <Sidehode tittel={t(`arkivar.kontroll.side.${nokkel}`)} ingress={t(`arkivar.kontroll.ingress.${nokkel}`)} />
      {flater}
      {children}
    </>
  );
}

/** Dokumentene til journalføring i en gruppe, eller restansene. */
function Dokumenter({ liste }: { liste: Kontrolliste }) {
  const t = useT(); const dato = useDato(); const kode = useKode();
  const restanser = liste === 'restanser';
  const hentet = useHent<{ journalposter: Journalpost[] }>(restanser ? '/api/arkiv/kontroll/restanser' : `/api/arkiv/kontroll/journalposter/${del(liste)}`);
  const handlinger = useJournalposthandlinger({ etter: hentet.last });
  const struktur = useStruktur();
  const tilgangsnavn = (k: string) => struktur.data?.tilgangskoder.find((x) => x.kode === k)?.navn ?? k;
  const tilgang = (r: Journalpost) => (r.tilgangskode ? <Status slag="skjermet"><span lang="nb">{tilgangsnavn(r.tilgangskode)}</span></Status> : t('arkivar.tilgang.offentlig'));
  const visninger = useJournalpostvisning({ tilgang, handling: handlinger.begge });
  const kolonner: Kolonne<Journalpost>[] = [
    { id: 'nummer', overskrift: t('arkivar.journalpost.nr'), stil: { width: '108px' }, celle: (r) => <Ruterlenke to={`/arkiv/saker/${r.sak_id}`}>{dokumentnummer(r.saksnummer, r.postnr)}</Ruterlenke>, sorter: (r) => `${r.saksnummer ?? ''}-${r.postnr}` },
    { id: 'type', overskrift: t('arkivar.journalpost.type'), stil: { width: '60px' }, celle: (r) => <abbr title={t(`arkivar.jptype.${r.type.toLowerCase()}`)}>{r.type}</abbr>, sorter: (r) => r.type },
    { id: 'tittel', overskrift: t('arkivar.journalpost.tittel'), sprak: 'nb', celle: (r) => <Ruterlenke to={`/arkiv/saker/${r.sak_id}/journalposter/${r.id}`}>{r.tittel}</Ruterlenke>, sorter: (r) => r.tittel },
    { id: 'part', overskrift: t('arkivar.journalpost.part'), stil: { width: '16%' }, sprak: 'nb', celle: (r) => r.part_navn, sorter: (r) => r.part_navn },
    // Restansene regnes fra journaldatoen. Det som står til journalføring, er ikke journalført ennå.
    restanser
      ? { id: 'dato', overskrift: t('arkivar.journal.journaldato'), stil: { width: '110px' }, celle: (r) => dato(r.journaldato), sorter: (r) => r.journaldato }
      : { id: 'dato', overskrift: t('arkivar.journalpost.dokumentdato'), stil: { width: '110px' }, celle: (r) => dato(r.dokumentdato), sorter: (r) => r.dokumentdato },
    restanser
      ? { id: 'status', overskrift: t('arkivar.avskrivning.kolonne'), stil: { width: '170px' }, celle: (r) => <Avskrivningsmerke post={r} />, sorter: (r) => r.restanse_dager ?? 0 }
      : { id: 'status', overskrift: t('arkivar.journalpost.status'), stil: { width: '64px' }, celle: (r) => <abbr title={kode('jpstatus', r.status)}>{r.status}</abbr>, sorter: (r) => r.status },
    { id: 'handling', overskrift: t('arkivar.kontroll.handling'), stil: { width: '170px' }, celle: handlinger.begge },
  ];
  const rader = hentet.data?.journalposter ?? [];
  return (
    <Ramme liste={liste} flater={handlinger.flater}>
      <Kort id="kontrolliste">
        {hentet.feil && !hentet.data ? <Hentefeil melding={hentet.feil} last={hentet.last} /> : !hentet.data ? <Laster /> : (
          <Oversikt rader={rader} kolonner={kolonner} visninger={visninger} nokkel={(r) => r.id} aria-label={t(`arkivar.kontroll.side.${liste}`)}
            forst={<Teller>{t('arkivar.kontroll.antall', { antall: rader.length })}</Teller>} startSortering={{ id: 'dato', retning: 'ascending' }}
            utvid={{ innhold: (r) => <Journalpostfiler post={r} />, vis: t('arkivar.filer.vis'), skjul: t('arkivar.filer.skjul'), beskrevetAv: 'tittel', kan: (r) => r.dokumenter.length > 0 }} />
        )}
      </Kort>
    </Ramme>
  );
}

/** Mappene saksbehandleren har opprettet (R), som arkivtjenesten kontrollerer og setter under behandling. */
function Mapper() {
  const t = useT();
  const hentet = useHent<{ saker: Sak[] }>('/api/arkiv/saker');
  const mappe = useMappehandlinger({ etter: hentet.last });
  const struktur = useStruktur();
  const rader = (hentet.data?.saker ?? []).filter((m) => m.status === 'R');
  const knapp = (m: Sak) => mappe.hovedknapp(m, 'small');
  const mappevalg = { sak: (m: Sak) => m, lenke: (m: Sak) => `/arkiv/saker/${m.id}`, enhetsnavn: (k: string) => struktur.data?.enheter.find((e) => e.kode === k)?.navn ?? k };
  const kolonner: Kolonne<Sak>[] = [...useMappekolonner<Sak>(mappevalg), { id: 'handling', overskrift: t('arkivar.kontroll.handling'), stil: { width: '170px' }, celle: knapp }];
  const visninger = useMappevisning<Sak>({ ...mappevalg, handling: knapp });
  return (
    <Ramme liste="saker-kontroll" flater={mappe.flater}>
      <Kort id="kontrolliste">
        {hentet.feil && !hentet.data ? <Hentefeil melding={hentet.feil} last={hentet.last} /> : !hentet.data ? <Laster /> : (
          <Oversikt rader={rader} kolonner={kolonner} visninger={visninger} nokkel={(m) => m.id} aria-label={t('arkivar.kontroll.side.saker_kontroll')}
            forst={<Teller>{t('arkivar.kontroll.antall', { antall: rader.length })}</Teller>} startSortering={{ id: 'dato', retning: 'ascending' }}
            utvid={{ innhold: (m) => <Mappefiler sak={m.id} />, vis: t('arkivar.filer.vis'), skjul: t('arkivar.filer.skjul'), beskrevetAv: 'tittel', kan: (m) => m.antall > 0 }} />
        )}
      </Kort>
    </Ramme>
  );
}

/**
 * Mappene som står til avslutning (S), hver med sjekklisten: det som mangler før mappen kan avsluttes, og knappen
 * «Avslutt mappen», som er sperret til listen er tom. Mappen kan også sendes tilbake til behandling.
 */
function Avslutning() {
  const c = s(); const t = useT(); const kode = useKode(); const { sprak } = useSprak();
  const hentet = useHent<{ saker: Sak[] }>('/api/arkiv/saker');
  const mappe = useMappehandlinger({ etter: hentet.last });
  // Kvitteringene for mappene som er avsluttet her. Mappen forsvinner fra listen, så kvitteringen står igjen øverst.
  const [avsluttet, settAvsluttet] = useState<{ saksnummer: string; antall: number }[]>([]);
  const rader = (hentet.data?.saker ?? []).filter((m) => m.status === 'S').sort((a, b) => a.saksdato.localeCompare(b.saksdato));
  return (
    <Ramme liste="saker-avslutning" flater={<>
      {mappe.flater}
      {avsluttet.map((a) => <div key={a.saksnummer} className={c.kvittering}><Ok melding={t('arkivar.sjekkliste.avsluttet', a)} /></div>)}
    </>}>
      {hentet.feil && !hentet.data ? <Hentefeil melding={hentet.feil} last={hentet.last} /> : !hentet.data ? <Laster /> : (
        <>
          <Teller>{t('arkivar.kontroll.antall', { antall: rader.length })}</Teller>
          {rader.map((m) => (
            <Kort key={m.id} id={`avslutning-${m.id}`} handlinger={mappe.hovedknapp(m, 'small')}
              tittel={<span className={c.mappetittel}><Mappeikon status={m.status}>{m.saksnummer}</Mappeikon><Ruterlenke to={`/arkiv/saker/${m.id}`} lang="nb">{m.tittel}</Ruterlenke></span>}>
              <Text className={c.meta} lang={sprak}>
                <span lang="nb">{m.klasse_tekst} · {m.enhet} / {m.saksansvarlig_navn}</span> · {kode('saksstatus', m.status)}
              </Text>
              <Sjekkliste sak={m} etter={hentet.last} avsluttet={(antall) => { settAvsluttet((gml) => [...gml, { saksnummer: m.saksnummer, antall }]); hentet.last(); }} />
            </Kort>
          ))}
        </>
      )}
    </Ramme>
  );
}
