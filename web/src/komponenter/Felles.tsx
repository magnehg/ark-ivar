// Felles komponenter og økten. Status vises alltid med ikon og tekst, aldri bare med farge.
import { Component, ReactNode, createContext, useContext, useEffect, useRef, useState } from 'react';
import {
  Body1, Button, Dialog, DialogActions, DialogBody, DialogContent, DialogSurface, DialogTitle, MessageBar, MessageBarActions, MessageBarBody, Popover, PopoverSurface, PopoverTrigger, Spinner, Title2,
  makeStyles, tokens,
} from '@fluentui/react-components';
import { ArrowUndo20Regular, QuestionCircle16Regular } from '@fluentui/react-icons';
import { Link as Ruterlenke } from 'react-router-dom';
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
  hjelp: { maxWidth: '44ch' },
  hjelpknapp: { minWidth: '24px', verticalAlign: 'middle' },
  dialog: { maxWidth: 'min(760px, calc(100vw - 32px))' },
  dialoginnhold: { display: 'flex', flexDirection: 'column', gap: '8px', '& p': { margin: 0 } },
  kvittering: { marginBottom: '12px', ':focus-visible': { outline: `2px solid ${tokens.colorStrokeFocus2}`, outlineOffset: '2px' } },
});

/**
 * tittelSprak: språket tittelen står på, når den kommer fra arkivet (norsk) og ikke fra grensesnittet. Ingressen kan
 * være ledeteksten fulgt av en verdi fra arkivet i et eget element med lang="nb".
 */
export function Sidehode({ tittel, ingress, handlinger, tittelSprak }: { tittel: string; ingress?: ReactNode; handlinger?: ReactNode; tittelSprak?: string }) {
  const c = s();
  const { oppsett } = useOkt();
  useEffect(() => { document.title = `${tittel} – ${oppsett.appnavn}`; }, [tittel, oppsett.appnavn]);
  return (
    <div className={c.hode}>
      <div><Title2 as="h1" lang={tittelSprak}>{tittel}</Title2>{ingress && <Body1 className={c.ingress}>{ingress}</Body1>}</div>
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

/**
 * Et hjelpeikon (spørsmålstegn) som viser en forklaring når det trykkes. navn sier hva forklaringen gjelder, og leses
 * opp som navnet på knappen.
 */
export function Hjelp({ navn, children }: { navn: string; children: ReactNode }) {
  const c = s();
  return (
    <Popover withArrow>
      <PopoverTrigger disableButtonEnhancement>
        <Button className={c.hjelpknapp} appearance="subtle" size="small" icon={<QuestionCircle16Regular />} aria-label={navn} title={navn} />
      </PopoverTrigger>
      <PopoverSurface className={c.hjelp} aria-label={navn}>{children}</PopoverSurface>
    </Popover>
  );
}

type Dialogegenskaper = {
  tittel: ReactNode; children: ReactNode; lukk: () => void;
  /** Knappene nederst. Uten har innholdet sine egne (et skjema med Lagre og Avbryt). */
  knapper?: ReactNode;
};

// Det siste som hadde fokus utenfor menyer og dialoger: knappen som åpnet menyen eller dialogen. En dialog som
// åpnes fra et menyvalg, har ingen utløser Fluent kan gi fokus tilbake til (menyvalget er borte når dialogen
// lukkes), så vi husker den selv.
let sisteFokus: HTMLElement | null = null;
if (typeof document !== 'undefined') {
  document.addEventListener('focusin', (e) => {
    const el = e.target;
    if (el instanceof HTMLElement && !el.closest('[role="menu"], [role="dialog"], [role="alertdialog"]')) sisteFokus = el;
  });
}

/**
 * En dialog over siden. Den lukkes med Escape og med knappene, og fokus går tilbake til det som åpnet den (knappen
 * eller menyknappen). Er det borte, eller kommer det en kvittering, tar kvitteringen fokus (Kvittering).
 */
export function Dialogvindu({ tittel, children, lukk, knapper }: Dialogegenskaper) {
  const c = s();
  const [utloser] = useState(() => sisteFokus);
  useEffect(() => () => {
    // Etter at dialogen er tatt bort: fokus tilbake dit det var, om det ikke alt er satt et annet sted.
    window.setTimeout(() => {
      const aktiv = document.activeElement;
      if (utloser?.isConnected && (aktiv === null || aktiv === document.body)) utloser.focus();
    }, 0);
  }, [utloser]);
  return (
    <Dialog open onOpenChange={(_, d) => { if (!d.open) lukk(); }}>
      <DialogSurface className={c.dialog}>
        <DialogBody>
          <DialogTitle>{tittel}</DialogTitle>
          <DialogContent className={c.dialoginnhold}>{children}</DialogContent>
          {knapper && <DialogActions>{knapper}</DialogActions>}
        </DialogBody>
      </DialogSurface>
    </Dialog>
  );
}

type Bekreftelse = { tittel: ReactNode; children: ReactNode; bekreft: () => void; bekreftTekst: string; avbryt: () => void; opptatt?: boolean; feil?: string | null };

/** Bekreftelsen før noe som ikke kan angres: hva som skjer, og knappene som gjør det eller avbryter. */
export function Bekreft({ tittel, children, bekreft, bekreftTekst, avbryt, opptatt, feil }: Bekreftelse) {
  const t = useT();
  return (
    <Dialogvindu tittel={tittel} lukk={avbryt} knapper={<>
      <Button appearance="primary" disabled={opptatt} onClick={bekreft}>{bekreftTekst}</Button>
      <Button disabled={opptatt} onClick={avbryt}>{t('arkivar.felles.avbryt')}</Button>
    </>}>
      {children}
      {feil && <Feil melding={feil} />}
    </Dialogvindu>
  );
}

export type Kvitteringsdata = {
  tekst: string;
  /** Det som angrer handlingen, når det går an. */
  angre?: { (): void };
  /** En lenke videre, for eksempel til mappen journalposten ble flyttet til. */
  lenke?: { til: string; tekst: string };
};

/** En kvittering siden eier selv, når flere sett med handlinger på samme side skal dele én (mappen og journalpostene). */
export type DeltKvittering = { kvittering: Kvitteringsdata | null; sett: (kvittering: Kvitteringsdata | null) => void };

/**
 * Kvitteringen etter en handling: hva som skjedde, med «Angre» der det går an. Den får fokus når den kommer, så den
 * blir lest opp og tastaturet står ved «Angre»: knappen eller raden som utløste handlingen, kan være borte.
 */
export function Kvittering({ kvittering, opptatt }: { kvittering: Kvitteringsdata; opptatt?: boolean }) {
  const c = s(); const t = useT();
  const flate = useRef<HTMLDivElement>(null);
  useEffect(() => {
    // Litt etter at dialogen har gitt fokus tilbake til utløseren, så kvitteringen er den som står igjen med det.
    const vent = window.setTimeout(() => flate.current?.focus(), 60);
    return () => window.clearTimeout(vent);
    // Teksten, ikke objektet: siden rundt kan lage et nytt objekt med samme kvittering hver gang den tegnes.
  }, [kvittering.tekst]);
  return (
    <MessageBar ref={flate} tabIndex={-1} className={c.kvittering} intent="success" role="status">
      <MessageBarBody>{kvittering.tekst}{kvittering.lenke && <> <Ruterlenke to={kvittering.lenke.til}>{kvittering.lenke.tekst}</Ruterlenke></>}</MessageBarBody>
      {kvittering.angre && <MessageBarActions><Button size="small" icon={<ArrowUndo20Regular />} disabled={opptatt} onClick={kvittering.angre}>{t('arkivar.kvittering.angre')}</Button></MessageBarActions>}
    </MessageBar>
  );
}

/** En feil ved henting, med en knapp som prøver på nytt. */
export function Hentefeil({ melding, last }: { melding: string; last: () => void }) {
  const t = useT();
  return (
    <MessageBar intent="error" role="alert" layout="multiline">
      <MessageBarBody>{melding} <Button size="small" onClick={last}>{t('arkivar.felles.prov_igjen')}</Button></MessageBarBody>
    </MessageBar>
  );
}

/**
 * Henter data og håndterer lasting og feil. last() henter på nytt. Data og feil gjelder alltid adressen som er gitt
 * nå: byttes adressen, er data tom til det nye svaret kommer, så svaret fra den forrige adressen aldri vises som om
 * det hørte til den nye. Det gjelder også når adressen forlates og kommer tilbake (en feil fra forrige gang skal ikke
 * gjelde for en ny henting). Når samme adresse hentes på nytt med last(), står det som vises, til svaret er der.
 */
export function useHent<T>(sti: string | null): { data: T | null; feil: string | null; laster: boolean; last: () => void } {
  const feiltekst = useFeiltekst();
  const [svar, settSvar] = useState<{ sti: string; runde: number; data: T | null; feil: string | null } | null>(null);
  const [runde, settRunde] = useState(0);
  // Svaret hører til adressen det ble hentet for. Byttes adressen (også til ingen), glemmes det.
  const [forrigeSti, settForrigeSti] = useState(sti);
  if (forrigeSti !== sti) {
    settForrigeSti(sti);
    settSvar(null);
  }
  useEffect(() => {
    if (!sti) return;
    let aktiv = true;
    api.get<T>(sti)
      .then((d) => { if (aktiv) settSvar({ sti, runde, data: d, feil: null }); })
      .catch((e: unknown) => { if (aktiv) settSvar((forrige) => ({ sti, runde, data: forrige && forrige.sti === sti ? forrige.data : null, feil: feiltekst(e) })); });
    return () => { aktiv = false; };
  }, [sti, runde]); // eslint-disable-line react-hooks/exhaustive-deps
  const gjeldende = svar && svar.sti === sti ? svar : null;
  const ferdig = gjeldende !== null && gjeldende.runde === runde;
  return { data: gjeldende ? gjeldende.data : null, feil: ferdig ? gjeldende.feil : null, laster: sti !== null && !ferdig, last: () => settRunde((n) => n + 1) };
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
