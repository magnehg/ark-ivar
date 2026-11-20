// Arkivstrukturen til Sirevåg kommune: arkivdelene, arkivnøkkelen, tilgangskodene, og enhetene med de ansatte.
import { makeStyles, tokens } from '@fluentui/react-components';
import { Struktur as Strukturdata, useStruktur } from '../../arkiv';
import { useT } from '../../i18n';
import { Kort, Laster, Sidehode } from '../../komponenter/Felles';
import { Kolonne, SorterbarTabell } from '../../komponenter/Tabell';

const s = makeStyles({
  nokkel: { columns: '3 280px', columnGap: '32px', margin: 0 },
  gruppe: { breakInside: 'avoid', marginBottom: '16px' },
  gruppetittel: { fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px', display: 'flex' },
  // Koden står i sin egen spalte, så en lang tittel brytes under tittelen og ikke under koden.
  klasser: { listStyle: 'none', margin: 0, padding: 0, '& li': { padding: '2px 0', display: 'flex' } },
  kode: { fontFamily: tokens.fontFamilyNumeric, color: tokens.colorNeutralForeground2, flex: 'none', width: '40px' },
  dempet: { color: tokens.colorNeutralForeground2 },
});

type Arkivdel = Strukturdata['arkivdeler'][number];
type Tilgangskode = Strukturdata['tilgangskoder'][number];
type Ansatt = Strukturdata['ansatte'][number];

export default function Struktur() {
  const c = s(); const t = useT();
  const struktur = useStruktur();
  if (!struktur) return <Laster />;
  const enhetsnavn = (kode: string) => struktur.enheter.find((e) => e.kode === kode)?.navn ?? kode;
  const arkivdeler: Kolonne<Arkivdel>[] = [
    { id: 'kode', overskrift: t('arkivar.struktur.kode'), stil: { width: '90px' }, celle: (r) => r.kode, sorter: (r) => r.kode },
    { id: 'tittel', overskrift: t('arkivar.struktur.navn'), stil: { width: '26%' }, celle: (r) => r.tittel, sorter: (r) => r.tittel },
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
  return (
    <div lang="nb">
      <Sidehode tittel={t('arkivar.struktur.tittel')} ingress={t('arkivar.struktur.ingress')} />
      <Kort tittel={t('arkivar.struktur.arkivdeler')} id="arkivdeler">
        <SorterbarTabell rader={struktur.arkivdeler} kolonner={arkivdeler} nokkel={(r) => r.kode} aria-labelledby="arkivdeler" />
      </Kort>
      <Kort tittel={t('arkivar.struktur.arkivnokkel')} id="arkivnokkel">
        <div className={c.nokkel}>
          {struktur.klasser.filter((k) => k.forelder === null).map((gruppe) => (
            <section key={gruppe.kode} className={c.gruppe}>
              <h3 className={c.gruppetittel}><span className={c.kode}>{gruppe.kode}</span>{gruppe.tittel}</h3>
              <ul className={c.klasser}>
                {struktur.klasser.filter((k) => k.forelder === gruppe.kode).map((k) => <li key={k.kode}><span className={c.kode}>{k.kode}</span>{k.tittel}</li>)}
              </ul>
            </section>
          ))}
        </div>
      </Kort>
      <Kort tittel={t('arkivar.struktur.tilgangskoder')} id="tilgangskoder">
        <SorterbarTabell rader={struktur.tilgangskoder} kolonner={tilgangskoder} nokkel={(r) => r.kode} aria-labelledby="tilgangskoder" />
      </Kort>
      <Kort tittel={t('arkivar.struktur.ansatte')} id="ansatte">
        <SorterbarTabell rader={struktur.ansatte} kolonner={ansatte} nokkel={(r) => r.initialer} aria-labelledby="ansatte" startSortering={{ id: 'enhet', retning: 'ascending' }} />
      </Kort>
    </div>
  );
}
