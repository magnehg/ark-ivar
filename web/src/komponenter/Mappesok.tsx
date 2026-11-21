// Søket i mappene. Studenten bruker det til å finne ut om mappen finnes fra før: finnes den, brukes den, ellers
// opprettes en ny. Alle ordene i søket må finnes i mappen eller i dokumentene som ligger i den.
import { ReactNode, useEffect, useState } from 'react';
import { Button, Field, Popover, PopoverSurface, PopoverTrigger, SearchBox, Text, makeStyles, tokens } from '@fluentui/react-components';
import { QuestionCircle20Regular } from '@fluentui/react-icons';
import { api } from '../api';
import { Flyt, Sak, Sokestreff, flytSok, useStruktur } from '../arkiv';
import { useT } from '../i18n';
import { useMappekolonner, useMappevisning } from '../sider/arkiv/visninger';
import { Feil, useFeiltekst } from './Felles';
import { Oversikt, Teller } from './Oversikt';

const s = makeStyles({
  felt: { maxWidth: '600px', display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto', columnGap: '4px', alignItems: 'start', marginBottom: '8px', '& .fui-SearchBox': { width: '100%', maxWidth: 'none' } },
  // Knappen står på høyde med søkefeltet, under ledeteksten.
  tipsknapp: { marginTop: '24px' },
  tips: { maxWidth: '44ch' },
  antall: { margin: '12px 0 4px', color: tokens.colorNeutralForeground2 },
  dokument: { display: 'block', fontSize: tokens.fontSizeBase200, fontWeight: tokens.fontWeightRegular, paddingLeft: '12px', borderLeft: `2px solid ${tokens.colorNeutralStroke2}` },
});

type Egenskaper = {
  /** Søketipset som vises under feltet (fra saksmalen, eller det generelle). */
  tips?: string | null;
  /** Språket tipset står på, når det kommer fra arkivets data og ikke fra grensesnittet (saksmalene er norske). */
  tipsSprak?: string;
  /** Det som står til høyre for hvert treff, for eksempel knappen som bruker mappen. */
  handling?: (sak: Sak) => ReactNode;
  /** Kalles når et søk er gjort, med antall treff. */
  sokt?: (antall: number) => void;
  /** Dokumentet som registreres. Det følger med i lenken til mappen, så det kan registreres derfra. */
  flyt?: Flyt;
  /** Titlene er ikke lenker: søket står i en dialog, og et trykk på tittelen skal ikke bytte siden under den. */
  utenLenker?: boolean;
};

export function Mappesok({ tips, tipsSprak, handling, sokt, flyt = null, utenLenker = false }: Egenskaper) {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const [sok, settSok] = useState('');
  // null betyr at det ikke er søkt ennå.
  const [treff, settTreff] = useState<Sokestreff[] | null>(null);
  const [feil, settFeil] = useState<string | null>(null);
  useEffect(() => {
    const q = sok.trim();
    if (q.length < 2) { settTreff(null); return undefined; }
    let aktiv = true;
    // Søket går når studenten har stoppet å skrive.
    const vent = window.setTimeout(() => {
      api.get<{ treff: Sokestreff[] }>(`/api/arkiv/sok?q=${encodeURIComponent(q)}`)
        .then((d) => { if (aktiv) { settTreff(d.treff); settFeil(null); sokt?.(d.treff.length); } })
        .catch((e: unknown) => { if (aktiv) settFeil(feiltekst(e)); });
    }, 300);
    return () => { aktiv = false; window.clearTimeout(vent); };
  }, [sok]); // eslint-disable-line react-hooks/exhaustive-deps
  // Dokumentene i mappen som ga treff i søket, står under mappen i alle visningene.
  const ekstra = (r: Sokestreff) => r.journalposter.map((p) => (
    <span key={p.postnr} className={c.dokument}>{t('arkivar.sok.dokument', { nr: p.postnr })}: <span lang="nb">{p.tittel}{p.part_navn && ` (${p.part_navn})`}</span></span>
  ));
  const struktur = useStruktur();
  const mappevalg = {
    sak: (r: Sokestreff) => r.sak, lenke: (m: Sak) => (utenLenker ? null : `/arkiv/saker/${m.id}${flytSok(flyt)}`),
    enhetsnavn: (kode: string) => struktur.data?.enheter.find((e) => e.kode === kode)?.navn ?? kode,
    handling: handling ? (r: Sokestreff) => handling(r.sak) : undefined, ekstra,
  };
  const kolonner = useMappekolonner<Sokestreff>(mappevalg);
  const visninger = useMappevisning<Sokestreff>(mappevalg);
  return (
    <div>
      <div className={c.felt}>
        <Field label={t('arkivar.sok.felt')} hint={t('arkivar.sok.hjelp')}>
          <SearchBox value={sok} onChange={(_, d) => settSok(d.value)} placeholder={t('arkivar.sok.plassholder')} dismiss={{ 'aria-label': t('arkivar.felles.tom_sok') }} />
        </Field>
        {/* Søketipset står bak et spørsmålstegn ved feltet, så det ikke tar plass før studenten ber om det. */}
        {tips && (
          <Popover withArrow positioning="below-end">
            <PopoverTrigger disableButtonEnhancement>
              <Button className={c.tipsknapp} appearance="subtle" icon={<QuestionCircle20Regular />} aria-label={t('arkivar.sok.tips.vis')} title={t('arkivar.sok.tips.vis')} />
            </PopoverTrigger>
            <PopoverSurface className={c.tips} aria-label={t('arkivar.sok.tips.vis')}>
              <Text weight="semibold" block>{t('arkivar.sok.tips')}</Text>
              <span lang={tipsSprak}>{tips}</span>
            </PopoverSurface>
          </Popover>
        )}
      </div>
      {feil && <Feil melding={feil} />}
      <div role="region" aria-live="polite" aria-label={t('arkivar.sok.resultat')}>
        {treff && treff.length === 0 && <p className={c.antall}>{t('arkivar.sok.treff', { antall: 0 })}</p>}
        {treff && treff.length > 0 && (
          <Oversikt forst={<Teller>{t('arkivar.sok.treff', { antall: treff.length })}</Teller>} rader={treff} kolonner={kolonner} visninger={visninger} nokkel={(r) => r.sak.id} aria-label={t('arkivar.sok.resultat')} />
        )}
      </div>
    </div>
  );
}
