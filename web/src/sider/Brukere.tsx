// Administrasjon av brukere: se alle, gi rolle, deaktivere, og opprette en bruker (lærer eller administrator).
import { useState } from 'react';
import { Button, Checkbox, Field, Input, Select, makeStyles } from '@fluentui/react-components';
import { Bruker, Rolle, api } from '../api';
import { useT, useTidspunkt } from '../i18n';
import { Feil, Kort, Laster, Ok, Sidehode, useFeiltekst, useHent, useOkt } from '../komponenter/Felles';
import { Kolonne, SorterbarTabell, TabellVerktoy } from '../komponenter/Tabell';

const ROLLER: Rolle[] = ['student', 'laerer', 'administrator'];

const s = makeStyles({
  // Feltene står på linje i toppen (hjelpeteksten under et felt skyver ikke de andre), og fyller kolonnen sin.
  skjema: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(220px, 1fr))', gap: '12px 16px', alignItems: 'start', marginBottom: '12px', '& .fui-Input, & .fui-Select': { width: '100%', minWidth: 0 } },
  sok: { maxWidth: '320px', marginBottom: '8px' },
});

export default function Brukere() {
  const c = s(); const t = useT(); const tid = useTidspunkt(); const feiltekst = useFeiltekst();
  const { oppsett } = useOkt();
  const [sok, settSok] = useState('');
  const liste = useHent<{ brukere: Bruker[] }>('/api/admin/brukere');
  const [feil, settFeil] = useState<string | null>(null); const [ok, settOk] = useState<string | null>(null);
  const [navn, settNavn] = useState(''); const [epost, settEpost] = useState(''); const [rolle, settRolle] = useState<Rolle>('laerer'); const [passord, settPassord] = useState('');
  const [opptatt, settOpptatt] = useState(false);

  const endre = (b: Bruker, endring: { rolle?: Rolle; aktiv?: boolean }) => {
    settFeil(null); settOk(null);
    api.put(`/api/admin/brukere/${b.id}`, endring).then(() => liste.last()).catch((e: unknown) => { settFeil(feiltekst(e)); liste.last(); });
  };
  const opprett = () => {
    settOpptatt(true); settFeil(null); settOk(null);
    api.post('/api/admin/brukere', { navn, epost, rolle, passord })
      .then(() => { settOk(t('arkivar.brukere.ny.opprettet', { navn })); settNavn(''); settEpost(''); settPassord(''); liste.last(); })
      .catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  const alle = liste.data?.brukere ?? [];
  const nal = sok.trim().toLowerCase();
  const rader = nal === '' ? alle : alle.filter((b) => b.navn.toLowerCase().includes(nal) || b.epost.includes(nal));
  const kolonner: Kolonne<Bruker>[] = [
    { id: 'navn', overskrift: t('arkivar.konto.navn'), stil: { width: '22%' }, celle: (r) => r.navn, sorter: (r) => r.navn },
    { id: 'epost', overskrift: t('arkivar.konto.epost'), celle: (r) => r.epost, sorter: (r) => r.epost, stil: { overflowWrap: 'anywhere' } },
    { id: 'rolle', overskrift: t('arkivar.brukere.rolle'), stil: { width: '170px' }, sorter: (r) => ROLLER.indexOf(r.rolle), celle: (r) => (
      <Select size="small" value={r.rolle} aria-label={t('arkivar.brukere.rolle_for', { navn: r.navn })} onChange={(_, d) => endre(r, { rolle: d.value as Rolle })}>
        {ROLLER.map((x) => <option key={x} value={x}>{t(`arkivar.rolle.${x}`)}</option>)}
      </Select>
    ) },
    { id: 'aktiv', overskrift: t('arkivar.brukere.aktiv'), stil: { width: '80px' }, sorter: (r) => r.aktiv, celle: (r) => <Checkbox checked={r.aktiv} aria-label={t('arkivar.brukere.aktiv_for', { navn: r.navn })} onChange={(_, d) => endre(r, { aktiv: d.checked === true })} /> },
    { id: 'bekreftet', overskrift: t('arkivar.brukere.bekreftet'), stil: { width: '140px' }, sorter: (r) => r.epost_bekreftet, celle: (r) => t(r.epost_bekreftet ? 'arkivar.felles.ja' : 'arkivar.felles.nei') },
    { id: 'sist', overskrift: t('arkivar.brukere.sist_innlogget'), stil: { width: '160px' }, sorter: (r) => r.sist_innlogget, celle: (r) => tid(r.sist_innlogget) },
  ];
  return (
    <>
      <Sidehode tittel={t('arkivar.brukere.tittel')} ingress={t('arkivar.brukere.ingress')} />
      {feil && <Feil melding={feil} />}
      {ok && <Ok melding={ok} />}
      <Kort tittel={t('arkivar.brukere.ny.tittel')} id="ny-bruker">
        <form noValidate onSubmit={(e) => { e.preventDefault(); opprett(); }}>
          <div className={c.skjema}>
            <Field label={t('arkivar.konto.navn')} required><Input value={navn} onChange={(_, d) => settNavn(d.value)} autoComplete="off" /></Field>
            <Field label={t('arkivar.konto.epost')} required><Input type="email" value={epost} onChange={(_, d) => settEpost(d.value)} autoComplete="off" /></Field>
            <Field label={t('arkivar.brukere.rolle')} required>
              <Select value={rolle} onChange={(_, d) => settRolle(d.value as Rolle)}>{ROLLER.map((x) => <option key={x} value={x}>{t(`arkivar.rolle.${x}`)}</option>)}</Select>
            </Field>
            <Field label={t('arkivar.brukere.ny.passord')} required hint={t('arkivar.konto.passordhjelp', { min: oppsett.passord.min })}><Input type="password" value={passord} onChange={(_, d) => settPassord(d.value)} autoComplete="new-password" /></Field>
          </div>
          <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.brukere.ny.knapp')}</Button>
        </form>
      </Kort>
      <Kort tittel={t('arkivar.brukere.liste')} id="brukerliste">
        <Field label={t('arkivar.brukere.sok')} className={c.sok}><Input value={sok} onChange={(_, d) => settSok(d.value)} type="search" /></Field>
        {liste.laster && !liste.data ? <Laster /> : liste.feil ? <Feil melding={liste.feil} /> : (
          <>
            <TabellVerktoy tekst={t('arkivar.tabell.antall', { vist: rader.length, alle: alle.length })}>{null}</TabellVerktoy>
            <SorterbarTabell rader={rader} kolonner={kolonner} nokkel={(r) => r.id} aria-labelledby="brukerliste" startSortering={{ id: 'navn', retning: 'ascending' }} />
          </>
        )}
      </Kort>
    </>
  );
}
