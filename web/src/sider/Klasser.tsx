// Lærerens klasser: opprett en klasse, del koden med studentene, og se hvem som er med.
import { useState } from 'react';
import { Button, Field, Input, Text, makeStyles, tokens } from '@fluentui/react-components';
import { ArrowSync20Regular } from '@fluentui/react-icons';
import { Klasse, Klassestudent, api } from '../api';
import { useT, useTidspunkt } from '../i18n';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../komponenter/Felles';
import { Kolonne, SorterbarTabell } from '../komponenter/Tabell';

const s = makeStyles({
  skjema: { display: 'flex', gap: '12px', alignItems: 'flex-end', flexWrap: 'wrap', '& .fui-Field': { minWidth: '260px' } },
  kode: { fontFamily: tokens.fontFamilyMonospace, fontSize: tokens.fontSizeBase500, letterSpacing: '0.08em', fontWeight: tokens.fontWeightSemibold },
  kodelinje: { display: 'flex', alignItems: 'center', gap: '12px', flexWrap: 'wrap', marginBottom: '12px' },
  dempet: { color: tokens.colorNeutralForeground2 },
});

function Studenter({ klasse, oppdatert }: { klasse: Klasse; oppdatert: () => void }) {
  const c = s(); const t = useT(); const tid = useTidspunkt(); const feiltekst = useFeiltekst();
  const detaljer = useHent<{ klasse: Klasse; studenter: Klassestudent[] }>(`/api/klasser/${klasse.id}`);
  const [feil, settFeil] = useState<string | null>(null);
  const nyKode = () => { settFeil(null); api.post(`/api/klasser/${klasse.id}/ny-kode`).then(() => { detaljer.last(); oppdatert(); }).catch((e: unknown) => settFeil(feiltekst(e))); };
  const kolonner: Kolonne<Klassestudent>[] = [
    { id: 'navn', overskrift: t('arkivar.konto.navn'), celle: (r) => r.navn, sorter: (r) => r.navn },
    { id: 'epost', overskrift: t('arkivar.konto.epost'), celle: (r) => r.epost, sorter: (r) => r.epost, stil: { overflowWrap: 'anywhere' } },
    { id: 'innmeldt', overskrift: t('arkivar.klasser.innmeldt'), celle: (r) => tid(r.innmeldt), sorter: (r) => r.innmeldt },
    { id: 'sist', overskrift: t('arkivar.brukere.sist_innlogget'), celle: (r) => tid(r.sist_innlogget), sorter: (r) => r.sist_innlogget },
  ];
  const kode = detaljer.data?.klasse.kode ?? klasse.kode ?? '';
  return (
    <Kort tittel={klasse.navn} id={`klasse-${klasse.id}`}>
      {feil && <Feil melding={feil} />}
      <div className={c.kodelinje}>
        <Text>{t('arkivar.klasser.kode')}</Text><span className={c.kode}>{kode}</span>
        <Button size="small" icon={<ArrowSync20Regular />} onClick={nyKode}>{t('arkivar.klasser.ny_kode')}</Button>
      </div>
      <Text block className={c.dempet}>{t('arkivar.klasser.kodehjelp')}</Text>
      {detaljer.laster && !detaljer.data ? <Laster /> : detaljer.feil ? <Feil melding={detaljer.feil} />
        : detaljer.data && detaljer.data.studenter.length > 0
          ? <SorterbarTabell rader={detaljer.data.studenter} kolonner={kolonner} nokkel={(r) => r.id} aria-labelledby={`klasse-${klasse.id}`} startSortering={{ id: 'navn', retning: 'ascending' }} />
          : <Text block className={c.dempet}>{t('arkivar.klasser.ingen_studenter')}</Text>}
    </Kort>
  );
}

export default function Klasser() {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const klasser = useHent<{ klasser: Klasse[] }>('/api/klasser');
  const [navn, settNavn] = useState(''); const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);
  const opprett = () => {
    settOpptatt(true); settFeil(null);
    api.post('/api/klasser', { navn }).then(() => { settNavn(''); klasser.last(); }).catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  return (
    <>
      <Sidehode tittel={t('arkivar.klasser.tittel')} ingress={t('arkivar.klasser.ingress')} />
      <Kort tittel={t('arkivar.klasser.ny.tittel')} id="ny-klasse">
        {feil && <Feil melding={feil} />}
        <form className={c.skjema} noValidate onSubmit={(e) => { e.preventDefault(); opprett(); }}>
          <Field label={t('arkivar.klasser.navn')} required><Input value={navn} onChange={(_, d) => settNavn(d.value)} /></Field>
          <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.klasser.ny.knapp')}</Button>
        </form>
      </Kort>
      {klasser.laster && !klasser.data ? <Laster /> : klasser.feil ? <Feil melding={klasser.feil} />
        : klasser.data?.klasser.map((k) => <Studenter key={k.id} klasse={k} oppdatert={klasser.last} />)}
    </>
  );
}
