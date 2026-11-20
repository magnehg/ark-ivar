// Felles komponenter og økten. Status vises alltid med ikon og tekst, aldri bare med farge.
import { Component, ReactNode, createContext, useContext, useEffect, useState } from 'react';
import { Body1, Button, MessageBar, MessageBarBody, Spinner, Title2, makeStyles, tokens } from '@fluentui/react-components';
import { ApiFeil, Innlogget, Oppsett, api } from '../api';
import { useT } from '../i18n';

// ------------------------------------------------------------------ økten og oppsettet
export type OktVerdi = { innlogget: Innlogget | null; oppsett: Oppsett; settInnlogget: (i: Innlogget | null) => void };
export const OktCtx = createContext<OktVerdi | null>(null);
export function useOkt(): OktVerdi {
  const v = useContext(OktCtx);
  if (!v) throw new Error('useOkt må brukes inne i OktCtx');
  return v;
}

/** Teksten for en feil fra API-et: serverens melding på brukerens språk, ellers en generell tekst med statusen. */
export function useFeiltekst() {
  const t = useT();
  return (feil: unknown) => (feil instanceof ApiFeil
    ? feil.melding() || t('arkivar.feil.ukjent', { status: feil.status })
    : t('arkivar.feil.nett'));
}

// ------------------------------------------------------------------ sidehode, kort, lasting og feil
const s = makeStyles({
  hode: { display: 'flex', flexWrap: 'wrap', gap: '12px', alignItems: 'flex-end', justifyContent: 'space-between', marginBottom: '16px' },
  ingress: { color: tokens.colorNeutralForeground2, display: 'block', marginTop: '4px', maxWidth: '72ch' },
  handlinger: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
  kort: { background: tokens.colorNeutralBackground1, borderRadius: tokens.borderRadiusLarge, boxShadow: tokens.shadow4, padding: '16px', minWidth: 0, marginBottom: '16px' },
  korthode: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px', marginBottom: '12px', flexWrap: 'wrap' },
  korttittel: { margin: 0, fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold },
});

export function Sidehode({ tittel, ingress, handlinger }: { tittel: string; ingress?: string; handlinger?: ReactNode }) {
  const c = s();
  const { oppsett } = useOkt();
  useEffect(() => { document.title = `${tittel} – ${oppsett.appnavn}`; }, [tittel, oppsett.appnavn]);
  return (
    <div className={c.hode}>
      <div><Title2 as="h1">{tittel}</Title2>{ingress && <Body1 className={c.ingress}>{ingress}</Body1>}</div>
      {handlinger && <div className={c.handlinger}>{handlinger}</div>}
    </div>
  );
}

export function Kort({ tittel, handlinger, children, id }: { tittel?: ReactNode; handlinger?: ReactNode; children: ReactNode; id?: string }) {
  const c = s();
  return (
    <section className={c.kort} aria-labelledby={tittel ? id : undefined}>
      {(tittel || handlinger) && <div className={c.korthode}>{tittel && <h2 id={id} className={c.korttittel}>{tittel}</h2>}{handlinger}</div>}
      {children}
    </section>
  );
}

export function Laster() { const t = useT(); return <Spinner label={t('arkivar.felles.laster')} />; }
export function Feil({ melding }: { melding: string }) { return <MessageBar intent="error" role="alert"><MessageBarBody>{melding}</MessageBarBody></MessageBar>; }
export function Ok({ melding }: { melding: string }) { return <MessageBar intent="success" role="status"><MessageBarBody>{melding}</MessageBarBody></MessageBar>; }

/** Henter data og håndterer lasting og feil. last() henter på nytt. */
export function useHent<T>(sti: string | null): { data: T | null; feil: string | null; laster: boolean; last: () => void } {
  const feiltekst = useFeiltekst();
  const [data, settData] = useState<T | null>(null);
  const [feil, settFeil] = useState<string | null>(null);
  const [laster, settLaster] = useState(sti !== null);
  const [runde, settRunde] = useState(0);
  useEffect(() => {
    if (!sti) return;
    let aktiv = true;
    settLaster(true); settFeil(null);
    api.get<T>(sti).then((d) => { if (aktiv) settData(d); }).catch((e: unknown) => { if (aktiv) settFeil(feiltekst(e)); }).finally(() => { if (aktiv) settLaster(false); });
    return () => { aktiv = false; };
  }, [sti, runde]); // eslint-disable-line react-hooks/exhaustive-deps
  return { data, feil, laster, last: () => settRunde((n) => n + 1) };
}

/** Fanger feil i en side, så menyen blir stående. nullstill: endres den (ny adresse), prøves siden på nytt. */
export class Feilgrense extends Component<{ nullstill: string; children: ReactNode }, { feilet: boolean }> {
  state = { feilet: false };
  static getDerivedStateFromError() { return { feilet: true }; }
  componentDidUpdate(forrige: { nullstill: string }) { if (forrige.nullstill !== this.props.nullstill && this.state.feilet) this.setState({ feilet: false }); }
  render() { return this.state.feilet ? <Sidefeil /> : this.props.children; }
}

function Sidefeil() {
  const t = useT();
  return (
    <MessageBar intent="error" role="alert" layout="multiline">
      <MessageBarBody>{t('arkivar.feil.side')} <Button size="small" onClick={() => window.location.reload()}>{t('arkivar.felles.last_pa_nytt')}</Button></MessageBarBody>
    </MessageBar>
  );
}
