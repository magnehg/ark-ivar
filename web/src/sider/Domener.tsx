// Godkjente e-postdomener: studenter med adresse fra et av dem kan registrere seg selv.
import { useState } from 'react';
import { Button, Checkbox, Field, Input, makeStyles } from '@fluentui/react-components';
import { Delete20Regular } from '@fluentui/react-icons';
import { Domene, api } from '../api';
import { useT } from '../i18n';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../komponenter/Felles';
import { Kolonne, SorterbarTabell } from '../komponenter/Tabell';

const s = makeStyles({
  skjema: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '12px 16px', alignItems: 'start', marginBottom: '12px', '& .fui-Input': { width: '100%', minWidth: 0 } },
  valg: { gridColumn: '1 / -1' },
});

export default function Domener() {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const liste = useHent<{ domener: Domene[] }>('/api/admin/domener');
  const [domene, settDomene] = useState(''); const [sted, settSted] = useState(''); const [under, settUnder] = useState(true);
  const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);

  const kjor = (kall: Promise<unknown>, etter?: () => void) => {
    settOpptatt(true); settFeil(null);
    kall.then(() => { etter?.(); liste.last(); }).catch((e: unknown) => { settFeil(feiltekst(e)); liste.last(); }).finally(() => settOpptatt(false));
  };
  const endre = (d: Domene, endring: Partial<Domene>) => kjor(api.put(`/api/admin/domener/${d.id}`, { sted: d.sted, underdomener: d.underdomener, aktiv: d.aktiv, ...endring }));
  const kolonner: Kolonne<Domene>[] = [
    { id: 'sted', overskrift: t('arkivar.domener.sted'), celle: (r) => r.sted, sorter: (r) => r.sted },
    { id: 'domene', overskrift: t('arkivar.domener.domene'), celle: (r) => r.domene, sorter: (r) => r.domene },
    { id: 'under', overskrift: t('arkivar.domener.underdomener'), stil: { width: '140px' }, sorter: (r) => r.underdomener, celle: (r) => <Checkbox checked={r.underdomener} aria-label={t('arkivar.domener.underdomener_for', { domene: r.domene })} onChange={(_, d) => endre(r, { underdomener: d.checked === true })} /> },
    { id: 'aktiv', overskrift: t('arkivar.brukere.aktiv'), stil: { width: '80px' }, sorter: (r) => r.aktiv, celle: (r) => <Checkbox checked={r.aktiv} aria-label={t('arkivar.domener.aktiv_for', { domene: r.domene })} onChange={(_, d) => endre(r, { aktiv: d.checked === true })} /> },
    { id: 'slett', overskrift: '', stil: { width: '56px' }, celle: (r) => <Button size="small" appearance="subtle" icon={<Delete20Regular />} aria-label={t('arkivar.domener.slett_for', { domene: r.domene })} title={t('arkivar.domener.slett_for', { domene: r.domene })} onClick={() => kjor(api.slett(`/api/admin/domener/${r.id}`))} /> },
  ];
  return (
    <>
      <Sidehode tittel={t('arkivar.domener.tittel')} ingress={t('arkivar.domener.ingress')} />
      {feil && <Feil melding={feil} />}
      <Kort tittel={t('arkivar.domener.ny.tittel')} id="nytt-domene">
        <form noValidate onSubmit={(e) => { e.preventDefault(); kjor(api.post('/api/admin/domener', { domene, sted, underdomener: under }), () => { settDomene(''); settSted(''); }); }}>
          <div className={c.skjema}>
            <Field label={t('arkivar.domener.sted')} required hint={t('arkivar.domener.sted.hjelp')}><Input value={sted} onChange={(_, d) => settSted(d.value)} /></Field>
            <Field label={t('arkivar.domener.domene')} required hint={t('arkivar.domener.domene.hjelp')}><Input value={domene} onChange={(_, d) => settDomene(d.value)} autoComplete="off" /></Field>
            <Checkbox className={c.valg} checked={under} onChange={(_, d) => settUnder(d.checked === true)} label={t('arkivar.domener.underdomener.valg')} />
          </div>
          <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.domener.ny.knapp')}</Button>
        </form>
      </Kort>
      <Kort tittel={t('arkivar.domener.liste')} id="domeneliste">
        {liste.laster && !liste.data ? <Laster /> : liste.feil ? <Feil melding={liste.feil} />
          : <SorterbarTabell rader={liste.data?.domener ?? []} kolonner={kolonner} nokkel={(r) => r.id} aria-labelledby="domeneliste" startSortering={{ id: 'sted', retning: 'ascending' }} />}
      </Kort>
    </>
  );
}
