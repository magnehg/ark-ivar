// Innlogging, registrering, bekreftelse av e-postadressen og glemt passord. Passordbehandlere virker (autocomplete).
// Ved for mange forsøk sier serveren hvor lenge det må ventes (429). Driftsinnloggingen (Kjernens nødinnlogging med
// passord og engangskode) ligger bak en lenke nederst.
import { FormEvent, ReactNode, useEffect, useState } from 'react';
import { Body1, Button, Caption1, Field, Input, Link, Title2, makeStyles, tokens } from '@fluentui/react-components';
import { ApiFeil, Okt, api } from '../api';
import { useT } from '../i18n';
import { Logo } from '../tema';
import { Feil, Ok, useFeiltekst, useOkt } from '../komponenter/Felles';

const s = makeStyles({
  side: { minHeight: '100vh', display: 'grid', placeItems: 'center', background: tokens.colorNeutralBackground3, padding: '16px', boxSizing: 'border-box' },
  boks: { width: '100%', maxWidth: '420px', background: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, boxShadow: tokens.shadow16, padding: '32px', display: 'flex', flexDirection: 'column', gap: '16px', boxSizing: 'border-box' },
  dempet: { color: tokens.colorNeutralForeground2 },
  lenker: { display: 'flex', flexWrap: 'wrap', gap: '8px 16px', justifyContent: 'space-between' },
  steder: { margin: '4px 0 0', paddingLeft: '20px', color: tokens.colorNeutralForeground2 },
  drift: { borderTop: `1px solid ${tokens.colorNeutralStroke2}`, paddingTop: '12px', textAlign: 'center' },
});

type Visning = 'inn' | 'registrer' | 'bekreft' | 'glemt' | 'nytt' | 'drift' | 'totp';
type Kall = { (): Promise<void> };

/** Rammen rundt alle skjemaene: logo, tittel, ingress og feilmelding på samme sted. */
function Skjema({ tittel, ingress, feil, melding, onSubmit, children }: { tittel: string; ingress?: ReactNode; feil: string | null; melding?: string | null; onSubmit: () => void; children: ReactNode }) {
  const c = s();
  useEffect(() => { document.title = tittel; }, [tittel]);
  return (
    <main className={c.side}>
      <form className={c.boks} noValidate onSubmit={(e: FormEvent) => { e.preventDefault(); onSubmit(); }}>
        <Logo storrelse={56} ramme={tokens.colorBrandForeground1} fyll={tokens.colorBrandForeground1} />
        <Title2 as="h1">{tittel}</Title2>
        {ingress && <Body1 className={c.dempet}>{ingress}</Body1>}
        {melding && <Ok melding={melding} />}
        {feil && <Feil melding={feil} />}
        {children}
      </form>
    </main>
  );
}

export default function Konto() {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const { oppsett, settInnlogget } = useOkt();
  const [visning, settVisning] = useState<Visning>('inn');
  const [epost, settEpost] = useState(''); const [passord, settPassord] = useState(''); const [navn, settNavn] = useState('');
  const [klassekode, settKlassekode] = useState(''); const [kode, settKode] = useState(''); const [brukernavn, settBrukernavn] = useState('');
  const [feil, settFeil] = useState<string | null>(null); const [melding, settMelding] = useState<string | null>(null);
  const [opptatt, settOpptatt] = useState(false);

  const bytt = (v: Visning, m: string | null = null) => { settVisning(v); settFeil(null); settMelding(m); settKode(''); if (v !== 'bekreft' && v !== 'nytt' && v !== 'totp') settPassord(''); };
  /** Kjører et kall, viser feilen fra serveren og hindrer dobbel innsending. */
  const kjor = (kall: Kall) => {
    settOpptatt(true); settFeil(null); settMelding(null);
    kall().catch((e: unknown) => {
      // Riktig passord, men adressen er ikke bekreftet: rett til bekreftelsen.
      if (e instanceof ApiFeil && e.kode === 'epost_ikke_bekreftet') { bytt('bekreft'); settFeil(feiltekst(e)); return; }
      settFeil(feiltekst(e));
    }).finally(() => settOpptatt(false));
  };
  /** Sender skjemaet til en rute som logger inn, og tar imot den innloggede. */
  async function loggInnMed(sti: string, kropp: Record<string, string>): Promise<void> {
    const okt: Okt = await api.post(sti, kropp);
    settInnlogget(okt.innlogget);
  }
  const passordhjelp = t('arkivar.konto.passordhjelp', { min: oppsett.passord.min });

  if (visning === 'registrer') {
    return (
      <Skjema tittel={t('arkivar.konto.registrer.tittel')} feil={feil} ingress={t('arkivar.konto.registrer.ingress', { virksomhet: oppsett.virksomhet })}
        onSubmit={() => kjor(async () => { await api.post('/api/registrering', { navn, epost, passord, ...(klassekode.trim() ? { klassekode } : {}) }); bytt('bekreft', t('arkivar.konto.bekreft.sendt', { epost })); })}>
        <Field label={t('arkivar.konto.navn')} required><Input value={navn} onChange={(_, d) => settNavn(d.value)} autoComplete="name" /></Field>
        <Field label={t('arkivar.konto.epost')} required hint={oppsett.steder.length > 0 ? t('arkivar.konto.registrer.steder') : t('arkivar.konto.registrer.ingen_steder')}>
          <Input type="email" value={epost} onChange={(_, d) => settEpost(d.value)} autoComplete="email" />
        </Field>
        {oppsett.steder.length > 0 && <ul className={c.steder}>{oppsett.steder.map((x) => <li key={x.domene}><Caption1>{x.sted} ({x.domene})</Caption1></li>)}</ul>}
        <Field label={t('arkivar.konto.klassekode')} hint={t('arkivar.konto.klassekode.hjelp')}><Input value={klassekode} onChange={(_, d) => settKlassekode(d.value)} autoComplete="off" /></Field>
        <Field label={t('arkivar.konto.passord')} required hint={passordhjelp}><Input type="password" value={passord} onChange={(_, d) => settPassord(d.value)} autoComplete="new-password" /></Field>
        <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.registrer.knapp')}</Button>
        <Link as="button" type="button" onClick={() => bytt('inn')}>{t('arkivar.konto.til_innlogging')}</Link>
      </Skjema>
    );
  }
  if (visning === 'bekreft') {
    return (
      <Skjema tittel={t('arkivar.konto.bekreft.tittel')} feil={feil} melding={melding} ingress={t('arkivar.konto.bekreft.ingress')}
        onSubmit={() => kjor(() => loggInnMed('/api/registrering/bekreft', { epost, kode }))}>
        <Field label={t('arkivar.konto.epost')} required><Input type="email" value={epost} onChange={(_, d) => settEpost(d.value)} autoComplete="email" /></Field>
        <Field label={t('arkivar.konto.kode')} required hint={t('arkivar.konto.kode.hjelp')}><Input value={kode} onChange={(_, d) => settKode(d.value)} autoComplete="one-time-code" /></Field>
        <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.bekreft.knapp')}</Button>
        <div className={c.lenker}>
          <Link as="button" type="button" onClick={() => kjor(async () => { await api.post('/api/registrering/ny-kode', { epost }); settMelding(t('arkivar.konto.bekreft.ny_sendt')); })}>{t('arkivar.konto.bekreft.ny_kode')}</Link>
          <Link as="button" type="button" onClick={() => bytt('inn')}>{t('arkivar.konto.til_innlogging')}</Link>
        </div>
      </Skjema>
    );
  }
  if (visning === 'glemt') {
    return (
      <Skjema tittel={t('arkivar.konto.glemt.tittel')} feil={feil} ingress={t('arkivar.konto.glemt.ingress')}
        onSubmit={() => kjor(async () => { await api.post('/api/passord/glemt', { epost }); bytt('nytt', t('arkivar.konto.glemt.sendt')); })}>
        <Field label={t('arkivar.konto.epost')} required><Input type="email" value={epost} onChange={(_, d) => settEpost(d.value)} autoComplete="email" /></Field>
        <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.glemt.knapp')}</Button>
        <Link as="button" type="button" onClick={() => bytt('inn')}>{t('arkivar.konto.til_innlogging')}</Link>
      </Skjema>
    );
  }
  if (visning === 'nytt') {
    return (
      <Skjema tittel={t('arkivar.konto.nytt.tittel')} feil={feil} melding={melding}
        onSubmit={() => kjor(() => loggInnMed('/api/passord/nytt', { epost, kode, passord }))}>
        <Field label={t('arkivar.konto.epost')} required><Input type="email" value={epost} onChange={(_, d) => settEpost(d.value)} autoComplete="email" /></Field>
        <Field label={t('arkivar.konto.kode')} required hint={t('arkivar.konto.kode.hjelp')}><Input value={kode} onChange={(_, d) => settKode(d.value)} autoComplete="one-time-code" /></Field>
        <Field label={t('arkivar.konto.nytt_passord')} required hint={passordhjelp}><Input type="password" value={passord} onChange={(_, d) => settPassord(d.value)} autoComplete="new-password" /></Field>
        <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.nytt.knapp')}</Button>
        <Link as="button" type="button" onClick={() => bytt('inn')}>{t('arkivar.konto.til_innlogging')}</Link>
      </Skjema>
    );
  }
  if (visning === 'drift') {
    return (
      <Skjema tittel={t('arkivar.konto.drift.tittel')} feil={feil} ingress={t('arkivar.konto.drift.ingress')}
        onSubmit={() => kjor(async () => { await api.post('/bivrost/api/innlogging/lokal', { brukernavn, passord }); bytt('totp'); })}>
        <Field label={t('arkivar.konto.drift.brukernavn')} required><Input value={brukernavn} onChange={(_, d) => settBrukernavn(d.value)} autoComplete="username" /></Field>
        <Field label={t('arkivar.konto.passord')} required><Input type="password" value={passord} onChange={(_, d) => settPassord(d.value)} autoComplete="current-password" /></Field>
        <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.videre')}</Button>
        <Link as="button" type="button" onClick={() => bytt('inn')}>{t('arkivar.konto.til_innlogging')}</Link>
      </Skjema>
    );
  }
  if (visning === 'totp') {
    return (
      <Skjema tittel={t('arkivar.konto.drift.tittel')} feil={feil} ingress={t('arkivar.konto.drift.totp')}
        onSubmit={() => kjor(() => loggInnMed('/bivrost/api/innlogging/lokal/totp', { kode }))}>
        <Field label={t('arkivar.konto.drift.kode')} required><Input value={kode} onChange={(_, d) => settKode(d.value)} autoComplete="one-time-code" inputMode="numeric" /></Field>
        <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.inn.knapp')}</Button>
        <Link as="button" type="button" onClick={() => bytt('inn')}>{t('arkivar.konto.til_innlogging')}</Link>
      </Skjema>
    );
  }
  return (
    <Skjema tittel={t('arkivar.konto.inn.tittel', { appnavn: oppsett.appnavn })} feil={feil} melding={melding} ingress={t('arkivar.konto.inn.ingress', { virksomhet: oppsett.virksomhet })}
      onSubmit={() => kjor(() => loggInnMed('/api/innlogging', { epost, passord }))}>
      <Field label={t('arkivar.konto.epost')} required><Input type="email" value={epost} onChange={(_, d) => settEpost(d.value)} autoComplete="username" /></Field>
      <Field label={t('arkivar.konto.passord')} required><Input type="password" value={passord} onChange={(_, d) => settPassord(d.value)} autoComplete="current-password" /></Field>
      <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.inn.knapp')}</Button>
      <div className={c.lenker}>
        <Link as="button" type="button" onClick={() => bytt('registrer')}>{t('arkivar.konto.til_registrering')}</Link>
        <Link as="button" type="button" onClick={() => bytt('glemt')}>{t('arkivar.konto.til_glemt')}</Link>
      </div>
      <div className={c.drift}><Link as="button" type="button" onClick={() => bytt('drift')}>{t('arkivar.konto.til_drift')}</Link></div>
    </Skjema>
  );
}
