// Arkivstrukturen til Sirevåg kommune: arkivdelene med ordningen sin, arkivnøkkelen, registrene mappene klassifiseres
// på, tilgangskodene, og enhetene med de ansatte.
import { makeStyles, tokens } from '@fluentui/react-components';
import { Arkivdel, Registerpost, Saksmal, Struktur as Strukturdata, useStruktur } from '../../arkiv';
import { useSprak, useT } from '../../i18n';
import { Hentefeil, Kort, Laster, Sidehode } from '../../komponenter/Felles';
import { Arkivnokkel } from './Arkivnokkel';
import { Kolonne, SorterbarTabell } from '../../komponenter/Tabell';

const s = makeStyles({
  dempet: { color: tokens.colorNeutralForeground2, margin: '0 0 8px', maxWidth: '72ch' },
});

type Tilgangskode = Strukturdata['tilgangskoder'][number];
type Ansatt = Strukturdata['ansatte'][number];

export default function Struktur() {
  const c = s(); const t = useT(); const { sprak } = useSprak();
  const { data: struktur, feil, last } = useStruktur();
  if (feil) return <Hentefeil melding={feil} last={last} />;
  if (!struktur) return <Laster />;
  const enhetsnavn = (kode: string) => struktur.enheter.find((e) => e.kode === kode)?.navn ?? kode;
  const arkivdeler: Kolonne<Arkivdel>[] = [
    { id: 'tittel', overskrift: t('arkivar.struktur.navn'), stil: { width: '18%' }, celle: (r) => r.tittel, sorter: (r) => r.tittel },
    { id: 'ordning', overskrift: t('arkivar.struktur.ordning'), stil: { width: '22%' }, celle: (r) => <span lang={sprak}>{t(`arkivar.ordning.${r.ordning}.navn`)}</span>, sorter: (r) => r.ordning },
    { id: 'mappe', overskrift: t('arkivar.struktur.mappe'), stil: { width: '22%' }, celle: (r) => r.mappe },
    { id: 'beskrivelse', overskrift: t('arkivar.struktur.beskrivelse'), celle: (r) => r.beskrivelse },
  ];
  // I personregistrene er klasseringen fødselsdato og navn. Fødselsnummeret står ved siden av.
  const register = (del: Arkivdel): Kolonne<Registerpost>[] => (del.ordning === 'person' ? [
    { id: 'tittel', overskrift: t(`arkivar.ordning.${del.ordning}.kode`), stil: { width: '28%' }, celle: (r) => r.kode, sorter: (r) => r.tittel },
    { id: 'fnr', overskrift: t('arkivar.struktur.fnr'), stil: { width: '190px' }, celle: (r) => r.fnr, sorter: (r) => r.fodt },
    { id: 'detalj', overskrift: t('arkivar.struktur.detalj'), celle: (r) => r.detalj, sorter: (r) => r.detalj },
  ] : [
    { id: 'kode', overskrift: t(`arkivar.ordning.${del.ordning}.kode`), stil: { width: '160px' }, celle: (r) => r.kode, sorter: (r) => r.kode },
    { id: 'tittel', overskrift: t('arkivar.struktur.navn'), stil: { width: '30%' }, celle: (r) => r.tittel, sorter: (r) => r.tittel },
    { id: 'detalj', overskrift: t('arkivar.struktur.detalj'), celle: (r) => r.detalj, sorter: (r) => r.detalj },
  ]);
  const arkivdelnavn = (kode: string) => struktur.arkivdeler.find((a) => a.kode === kode)?.tittel ?? kode;
  const saksmaler: Kolonne<Saksmal>[] = [
    { id: 'navn', overskrift: t('arkivar.struktur.navn'), stil: { width: '14%' }, celle: (r) => r.navn, sorter: (r) => r.navn },
    { id: 'arkivdel', overskrift: t('arkivar.sak.arkivdel'), stil: { width: '14%' }, celle: (r) => arkivdelnavn(r.arkivdel), sorter: (r) => arkivdelnavn(r.arkivdel) },
    { id: 'tittel', overskrift: t('arkivar.struktur.mal.tittel'), stil: { width: '30%' }, celle: (r) => r.tittel, sorter: (r) => r.tittel },
    { id: 'beskrivelse', overskrift: t('arkivar.struktur.beskrivelse'), celle: (r) => r.beskrivelse },
  ];
  const tilgangskoder: Kolonne<Tilgangskode>[] = [
    { id: 'kode', overskrift: t('arkivar.struktur.kode'), stil: { width: '90px' }, celle: (r) => r.kode, sorter: (r) => r.kode },
    { id: 'navn', overskrift: t('arkivar.struktur.navn'), celle: (r) => r.navn, sorter: (r) => r.navn },
    { id: 'hjemmel', overskrift: t('arkivar.struktur.hjemmel'), celle: (r) => r.hjemmel, sorter: (r) => r.hjemmel },
  ];
  const ansatte: Kolonne<Ansatt>[] = [
    { id: 'enhet', overskrift: t('arkivar.struktur.enhet'), celle: (r) => enhetsnavn(r.enhet), sorter: (r) => enhetsnavn(r.enhet) },
    { id: 'navn', overskrift: t('arkivar.konto.navn'), celle: (r) => r.navn, sorter: (r) => r.navn },
    { id: 'tittel', overskrift: t('arkivar.struktur.stilling'), celle: (r) => r.tittel, sorter: (r) => r.tittel },
    { id: 'initialer', overskrift: t('arkivar.struktur.initialer'), stil: { width: '100px' }, celle: (r) => r.initialer, sorter: (r) => r.initialer },
  ];
  // Innholdet i tabellene kommer fra arkivet og er norsk; overskriftene og tekstene rundt er oversatt.
  return (
    <>
      <Sidehode tittel={t('arkivar.struktur.tittel')} ingress={t('arkivar.struktur.ingress')} />
      <Kort tittel={t('arkivar.struktur.arkivdeler')} id="arkivdeler">
        <SorterbarTabell rader={struktur.arkivdeler} kolonner={arkivdeler} nokkel={(r) => r.kode} aria-labelledby="arkivdeler" kroppSprak="nb" />
      </Kort>
      <Kort tittel={t('arkivar.struktur.saksmaler')} id="saksmaler">
        <p className={c.dempet}>{t('arkivar.struktur.saksmaler.om')}</p>
        <SorterbarTabell rader={struktur.saksmaler} kolonner={saksmaler} nokkel={(r) => r.kode} aria-labelledby="saksmaler" kroppSprak="nb" />
      </Kort>
      <Kort tittel={t('arkivar.struktur.arkivnokkel')} id="arkivnokkel">
        <p className={c.dempet}>{t('arkivar.struktur.arkivnokkel.om')}</p>
        <Arkivnokkel struktur={struktur} />
      </Kort>
      {struktur.arkivdeler.filter((del) => del.ordning !== 'emne').map((del) => (
        <Kort key={del.kode} tittel={t(`arkivar.ordning.${del.ordning}.register`, { arkivdel: del.tittel })} id={`register-${del.kode}`}>
          <SorterbarTabell rader={struktur.registre[del.kode] ?? []} kolonner={register(del)} nokkel={(r) => r.kode} aria-labelledby={`register-${del.kode}`} startSortering={{ id: 'tittel', retning: 'ascending' }} kroppSprak="nb" />
        </Kort>
      ))}
      <Kort tittel={t('arkivar.struktur.tilgangskoder')} id="tilgangskoder">
        <SorterbarTabell rader={struktur.tilgangskoder} kolonner={tilgangskoder} nokkel={(r) => r.kode} aria-labelledby="tilgangskoder" kroppSprak="nb" />
      </Kort>
      <Kort tittel={t('arkivar.struktur.ansatte')} id="ansatte">
        <SorterbarTabell rader={struktur.ansatte} kolonner={ansatte} nokkel={(r) => r.initialer} aria-labelledby="ansatte" startSortering={{ id: 'enhet', retning: 'ascending' }} kroppSprak="nb" />
      </Kort>
    </>
  );
}
