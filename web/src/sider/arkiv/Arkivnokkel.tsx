// Arkivnøkkelen til sakarkivet: de ni klassene som åpnes hver for seg, og et søk i koder, titler og merknader. Brukes
// på siden Arkivstruktur.
import { useState } from 'react';
import { Accordion, AccordionHeader, AccordionItem, AccordionPanel, Field, SearchBox, makeStyles, tokens } from '@fluentui/react-components';
import { Klasse, Struktur } from '../../arkiv';
import { useT } from '../../i18n';

const s = makeStyles({
  sok: { maxWidth: '420px', marginBottom: '12px', '& .fui-SearchBox': { width: '100%', maxWidth: 'none' } },
  spalter: { columns: '3 300px', columnGap: '32px', margin: 0 },
  hovedgruppe: { breakInside: 'avoid', marginBottom: '16px' },
  hovedtittel: { fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold, margin: '0 0 4px', display: 'flex' },
  // Koden står i sin egen spalte, så en lang tittel brytes under tittelen og ikke under koden.
  klasser: { listStyle: 'none', margin: 0, padding: 0, '& li': { padding: '2px 0', display: 'flex' } },
  kode: { fontFamily: tokens.fontFamilyNumeric, color: tokens.colorNeutralForeground2, flex: 'none', width: '44px' },
  merknad: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, lineHeight: tokens.lineHeightBase200 },
  klassetittel: { fontWeight: tokens.fontWeightSemibold },
  antall: { color: tokens.colorNeutralForeground2, marginLeft: '8px', fontWeight: tokens.fontWeightRegular },
  treff: { listStyle: 'none', margin: 0, padding: 0, maxWidth: '80ch', '& li': { padding: '6px 0', display: 'flex', borderTop: `1px solid ${tokens.colorNeutralStroke2}` } },
  dempet: { color: tokens.colorNeutralForeground2, margin: '0 0 8px' },
});

export function Arkivnokkel({ struktur }: { struktur: Struktur }) {
  const c = s(); const t = useT();
  const [sok, settSok] = useState('');
  const klasser = struktur.klasser;
  const barn = (kode: string) => klasser.filter((k) => k.forelder === kode);
  const under = (kode: string): Klasse[] => barn(kode).flatMap((k) => [k, ...under(k.kode)]);
  const arkivdel = (kode: string) => struktur.arkivdeler.find((a) => a.kode === kode)?.tittel ?? kode;
  /** Klassene over en klasse: «2 Stillinger og personell › 21 Stillinger». */
  const sti = (k: Klasse): string => {
    const over = klasser.find((x) => x.kode === k.forelder);
    return over ? [sti(over), `${over.kode} ${over.tittel}`].filter(Boolean).join(' › ') : '';
  };
  const tekst = (k: Klasse) => (
    <span>
      {k.tittel}
      {k.merknad && <span className={c.merknad}>{k.merknad}</span>}
      {k.henvisning && <span className={c.merknad}>{t('arkivar.struktur.arkivnokkel.henvisning', { arkivdel: arkivdel(k.henvisning) })}</span>}
    </span>
  );
  const ord = sok.trim().toLocaleLowerCase('nb');
  const treff = ord ? klasser.filter((k) => k.kode.startsWith(ord) || `${k.tittel} ${k.merknad ?? ''}`.toLocaleLowerCase('nb').includes(ord)) : [];

  return (
    <>
      <div className={c.sok}>
        <Field label={t('arkivar.struktur.arkivnokkel.sok')}>
          <SearchBox value={sok} onChange={(_, d) => settSok(d.value)} placeholder={t('arkivar.registrering.klasse.sok')} />
        </Field>
      </div>
      {ord ? (
        <div role="region" aria-live="polite">
          <p className={c.dempet}>{t('arkivar.struktur.arkivnokkel.treff', { antall: treff.length })}</p>
          <ul className={c.treff}>
            {treff.map((k) => (
              <li key={k.kode}>
                <span className={c.kode}>{k.kode}</span>
                <span>{tekst(k)}{sti(k) && <span className={c.merknad}>{sti(k)}</span>}</span>
              </li>
            ))}
          </ul>
        </div>
      ) : (
        <Accordion multiple collapsible>
          {klasser.filter((k) => k.forelder === null).map((klasse) => (
            <AccordionItem key={klasse.kode} value={klasse.kode}>
              <AccordionHeader as="h3">
                <span className={c.klassetittel}>{klasse.kode} {klasse.tittel}</span>
                <span className={c.antall}>{t('arkivar.struktur.arkivnokkel.antall', { antall: under(klasse.kode).length })}</span>
              </AccordionHeader>
              <AccordionPanel>
                <div className={c.spalter}>
                  {barn(klasse.kode).map((hoved) => (
                    <section key={hoved.kode} className={c.hovedgruppe}>
                      <h4 className={c.hovedtittel}><span className={c.kode}>{hoved.kode}</span>{tekst(hoved)}</h4>
                      <ul className={c.klasser}>
                        {under(hoved.kode).map((k) => <li key={k.kode}><span className={c.kode}>{k.kode}</span>{tekst(k)}</li>)}
                      </ul>
                    </section>
                  ))}
                </div>
              </AccordionPanel>
            </AccordionItem>
          ))}
        </Accordion>
      )}
    </>
  );
}
