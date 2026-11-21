// Dokumentfilene til journalpostene: filikoner for produksjonsformat og arkivformat, listen som vises når en rad i en
// oversikt er utvidet, og lesevinduet som åpnes til høyre når studenten klikker på en fil. Øvingsarkivet har ikke
// virkelige filer: lesevinduet viser dokumentet slik filen ville sett ut (som ark, eller som ren tekst for txt).
import { KeyboardEvent as ReactKeyboardEvent, ReactNode, createContext, useCallback, useContext, useEffect, useId, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { Link as Ruterlenke, useLocation } from 'react-router-dom';
import { Button, MessageBar, MessageBarBody, DrawerBody, DrawerHeader, DrawerHeaderTitle, OverlayDrawer, Text, makeStyles, mergeClasses, tokens } from '@fluentui/react-components';
import { ChevronDown20Regular, ChevronRight20Regular, Dismiss24Regular } from '@fluentui/react-icons';
import { Dokument, Dokumentbeskrivelse, Dokumentfil, Journalpost, del, hovedfil } from '../arkiv';
import { useSprak, useT } from '../i18n';
import { Ark } from './Ark';
import { Hentefeil, Laster, useHent } from './Felles';

/**
 * Bredden på lesevinduet. Siden ved siden av får like mye marg mens vinduet er åpent. Brukeren kan dra i kanten
 * mellom siden og vinduet; da står bredden i CSS-variabelen, ellers gjelder standarden.
 */
const BREDDE = 'var(--arkivar-lesevindu-bredde, min(680px, 44vw))';
const BREDDEVARIABEL = '--arkivar-lesevindu-bredde';
/**
 * Minste bredde på lesevinduet og på siden ved siden av (venstremenyen og en brukbar bredde til innholdet), og
 * steget piltastene flytter kanten.
 */
const MINST = 360;
const MINST_SIDE = 720;
const STEG = 24;
/** Bredden huskes i nettleseren: den hører til skjermen, ikke kontoen. */
const LAGERNOKKEL = 'arkivar-lesevindu-bredde';

/** Bredden holdt innenfor det skjermen har plass til: vinduet og siden ved siden av skal begge kunne brukes. */
const innenfor = (bredde: number): number => Math.round(Math.min(Math.max(bredde, MINST), Math.max(MINST, window.innerWidth - MINST_SIDE)));

function lagretBredde(): number | null {
  try {
    const tall = Number(window.localStorage.getItem(LAGERNOKKEL));
    return Number.isFinite(tall) && tall >= MINST ? innenfor(tall) : null;
  } catch { return null; }
}
function huskBredde(bredde: number | null): void {
  try {
    if (bredde === null) window.localStorage.removeItem(LAGERNOKKEL); else window.localStorage.setItem(LAGERNOKKEL, String(Math.round(bredde)));
  } catch { /* uten lager gjelder bredden til siden lastes på nytt */ }
}

const s = makeStyles({
  ikon: { flexShrink: 0, display: 'block' },
  side: { fill: tokens.colorNeutralBackground1, stroke: tokens.colorNeutralForeground2, strokeWidth: 1.25 },
  // Produksjonsformat: merket har bare ramme. Arkivformat: merket er fylt. Forskjellen står også som tekst ved filen.
  merkeP: { fill: tokens.colorNeutralBackground1, stroke: tokens.colorNeutralForeground1, strokeWidth: 1.25 },
  merkeA: { fill: tokens.colorBrandBackground, stroke: tokens.colorBrandBackground, strokeWidth: 1.25 },
  kodeP: { fill: tokens.colorNeutralForeground1, fontSize: '7.5px', fontWeight: 700, fontFamily: tokens.fontFamilyBase },
  kodeA: { fill: tokens.colorNeutralForegroundOnBrand, fontSize: '7.5px', fontWeight: 700, fontFamily: tokens.fontFamilyBase },
  liste: { listStyleType: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '2px' },
  fil: { justifyContent: 'flex-start', textAlign: 'left', maxWidth: '100%', minWidth: 0, fontWeight: tokens.fontWeightRegular },
  valgt: { backgroundColor: tokens.colorNeutralBackground1Selected },
  filtekst: { display: 'flex', flexDirection: 'column', minWidth: 0 },
  filnavn: { overflowWrap: 'anywhere' },
  dempet: { color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
  utvidet: { padding: '8px 0 12px 8px' },
  post: { margin: '0 0 2px', fontWeight: tokens.fontWeightSemibold, fontSize: tokens.fontSizeBase300 },
  poster: { listStyleType: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '4px' },
  versjoner: { listStyleType: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '8px' },
  versjon: { display: 'block', fontWeight: tokens.fontWeightSemibold, fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground2, marginBottom: '2px' },
  dokument: { display: 'inline-flex', alignItems: 'flex-start', gap: '8px', minWidth: 0 },
  dokumentrad: { display: 'flex', alignItems: 'flex-start', gap: '4px' },
  utenknapp: { width: '24px', flexShrink: 0 },
  under: { padding: '4px 0 8px 32px' },
  rolle: { color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, fontWeight: tokens.fontWeightSemibold, textTransform: 'uppercase', letterSpacing: '0.03em', marginRight: '4px' },
  vedlegg: { display: 'flex', flexDirection: 'column', gap: '8px', padding: '20px', backgroundColor: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}` },
  // Siden gir plass til vinduet (plass), så ingenting ligger under det. På smale skjermer dekker vinduet hele bredden.
  skuff: { width: BREDDE, '@media (max-width: 900px)': { width: '100vw' } },
  // Kanten mellom siden og lesevinduet. Den kan dras sidelengs, og flyttes med piltastene når den har fokus.
  kant: {
    position: 'absolute', top: 0, bottom: 0, left: 0, width: '9px', cursor: 'col-resize', zIndex: 10, touchAction: 'none',
    display: 'flex', alignItems: 'center', justifyContent: 'center',
    '::after': { content: '""', width: '3px', height: '40px', borderRadius: '2px', backgroundColor: tokens.colorNeutralStroke1 },
    ':hover': { backgroundColor: tokens.colorNeutralBackground1Hover },
    ':hover::after': { backgroundColor: tokens.colorBrandStroke1 },
    ':focus-visible': { outline: `2px solid ${tokens.colorStrokeFocus2}`, outlineOffset: '-2px' },
    '@media (max-width: 900px)': { display: 'none' },
  },
  dras: { backgroundColor: tokens.colorNeutralBackground1Pressed, '::after': { backgroundColor: tokens.colorBrandStroke1 } },
  plass: { '@media (min-width: 901px)': { paddingRight: BREDDE } },
  hodetekst: { display: 'flex', gap: '10px', alignItems: 'center', minWidth: 0 },
  kropp: { backgroundColor: tokens.colorNeutralBackground3, paddingTop: '16px', paddingBottom: '24px' },
  lenke: { display: 'block', marginBottom: '12px' },
  // En fil i ren tekst: fast tegnbredde på en flate som et tekstvindu.
  tekstfil: {
    margin: 0, padding: '20px', whiteSpace: 'pre-wrap', overflowWrap: 'anywhere', fontFamily: tokens.fontFamilyMonospace, fontSize: tokens.fontSizeBase300, lineHeight: tokens.lineHeightBase400,
    backgroundColor: tokens.colorNeutralBackground1, color: tokens.colorNeutralForeground1, border: `1px solid ${tokens.colorNeutralStroke2}`, boxShadow: tokens.shadow4,
  },
});

/** Filikonet: et ark med filtypen på et merke. Merket er fylt for arkivformat og har bare ramme for produksjonsformat. */
export function Filikon({ fil, storrelse = 32 }: { fil: Dokumentfil; storrelse?: number }) {
  const c = s();
  const arkiv = fil.variantformat === 'A';
  return (
    <svg className={c.ikon} width={storrelse * 0.875} height={storrelse} viewBox="0 0 28 32" aria-hidden="true" focusable="false">
      <path className={c.side} d="M4.6 1.6h12.6l6.2 6.2v22.6H4.6z M17.2 1.6v6.2h6.2" strokeLinejoin="round" />
      <rect className={arkiv ? c.merkeA : c.merkeP} x="0.8" y="14.5" width="26.4" height="11" rx="1.5" />
      <text className={arkiv ? c.kodeA : c.kodeP} x="14" y="22.7" textAnchor="middle">{fil.format.toUpperCase()}</text>
    </svg>
  );
}

// ------------------------------------------------------------------ lesevinduet
/** Filen som er åpen: journalposten, dokumentet i den (1 = hoveddokumentet) og varianten. */
type Apen = { journalpost: string; sak: string; dokument: number; tittel: string; fil: Dokumentfil; utkast: boolean };
type Lesevindu = { apen: Apen | null; apne: (valg: Apen) => void; lukk: () => void };
const LesevinduCtx = createContext<Lesevindu>({ apen: null, apne: () => undefined, lukk: () => undefined });
export const useLesevindu = (): Lesevindu => useContext(LesevinduCtx);

/** Datoen slik den står i en norsk tekstfil, uansett språk i grensesnittet: 12.10.2026. */
const norskDato = (iso: string): string => iso.slice(0, 10).split('-').reverse().join('.');

/**
 * Dokumentet som ren tekst, slik en e-post ligger i arkivformat. Linjene «Fra:», «Emne:» og de andre er en del av
 * den norske filen og ikke tekst i grensesnittet, så de oversettes ikke.
 */
function somTekst(dok: Dokument): string {
  const linjer = [`Fra: ${[dok.avsender.navn, dok.avsender.epost ? `<${dok.avsender.epost}>` : ''].filter(Boolean).join(' ')}`];
  if (dok.mottaker) linjer.push(`Til: ${dok.mottaker}`);
  linjer.push(`Dato: ${norskDato(dok.dokumentdato)}`, `Emne: ${dok.emne}`, '', ...dok.tekst.flatMap((avsnitt) => [avsnitt.replace(/^## /, ''), '']));
  if (dok.vedlegg.length > 0) linjer.push(`Vedlegg: ${dok.vedlegg.join(', ')}`);
  return linjer.join('\n').trimEnd();
}

function Filinnhold({ valg }: { valg: Apen }) {
  const c = s(); const t = useT();
  const hentet = useHent<{ dokument: Dokument | null }>(`/api/arkiv/journalposter/${del(valg.journalpost)}?versjon=${valg.fil.versjon}`);
  if (hentet.feil) return <Hentefeil melding={hentet.feil} last={hentet.last} />;
  if (!hentet.data) return <Laster />;
  const dok = hentet.data.dokument;
  if (!dok) return <Text>{t('arkivar.journalpost.uten_dokument')}</Text>;
  // Vedleggene i øvingsarkivet har bare tittel.
  if (valg.dokument > 1) return <div className={c.vedlegg}><Text weight="semibold" lang="nb" block>{valg.tittel}</Text><Text block>{t('arkivar.lesevindu.vedlegg')}</Text></div>;
  // En tidligere versjon: tjeneren har gitt teksten slik den var da.
  const tidligere = valg.utkast && <MessageBar className={c.lenke} intent="info"><MessageBarBody>{t('arkivar.lesevindu.utkast')}</MessageBarBody></MessageBar>;
  return <>{tidligere}{valg.fil.format === 'txt' ? <pre className={c.tekstfil} lang="nb">{somTekst(dok)}</pre> : <Ark dok={dok} />}</>;
}

/** Holder lesevinduet for sidene under. Vinduet ligger til høyre og lukkes når studenten går til en annen side. */
export function LesevinduTilbyder({ children }: { children: ReactNode }) {
  const c = s(); const t = useT(); const { sprak } = useSprak();
  const [apen, settApen] = useState<Apen | null>(null);
  // Knappen som åpnet vinduet. Fokus settes tilbake dit når vinduet lukkes, så tastaturbrukeren ikke mister plassen.
  const apner = useRef<HTMLElement | null>(null);
  const apne = useCallback((valg: Apen) => {
    if (document.activeElement instanceof HTMLElement && !document.activeElement.closest('[data-lesevindu]')) apner.current = document.activeElement;
    settApen(valg);
  }, []);
  const lukk = useCallback(() => {
    settApen(null);
    const tilbake = apner.current;
    apner.current = null;
    // Etter at vinduet er tatt bort: ellers flytter det fokus selv.
    if (tilbake?.isConnected) window.setTimeout(() => tilbake.focus(), 0);
  }, []);
  // Vinduet hører til listen det ble åpnet fra: det lukkes når studenten går til en annen side eller bytter filter.
  const { pathname, search } = useLocation();
  useEffect(() => { apner.current = null; settApen(null); }, [pathname, search]);
  // Vinduet er ikke modalt, så Escape lukker det ikke av seg selv.
  useEffect(() => {
    if (apen === null) return undefined;
    const tast = (e: KeyboardEvent) => { if (e.key === 'Escape' && !e.defaultPrevented) lukk(); };
    document.addEventListener('keydown', tast);
    return () => document.removeEventListener('keydown', tast);
  }, [apen, lukk]);
  // Bredden brukeren har dratt vinduet til (null = standarden). Den står i en CSS-variabel på dokumentet, så både
  // vinduet og margen på siden følger den.
  const [bredde, settBredde] = useState<number | null>(lagretBredde);
  const [dras, settDras] = useState(false);
  useEffect(() => {
    const rot = document.documentElement;
    if (bredde === null) rot.style.removeProperty(BREDDEVARIABEL); else rot.style.setProperty(BREDDEVARIABEL, `${innenfor(bredde)}px`);
  }, [bredde]);
  // Blir nettleservinduet smalere, holdes bredden innenfor det som er plass til.
  useEffect(() => {
    const juster = () => settBredde((b) => (b === null ? null : innenfor(b)));
    window.addEventListener('resize', juster);
    return () => window.removeEventListener('resize', juster);
  }, []);
  // Bredden vinduet faktisk har på skjermen (til aria-valuenow) og bredden på nettleservinduet (til aria-valuemax).
  // De måles etter tegningen, ikke under den.
  const [maalt, settMaalt] = useState({ vindu: 0, skjerm: window.innerWidth });
  useLayoutEffect(() => {
    const maal = () => settMaalt({ vindu: Math.round(document.querySelector('[data-lesevindu]')?.getBoundingClientRect().width ?? 0), skjerm: window.innerWidth });
    maal();
    window.addEventListener('resize', maal);
    return () => window.removeEventListener('resize', maal);
  }, [apen, bredde]);
  // Har brukeren satt en bredde, er det den som gjelder. Ellers er det bredden vinduet er målt til.
  const naa = (): number => (bredde !== null ? innenfor(bredde) : maalt.vindu);
  const settOgHusk = (ny: number | null) => { const b = ny === null ? null : innenfor(ny); settBredde(b); huskBredde(b); };
  // Dragingen regnes fra der pekeren og kanten stod da den begynte, så et klikk uten bevegelse ikke endrer noe.
  const start = useRef<{ x: number; bredde: number; flyttet: boolean } | null>(null);
  const draTil = (x: number): number => innenfor((start.current?.bredde ?? naa()) + ((start.current?.x ?? x) - x));
  const slutt = (lagre: number | null) => { if (start.current?.flyttet && lagre !== null) settOgHusk(lagre); start.current = null; settDras(false); };
  const kanttast = (e: ReactKeyboardEvent) => {
    if (e.key === 'ArrowLeft') settOgHusk(naa() + STEG);
    else if (e.key === 'ArrowRight') settOgHusk(naa() - STEG);
    else if (e.key === 'Home') settOgHusk(window.innerWidth);
    else if (e.key === 'End') settOgHusk(MINST);
    else return;
    e.preventDefault();
  };
  const verdi = useMemo(() => ({ apen, apne, lukk }), [apen, apne, lukk]);
  return (
    <LesevinduCtx.Provider value={verdi}>
      <div className={apen ? c.plass : undefined}>{children}</div>
      {/* Ikke modalt: listen ved siden av kan brukes mens vinduet er åpent, og en annen fil bytter innholdet. */}
      <OverlayDrawer className={c.skuff} data-lesevindu="" position="end" modalType="non-modal" open={apen !== null} onOpenChange={(_, d) => { if (!d.open) lukk(); }} aria-label={t('arkivar.lesevindu.tittel')}>
        {apen && (
          <>
            <DrawerHeader>
              <DrawerHeaderTitle action={<Button appearance="subtle" icon={<Dismiss24Regular />} aria-label={t('arkivar.lesevindu.lukk')} title={t('arkivar.lesevindu.lukk')} onClick={lukk} />}>
                <span className={c.hodetekst}>
                  <Filikon fil={apen.fil} />
                  <span className={c.filtekst}>
                    <span className={c.filnavn} lang="nb">{apen.fil.filnavn}</span>
                    <span className={c.dempet} lang={sprak}>{t(`arkivar.variantformat.${apen.fil.variantformat.toLowerCase()}`)} · {t(`arkivar.filformat.${apen.fil.format}`)}</span>
                  </span>
                </span>
              </DrawerHeaderTitle>
            </DrawerHeader>
            <DrawerBody className={c.kropp}>
              <Ruterlenke className={c.lenke} to={`/arkiv/saker/${apen.sak}/journalposter/${apen.journalpost}`}>{t('arkivar.lesevindu.journalpost')}</Ruterlenke>
              <Filinnhold key={`${apen.journalpost}/${apen.dokument}/${apen.fil.versjon}/${apen.fil.variantformat}`} valg={apen} />
            </DrawerBody>
            {/* Kanten står sist i vinduet, så fokus går til lukkeknappen og ikke hit når en fil åpnes. Dobbeltklikk gir standarden. */}
            <div className={mergeClasses(c.kant, dras && c.dras)} role="separator" aria-orientation="vertical" tabIndex={0} aria-label={t('arkivar.lesevindu.kant')} title={t('arkivar.lesevindu.kant.hjelp')}
              aria-valuemin={MINST} aria-valuemax={Math.max(MINST, maalt.skjerm - MINST_SIDE)} aria-valuenow={naa()}
              onPointerDown={(e) => {
                if (e.button !== 0 || !e.isPrimary) return;
                e.currentTarget.setPointerCapture(e.pointerId); start.current = { x: e.clientX, bredde: naa(), flyttet: false }; settDras(true); e.preventDefault();
              }}
              onPointerMove={(e) => {
                if (!start.current) return;
                if (Math.abs(e.clientX - start.current.x) > 2) start.current.flyttet = true;
                if (start.current.flyttet) settBredde(draTil(e.clientX));
              }}
              onPointerUp={(e) => slutt(draTil(e.clientX))}
              onPointerCancel={() => slutt(null)}
              onLostPointerCapture={() => slutt(null)}
              onDoubleClick={() => settOgHusk(null)}
              onKeyDown={kanttast} />
          </>
        )}
      </OverlayDrawer>
    </LesevinduCtx.Provider>
  );
}

// ------------------------------------------------------------------ fillistene
type Postref = Pick<Journalpost, 'id' | 'sak_id'>;

/**
 * Versjonene av ett dokument, og variantene (produksjonsformat og arkivformat) i hver versjon. Et klikk på en variant
 * åpner filen i lesevinduet.
 */
export function Filliste({ post, dokument }: { post: Postref; dokument: Dokumentbeskrivelse }) {
  const c = s(); const t = useT(); const { sprak } = useSprak(); const vindu = useLesevindu();
  if (dokument.filer.length === 0) return <Text className={c.dempet}>{t('arkivar.filer.ingen')}</Text>;
  const versjoner = [...new Set(dokument.filer.map((f) => f.versjon))].sort((a, b) => b - a);
  // Med flere versjoner er alle unntatt den nyeste utkast.
  const erUtkast = (versjon: number): boolean => versjoner[0] > versjon;
  return (
    <ul className={c.versjoner} aria-label={t('arkivar.filer.tittel')} lang={sprak}>
      {versjoner.map((versjon) => (
        <li key={versjon}>
          <span className={c.versjon}>{t('arkivar.filer.versjon', { nr: versjon })}{erUtkast(versjon) && <> · {t('arkivar.filer.utkast')}</>}</span>
          <ul className={c.liste}>
            {dokument.filer.filter((f) => f.versjon === versjon).map((fil) => {
              // Varianten kjennes på versjonen og variantformatet: to varianter kan ha samme filnavn (begge PDF).
              const valgt = vindu.apen?.journalpost === post.id && vindu.apen.dokument === dokument.nr && vindu.apen.fil.versjon === fil.versjon && vindu.apen.fil.variantformat === fil.variantformat;
              return (
                <li key={`${fil.versjon}-${fil.variantformat}`}>
                  <Button appearance="subtle" className={mergeClasses(c.fil, valgt && c.valgt)} icon={<Filikon fil={fil} />} aria-pressed={valgt}
                    onClick={() => vindu.apne({ journalpost: post.id, sak: post.sak_id, dokument: dokument.nr, tittel: dokument.tittel, fil, utkast: erUtkast(versjon) })}>
                    <span className={c.filtekst}>
                      <span>{t(`arkivar.variantformat.${fil.variantformat.toLowerCase()}`)} · {t(`arkivar.filformat.${fil.format}`)}</span>
                      <span className={mergeClasses(c.filnavn, c.dempet)} lang="nb">{fil.filnavn}</span>
                    </span>
                  </Button>
                </li>
              );
            })}
          </ul>
        </li>
      ))}
    </ul>
  );
}

/**
 * Tittelen på et dokument i en liste, med ikonet til filen foran (arkivformatet når det finnes). Uten fil står
 * tittelen alene.
 */
export function Dokumenttittel({ filer, children }: { filer: Dokumentfil[]; children: ReactNode }) {
  const c = s();
  const fil = hovedfil(filer);
  return <span className={c.dokument}>{fil && <Filikon fil={fil} storrelse={24} />}<span>{children}</span></span>;
}

/** En rad som kan utvides: knappen, innholdet i raden og det som vises under når den er åpen. */
function Utvidbar({ kan, rad, children }: { kan: boolean; rad: ReactNode; children: ReactNode }) {
  const c = s(); const t = useT(); const { sprak } = useSprak();
  const [apen, settApen] = useState(false);
  const id = useId();
  const navn = apen ? t('arkivar.filer.skjul') : t('arkivar.filer.vis');
  return (
    <li>
      <div className={c.dokumentrad}>
        {kan
          ? <Button appearance="subtle" size="small" icon={apen ? <ChevronDown20Regular /> : <ChevronRight20Regular />} aria-expanded={apen} aria-controls={apen ? id : undefined}
              aria-label={navn} title={navn} aria-describedby={`${id}-tittel`} lang={sprak} onClick={() => settApen(!apen)} />
          : <span className={c.utenknapp} />}
        <span id={`${id}-tittel`}>{rad}</span>
      </div>
      {apen && <div id={id} className={c.under}>{children}</div>}
    </li>
  );
}

/**
 * Dokumentene i en journalpost: hoveddokumentet og vedleggene. Hvert av dem kan utvides og viser da versjonene og
 * variantene.
 */
export function Dokumentliste({ post }: { post: Journalpost }) {
  const c = s(); const t = useT(); const { sprak } = useSprak();
  if (post.dokumenter.length === 0) return <Text className={c.dempet}>{t('arkivar.filer.ingen')}</Text>;
  let vedlegg = 0;
  return (
    <ul className={c.poster} aria-label={t('arkivar.dokumenter.tittel')}>
      {post.dokumenter.map((d) => {
        const rolle = d.rolle === 'H' ? t('arkivar.dokrolle.h') : t('arkivar.dokrolle.v', { nr: ++vedlegg });
        return (
          <Utvidbar key={d.nr} kan={d.filer.length > 0} rad={<Dokumenttittel filer={d.filer}><span className={c.rolle} lang={sprak}>{rolle}</span> <span lang="nb">{d.tittel}</span></Dokumenttittel>}>
            <Filliste post={post} dokument={d} />
          </Utvidbar>
        );
      })}
    </ul>
  );
}

/** Innholdet under en utvidet journalpost i en oversikt: hoveddokumentet og vedleggene. */
export function Journalpostfiler({ post }: { post: Journalpost }) {
  const c = s();
  return <div className={c.utvidet}><Dokumentliste post={post} /></div>;
}

/** Innholdet under en utvidet mappe: journalpostene i mappen, som hver kan utvides til dokumentene sine. */
export function Mappefiler({ sak }: { sak: string }) {
  const c = s(); const t = useT(); const { sprak } = useSprak();
  const hentet = useHent<{ journalposter: Journalpost[] }>(`/api/arkiv/saker/${del(sak)}`);
  if (hentet.feil) return <div className={c.utvidet}><Hentefeil melding={hentet.feil} last={hentet.last} /></div>;
  if (!hentet.data) return <div className={c.utvidet}><Laster /></div>;
  const poster = hentet.data.journalposter;
  if (poster.length === 0) return <div className={c.utvidet}><Text className={c.dempet} lang={sprak}>{t('arkivar.sak.tom')}</Text></div>;
  return (
    <div className={c.utvidet}>
      <ul className={c.poster}>
        {poster.map((p) => (
          <Utvidbar key={p.id} kan={p.dokumenter.length > 0}
            rad={<Dokumenttittel filer={p.filer}>{p.postnr}. <Ruterlenke lang="nb" to={`/arkiv/saker/${p.sak_id}/journalposter/${p.id}`}>{p.tittel}</Ruterlenke></Dokumenttittel>}>
            <Dokumentliste post={p} />
          </Utvidbar>
        ))}
      </ul>
    </div>
  );
}
