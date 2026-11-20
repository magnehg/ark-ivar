// Sakene i arkivet, og én sak med journalpostene.
import { Link as Ruterlenke, useParams } from 'react-router-dom';
import { Text, makeStyles, tokens } from '@fluentui/react-components';
import { Journalpost, Sak } from '../../arkiv';
import { useDato, useT } from '../../i18n';
import { Feil, Kort, Laster, Sidehode, useHent } from '../../komponenter/Felles';
import { Status } from '../../komponenter/Status';
import { Kolonne, SorterbarTabell, TabellVerktoy } from '../../komponenter/Tabell';

const s = makeStyles({
  fakta: { display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 16px', margin: 0, '& dt': { color: tokens.colorNeutralForeground2 }, '& dd': { margin: 0 } },
  innhold: { color: tokens.colorNeutralForeground2, display: 'block', marginTop: '2px', fontSize: tokens.fontSizeBase200 },
});

/** Saksnummeret som tall til sortering: 2026/7 → 2026 0007. */
const sorterbart = (nummer: string) => nummer.split('/').map((del) => del.padStart(6, '0')).join('/');

export function Saker() {
  const t = useT(); const dato = useDato();
  const liste = useHent<{ saker: Sak[] }>('/api/arkiv/saker');
  const rader = liste.data?.saker ?? [];
  const kolonner: Kolonne<Sak>[] = [
    { id: 'nummer', overskrift: t('arkivar.sak.nummer'), stil: { width: '90px' }, celle: (r) => r.saksnummer, sorter: (r) => sorterbart(r.saksnummer) },
    { id: 'tittel', overskrift: t('arkivar.sak.tittel'), celle: (r) => (<Ruterlenke to={`/arkiv/saker/${r.id}`}>{r.tittel}</Ruterlenke>), sorter: (r) => r.tittel },
    { id: 'klasse', overskrift: t('arkivar.sak.klasse'), stil: { width: '20%' }, celle: (r) => r.klasse_tekst, sorter: (r) => r.klasse },
    { id: 'ansvarlig', overskrift: t('arkivar.sak.ansvarlig'), stil: { width: '150px' }, celle: (r) => r.saksansvarlig_navn, sorter: (r) => r.saksansvarlig_navn },
    { id: 'dato', overskrift: t('arkivar.sak.dato'), stil: { width: '110px' }, celle: (r) => dato(r.saksdato), sorter: (r) => r.saksdato },
    { id: 'antall', overskrift: t('arkivar.sak.antall'), stil: { width: '110px' }, celle: (r) => r.antall, sorter: (r) => r.antall },
  ];
  return (
    <>
      <Sidehode tittel={t('arkivar.saker.tittel')} ingress={t('arkivar.saker.ingress')} />
      <Kort id="saksliste">
        {liste.laster && !liste.data ? <Laster /> : liste.feil ? <Feil melding={liste.feil} /> : (
          <>
            <TabellVerktoy tekst={t('arkivar.saker.antall', { antall: rader.length })}>{null}</TabellVerktoy>
            <SorterbarTabell rader={rader} kolonner={kolonner} nokkel={(r) => r.id} aria-label={t('arkivar.saker.tittel')} startSortering={{ id: 'nummer', retning: 'descending' }} />
          </>
        )}
      </Kort>
    </>
  );
}

export function EnSak() {
  const c = s(); const t = useT(); const dato = useDato();
  const { id = '' } = useParams();
  const sak = useHent<{ sak: Sak; journalposter: Journalpost[] }>(`/api/arkiv/saker/${id}`);
  if (sak.feil) return <Feil melding={sak.feil} />;
  if (!sak.data) return <Laster />;
  const d = sak.data.sak;
  const tilgang = (r: Journalpost) => (r.tilgangskode ? <Status slag="skjermet">{r.hjemmel}</Status> : t('arkivar.tilgang.offentlig'));
  const kolonner: Kolonne<Journalpost>[] = [
    { id: 'nr', overskrift: t('arkivar.journalpost.nr'), stil: { width: '70px' }, celle: (r) => r.postnr, sorter: (r) => r.postnr },
    { id: 'type', overskrift: t('arkivar.journalpost.type'), stil: { width: '70px' }, celle: (r) => (<abbr title={t(`arkivar.jptype.${r.type.toLowerCase()}`)}>{r.type}</abbr>), sorter: (r) => r.type },
    { id: 'tittel', overskrift: t('arkivar.journalpost.tittel'), celle: (r) => (<><Text block>{r.tittel}</Text>{r.innhold && <span className={c.innhold}>{r.innhold}</span>}</>), sorter: (r) => r.tittel },
    { id: 'part', overskrift: t('arkivar.journalpost.part'), stil: { width: '15%' }, celle: (r) => r.part_navn, sorter: (r) => r.part_navn },
    { id: 'dato', overskrift: t('arkivar.journalpost.dokumentdato'), stil: { width: '110px' }, celle: (r) => dato(r.dokumentdato), sorter: (r) => r.dokumentdato },
    { id: 'status', overskrift: t('arkivar.journalpost.status'), stil: { width: '120px' }, celle: (r) => t(r.journalfort ? `arkivar.jpstatus.${r.status.toLowerCase()}` : 'arkivar.jpstatus.uten'), sorter: (r) => r.status },
    { id: 'tilgang', overskrift: t('arkivar.journalpost.tilgang'), stil: { width: '16%' }, celle: tilgang, sorter: (r) => r.tilgangskode ?? '' },
  ];
  return (
    <>
      <Sidehode tittel={`${d.saksnummer} ${d.tittel}`} />
      <Kort tittel={t('arkivar.sak.opplysninger')} id="sak-opplysninger">
        <dl className={c.fakta}>
          <dt>{t('arkivar.sak.arkivdel')}</dt><dd>{d.arkivdel_tekst}</dd>
          <dt>{t('arkivar.sak.klasse')}</dt><dd>{d.klasse_tekst}</dd>
          <dt>{t('arkivar.sak.ansvarlig')}</dt><dd>{d.saksansvarlig_navn} ({d.enhet})</dd>
          <dt>{t('arkivar.sak.dato')}</dt><dd>{dato(d.saksdato)}</dd>
          <dt>{t('arkivar.sak.status')}</dt><dd>{t(`arkivar.saksstatus.${d.status.toLowerCase()}`)}</dd>
          {d.skjerm_tittel && <><dt>{t('arkivar.journalpost.offentlig_tittel')}</dt><dd>{d.offentlig_tittel}</dd></>}
        </dl>
      </Kort>
      <Kort tittel={t('arkivar.sak.journalposter')} id="sak-journalposter">
        <SorterbarTabell rader={sak.data.journalposter} kolonner={kolonner} nokkel={(r) => r.id} aria-labelledby="sak-journalposter" startSortering={{ id: 'nr', retning: 'ascending' }} />
      </Kort>
      <Ruterlenke to="/arkiv/saker">{t('arkivar.sak.tilbake')}</Ruterlenke>
    </>
  );
}
