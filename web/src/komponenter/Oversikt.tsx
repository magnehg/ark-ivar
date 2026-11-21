// Oversiktene (mapper, dokumenter i en mappe, journalen og søketreff) kan vises på tre måter: i kolonner (tabell),
// med tittelen først, eller på to linjer. Brukeren velger med ikonene over oversikten, og valget lagres på kontoen
// (PUT /api/meg/valg), så det følger brukeren fra maskin til maskin og gjelder alle oversiktene.
import { ReactNode, createContext, useCallback, useContext, useId, useMemo, useState } from 'react';
import { Button, Menu, MenuButton, MenuItemRadio, MenuList, MenuPopover, MenuTrigger, ToggleButton, makeStyles, tokens } from '@fluentui/react-components';
import { ArrowSort20Regular, ArrowSortDown20Regular, ArrowSortUp20Regular, ChevronDown20Regular, ChevronRight20Regular, List20Regular, Table20Regular, TextBulletListSquare20Regular } from '@fluentui/react-icons';
import { api } from '../api';
import { useSprak, useT } from '../i18n';
import { Laster, useHent } from './Felles';
import { Apne, LENKE, SettApne, SorterbarTabell, Sortering, TabellProps, useRyddApne, useSortering } from './Tabell';

export type Listevisning = 'kolonner' | 'tittel' | 'linjer';
const VISNINGER: Listevisning[] = ['kolonner', 'tittel', 'linjer'];
const erVisning = (v: unknown): v is Listevisning => VISNINGER.includes(v as Listevisning);

/** klar: valget er hentet fra kontoen (eller lot seg ikke hente). Før det vet ikke oversikten hvilken visning den skal ha. */
type Visningsverdi = { visning: Listevisning; klar: boolean; settVisning: (v: Listevisning) => void };
const VisningCtx = createContext<Visningsverdi>({ visning: 'kolonner', klar: true, settVisning: () => undefined });
export const useVisning = (): Visningsverdi => useContext(VisningCtx);

/**
 * Henter valget fra kontoen og lagrer det når brukeren bytter. Lar det seg ikke hente eller lagre (driftsbrukeren
 * har ingen konto i appen), gjelder valget bare til siden lastes på nytt.
 */
export function VisningTilbyder({ children }: { children: ReactNode }) {
  const hentet = useHent<{ valg: { listevisning?: string } }>('/api/meg/valg');
  const [valgt, settValgt] = useState<Listevisning | null>(null);
  const lagret = hentet.data?.valg.listevisning;
  const visning = valgt ?? (erVisning(lagret) ? lagret : 'kolonner');
  const klar = valgt !== null || hentet.data !== null || hentet.feil !== null;
  const settVisning = useCallback((v: Listevisning) => {
    settValgt(v);
    api.put('/api/meg/valg', { valg: 'listevisning', verdi: v }).catch(() => undefined);
  }, []);
  const verdi = useMemo(() => ({ visning, klar, settVisning }), [visning, klar, settVisning]);
  return <VisningCtx.Provider value={verdi}>{children}</VisningCtx.Provider>;
}

const s = makeStyles({
  verktoy: { display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: '8px 16px', flexWrap: 'wrap', marginBottom: '6px' },
  sortering: { display: 'flex', alignItems: 'center', gap: '2px' },
  valgt: { fontWeight: tokens.fontWeightSemibold },
  velger: { display: 'flex', gap: '2px', marginLeft: 'auto' },
  liste: { listStyleType: 'none', margin: 0, padding: 0, borderTop: `1px solid ${tokens.colorNeutralStroke2}`, '& a': LENKE },
  post: { borderBottom: `1px solid ${tokens.colorNeutralStroke2}` },
  rad: { display: 'flex', alignItems: 'flex-start', gap: '4px', padding: '8px 0' },
  knapp: { flexShrink: 0 },
  utenknapp: { width: '24px', flexShrink: 0 },
  innhold: { flexGrow: 1, minWidth: 0 },
  under: { backgroundColor: tokens.colorNeutralBackground2, padding: '0 8px' },
});

const IKON: Record<Listevisning, JSX.Element> = { kolonner: <Table20Regular />, tittel: <TextBulletListSquare20Regular />, linjer: <List20Regular /> };

/** De tre ikonene som bytter visning. Det valgte er trykket inn, og hvert ikon har navnet som tekst for skjermlesere. */
export function Visningsvelger() {
  const c = s(); const t = useT(); const { visning, settVisning } = useVisning();
  return (
    <div className={c.velger} role="group" aria-label={t('arkivar.visning.velg')}>
      {VISNINGER.map((v) => (
        <ToggleButton key={v} appearance="subtle" size="small" icon={IKON[v]} checked={visning === v} aria-label={t(`arkivar.visning.${v}`)} title={t(`arkivar.visning.${v}`)} onClick={() => settVisning(v)} />
      ))}
    </div>
  );
}

type OversiktProps<T> = TabellProps<T> & {
  /** Én rad i visningene uten kolonner: med tittelen først, og på to linjer. */
  visninger: { tittel: (rad: T, indeks: number) => ReactNode; linjer: (rad: T, indeks: number) => ReactNode };
};

/**
 * En oversikt i den visningen brukeren har valgt. I kolonner er den tabellen (SorterbarTabell). Ellers er den en
 * liste med de samme radene. Sorteringen og de utvidede radene eies her, så de står når visningen byttes, og
 * verktøylinjen med ikonene tegnes ett sted, så fokus blir stående på ikonet som ble valgt.
 */
export function Oversikt<T>({ visninger, ...tabell }: OversiktProps<T>) {
  const c = s(); const t = useT(); const { visning, klar } = useVisning();
  const { rader, kolonner, nokkel, startSortering, utvid } = tabell;
  const [sortering, settSortering] = useState<Sortering | null>(startSortering ?? null);
  const [apne, settApne] = useState<Apne>(new Set());
  const styrt = { sortering, settSortering, apne, settApne };
  const { sortert, velg } = useSortering(rader, kolonner, null, styrt);
  useRyddApne(rader, nokkel, utvid !== undefined, settApne);
  const sorterbare = kolonner.filter((k) => k.sorter);
  const valgt = sorterbare.find((k) => k.id === sortering?.id);
  const stigende = sortering?.retning !== 'descending';
  const retning = t(stigende ? 'arkivar.visning.stigende' : 'arkivar.visning.synkende');
  // Før valget er hentet fra kontoen, vises ingen av visningene: ellers byttes tabellen ut under brukeren.
  if (!klar) return <Laster />;
  return (
    <>
      <div className={c.verktoy}>
        {/* I kolonner sorteres det med overskriftene. I listene er det en meny, ikke et skjemafelt. */}
        {visning !== 'kolonner' && sorterbare.length > 0 && (
          <div className={c.sortering}>
            <Menu checkedValues={{ kolonne: sortering ? [sortering.id] : [] }} onCheckedValueChange={(_, d) => velg(d.checkedItems[0] ?? '', sortering?.retning ?? 'ascending')}>
              <MenuTrigger disableButtonEnhancement>
                <MenuButton appearance="subtle" size="small" icon={<ArrowSort20Regular />}>
                  <span>{t('arkivar.visning.sorter')}{valgt && <>: <span className={c.valgt}>{valgt.overskrift}</span></>}</span>
                </MenuButton>
              </MenuTrigger>
              <MenuPopover>
                <MenuList>
                  {sorterbare.map((k) => <MenuItemRadio key={k.id} name="kolonne" value={k.id}>{k.overskrift}</MenuItemRadio>)}
                </MenuList>
              </MenuPopover>
            </Menu>
            <Button appearance="subtle" size="small" icon={stigende ? <ArrowSortUp20Regular /> : <ArrowSortDown20Regular />} aria-label={retning} title={retning}
              disabled={!sortering} onClick={() => sortering && velg(sortering.id, stigende ? 'descending' : 'ascending')} />
          </div>
        )}
        <Visningsvelger />
      </div>
      {visning === 'kolonner'
        ? <SorterbarTabell {...tabell} styrt={styrt} />
        : <Radliste sortert={sortert} nokkel={nokkel} utvid={utvid} apne={apne} settApne={settApne} kroppSprak={tabell.kroppSprak} aria-label={tabell['aria-label']} aria-labelledby={tabell['aria-labelledby']} rad={visninger[visning]} />}
    </>
  );
}

type RadlisteProps<T> = Pick<TabellProps<T>, 'nokkel' | 'utvid' | 'kroppSprak' | 'aria-label' | 'aria-labelledby'> & {
  sortert: { rad: T; indeks: number }[]; apne: Apne; settApne: SettApne; rad: (rad: T, indeks: number) => ReactNode;
};

function Radliste<T>({ sortert, nokkel, utvid, apne, settApne, kroppSprak, rad, ...aria }: RadlisteProps<T>) {
  const c = s(); const { sprak } = useSprak();
  const idStart = useId();
  const veksle = (n: string | number) => settApne((gml) => { const ny = new Set(gml); if (!ny.delete(n)) ny.add(n); return ny; });
  return (
    <ul className={c.liste} aria-label={aria['aria-label']} aria-labelledby={aria['aria-labelledby']} lang={kroppSprak}>
      {sortert.map(({ rad: r, indeks }) => {
        const n = nokkel(r, indeks);
        const apen = utvid !== undefined && apne.has(n);
        const under = `${idStart}-${n}`;
        const navn = utvid ? (apen ? utvid.skjul : utvid.vis) : '';
        return (
          <li key={n} className={c.post}>
            <div className={c.rad}>
              {utvid && ((utvid.kan?.(r, indeks) ?? true)
                ? <Button className={c.knapp} appearance="subtle" size="small" icon={apen ? <ChevronDown20Regular /> : <ChevronRight20Regular />} aria-expanded={apen} aria-controls={apen ? under : undefined}
                    aria-label={navn} title={navn} aria-describedby={`${under}-rad`} lang={sprak} onClick={() => veksle(n)} />
                : <span className={c.utenknapp} />)}
              <div className={c.innhold} id={`${under}-rad`}>{rad(r, indeks)}</div>
            </div>
            {apen && <div id={under} className={c.under}>{utvid.innhold(r, indeks)}</div>}
          </li>
        );
      })}
    </ul>
  );
}
