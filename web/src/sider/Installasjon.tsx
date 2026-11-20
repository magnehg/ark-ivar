// Installasjonen i nettleseren (Kjernens API under /bivrost/api/installasjon): installasjonskoden fra serveren,
// databasen, den første driftsbrukeren med engangskode (TOTP), og til slutt gjenopprettingskodene.
import { ReactNode, useEffect, useState } from 'react';
import { Body1, Button, Field, Input, Title2, makeStyles, tokens } from '@fluentui/react-components';
import { api } from '../api';
import { useT } from '../i18n';
import { Logo } from '../tema';
import { Feil, useFeiltekst } from '../komponenter/Felles';

const s = makeStyles({
  side: { minHeight: '100vh', display: 'grid', placeItems: 'center', background: tokens.colorNeutralBackground3, padding: '16px', boxSizing: 'border-box' },
  boks: { width: '100%', maxWidth: '520px', background: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusXLarge, boxShadow: tokens.shadow16, padding: '32px', display: 'flex', flexDirection: 'column', gap: '16px', boxSizing: 'border-box' },
  dempet: { color: tokens.colorNeutralForeground2 },
  kode: { fontFamily: tokens.fontFamilyMonospace, overflowWrap: 'anywhere', background: tokens.colorNeutralBackground3, padding: '8px 12px', borderRadius: tokens.borderRadiusMedium },
  koder: { fontFamily: tokens.fontFamilyMonospace, columns: 2, margin: 0, paddingLeft: '20px' },
  mangler: { margin: 0, paddingLeft: '20px' },
});

type Forutsetning = { id: string; ok: boolean; kreves: boolean };
type Status = { installert: boolean; kode_kreves?: boolean; steg?: 'database' | 'bruker'; forutsetninger?: Forutsetning[] };
type Steg = 'kode' | 'database' | 'bruker' | 'totp' | 'ferdig';
type Kall = { (): Promise<void> };

export default function Installasjon({ appnavn }: { appnavn: string }) {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const [steg, settSteg] = useState<Steg>('kode');
  const [kode, settKode] = useState(''); const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);
  const [mangler, settMangler] = useState<string[]>([]);
  const [db, settDb] = useState({ vert: 'localhost', port: '3306', navn: '', bruker: '', passord: '', adresse: window.location.origin });
  const [bruker, settBruker] = useState({ brukernavn: '', navn: '', passord: '' });
  const [totp, settTotp] = useState<{ otpauth: string; hemmelighet: string } | null>(null); const [engangskode, settEngangskode] = useState('');
  const [koder, settKoder] = useState<string[]>([]);
  const hode = { 'X-Bivrost-Installasjonskode': kode.trim() };

  // Serveren lager installasjonskoden (drift/installasjonskode.txt) første gang installasjonen blir spurt om status.
  useEffect(() => { api.get('/bivrost/api/installasjon').catch(() => { /* feilen vises når koden sendes inn */ }); }, []);

  const kjor = (kall: Kall) => {
    settOpptatt(true); settFeil(null);
    kall().catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  const sjekkKode = () => kjor(async () => {
    const st = await api.get<Status>('/bivrost/api/installasjon', hode);
    settMangler((st.forutsetninger ?? []).filter((f) => f.kreves && !f.ok).map((f) => f.id));
    settSteg(st.steg === 'bruker' ? 'bruker' : 'database');
  });
  const lagreDatabase = () => kjor(async () => { await api.post('/bivrost/api/installasjon/database', { ...db, port: Number(db.port) }, hode); settSteg('bruker'); });
  const lagreBruker = () => kjor(async () => { settTotp(await api.post<{ otpauth: string; hemmelighet: string }>('/bivrost/api/installasjon/bruker', bruker, hode)); settSteg('totp'); });
  const fullfor = () => kjor(async () => { const svar = await api.post<{ gjenopprettingskoder: string[] }>('/bivrost/api/installasjon/fullfor', { kode: engangskode }, hode); settKoder(svar.gjenopprettingskoder); settSteg('ferdig'); });

  const ramme = (tittel: string, ingress: string, innhold: ReactNode, send?: () => void) => (
    <main className={c.side}>
      <form className={c.boks} noValidate onSubmit={(e) => { e.preventDefault(); send?.(); }}>
        <Logo storrelse={56} ramme={tokens.colorBrandForeground1} fyll={tokens.colorBrandForeground1} />
        <Title2 as="h1">{tittel}</Title2>
        <Body1 className={c.dempet}>{ingress}</Body1>
        {feil && <Feil melding={feil} />}
        {mangler.length > 0 && steg !== 'ferdig' && <Feil melding={t('arkivar.installasjon.mangler', { liste: mangler.join(', ') })} />}
        {innhold}
      </form>
    </main>
  );
  const tittel = t('arkivar.installasjon.tittel', { appnavn });

  if (steg === 'kode') {
    return ramme(tittel, t('arkivar.installasjon.kode.ingress'), <>
      <Field label={t('arkivar.installasjon.kode')} required><Input value={kode} onChange={(_, d) => settKode(d.value)} autoComplete="off" /></Field>
      <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.videre')}</Button>
    </>, sjekkKode);
  }
  if (steg === 'database') {
    return ramme(tittel, t('arkivar.installasjon.database.ingress'), <>
      <Field label={t('arkivar.installasjon.database.vert')} required><Input value={db.vert} onChange={(_, d) => settDb({ ...db, vert: d.value })} /></Field>
      <Field label={t('arkivar.installasjon.database.port')} required><Input value={db.port} inputMode="numeric" onChange={(_, d) => settDb({ ...db, port: d.value })} /></Field>
      <Field label={t('arkivar.installasjon.database.navn')} required><Input value={db.navn} onChange={(_, d) => settDb({ ...db, navn: d.value })} /></Field>
      <Field label={t('arkivar.installasjon.database.bruker')} required><Input value={db.bruker} onChange={(_, d) => settDb({ ...db, bruker: d.value })} autoComplete="off" /></Field>
      <Field label={t('arkivar.installasjon.database.passord')}><Input type="password" value={db.passord} onChange={(_, d) => settDb({ ...db, passord: d.value })} autoComplete="off" /></Field>
      <Field label={t('arkivar.installasjon.database.adresse')} required hint={t('arkivar.installasjon.database.adresse.hjelp')}><Input value={db.adresse} onChange={(_, d) => settDb({ ...db, adresse: d.value })} /></Field>
      <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.videre')}</Button>
    </>, lagreDatabase);
  }
  if (steg === 'bruker') {
    return ramme(tittel, t('arkivar.installasjon.bruker.ingress'), <>
      <Field label={t('arkivar.konto.drift.brukernavn')} required><Input value={bruker.brukernavn} onChange={(_, d) => settBruker({ ...bruker, brukernavn: d.value })} autoComplete="username" /></Field>
      <Field label={t('arkivar.konto.navn')} required><Input value={bruker.navn} onChange={(_, d) => settBruker({ ...bruker, navn: d.value })} autoComplete="name" /></Field>
      <Field label={t('arkivar.konto.passord')} required hint={t('arkivar.konto.passordhjelp', { min: 12 })}><Input type="password" value={bruker.passord} onChange={(_, d) => settBruker({ ...bruker, passord: d.value })} autoComplete="new-password" /></Field>
      <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.konto.videre')}</Button>
    </>, lagreBruker);
  }
  if (steg === 'totp') {
    return ramme(tittel, t('arkivar.installasjon.totp.ingress'), <>
      <div className={c.kode}>{totp?.hemmelighet}</div>
      <Body1 className={c.dempet}>{t('arkivar.installasjon.totp.lenke')}</Body1>
      <div className={c.kode}>{totp?.otpauth}</div>
      <Field label={t('arkivar.konto.drift.kode')} required><Input value={engangskode} onChange={(_, d) => settEngangskode(d.value)} inputMode="numeric" autoComplete="one-time-code" /></Field>
      <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.installasjon.fullfor')}</Button>
    </>, fullfor);
  }
  return ramme(t('arkivar.installasjon.ferdig.tittel', { appnavn }), t('arkivar.installasjon.ferdig.ingress'), <>
    <ol className={c.koder}>{koder.map((k) => <li key={k}>{k}</li>)}</ol>
    <Button appearance="primary" onClick={() => window.location.assign('/')}>{t('arkivar.installasjon.ferdig.knapp')}</Button>
  </>);
}
