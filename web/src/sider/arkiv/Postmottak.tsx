// Postmottaket: dokumentene som er kommet inn til Sirevåg kommune, og som arkivtjenesten skal behandle.
import { Link as Ruterlenke } from 'react-router-dom';
import { Postmottaksrad } from '../../arkiv';
import { useDato, useT } from '../../i18n';
import { Feil, Kort, Laster, Sidehode, useHent } from '../../komponenter/Felles';
import { Status } from '../../komponenter/Status';
import { Kolonne, SorterbarTabell, TabellVerktoy } from '../../komponenter/Tabell';

export default function Postmottak() {
  const t = useT(); const dato = useDato();
  const liste = useHent<{ dokumenter: Postmottaksrad[] }>('/api/arkiv/postmottak');
  const rader = liste.data?.dokumenter ?? [];
  const behandlet = rader.filter((r) => r.behandlet).length;
  const status = (r: Postmottaksrad) => (r.behandlet
    ? <Status slag={r.poeng === r.maks ? 'ok' : 'feil'}>{t('arkivar.postmottak.behandlet', { poeng: r.poeng ?? 0, maks: r.maks ?? 0 })}</Status>
    : <Status slag="noytral">{t('arkivar.postmottak.ny')}</Status>);
  const kolonner: Kolonne<Postmottaksrad>[] = [
    { id: 'mottatt', overskrift: t('arkivar.postmottak.mottatt'), stil: { width: '110px' }, celle: (r) => dato(r.mottatt), sorter: (r) => r.mottatt },
    { id: 'kanal', overskrift: t('arkivar.postmottak.kanal'), stil: { width: '90px' }, celle: (r) => t(`arkivar.kanal.${r.kanal}`), sorter: (r) => r.kanal },
    { id: 'avsender', overskrift: t('arkivar.postmottak.avsender'), stil: { width: '24%' }, celle: (r) => r.avsender, sorter: (r) => r.avsender },
    { id: 'emne', overskrift: t('arkivar.postmottak.emne'), celle: (r) => (<Ruterlenke to={`/arkiv/postmottak/${r.id}`}>{r.emne}</Ruterlenke>), sorter: (r) => r.emne },
    { id: 'status', overskrift: t('arkivar.postmottak.status'), stil: { width: '170px' }, celle: status, sorter: (r) => (r.behandlet ? 1 : 0) },
  ];
  return (
    <>
      <Sidehode tittel={t('arkivar.postmottak.tittel')} ingress={t('arkivar.postmottak.ingress')} />
      <Kort id="postmottak-liste">
        {liste.laster && !liste.data ? <Laster /> : liste.feil ? <Feil melding={liste.feil} /> : (
          <>
            <TabellVerktoy tekst={t('arkivar.postmottak.antall', { behandlet, alle: rader.length })}>{null}</TabellVerktoy>
            <SorterbarTabell rader={rader} kolonner={kolonner} nokkel={(r) => r.id} aria-label={t('arkivar.postmottak.tittel')} startSortering={{ id: 'mottatt', retning: 'ascending' }} />
          </>
        )}
      </Kort>
    </>
  );
}
