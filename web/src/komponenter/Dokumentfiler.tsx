// Dokumentfilene til journalpostene: filikoner for produksjonsformat og arkivformat, listen som vises når en rad i en
// oversikt er utvidet, og lesevinduet som åpnes til høyre når studenten klikker på en fil. Øvingsarkivet har ikke
// virkelige filer: lesevinduet viser dokumentet slik filen ville sett ut (som ark, eller som ren tekst for txt).
import { ReactNode, createContext, useCallback, useContext, useEffect, useMemo, useRef, useState } from 'react';
import { Link as Ruterlenke, useLocation } from 'react-router-dom';
import { Button, DrawerBody, DrawerHeader, DrawerHeaderTitle, OverlayDrawer, Text, makeStyles, mergeClasses, tokens } from '@fluentui/react-components';
import { Dismiss24Regular } from '@fluentui/react-icons';
import { Dokument, Dokumentfil, Journalpost, del } from '../arkiv';
import { useSprak, useT } from '../i18n';
import { Ark } from './Ark';
import { Hentefeil, Laster, useHent } from './Felles';

/** Bredden på lesevinduet. Siden ved siden av får like mye marg mens vinduet er åpent. */
const BREDDE = 'min(680px, 44vw)';

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
  utvidet: { padding: '8px 0 12px 36px' },
  post: { margin: '0 0 2px', fontWeight: tokens.fontWeightSemibold, fontSize: tokens.fontSizeBase300 },
  poster: { listStyleType: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '10px' },
  // Siden gir plass til vinduet (plass), så ingenting ligger under det. På smale skjermer dekker vinduet hele bredden.
  skuff: { width: BREDDE, '@media (max-width: 900px)': { width: '100vw' } },
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
type Apen = { journalpost: string; sak: string; fil: Dokumentfil };
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
  const hentet = useHent<{ dokument: Dokument | null }>(`/api/arkiv/journalposter/${del(valg.journalpost)}`);
  if (hentet.feil) return <Hentefeil melding={hentet.feil} last={hentet.last} />;
  if (!hentet.data) return <Laster />;
  const dok = hentet.data.dokument;
  if (!dok) return <Text>{t('arkivar.journalpost.uten_dokument')}</Text>;
  return valg.fil.format === 'txt' ? <pre className={c.tekstfil} lang="nb">{somTekst(dok)}</pre> : <Ark dok={dok} />;
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
              <Filinnhold key={`${apen.journalpost}/${apen.fil.filnavn}`} valg={apen} />
            </DrawerBody>
          </>
        )}
      </OverlayDrawer>
    </LesevinduCtx.Provider>
  );
}

// ------------------------------------------------------------------ fillistene
/** Filene til én journalpost. Et klikk åpner filen i lesevinduet. */
export function Filliste({ post }: { post: Pick<Journalpost, 'id' | 'sak_id' | 'filer'> }) {
  const c = s(); const t = useT(); const { sprak } = useSprak(); const vindu = useLesevindu();
  if (post.filer.length === 0) return <Text className={c.dempet}>{t('arkivar.filer.ingen')}</Text>;
  return (
    <ul className={c.liste} aria-label={t('arkivar.filer.tittel')}>
      {post.filer.map((fil) => {
        const valgt = vindu.apen?.journalpost === post.id && vindu.apen.fil.filnavn === fil.filnavn;
        return (
          <li key={fil.filnavn}>
            <Button appearance="subtle" className={mergeClasses(c.fil, valgt && c.valgt)} icon={<Filikon fil={fil} />} aria-pressed={valgt}
              onClick={() => vindu.apne({ journalpost: post.id, sak: post.sak_id, fil })}>
              <span className={c.filtekst}>
                <span className={c.filnavn} lang="nb">{fil.filnavn}</span>
                <span className={c.dempet} lang={sprak}>{t(`arkivar.variantformat.${fil.variantformat.toLowerCase()}`)} · {t(`arkivar.filformat.${fil.format}`)}</span>
              </span>
            </Button>
          </li>
        );
      })}
    </ul>
  );
}

/** Innholdet under en utvidet journalpost i en oversikt. */
export function Journalpostfiler({ post }: { post: Journalpost }) {
  const c = s();
  return <div className={c.utvidet}><Filliste post={post} /></div>;
}

/** Innholdet under en utvidet mappe: dokumentene i mappen, hver med filene sine. Hentes når raden utvides. */
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
          <li key={p.id}>
            <p className={c.post}>{p.postnr}. <Ruterlenke lang="nb" to={`/arkiv/saker/${p.sak_id}/journalposter/${p.id}`}>{p.tittel}</Ruterlenke></p>
            <Filliste post={p} />
          </li>
        ))}
      </ul>
    </div>
  );
}
