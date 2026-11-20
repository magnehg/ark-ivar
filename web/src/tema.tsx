// Utseende: fargetema, lys og mørk modus, høy kontrast og bredden på siden, fra Bivrosts designpakke. Valgene huskes i
// nettleseren (localStorage) og settes som data-bivrost-attributter på <html>, så stilfilene og Fluent-temaet følger
// dem. Fluent-temaet lages fra designtokenene (grensesnitt/tokens), én fil per tema, modus og kontrast, hentet ved behov.
import { ReactNode, createContext, useContext, useEffect, useMemo, useState } from 'react';
import { FluentProvider, RendererProvider, Theme, createDOMRenderer, webDarkTheme, webLightTheme } from '@fluentui/react-components';
import grunnlag from '../../grensesnitt/tokens/grunnlag.tokens.json';
import { fluentTema, tokenfil } from '../../grensesnitt/src/fluent.ts';
import { Utseende, bruk, husk, les, tema as valgtTema } from '../../grensesnitt/src/preferanser.ts';
import { TEMAER, type TemaId } from '../../grensesnitt/src/temaer.ts';

type Tokenfil = { default: Record<string, unknown> };
const fargefiler = import.meta.glob<Tokenfil>('../../grensesnitt/tokens/tema/*.tokens.json');

function lager(): Storage | undefined {
  try { return window.localStorage; } catch { return undefined; }
}

/** Følger en mediespørring (operativsystemets valg av mørk modus eller høy kontrast). */
function useMedie(sporring: string): boolean {
  const mq = useMemo(() => (typeof window.matchMedia === 'function' ? window.matchMedia(sporring) : null), [sporring]);
  const [treff, sett] = useState(!!mq?.matches);
  useEffect(() => {
    if (!mq) return;
    const lytt = (e: MediaQueryListEvent) => sett(e.matches);
    mq.addEventListener('change', lytt);
    return () => mq.removeEventListener('change', lytt);
  }, [mq]);
  return treff;
}

type TemaVerdi = { utseende: Utseende; tema: TemaId; mork: boolean; endre: (endring: Partial<Utseende>) => void };
const TemaCtx = createContext<TemaVerdi | null>(null);
export function useTema(): TemaVerdi {
  const v = useContext(TemaCtx);
  if (!v) throw new Error('useTema må brukes inne i TemaTilbyder');
  return v;
}

// Fluent lager stilene i <style>-elementer. De får engangsverdien fra serveren (CSP), se ArkIvar\App::grensesnitt.
const nonce = document.querySelector('meta[name="arkivar-stil-nonce"]')?.getAttribute('content') ?? '';
const tegner = createDOMRenderer(document, { styleElementAttributes: nonce ? { nonce } : {} });

export function TemaTilbyder({ children }: { children: ReactNode }) {
  const [utseende, sett] = useState<Utseende>(() => les(lager()));
  const systemMork = useMedie('(prefers-color-scheme: dark)');
  const systemKontrast = useMedie('(prefers-contrast: more)');
  const tema = valgtTema(utseende);
  const mork = utseende.modus === 'mork' || ((utseende.modus ?? 'system') === 'system' && systemMork);
  const hoy = utseende.hoyKontrast === 'ja' || ((utseende.hoyKontrast ?? 'system') === 'system' && systemKontrast);
  const [fluent, settFluent] = useState<Theme>(mork ? webDarkTheme : webLightTheme);

  // Språket eies av Sprak (i18n.tsx), ikke av utseendet: lang på siden blir stående slik Sprak har satt det.
  useEffect(() => {
    const sprak = document.documentElement.getAttribute('lang');
    bruk(document.documentElement, { ...utseende, preferredLanguage: sprak ?? utseende.preferredLanguage }, document);
  }, [utseende]);
  useEffect(() => {
    let aktiv = true;
    const navn = tokenfil(tema, mork ? 'mork' : 'lys', hoy ? 'hoy' : 'normal');
    const hent = Object.entries(fargefiler).find(([sti]) => sti.endsWith('/' + navn))?.[1];
    hent?.().then((fil) => {
      if (aktiv) settFluent({ ...(mork ? webDarkTheme : webLightTheme), ...fluentTema(grunnlag as Record<string, unknown>, fil.default) } as Theme);
    }).catch(() => { /* standardtemaet til Fluent brukes */ });
    return () => { aktiv = false; };
  }, [tema, mork, hoy]);
  useEffect(() => {
    // Rullefelt, skjemaelementer og bakgrunnen bak appen følger modus.
    document.documentElement.style.colorScheme = mork ? 'dark' : 'light';
    document.body.style.backgroundColor = fluent.colorNeutralBackground3;
  }, [mork, fluent]);

  const endre = (endring: Partial<Utseende>) => sett((forrige) => {
    const ny = { ...forrige, ...endring };
    // Språkvalget lagres av Sprak. Det som ligger i lageret, står: utseendet her kan ha et eldre valg.
    const lagres: Utseende = { ...ny };
    delete lagres.preferredLanguage;
    husk(lager(), { ...les(lager()), ...lagres });
    return ny;
  });
  return (
    <TemaCtx.Provider value={{ utseende, tema, mork, endre }}>
      <RendererProvider renderer={tegner}>
        <FluentProvider theme={fluent} style={{ minHeight: '100vh' }}>{children}</FluentProvider>
      </RendererProvider>
    </TemaCtx.Provider>
  );
}

/** Liten fargeprøve av et tema (hovedfarge og aksent). Dekorativ: navnet står alltid ved siden av. */
export function Fargeprove({ id, storrelse = 16 }: { id: TemaId; storrelse?: number }) {
  const f = TEMAER[id];
  return (
    <svg aria-hidden="true" focusable="false" width={storrelse} height={storrelse} viewBox="0 0 3 3" style={{ flex: 'none', borderRadius: 4 }}>
      <rect width="2" height="3" fill={f.hoved} /><rect x="2" width="1" height="3" fill={f.aksent} />
    </svg>
  );
}

const RAMME = 'M57.35 9.6Q64 5.76 70.65 9.6L107.79 31.04Q114.44 34.88 114.44 42.56L114.44 85.44Q114.44 93.12 107.79 96.96L70.65 118.4Q64 122.24 57.35 118.4L20.21 96.96Q13.56 93.12 13.56 85.44L13.56 42.56Q13.56 34.88 20.21 31.04Z';

/**
 * Ark-Ivar-logoen: en arkivboks i den samme sekskantrammen som Bivrost-appene bruker. Dekorativ: navnet står ved
 * siden av. Fargene er variabler fra temaet (toppfeltet), eller de som gis.
 */
export function Logo({ storrelse = 24, ramme = 'var(--bivrost-farge-toppfelt-logo-ramme)', fyll = 'var(--bivrost-farge-toppfelt-logo-fyll)' }: { storrelse?: number; ramme?: string; fyll?: string }) {
  return (
    <svg viewBox="0 0 128 128" width={storrelse} height={storrelse} aria-hidden="true" focusable="false" style={{ flex: 'none' }}>
      <path d={RAMME} fill="none" stroke={ramme} strokeWidth="7" strokeLinejoin="round" />
      <path d="M40 46h48v10H40z" fill={fyll} />
      <path d="M44 60h40v26a4 4 0 0 1-4 4H48a4 4 0 0 1-4-4z" fill="none" stroke={ramme} strokeWidth="5" strokeLinejoin="round" />
      <path d="M56 70h16" stroke={fyll} strokeWidth="5" strokeLinecap="round" />
    </svg>
  );
}
