// Journalen: alle journalførte dokumenter. Bryteren viser den slik allmennheten ser den (offentlig journal), der
// skjermede titler og navn er byttet ut.
import { useState } from 'react';
import { Link as Ruterlenke } from 'react-router-dom';
import { Switch, Text, makeStyles, tokens } from '@fluentui/react-components';
import { Journalpost } from '../../arkiv';
import { useDato, useT } from '../../i18n';
import { Feil, Kort, Laster, Sidehode, useHent } from '../../komponenter/Felles';
import { Status } from '../../komponenter/Status';
import { Kolonne, SorterbarTabell, TabellVerktoy } from '../../komponenter/Tabell';

const s = makeStyles({ sak: { color: tokens.colorNeutralForeground2, display: 'block', marginTop: '2px' } });

const sorterbart = (nummer: string) => nummer.split('/').map((del) => del.padStart(6, '0')).join('/');

export default function Journal() {
  const c = s(); const t = useT(); const dato = useDato();
  const [offentlig, settOffentlig] = useState(false);
  const journal = useHent<{ journalposter: Journalpost[] }>(`/api/arkiv/journal${offentlig ? '?offentlig=1' : ''}`);
  const rader = journal.data?.journalposter ?? [];
  const tittel = (r: Journalpost) => (
    <>
      <Text block>{r.tittel}</Text>
      <span className={c.sak}>{t('arkivar.journal.sak')} <Ruterlenke to={`/arkiv/saker/${r.sak_id}`}>{r.saksnummer}-{r.postnr}</Ruterlenke> {r.sakstittel}</span>
      <span className={c.sak}>{t('arkivar.journal.klassering', { klassering: r.klasse_tekst ?? '' })}</span>
    </>
  );
  // Hjemmelen for skjermingen står alltid i journalen. Er mappen skjermet (tittelen eller klasseringen) med en annen
  // hjemmel enn journalposten, eller er journalposten selv åpen, står mappens hjemmel for seg.
  const tilgang = (r: Journalpost) => (
    <>
      {r.tilgangskode ? <Status slag="skjermet">{r.hjemmel}</Status> : t('arkivar.tilgang.offentlig')}
      {r.sak_hjemmel && r.sak_hjemmel !== r.hjemmel && <span className={c.sak}>{t('arkivar.journal.mappehjemmel', { hjemmel: r.sak_hjemmel })}</span>}
    </>
  );
  const kolonner: Kolonne<Journalpost>[] = [
    { id: 'lopenr', overskrift: t('arkivar.journal.lopenummer'), stil: { width: '90px' }, celle: (r) => r.lopenummer, sorter: (r) => sorterbart(r.lopenummer) },
    { id: 'jdato', overskrift: t('arkivar.journal.journaldato'), stil: { width: '110px' }, celle: (r) => dato(r.journaldato), sorter: (r) => r.journaldato },
    { id: 'type', overskrift: t('arkivar.journalpost.type'), stil: { width: '70px' }, celle: (r) => (<abbr title={t(`arkivar.jptype.${r.type.toLowerCase()}`)}>{r.type}</abbr>), sorter: (r) => r.type },
    { id: 'tittel', overskrift: t('arkivar.journalpost.tittel'), celle: tittel, sorter: (r) => r.tittel },
    { id: 'part', overskrift: t('arkivar.journalpost.part'), stil: { width: '15%' }, celle: (r) => r.part_navn, sorter: (r) => r.part_navn },
    { id: 'ddato', overskrift: t('arkivar.journalpost.dokumentdato'), stil: { width: '110px' }, celle: (r) => dato(r.dokumentdato), sorter: (r) => r.dokumentdato },
    { id: 'tilgang', overskrift: t('arkivar.journalpost.tilgang'), stil: { width: '16%' }, celle: tilgang, sorter: (r) => r.tilgangskode ?? '' },
  ];
  return (
    <>
      <Sidehode tittel={t(offentlig ? 'arkivar.journal.tittel.offentlig' : 'arkivar.journal.tittel')} ingress={t(offentlig ? 'arkivar.journal.ingress.offentlig' : 'arkivar.journal.ingress')}
        handlinger={<Switch checked={offentlig} onChange={(_, d) => settOffentlig(d.checked)} label={t('arkivar.journal.vis_offentlig')} />} />
      <Kort id="journalliste">
        {journal.laster && !journal.data ? <Laster /> : journal.feil ? <Feil melding={journal.feil} /> : (
          <>
            <TabellVerktoy tekst={t('arkivar.journal.antall', { antall: rader.length })}>{null}</TabellVerktoy>
            <SorterbarTabell rader={rader} kolonner={kolonner} nokkel={(r) => r.id} aria-label={t('arkivar.journal.tittel')} startSortering={{ id: 'lopenr', retning: 'descending' }} />
          </>
        )}
      </Kort>
    </>
  );
}
