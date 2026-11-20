// Språk: alle tekster ligger i språkfilene (ICU MessageFormat), de samme filene som serveren bruker
// (arkivar/i18n og kjerne/i18n). Ingen tekst i komponentene. Tekster som mangler på et språk, vises på bokmål.
import { ReactNode, createContext, useCallback, useContext, useMemo, useState } from 'react';
import { IntlProvider, useIntl } from 'react-intl';
import { slaSammen, velgSprak } from '../../grensesnitt/src/i18n.ts';
import { husk, les } from '../../grensesnitt/src/preferanser.ts';

type Fil = { default: Record<string, string> };
const filer = {
  ...import.meta.glob<Fil>('../../kjerne/i18n/*/*.json', { eager: true }),
  ...import.meta.glob<Fil>('../../arkivar/i18n/*/*.json', { eager: true }),
};

export const SPRAK = ['nb', 'nn', 'en'] as const;
export type Sprakkode = (typeof SPRAK)[number];
const LOKALE: Record<Sprakkode, string> = { nb: 'nb-NO', nn: 'nn-NO', en: 'en-GB' };

function lager(): Storage | undefined {
  try { return window.localStorage; } catch { return undefined; }
}

const SprakCtx = createContext<{ sprak: Sprakkode; settSprak: (s: Sprakkode) => void }>({ sprak: 'nb', settSprak: () => {} });
export const useSprak = () => useContext(SprakCtx);

/** Språket: brukerens valg i nettleseren, så nettleserens språk, så bokmål. */
export function Sprak({ children }: { children: ReactNode }) {
  const [sprak, sett] = useState<Sprakkode>(() => velgSprak([...SPRAK], les(lager()).preferredLanguage, navigator.language) as Sprakkode);
  const settSprak = useCallback((s: Sprakkode) => {
    sett(s);
    husk(lager(), { ...les(lager()), preferredLanguage: s });
    document.documentElement.setAttribute('lang', s);
  }, []);
  const { meldinger } = useMemo(() => slaSammen(filer, sprak, 'nb'), [sprak]);
  return (
    <SprakCtx.Provider value={{ sprak, settSprak }}>
      <IntlProvider locale={LOKALE[sprak]} messages={meldinger} defaultLocale="nb-NO" onError={(e) => { if (e.code !== 'MISSING_TRANSLATION') console.error(e); }}>
        {children}
      </IntlProvider>
    </SprakCtx.Provider>
  );
}

/** Kort hjelpefunksjon: t('nøkkel', { verdi }). */
export function useT() {
  const intl = useIntl();
  return useCallback((id: string, verdier?: Record<string, string | number | null | undefined>) => intl.formatMessage({ id }, (verdier ?? {}) as never), [intl]);
}

/** Dato og klokkeslett på brukerens språk, eller tom tekst. */
export function useTidspunkt() {
  const intl = useIntl();
  return (iso?: string | null) => (iso ? intl.formatDate(iso, { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
}
