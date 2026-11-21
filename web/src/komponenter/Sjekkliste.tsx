// Sjekklisten før en mappe avsluttes: det som mangler (dokumenter som ikke er journalført, restanser og dokumenter
// under redigering), hvert punkt med lenke til dokumentet og en knapp som løser det der. «Avslutt mappen» er sperret
// til listen er tom, og serveren håndhever det samme.
import { ReactNode, useState } from 'react';
import { Link as Ruterlenke, useNavigate } from 'react-router-dom';
import { Button, Text, makeStyles, tokens } from '@fluentui/react-components';
import { api } from '../api';
import { Journalpost, Mangel, Sak, Sjekklistedata, del } from '../arkiv';
import { useDato, useKode, useT } from '../i18n';
import { Avskrivfelt, dokumentnummer } from './Avskrivning';
import { Bekreft, Feil, Hentefeil, Laster, useFeiltekst, useHent } from './Felles';
import { useJournalposthandlinger } from './Journalposthandlinger';
import { useKontrolltall } from './Kontrolloppgaver';
import { avsluttknappId } from './Mappehandlinger';
import { Status } from './Status';

const s = makeStyles({
  tittel: { margin: '0 0 8px', fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold },
  liste: { listStyleType: 'none', margin: '0 0 12px', padding: 0, borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
  punkt: { borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, padding: '10px 0' },
  rad: { display: 'grid', gridTemplateColumns: '170px minmax(0, 1fr) auto', gap: '4px 12px', alignItems: 'start', '@container (max-width: 640px)': { gridTemplateColumns: '1fr' } },
  ramme: { containerType: 'inline-size' },
  type: {
    display: 'inline-flex', alignItems: 'center', justifyContent: 'center', width: '24px', height: '22px', border: `1.5px solid ${tokens.colorNeutralForeground2}`, borderRadius: '3px',
    fontWeight: tokens.fontWeightSemibold, textDecorationLine: 'none', cursor: 'help', marginRight: '6px',
  },
  nummer: { fontVariantNumeric: 'tabular-nums', marginRight: '6px' },
  dempet: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, marginTop: '2px' },
  avskriv: { marginTop: '10px', padding: '12px', background: tokens.colorNeutralBackground2, borderRadius: tokens.borderRadiusMedium, maxWidth: '640px' },
  bunn: { display: 'flex', gap: '12px', flexWrap: 'wrap', alignItems: 'center' },
  sperret: { color: tokens.colorNeutralForeground2 },
});

type Egenskaper = {
  sak: Sak;
  /** Kalles når et punkt er løst, så siden rundt henter på nytt. */
  etter: () => void;
  /** Kalles når mappen er avsluttet, med antall dokumenter som ble arkivert. Siden rundt viser kvitteringen. */
  avsluttet: (antall: number) => void;
  /** Sjekklisten står på siden til mappen: da står dokumentnummeret alene (3), ellers hele nummeret (2026/28-3). */
  iMappen?: boolean;
};

export function Sjekkliste({ sak, etter, avsluttet, iMappen = false }: Egenskaper) {
  const c = s(); const t = useT(); const dato = useDato(); const kode = useKode(); const feiltekst = useFeiltekst(); const naviger = useNavigate();
  const kontroll = useKontrolltall();
  const hentet = useHent<Sjekklistedata>(`/api/arkiv/saker/${del(sak.id)}/sjekkliste`);
  const [feil, settFeil] = useState<string | null>(null);
  const [opptatt, settOpptatt] = useState(false);
  // Punktet der feltene for avskrivning er åpne, og om avslutningen venter på bekreftelse.
  const [avskriver, settAvskriver] = useState<string | null>(null);
  const [bekrefter, settBekrefter] = useState(false);
  // Punktene løses som ellers: journalføringen bekreftes først, og kvitteringen har «Angre».
  const lost = () => { settAvskriver(null); hentet.last(); kontroll.oppdater(); etter(); };
  const handlinger = useJournalposthandlinger({ etter: lost, sak });
  if (hentet.feil && !hentet.data) return <Hentefeil melding={hentet.feil} last={hentet.last} />;
  if (!hentet.data) return <Laster />;
  const { mangler, dokumenter } = hentet.data;
  const send = (sti: string, da: () => void) => {
    settFeil(null); settOpptatt(true);
    api.post(sti).then(da).catch((e: unknown) => { settFeil(feiltekst(e)); settBekrefter(false); }).finally(() => settOpptatt(false));
  };
  const lenke = (p: Journalpost) => (
    <>
      <abbr className={c.type} title={t(`arkivar.jptype.${p.type.toLowerCase()}`)}>{p.type}</abbr>
      <span className={c.nummer}>{iMappen ? p.postnr : dokumentnummer(sak.saksnummer, p.postnr)}</span>
      <Ruterlenke to={`/arkiv/saker/${sak.id}/journalposter/${p.id}`} lang="nb">{p.tittel}</Ruterlenke>
    </>
  );
  const punkt = (m: Mangel): { merke: string; forklaring: string; knapp: ReactNode } => {
    const p = m.journalpost;
    if (m.slag === 'ikke_journalfort') {
      return {
        merke: t('arkivar.sjekkliste.ikke_journalfort'), forklaring: t('arkivar.sjekkliste.ikke_journalfort.hjelp', { status: kode('jpstatus', p.status) }),
        knapp: handlinger.hovedknapp(p, 'small', 'secondary'),
      };
    }
    if (m.slag === 'restanse') {
      return {
        merke: t('arkivar.restanse.merke', { dager: p.restanse_dager ?? 0 }), forklaring: t('arkivar.sjekkliste.restanse.hjelp', { dato: dato(p.journaldato) }),
        knapp: <Button size="small" disabled={opptatt} aria-expanded={avskriver === p.id} onClick={() => settAvskriver(avskriver === p.id ? null : p.id)}>{t('arkivar.jpmeny.avskriv')}</Button>,
      };
    }
    return {
      merke: t('arkivar.sjekkliste.under_redigering'), forklaring: t('arkivar.sjekkliste.under_redigering.hjelp', { status: kode('jpstatus', p.status) }),
      knapp: <Button size="small" disabled={opptatt} onClick={() => naviger(`/arkiv/saker/${sak.id}/journalposter/${p.id}`)}>{t('arkivar.sjekkliste.apne')}</Button>,
    };
  };
  return (
    <div className={c.ramme}>
      {handlinger.flater}
      <h3 className={c.tittel}>{mangler.length > 0 ? t('arkivar.sjekkliste.mangler', { antall: mangler.length }) : t('arkivar.sjekkliste.i_orden')}</h3>
      <ul className={c.liste}>
        {mangler.map((m) => {
          const { merke, forklaring, knapp } = punkt(m);
          return (
            <li key={m.journalpost.id} className={c.punkt}>
              <div className={c.rad}>
                <Status slag="advarsel">{merke}</Status>
                <div>{lenke(m.journalpost)}<span className={c.dempet}>{forklaring}</span></div>
                <div>{knapp}</div>
              </div>
              {/* Feltene for avskrivning åpnes rett under punktet. Punktet er løst først når avskrivningen er bekreftet. */}
              {avskriver === m.journalpost.id && <div className={c.avskriv}><Avskrivfelt post={m.journalpost} avbryt={() => settAvskriver(null)} etter={(ny) => { settAvskriver(null); handlinger.kvitterAvskrevet(m.journalpost, ny); }} /></div>}
            </li>
          );
        })}
        {mangler.length === 0 && (
          <>
            <li className={c.punkt}><Status slag="ok">{t('arkivar.sjekkliste.ok.journalfort', { antall: dokumenter })}</Status></li>
            <li className={c.punkt}><Status slag="ok">{t('arkivar.sjekkliste.ok.restanser')}</Status></li>
            <li className={c.punkt}><Status slag="ok">{t('arkivar.sjekkliste.ok.redigering')}</Status></li>
          </>
        )}
      </ul>
      {feil && <Feil melding={feil} />}
      <div className={c.bunn}>
        {/* Knappen kan få fokus også når den er sperret, så forklaringen ved siden av blir lest. */}
        <Button id={avsluttknappId(sak.id)} appearance="primary" disabledFocusable={mangler.length > 0 || opptatt} aria-describedby={`sjekk-${sak.id}`} onClick={() => { if (mangler.length === 0 && !opptatt) settBekrefter(true); }}>
          {t('arkivar.kontroll.avslutt')}
        </Button>
        <Text id={`sjekk-${sak.id}`} className={c.sperret}>
          {mangler.length > 0 ? t('arkivar.sjekkliste.sperret', { antall: mangler.length }) : t('arkivar.sjekkliste.klar', { antall: dokumenter, fra: kode('jpstatus', 'J'), til: kode('jpstatus', 'A') })}
        </Text>
      </div>
      {bekrefter && (
        <Bekreft tittel={t('arkivar.bekreft.avslutt.tittel', { saksnummer: sak.saksnummer })} bekreftTekst={t('arkivar.kontroll.avslutt')} avbryt={() => settBekrefter(false)} opptatt={opptatt}
          bekreft={() => send(`/api/arkiv/saker/${del(sak.id)}/avslutt`, () => { settBekrefter(false); kontroll.oppdater(); avsluttet(dokumenter); })}>
          <p>{t('arkivar.bekreft.avslutt.tekst', { antall: dokumenter, status: kode('saksstatus', 'A') })}</p>
          <p className={c.sperret}>{t('arkivar.bekreft.kan_ikke_angres')}</p>
        </Bekreft>
      )}
    </div>
  );
}
