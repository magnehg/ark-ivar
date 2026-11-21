// En liste med kontrolloppgaver: dokumenter til journalføring (inngående, interne eller utgående), eller mapper til
// kontroll eller til avslutning. Hver rad har handlingen som løser oppgaven, og telleren i menyen følger med.
import { ReactNode, useState } from 'react';
import { Link as Ruterlenke, Navigate, useParams } from 'react-router-dom';
import { Button } from '@fluentui/react-components';
import { api } from '../../api';
import { Journalpost, Sak, del, useStruktur } from '../../arkiv';
import { useDato, useKode, useT } from '../../i18n';
import { Journalpostfiler, Mappefiler } from '../../komponenter/Dokumentfiler';
import { Feil, Hentefeil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { KONTROLLISTER, Kontrolliste, useKontrolltall } from '../../komponenter/Kontrolloppgaver';
import { Oversikt, Teller } from '../../komponenter/Oversikt';
import { Status } from '../../komponenter/Status';
import { Kolonne } from '../../komponenter/Tabell';
import { useJournalpostvisning, useMappekolonner, useMappevisning } from './visninger';

const erListe = (v: string): v is Kontrolliste => (KONTROLLISTER as readonly string[]).includes(v);

export default function Kontroll() {
  const { liste = '' } = useParams();
  if (!erListe(liste)) return <Navigate to="/arkiv/kontroll/inngaende" replace />;
  // Nøkkelen gir en ny liste (og ny sortering) når studenten bytter liste i menyen.
  return liste.startsWith('saker-') ? <Mapper key={liste} liste={liste} /> : <Dokumenter key={liste} liste={liste} />;
}

/** Hodet på siden og tilstanden rundt handlingene: feilen fra den siste, og om en handling går nå. */
function useHandling(etter: () => void) {
  const feiltekst = useFeiltekst(); const { oppdater } = useKontrolltall();
  const [feil, settFeil] = useState<string | null>(null);
  const [opptatt, settOpptatt] = useState(false);
  const utfor = (sti: string) => {
    settFeil(null); settOpptatt(true);
    api.post(sti).then(() => { etter(); oppdater(); }).catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  return { feil, opptatt, utfor };
}

function Ramme({ liste, feil, children }: { liste: Kontrolliste; feil: string | null; children: ReactNode }) {
  const t = useT();
  const nokkel = liste.replace('-', '_');
  return (
    <>
      <Sidehode tittel={t(`arkivar.kontroll.side.${nokkel}`)} ingress={t(`arkivar.kontroll.ingress.${nokkel}`)} />
      {feil && <Feil melding={feil} />}
      <Kort id="kontrolliste">{children}</Kort>
    </>
  );
}

function Dokumenter({ liste }: { liste: Kontrolliste }) {
  const t = useT(); const dato = useDato(); const kode = useKode();
  const hentet = useHent<{ journalposter: Journalpost[] }>(`/api/arkiv/kontroll/journalposter/${del(liste)}`);
  const handling = useHandling(hentet.last);
  const struktur = useStruktur();
  const tilgangsnavn = (k: string) => struktur.data?.tilgangskoder.find((x) => x.kode === k)?.navn ?? k;
  const tilgang = (r: Journalpost) => (r.tilgangskode ? <Status slag="skjermet"><span lang="nb">{tilgangsnavn(r.tilgangskode)}</span></Status> : t('arkivar.tilgang.offentlig'));
  const knapp = (r: Journalpost) => <Button size="small" appearance="primary" disabled={handling.opptatt} onClick={() => handling.utfor(`/api/arkiv/journalposter/${del(r.id)}/journalfor`)}>{t('arkivar.kontroll.journalfor')}</Button>;
  const visninger = useJournalpostvisning({ tilgang, handling: knapp });
  const kolonner: Kolonne<Journalpost>[] = [
    { id: 'nummer', overskrift: t('arkivar.journalpost.nr'), stil: { width: '108px' }, celle: (r) => <Ruterlenke to={`/arkiv/saker/${r.sak_id}`}>{r.saksnummer}-{r.postnr}</Ruterlenke>, sorter: (r) => `${r.saksnummer ?? ''}-${r.postnr}` },
    { id: 'type', overskrift: t('arkivar.journalpost.type'), stil: { width: '60px' }, celle: (r) => <abbr title={t(`arkivar.jptype.${r.type.toLowerCase()}`)}>{r.type}</abbr>, sorter: (r) => r.type },
    { id: 'tittel', overskrift: t('arkivar.journalpost.tittel'), sprak: 'nb', celle: (r) => <Ruterlenke to={`/arkiv/saker/${r.sak_id}/journalposter/${r.id}`}>{r.tittel}</Ruterlenke>, sorter: (r) => r.tittel },
    { id: 'part', overskrift: t('arkivar.journalpost.part'), stil: { width: '16%' }, sprak: 'nb', celle: (r) => r.part_navn, sorter: (r) => r.part_navn },
    { id: 'dato', overskrift: t('arkivar.journalpost.dokumentdato'), stil: { width: '110px' }, celle: (r) => dato(r.dokumentdato), sorter: (r) => r.dokumentdato },
    { id: 'status', overskrift: t('arkivar.journalpost.status'), stil: { width: '64px' }, celle: (r) => <abbr title={kode('jpstatus', r.status)}>{r.status}</abbr>, sorter: (r) => r.status },
    { id: 'handling', overskrift: t('arkivar.kontroll.handling'), stil: { width: '130px' }, celle: knapp },
  ];
  const rader = hentet.data?.journalposter ?? [];
  return (
    <Ramme liste={liste} feil={handling.feil}>
      {hentet.feil && !hentet.data ? <Hentefeil melding={hentet.feil} last={hentet.last} /> : !hentet.data ? <Laster /> : (
        <Oversikt rader={rader} kolonner={kolonner} visninger={visninger} nokkel={(r) => r.id} aria-label={t(`arkivar.kontroll.side.${liste}`)}
          forst={<Teller>{t('arkivar.kontroll.antall', { antall: rader.length })}</Teller>} startSortering={{ id: 'dato', retning: 'ascending' }}
          utvid={{ innhold: (r) => <Journalpostfiler post={r} />, vis: t('arkivar.filer.vis'), skjul: t('arkivar.filer.skjul'), beskrevetAv: 'tittel', kan: (r) => r.dokumenter.length > 0 }} />
      )}
    </Ramme>
  );
}

function Mapper({ liste }: { liste: Kontrolliste }) {
  const t = useT();
  const hentet = useHent<{ saker: Sak[] }>('/api/arkiv/saker');
  const handling = useHandling(hentet.last);
  const struktur = useStruktur();
  const kontroll = liste === 'saker-kontroll';
  const rader = (hentet.data?.saker ?? []).filter((m) => m.status === (kontroll ? 'R' : 'S'));
  const knapp = (m: Sak) => (
    <Button size="small" appearance="primary" disabled={handling.opptatt} onClick={() => handling.utfor(`/api/arkiv/saker/${del(m.id)}/${kontroll ? 'kontroller' : 'avslutt'}`)}>
      {t(kontroll ? 'arkivar.kontroll.kontroller' : 'arkivar.kontroll.avslutt')}
    </Button>
  );
  const mappevalg = { sak: (m: Sak) => m, lenke: (m: Sak) => `/arkiv/saker/${m.id}`, enhetsnavn: (k: string) => struktur.data?.enheter.find((e) => e.kode === k)?.navn ?? k };
  const kolonner: Kolonne<Sak>[] = [...useMappekolonner<Sak>(mappevalg), { id: 'handling', overskrift: t('arkivar.kontroll.handling'), stil: { width: '170px' }, celle: knapp }];
  const visninger = useMappevisning<Sak>({ ...mappevalg, handling: knapp });
  const nokkel = liste.replace('-', '_');
  return (
    <Ramme liste={liste} feil={handling.feil}>
      {hentet.feil && !hentet.data ? <Hentefeil melding={hentet.feil} last={hentet.last} /> : !hentet.data ? <Laster /> : (
        <Oversikt rader={rader} kolonner={kolonner} visninger={visninger} nokkel={(m) => m.id} aria-label={t(`arkivar.kontroll.side.${nokkel}`)}
          forst={<Teller>{t('arkivar.kontroll.antall', { antall: rader.length })}</Teller>} startSortering={{ id: 'dato', retning: 'ascending' }}
          utvid={{ innhold: (m) => <Mappefiler sak={m.id} />, vis: t('arkivar.filer.vis'), skjul: t('arkivar.filer.skjul'), beskrevetAv: 'tittel', kan: (m) => m.antall > 0 }} />
      )}
    </Ramme>
  );
}
