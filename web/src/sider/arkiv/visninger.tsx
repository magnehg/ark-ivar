// Radene i oversiktene når de ikke vises i kolonner: «tittel først» (tittelen, så saken, så resten på én dempet
// linje) og «to linjer» (type eller mappeikon, nummer, tittel og dato på første linje, resten på den andre).
// Brukes av mappeoversikten, dokumentene i en mappe, journalen og søketreffene.
import { ReactNode } from 'react';
import { Link as Ruterlenke } from 'react-router-dom';
import { makeStyles, tokens } from '@fluentui/react-components';
import { Journalpost, Sak } from '../../arkiv';
import { useDato, useSprak, useT } from '../../i18n';
import { Kolonne } from '../../komponenter/Tabell';
import { Dokumenttittel } from '../../komponenter/Dokumentfiler';
import { Mappeikon, Status } from '../../komponenter/Status';

const s = makeStyles({
  // To linjer: [type eller ikon] [nummer] [tittel og linje to] [dato]
  linje: { display: 'grid', gridTemplateColumns: '34px 124px minmax(0, 1fr) 92px', columnGap: '10px', alignItems: 'start' },
  mappelinje: { display: 'grid', gridTemplateColumns: '124px minmax(0, 1fr) 92px', columnGap: '10px', alignItems: 'start' },
  type: {
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: '26px', height: '24px', border: `1.5px solid ${tokens.colorNeutralForeground2}`, borderRadius: '3px',
    fontWeight: tokens.fontWeightSemibold, textDecorationLine: 'none', cursor: 'help',
  },
  nummer: { display: 'flex', flexDirection: 'column', fontVariantNumeric: 'tabular-nums', lineHeight: tokens.lineHeightBase300 },
  lopenr: { color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
  tittel: { fontWeight: tokens.fontWeightSemibold, minWidth: 0, overflowWrap: 'anywhere' },
  stor: { fontSize: tokens.fontSizeBase400 },
  dato: { textAlign: 'right', fontVariantNumeric: 'tabular-nums', whiteSpace: 'nowrap' },
  under: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, fontWeight: tokens.fontWeightRegular, marginTop: '2px' },
  meta: { display: 'flex', flexWrap: 'wrap', gap: '2px 16px', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, marginTop: '4px', fontVariantNumeric: 'tabular-nums' },
  verdi: { color: tokens.colorNeutralForeground1 },
  sak: { display: 'block', marginTop: '2px' },
  ekstra: { marginTop: '4px' },
  skjult: { position: 'absolute', width: '1px', height: '1px', overflow: 'hidden', clipPath: 'inset(50%)', whiteSpace: 'nowrap' },
  liten: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
  hode: { display: 'flex', gap: '12px', alignItems: 'flex-start', justifyContent: 'space-between' },
});

/** Løpenummeret i kortform, slik det står under saks- og dokumentnummeret: «3490/2026» blir «(3490/26)». */
export const kortLopenummer = (lopenummer: string): string => {
  const [nr, aar = ''] = lopenummer.split('/');
  return `(${nr}/${aar.slice(-2)})`;
};

type Postvalg = {
  /** Mappen radene ligger i, når oversikten er dokumentene i én mappe (radene har da ikke saksnummeret selv). */
  sak?: Sak;
  /** Tilgangen slik oversikten viser den (kort navn i en mappe, hele hjemmelen i journalen). */
  tilgang: (rad: Journalpost) => ReactNode;
};

/** Radene for journalposter i visningene «tittel først» og «to linjer». */
export function useJournalpostvisning({ sak, tilgang }: Postvalg) {
  const c = s(); const t = useT(); const dato = useDato(); const { sprak } = useSprak();
  const saksnummer = (r: Journalpost) => `${sak?.saksnummer ?? r.saksnummer ?? ''}-${r.postnr}`;
  const sakstittel = (r: Journalpost) => sak?.tittel ?? r.sakstittel ?? '';
  const sakId = (r: Journalpost) => sak?.id ?? r.sak_id;
  const lenke = (r: Journalpost) => <Ruterlenke to={`/arkiv/saker/${sakId(r)}/journalposter/${r.id}`} lang="nb">{r.tittel}</Ruterlenke>;
  const type = (r: Journalpost) => <abbr className={c.type} title={t(`arkivar.jptype.${r.type.toLowerCase()}`)}>{r.type}</abbr>;
  // «Til» for utgående dokumenter, «Fra» for inngående og interne.
  const part = (r: Journalpost) => <>{t(r.type === 'U' ? 'arkivar.part.til' : 'arkivar.part.fra')} <span className={c.verdi} lang="nb">{r.part_navn}</span></>;
  const felt = (ledetekst: string, verdi: ReactNode, norsk = false) => <span>{ledetekst} <span className={c.verdi} lang={norsk ? 'nb' : undefined}>{verdi}</span></span>;
  return {
    tittel: (r: Journalpost) => (
      <div lang={sprak}>
        <span className={`${c.tittel} ${c.stor}`}><Dokumenttittel filer={r.filer}>{type(r)} {lenke(r)}</Dokumenttittel></span>
        {/* I journalen står saken under tittelen. I en mappe er saken overskriften på siden. */}
        {!sak && <span className={c.sak}>{t('arkivar.journal.sak')} <Ruterlenke to={`/arkiv/saker/${r.sak_id}`}>{saksnummer(r)}</Ruterlenke> <span lang="nb">{sakstittel(r)}</span></span>}
        <div className={c.meta}>
          <span>{part(r)}</span>
          {sak && felt(t('arkivar.journalpost.nr'), saksnummer(r))}
          {felt(t('arkivar.journal.lopenummer'), r.lopenummer)}
          {felt(t('arkivar.journal.journaldato'), dato(r.journaldato))}
          {felt(t('arkivar.journalpost.dokumentdato'), dato(r.dokumentdato))}
          {!sak && felt(t('arkivar.journal.klassering'), r.klasse_tekst ?? '', true)}
          {felt(t('arkivar.visning.saksbehandler'), `${r.enhet} / ${r.saksbehandler_navn}`, true)}
          <span>{t('arkivar.visning.tilgang')} <span className={c.verdi}>{tilgang(r)}</span></span>
        </div>
      </div>
    ),
    linjer: (r: Journalpost) => (
      <div className={c.linje} lang={sprak}>
        <span>{type(r)}</span>
        <span className={c.nummer}>
          {sak ? saksnummer(r) : <Ruterlenke to={`/arkiv/saker/${r.sak_id}`}>{saksnummer(r)}</Ruterlenke>}
          <span className={c.lopenr}>{kortLopenummer(r.lopenummer)}</span>
        </span>
        <span className={c.tittel}>
          <Dokumenttittel filer={r.filer}>{lenke(r)}</Dokumenttittel>
          <span className={c.under}>
            {part(r)}{!sak && <> · {t('arkivar.journal.sak')} <span className={c.verdi} lang="nb">{sakstittel(r)}</span></>} · <span className={c.verdi}>{tilgang(r)}</span>
          </span>
        </span>
        <span className={c.dato}>{dato(r.dokumentdato)}</span>
      </div>
    ),
  };
}

type Mappevalg<T> = {
  sak: (rad: T) => Sak;
  lenke: (sak: Sak) => string;
  enhetsnavn: (kode: string) => string;
  /** Det som står til høyre for mappen, for eksempel knappen som bruker den (søketreffene). */
  handling?: (rad: T) => ReactNode;
  /** Det som står under mappen, for eksempel dokumentene som ga treff i søket. */
  ekstra?: (rad: T) => ReactNode;
};

/** Radene for mapper i visningene «tittel først» og «to linjer». */
export function useMappevisning<T>({ sak, lenke, enhetsnavn, handling, ekstra }: Mappevalg<T>) {
  const c = s(); const t = useT(); const dato = useDato(); const { sprak } = useSprak();
  const felt = (ledetekst: string, verdi: ReactNode, norsk = false) => <span>{ledetekst} <span className={c.verdi} lang={norsk ? 'nb' : undefined}>{verdi}</span></span>;
  const status = (m: Sak) => `${m.status} – ${t(`arkivar.saksstatus.${m.status.toLowerCase()}`)}`;
  const klassering = (m: Sak) => <><span lang="nb">{m.klasse_tekst} · {m.arkivdel_tekst}</span>{m.skjerm_klasse && <> <Status slag="skjermet">{t('arkivar.sak.skjermet_klasse')}</Status></>}</>;
  const til = (m: Sak) => <Ruterlenke to={lenke(m)} lang="nb">{m.tittel}</Ruterlenke>;
  return {
    tittel: (r: T) => {
      const m = sak(r);
      return (
        <div lang={sprak}>
          <div className={c.hode}>
            <span className={`${c.tittel} ${c.stor}`}>{til(m)}</span>
            {handling?.(r)}
          </div>
          <div className={c.meta}>
            <Mappeikon status={m.status}><span className={c.verdi}>{m.saksnummer}</span></Mappeikon>
            {felt(t('arkivar.sak.enhet.kort'), enhetsnavn(m.enhet), true)}
            {felt(t('arkivar.sak.ansvarlig'), m.saksansvarlig_navn, true)}
            {felt(t('arkivar.sak.dato'), dato(m.saksdato))}
            {felt(t('arkivar.sak.status'), status(m))}
            {felt(t('arkivar.sak.antall'), m.antall)}
            <span>{t('arkivar.sak.primaer')} <span className={c.verdi}>{klassering(m)}</span></span>
          </div>
          {ekstra && <div className={c.ekstra}>{ekstra(r)}</div>}
        </div>
      );
    },
    linjer: (r: T) => {
      const m = sak(r);
      return (
        <div lang={sprak}>
          <div className={c.mappelinje}>
            <Mappeikon status={m.status}>{m.saksnummer}</Mappeikon>
            <span className={c.tittel}>
              <span className={c.hode}>{til(m)}{handling?.(r)}</span>
              <span className={c.under}>
                <span className={c.verdi} lang="nb">{m.enhet} / {m.saksansvarlig_navn}</span> · <span className={c.verdi}>{klassering(m)}</span> · {status(m)} · {t('arkivar.sok.dokumenter', { antall: m.antall })}
              </span>
            </span>
            <span className={c.dato}>{dato(m.saksdato)}</span>
          </div>
          {ekstra && <div className={c.ekstra}>{ekstra(r)}</div>}
        </div>
      );
    },
  };
}

/** Saksnummeret som tekst til sortering: 2026/7 → 002026/000007. */
const sorterbart = (nummer: string) => nummer.split('/').map((del) => del.padStart(6, '0')).join('/');

/**
 * Kolonnene for mapper: saksnummeret med mappeikonet og tittelen først, så enheten og saksansvarlig, og
 * klasseringen sist med arkivdelen under (arkivdelen sier hva slags klassering det er).
 */
export function useMappekolonner<T>({ sak, lenke, enhetsnavn, handling, ekstra }: Mappevalg<T>): Kolonne<T>[] {
  const c = s(); const t = useT(); const dato = useDato(); const { sprak } = useSprak();
  const kolonner: Kolonne<T>[] = [
    { id: 'nummer', overskrift: t('arkivar.sak.nummer'), stil: { width: '108px' }, celle: (r) => <Mappeikon status={sak(r).status}>{sak(r).saksnummer}</Mappeikon>, sorter: (r) => sorterbart(sak(r).saksnummer) },
    { id: 'tittel', overskrift: t('arkivar.sak.tittel'), celle: (r) => <><Ruterlenke to={lenke(sak(r))} lang="nb">{sak(r).tittel}</Ruterlenke>{ekstra && <div className={c.ekstra} lang={sprak}>{ekstra(r)}</div>}</>, sorter: (r) => sak(r).tittel },
    { id: 'enhet', overskrift: t('arkivar.sak.enhet.kort'), stil: { width: '64px' }, sprak: 'nb', celle: (r) => <abbr title={enhetsnavn(sak(r).enhet)}>{sak(r).enhet}</abbr>, sorter: (r) => sak(r).enhet },
    { id: 'ansvarlig', overskrift: t('arkivar.sak.ansvarlig'), stil: { width: '120px' }, sprak: 'nb', celle: (r) => sak(r).saksansvarlig_navn, sorter: (r) => sak(r).saksansvarlig_navn },
    { id: 'dato', overskrift: t('arkivar.sak.dato'), stil: { width: '96px' }, celle: (r) => dato(sak(r).saksdato), sorter: (r) => sak(r).saksdato },
    { id: 'status', overskrift: t('arkivar.sak.status'), stil: { width: '64px' }, celle: (r) => <abbr title={t(`arkivar.saksstatus.${sak(r).status.toLowerCase()}`)}>{sak(r).status}</abbr>, sorter: (r) => sak(r).status },
    { id: 'antall', overskrift: t('arkivar.sak.antall'), stil: { width: '92px' }, celle: (r) => sak(r).antall, sorter: (r) => sak(r).antall },
    { id: 'klasse', overskrift: t('arkivar.sak.primaer'), stil: { width: handling ? '180px' : '230px' }, sprak: 'nb', celle: (r) => (<>{sak(r).klasse_tekst}<span className={c.liten}>{sak(r).arkivdel_tekst}</span></>), sorter: (r) => `${sak(r).arkivdel_tekst} ${sak(r).klasse_tekst}` },
  ];
  if (handling) kolonner.push({ id: 'handling', overskrift: <span className={c.skjult}>{t('arkivar.visning.handling')}</span>, stil: { width: '190px' }, celle: (r) => handling(r) });
  return kolonner;
}
