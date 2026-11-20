// Felles tabell og filter, hentet fra Gerð (bivrost/Tabell.tsx i Gerð-prototypen), der Bivrosts felles komponenter ligger
// til de flyttes til designpakken. Kjenner ingen begreper fra appen.
// - Alle tabeller kan sorteres ved å klikke på kolonneoverskriften. Sorteringen følger språket (Intl.Collator).
// - Filter vises som et diskret ikon øverst til høyre over tabellen. Filterfeltene ligger i en sprettboks.
//   Når et filter er i bruk, er ikonet fylt og viser antall aktive filtre (ikke bare farge, UU-05).
import { CSSProperties, FocusEvent, Fragment, ReactNode, useId, useMemo, useState } from 'react';
import { useIntl } from 'react-intl';
import {
  Button, Popover, PopoverSurface, PopoverTrigger, Table, TableBody, TableCell, TableHeader, TableHeaderCell, TableRow,
  TableRowProps, makeStyles, tokens,
} from '@fluentui/react-components';
import { ChevronDown20Regular, ChevronRight20Regular, Dismiss16Regular, Filter20Filled, Filter20Regular } from '@fluentui/react-icons';
import { useSprak, useT } from '../i18n';

export type Sorteringsverdi = string | number | boolean | null | undefined;

export type Kolonne<T> = {
  id: string;
  overskrift: ReactNode;
  /** Innholdet i cellen. Indeksen er radens plass i den usorterte listen (brukes ved redigering). */
  celle: (rad: T, indeks: number) => ReactNode;
  /** Verdien det sorteres på. Kolonner uten verdi (f.eks. knapper) kan ikke sorteres. Datoer gis som ISO-tekst. */
  sorter?: (rad: T, indeks: number) => Sorteringsverdi;
  stil?: CSSProperties;
  /** Språket cellene står på, når innholdet kommer fra arkivet (norsk) og ikke fra grensesnittet. */
  sprak?: string;
};

type Sortering = { id: string; retning: 'ascending' | 'descending' };

const tom = (v: Sorteringsverdi) => v === null || v === undefined || v === '';

/** Sorterer radene etter valgt kolonne. Tomme verdier havner alltid sist. Lik verdi beholder opprinnelig rekkefølge. */
export function useSortering<T>(rader: T[], kolonner: Kolonne<T>[], start: Sortering | null = null) {
  const intl = useIntl();
  const [sortering, settSortering] = useState<Sortering | null>(start);
  const sammenlign = useMemo(() => new Intl.Collator(intl.locale, { numeric: true, sensitivity: 'base' }), [intl.locale]);
  const sortert = useMemo(() => {
    const med = rader.map((rad, indeks) => ({ rad, indeks }));
    const k = sortering && kolonner.find((x) => x.id === sortering.id);
    if (!sortering || !k?.sorter) return med;
    const retning = sortering.retning === 'ascending' ? 1 : -1;
    const verdi = (x: { rad: T; indeks: number }) => k.sorter!(x.rad, x.indeks);
    return [...med].sort((a, b) => {
      const va = verdi(a), vb = verdi(b);
      if (tom(va) || tom(vb)) return tom(va) && tom(vb) ? a.indeks - b.indeks : tom(va) ? 1 : -1;
      const r = typeof va === 'number' && typeof vb === 'number' ? va - vb
        : typeof va === 'boolean' && typeof vb === 'boolean' ? Number(va) - Number(vb)
        : sammenlign.compare(String(va), String(vb));
      return r * retning || a.indeks - b.indeks;
    });
  }, [rader, kolonner, sortering, sammenlign]);
  const klikk = (id: string) => settSortering((s) => (s?.id === id ? { id, retning: s.retning === 'ascending' ? 'descending' : 'ascending' } : { id, retning: 'ascending' }));
  return { sortert, sortering, klikk };
}

/**
 * Rullevegg rundt brede tabeller, så siden ikke ruller sidelengs (UU, mobil). Den kan få fokus (tastatur), og har derfor
 * et navn (L6): tabellens eget navn, ellers «Tabell».
 */
export function TabellVegg({ children, etikett, etikettId }: { children: ReactNode; etikett?: string; etikettId?: string }) {
  const t = useT();
  return <div className={s().tabellvegg} tabIndex={0} role="region" aria-label={etikettId ? undefined : etikett ?? t('arkivar.tabell.vegg')} aria-labelledby={etikettId}>{children}</div>;
}

type TabellProps<T> = {
  rader: T[];
  kolonner: Kolonne<T>[];
  nokkel: (rad: T, indeks: number) => string | number;
  size?: 'extra-small' | 'small' | 'medium';
  style?: CSSProperties;
  radEgenskaper?: { (rad: T, indeks: number): Partial<TableRowProps> & { [data: `data-${string}`]: string } };
  'aria-label'?: string;
  'aria-labelledby'?: string;
  /** Tabellen ligger allerede i en rullevegg. */
  utenVegg?: boolean;
  /** Redigerbar tabell: rekkefølgen fryses mens fokus er i tabellkroppen, så raden ikke flytter seg mens brukeren skriver. */
  frysVedRedigering?: boolean;
  startSortering?: Sortering | null;
  /** Språket radene er på, når det er et annet enn grensesnittet (innholdet fra arkivet er norsk). Overskriftene er oversatt. */
  kroppSprak?: string;
  /**
   * Rader som kan utvides: en knapp først i raden viser innholdet i en egen rad under. vis og skjul er navnet på
   * knappen i de to tilstandene; kan sier om raden har noe å vise (ellers får den ingen knapp).
   */
  utvid?: { innhold: (rad: T, indeks: number) => ReactNode; vis: string; skjul: string; kan?: (rad: T, indeks: number) => boolean };
};

/** Tabell der alle kolonner med sorteringsverdi kan sorteres ved klikk på overskriften (GRS-02). */
export function SorterbarTabell<T>({ rader, kolonner, nokkel, size = 'small', style, radEgenskaper, utenVegg, startSortering, frysVedRedigering, kroppSprak, utvid, ...aria }: TabellProps<T>) {
  const { sortert: levende, sortering, klikk: sorter } = useSortering(rader, kolonner, startSortering ?? null);
  const [frosset, settFrosset] = useState<number[] | null>(null);
  // Fryst rekkefølge: radene står der de stod da fokus kom inn i tabellkroppen. Nye rader legges sist.
  const sortert = useMemo(() => {
    if (!frosset) return levende;
    const finnes = new Set(levende.map((x) => x.indeks));
    const forst = frosset.filter((i) => finnes.has(i));
    const resten = levende.filter((x) => !forst.includes(x.indeks)).map((x) => x.indeks);
    return [...forst, ...resten].map((i) => ({ rad: rader[i], indeks: i }));
  }, [frosset, levende, rader]);
  const klikk = (id: string) => { settFrosset(null); sorter(id); };
  const fokusInn = (e: FocusEvent<HTMLElement>) => { if (frysVedRedigering && !frosset && (e.target as Element).closest('tbody')) settFrosset(levende.map((x) => x.indeks)); };
  const fokusUt = (e: FocusEvent<HTMLElement>) => { if (frosset && !e.currentTarget.contains(e.relatedTarget as Node | null)) settFrosset(null); };
  const c = s(); const { sprak } = useSprak();
  const idStart = useId();
  // Radene som er utvidet, etter nøkkelen. En rad som forsvinner (filter), er lukket når den kommer tilbake.
  const [apne, settApne] = useState<ReadonlySet<string | number>>(new Set());
  const veksle = (n: string | number) => settApne((gml) => { const ny = new Set(gml); if (!ny.delete(n)) ny.add(n); return ny; });
  const tabell = (
    <Table sortable size={size} style={style} aria-label={aria['aria-label']} aria-labelledby={aria['aria-labelledby']} onFocus={fokusInn} onBlur={fokusUt}>
      <TableHeader>
        <TableRow>
          {utvid && <TableHeaderCell className={c.utvidcelle} sortable={false}><span className={c.skjult}>{utvid.vis}</span></TableHeaderCell>}
          {kolonner.map((k) => k.sorter
            ? <TableHeaderCell key={k.id} style={k.stil} sortDirection={sortering?.id === k.id ? sortering.retning : undefined} onClick={() => klikk(k.id)}>{k.overskrift}</TableHeaderCell>
            : <TableHeaderCell key={k.id} style={k.stil} sortable={false}>{k.overskrift}</TableHeaderCell>)}
        </TableRow>
      </TableHeader>
      <TableBody lang={kroppSprak}>
        {sortert.map(({ rad, indeks }) => {
          const n = nokkel(rad, indeks);
          const apen = utvid !== undefined && apne.has(n);
          const under = `${idStart}-${n}`;
          return (
            <Fragment key={n}>
              <TableRow {...radEgenskaper?.(rad, indeks)}>
                {utvid && (
                  <TableCell className={c.utvidcelle} lang={sprak}>
                    {(utvid.kan?.(rad, indeks) ?? true) && (
                      <Button appearance="subtle" size="small" icon={apen ? <ChevronDown20Regular /> : <ChevronRight20Regular />} aria-expanded={apen} aria-controls={apen ? under : undefined}
                        aria-label={apen ? utvid.skjul : utvid.vis} title={apen ? utvid.skjul : utvid.vis} onClick={() => veksle(n)} />
                    )}
                  </TableCell>
                )}
                {kolonner.map((k) => <TableCell key={k.id} style={k.stil} lang={k.sprak}>{k.celle(rad, indeks)}</TableCell>)}
              </TableRow>
              {apen && (
                <TableRow id={under} className={c.utvidetrad}>
                  <TableCell colSpan={kolonner.length + 1} className={c.utvidetcelle}>{utvid.innhold(rad, indeks)}</TableCell>
                </TableRow>
              )}
            </Fragment>
          );
        })}
      </TableBody>
    </Table>
  );
  return utenVegg ? tabell : <TabellVegg etikett={aria['aria-label']} etikettId={aria['aria-labelledby']}>{tabell}</TabellVegg>;
}

const s = makeStyles({
  tabellvegg: { overflowX: 'auto' },
  utvidcelle: { width: '40px', paddingLeft: '4px', paddingRight: 0 },
  utvidetrad: { backgroundColor: tokens.colorNeutralBackground2, ':hover': { backgroundColor: tokens.colorNeutralBackground2 } },
  utvidetcelle: { height: 'auto', whiteSpace: 'normal' },
  skjult: { position: 'absolute', width: '1px', height: '1px', overflow: 'hidden', clipPath: 'inset(50%)', whiteSpace: 'nowrap' },
  flate: { display: 'flex', flexDirection: 'column', gap: '12px', width: 'min(320px, calc(100vw - 48px))' },
  tittel: { margin: 0, fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold },
  felt: { display: 'flex', flexDirection: 'column', gap: '12px', '& .fui-Input, & .fui-Select': { width: '100%', minWidth: 0 } },
  verktoy: { display: 'flex', alignItems: 'center', justifyContent: 'flex-end', gap: '8px', minHeight: '32px', marginBottom: '4px' },
  tekst: { marginRight: 'auto', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
});

/**
 * Diskret filterikon (GRS-01). Plasseres øverst til høyre over tabellen, f.eks. i kortets handlinger eller i TabellVerktoy.
 * Barna er filterfeltene. Antall aktive filtre vises som tall ved ikonet.
 */
export function FilterKnapp({ antallAktive, children, onNullstill }: { antallAktive: number; children: ReactNode; onNullstill?: () => void }) {
  const t = useT(); const c = s();
  const etikett = antallAktive ? t('arkivar.filter.aktive', { antall: antallAktive }) : t('arkivar.filter.knapp');
  return (
    <Popover positioning="below-end" trapFocus>
      <PopoverTrigger disableButtonEnhancement>
        <Button appearance="subtle" size="small" icon={antallAktive ? <Filter20Filled /> : <Filter20Regular />} aria-label={etikett} title={etikett}>
          {antallAktive > 0 ? <span aria-hidden="true">{antallAktive}</span> : null}
        </Button>
      </PopoverTrigger>
      <PopoverSurface aria-label={t('arkivar.filter.tittel')} className={c.flate}>
        <h3 className={c.tittel}>{t('arkivar.filter.tittel')}</h3>
        <div className={c.felt}>{children}</div>
        {onNullstill && <div><Button size="small" icon={<Dismiss16Regular />} disabledFocusable={!antallAktive} onClick={() => { if (antallAktive) onNullstill(); }}>{t('arkivar.filter.nullstill')}</Button></div>}
      </PopoverSurface>
    </Popover>
  );
}

/** Linje over en tabell uten korthode: tekst til venstre (f.eks. antall), filterikonet øverst til høyre. */
export function TabellVerktoy({ tekst, children }: { tekst?: ReactNode; children: ReactNode }) {
  const c = s();
  return <div className={c.verktoy}>{tekst && <span className={c.tekst} role="status">{tekst}</span>}{children}</div>;
}
