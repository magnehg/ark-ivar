// Søket i mappene. Studenten bruker det til å finne ut om mappen finnes fra før: finnes den, brukes den, ellers
// opprettes en ny. Alle ordene i søket må finnes i mappen eller i dokumentene som ligger i den.
import { ReactNode, useEffect, useState } from 'react';
import { Link as Ruterlenke } from 'react-router-dom';
import { Field, SearchBox, Text, makeStyles, tokens } from '@fluentui/react-components';
import { Lightbulb20Regular } from '@fluentui/react-icons';
import { api } from '../api';
import { Flyt, Sak, Sokestreff, flytSok } from '../arkiv';
import { useT } from '../i18n';
import { Feil, useFeiltekst } from './Felles';
import { Status } from './Status';

const s = makeStyles({
  felt: { maxWidth: '560px', '& .fui-SearchBox': { width: '100%', maxWidth: 'none' } },
  tips: { display: 'flex', gap: '8px', alignItems: 'flex-start', background: tokens.colorNeutralBackground2, borderRadius: tokens.borderRadiusMedium, padding: '10px 12px', margin: '12px 0', maxWidth: '72ch' },
  tipsikon: { flex: 'none', color: tokens.colorNeutralForeground2, marginTop: '2px' },
  antall: { margin: '12px 0 4px', color: tokens.colorNeutralForeground2 },
  liste: { listStyle: 'none', margin: 0, padding: 0 },
  treff: { padding: '10px 0', borderTop: `1px solid ${tokens.colorNeutralStroke2}`, display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto', gap: '2px 12px', alignItems: 'start' },
  tittel: { fontWeight: tokens.fontWeightSemibold, minWidth: 0 },
  dempet: { gridColumn: '1 / -1', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
  dokument: { gridColumn: '1 / -1', fontSize: tokens.fontSizeBase200, paddingLeft: '12px', borderLeft: `2px solid ${tokens.colorNeutralStroke2}` },
});

type Egenskaper = {
  /** Søketipset som vises under feltet (fra saksmalen, eller det generelle). */
  tips?: string | null;
  /** Det som står til høyre for hvert treff, for eksempel knappen som bruker mappen. */
  handling?: (sak: Sak) => ReactNode;
  /** Kalles når et søk er gjort, med antall treff. */
  sokt?: (antall: number) => void;
  /** Dokumentet som registreres. Det følger med i lenken til mappen, så det kan registreres derfra. */
  flyt?: Flyt;
};

export function Mappesok({ tips, handling, sokt, flyt = null }: Egenskaper) {
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
  return (
    <div>
      <div className={c.felt}>
        <Field label={t('arkivar.sok.felt')} hint={t('arkivar.sok.hjelp')}>
          <SearchBox value={sok} onChange={(_, d) => settSok(d.value)} placeholder={t('arkivar.sok.plassholder')} />
        </Field>
      </div>
      {tips && (
        <div className={c.tips} lang="nb">
          <Lightbulb20Regular className={c.tipsikon} aria-hidden />
          <span><Text weight="semibold">{t('arkivar.sok.tips')}</Text> {tips}</span>
        </div>
      )}
      {feil && <Feil melding={feil} />}
      <div role="region" aria-live="polite" aria-label={t('arkivar.sok.resultat')}>
        {treff && <p className={c.antall}>{t('arkivar.sok.treff', { antall: treff.length })}</p>}
        {treff && treff.length > 0 && (
          <ul className={c.liste} lang="nb">
            {treff.map(({ sak, journalposter }) => (
              <li key={sak.id} className={c.treff}>
                <span className={c.tittel}><Ruterlenke to={`/arkiv/saker/${sak.id}${flytSok(flyt)}`}>{sak.saksnummer} {sak.tittel}</Ruterlenke></span>
                <span>{handling?.(sak)}</span>
                <span className={c.dempet}>
                  {sak.arkivdel_tekst} · {sak.klasse_tekst}{sak.skjerm_klasse && <> <Status slag="skjermet">{t('arkivar.sak.skjermet_klasse')}</Status></>}
                  {' · '}{t(`arkivar.saksstatus.${sak.status.toLowerCase()}`)} · {t('arkivar.sok.dokumenter', { antall: sak.antall })}
                </span>
                {journalposter.map((p) => (
                  <span key={p.postnr} className={c.dokument}>{t('arkivar.sok.dokument', { nr: p.postnr, tittel: p.tittel })}{p.part_navn && ` (${p.part_navn})`}</span>
                ))}
              </li>
            ))}
          </ul>
        )}
      </div>
    </div>
  );
}
