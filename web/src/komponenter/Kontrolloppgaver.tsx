// Kontrolloppgavene til arkivtjenesten: tellerne (hentet én gang og oppdatert når en oppgave er løst) og den
// utvidbare undermenyen i sidemenyen med en teller per liste.
import { ReactNode, createContext, useContext, useMemo, useState } from 'react';
import { NavLink } from 'react-router-dom';
import { makeStyles, mergeClasses, tokens } from '@fluentui/react-components';
import { ChevronDown16Regular, ChevronRight16Regular, ClipboardTaskListLtr20Regular } from '@fluentui/react-icons';
import { useT } from '../i18n';
import { useHent } from './Felles';

export type Kontrolltall = { journalforing: { inngaende: number; interne: number; utgaende: number }; saker: { kontroll: number; avslutning: number } };
/** Listene med kontrolloppgaver, i den rekkefølgen de står i menyen. Navnet er også siste ledd i adressen. */
export const KONTROLLISTER = ['inngaende', 'interne', 'utgaende', 'saker-kontroll', 'saker-avslutning'] as const;
export type Kontrolliste = typeof KONTROLLISTER[number];

type Verdi = { tall: Kontrolltall | null; oppdater: () => void };
const Ctx = createContext<Verdi>({ tall: null, oppdater: () => undefined });
export const useKontrolltall = (): Verdi => useContext(Ctx);

/** Antallet i én liste. */
export function antallI(tall: Kontrolltall | null, liste: Kontrolliste): number {
  if (!tall) return 0;
  if (liste === 'saker-kontroll') return tall.saker.kontroll;
  if (liste === 'saker-avslutning') return tall.saker.avslutning;
  return tall.journalforing[liste];
}

/** Henter tellerne for den som har et øvingsarkiv (aktiv). Sidene kaller oppdater() når en oppgave er løst. */
export function KontrolltallTilbyder({ aktiv, children }: { aktiv: boolean; children: ReactNode }) {
  const hentet = useHent<Kontrolltall>(aktiv ? '/api/arkiv/kontroll' : null);
  const verdi = useMemo(() => ({ tall: hentet.data, oppdater: hentet.last }), [hentet.data, hentet.last]);
  return <Ctx.Provider value={verdi}>{children}</Ctx.Provider>;
}

const s = makeStyles({
  knapp: {
    display: 'flex', gap: '8px', alignItems: 'center', width: '100%', padding: '8px', border: 'none', background: 'transparent', color: tokens.colorNeutralForeground1,
    borderRadius: tokens.borderRadiusMedium, font: 'inherit', textAlign: 'left', cursor: 'pointer', minHeight: '24px', ':hover': { background: tokens.colorNeutralBackground3 },
  },
  navn: { flexGrow: 1, minWidth: 0 },
  // Telleren er et tall i en rute. Null oppgaver vises dempet, så det som gjenstår, skiller seg ut.
  teller: {
    minWidth: '22px', padding: '0 6px', borderRadius: tokens.borderRadiusCircular, textAlign: 'center', fontSize: tokens.fontSizeBase200, fontWeight: tokens.fontWeightSemibold,
    fontVariantNumeric: 'tabular-nums', backgroundColor: tokens.colorBrandBackground, color: tokens.colorNeutralForegroundOnBrand,
  },
  tom: { backgroundColor: tokens.colorNeutralBackground4, color: tokens.colorNeutralForeground2 },
  under: { listStyleType: 'none', margin: 0, padding: '0 0 4px 28px' },
  gruppe: { fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground3, margin: '6px 8px 2px', fontWeight: tokens.fontWeightSemibold },
  lenke: {
    display: 'flex', gap: '8px', alignItems: 'center', padding: '6px 8px', borderRadius: tokens.borderRadiusMedium, color: tokens.colorNeutralForeground1, textDecoration: 'none',
    ':hover': { background: tokens.colorNeutralBackground3 },
  },
  aktiv: { background: tokens.colorBrandBackground2, fontWeight: tokens.fontWeightSemibold, boxShadow: `inset 3px 0 0 ${tokens.colorBrandStroke1}` },
  skjult: { position: 'absolute', width: '1px', height: '1px', overflow: 'hidden', clipPath: 'inset(50%)', whiteSpace: 'nowrap' },
});

/** Telleren ved en liste. Tallet står synlig, og hva det teller, leses opp. */
function Teller({ antall }: { antall: number }) {
  const c = s(); const t = useT();
  return <span className={mergeClasses(c.teller, antall === 0 && c.tom)}><span aria-hidden="true">{antall}</span><span className={c.skjult}>{t('arkivar.kontroll.antall', { antall })}</span></span>;
}

const GRUPPER: { tittel: string; lister: Kontrolliste[] }[] = [
  { tittel: 'arkivar.kontroll.journalforing', lister: ['inngaende', 'interne', 'utgaende'] },
  { tittel: 'arkivar.kontroll.saksmapper', lister: ['saker-kontroll', 'saker-avslutning'] },
];

/** Undermenyen «Kontrolloppgaver» i sidemenyen: utvidbar, med samlet teller på knappen og en teller per liste. */
export function Kontrollmeny() {
  const c = s(); const t = useT(); const { tall } = useKontrolltall();
  const [apen, settApen] = useState(true);
  const alle = KONTROLLISTER.reduce((sum, l) => sum + antallI(tall, l), 0);
  return (
    <div>
      <button type="button" className={c.knapp} aria-expanded={apen} aria-controls="kontrollmeny" onClick={() => settApen(!apen)}>
        <ClipboardTaskListLtr20Regular />
        <span className={c.navn}>{t('arkivar.kontroll.tittel')}</span>
        <Teller antall={alle} />
        {apen ? <ChevronDown16Regular /> : <ChevronRight16Regular />}
      </button>
      {apen && (
        <div id="kontrollmeny">
          {GRUPPER.map((g) => (
            <div key={g.tittel} role="group" aria-label={t(g.tittel)}>
              <div className={c.gruppe} aria-hidden="true">{t(g.tittel)}</div>
              <ul className={c.under}>
                {g.lister.map((l) => (
                  <li key={l}>
                    <NavLink to={`/arkiv/kontroll/${l}`} className={({ isActive }) => mergeClasses(c.lenke, isActive && c.aktiv)}>
                      <span className={c.navn}>{t(`arkivar.kontroll.liste.${l.replace('-', '_')}`)}</span>
                      <Teller antall={antallI(tall, l)} />
                    </NavLink>
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
