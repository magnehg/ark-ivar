// Avskrivning og restanser. Et inngående dokument skal avskrives: det besvares (BU når svaret er ekspedert), eller
// arkivtjenesten avskriver det uten svar med en avskrivningsmåte fra kodelisten. Et journalført inngående dokument
// uten avskrivning er en restanse. Her er merket som viser avskrivningen i listene, og feltene som avskriver.
import { ReactNode, useState } from 'react';
import { Link as Ruterlenke } from 'react-router-dom';
import { Button, Field, Radio, RadioGroup, Textarea, makeStyles, tokens } from '@fluentui/react-components';
import { api } from '../api';
import { Henvisning, Journalpost, del, useStruktur } from '../arkiv';
import { useDato, useT } from '../i18n';
import { Feil, Hjelp, useFeiltekst } from './Felles';
import { Status } from './Status';

const s = makeStyles({
  linje: { display: 'block' },
  dempet: { color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
  kode: { fontWeight: tokens.fontWeightSemibold, fontVariantNumeric: 'tabular-nums' },
  felt: { display: 'flex', flexDirection: 'column', gap: '12px', '& .fui-Textarea': { width: '100%' } },
  valg: { display: 'flex', flexDirection: 'column' },
  rad: { display: 'flex', alignItems: 'center', flexWrap: 'wrap' },
  forklaring: { color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, marginLeft: '32px', marginTop: '-4px', marginBottom: '4px' },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
});

/** Nummeret til en journalpost slik det skrives: «2026/28-3». */
export const dokumentnummer = (saksnummer: string | undefined, postnr: number): string => (saksnummer ? `${saksnummer}-${postnr}` : String(postnr));

/**
 * Lenken til en annen journalpost. I mappen de begge ligger i, står bare dokumentnummeret («2»); ellers hele nummeret
 * («2026/28-2»).
 */
export function Henvisningslenke({ til, sakId }: { til: Henvisning; sakId?: string }) {
  return <Ruterlenke to={`/arkiv/saker/${til.sak_id}/journalposter/${til.id}`}>{til.sak_id === sakId ? til.postnr : dokumentnummer(til.saksnummer, til.postnr)}</Ruterlenke>;
}

/** Koden med navnet fra kodelisten: «BU Besvart med brev». */
export function Avskrivningsmate({ kode }: { kode: string }) {
  const c = s(); const t = useT();
  return <><span className={c.kode}>{kode}</span> {t(`arkivar.avskrivningsmate.${kode.toLowerCase()}`)}</>;
}

/** Om journalposten har noe å vise om avskrivning: et inngående dokument, eller et svar på et. */
export const harAvskrivning = (post: Journalpost): boolean => post.restanse || post.avskrivning !== null || post.svar_paa.length > 0
  || (post.type === 'I' && post.status !== 'A' && post.dokumentstatus !== 'B');

/**
 * Avskrivningen slik den står i en liste: «Restanse, 9 dager» (alltid med tekst, ikke bare farge), avskrivningsmåten
 * med journalposten som avskriver, eller hva et utgående dokument er svar på. sakId er mappen listen viser, når
 * den viser dokumentene i én mappe. Tom for journalposter som ikke har noe med avskrivning å gjøre.
 */
export function Avskrivningsmerke({ post, sakId }: { post: Journalpost; sakId?: string }) {
  const c = s(); const t = useT(); const dato = useDato();
  const deler: ReactNode[] = [];
  if (post.restanse) {
    deler.push(<Status key="restanse" slag="advarsel">{t('arkivar.restanse.merke', { dager: post.restanse_dager ?? 0 })}</Status>);
  } else if (post.avskrivning) {
    const a = post.avskrivning;
    deler.push(
      <span key="avskrevet" className={c.linje}>
        <Status slag="ok"><Avskrivningsmate kode={a.mate} /></Status>
        <span className={`${c.linje} ${c.dempet}`}>
          {a.av ? <>{t('arkivar.avskrivning.avskrevet_av')} <Henvisningslenke til={a.av} sakId={sakId} />, {dato(a.dato)}</> : <>{t('arkivar.avskrivning.avskrevet')} {dato(a.dato)}</>}
        </span>
      </span>,
    );
  } else if (post.type === 'I' && post.status !== 'A' && post.dokumentstatus !== 'B') {
    deler.push(<span key="ikke" className={c.dempet}>{t('arkivar.avskrivning.ikke')}</span>);
  }
  if (post.svar_paa.length > 0) {
    const avskriver = post.svar_paa.some((p) => p.avskriver);
    deler.push(
      <span key="svar" className={c.linje}>
        {t('arkivar.avskrivning.svar_paa')}{' '}
        {post.svar_paa.map((p, i) => <span key={p.id}>{i > 0 && ', '}<Henvisningslenke til={p} sakId={sakId} /></span>)}
        {avskriver ? t('arkivar.avskrivning.som_det_avskriver') : <span className={`${c.linje} ${c.dempet}`}>{t('arkivar.avskrivning.avskriver_ikke')}</span>}
      </span>,
    );
  }
  return deler.length > 0 ? <>{deler}</> : null;
}

type Feltegenskaper = {
  post: Journalpost;
  /** Kalles når dokumentet er avskrevet, med journalposten slik den er nå. */
  etter: (post: Journalpost) => void;
  avbryt: () => void;
};

/**
 * Feltene som avskriver et dokument uten svar: avskrivningsmåten og en merknad. Måtene som ikke kan velges her (BU
 * settes av svaret, SA når saksmappen avsluttes), står grået ut med forklaringen. Brukes i dialogen «Avskriv» og
 * rett under punktet i sjekklisten før en mappe avsluttes.
 */
export function Avskrivfelt({ post, etter, avbryt }: Feltegenskaper) {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const struktur = useStruktur();
  const [mate, settMate] = useState('');
  const [merknad, settMerknad] = useState('');
  const [feil, settFeil] = useState<string | null>(null);
  const [opptatt, settOpptatt] = useState(false);
  const kanVelges = struktur.data?.avskrivning_uten_svar ?? [];
  // De som kan velges, står først. Resten av kodelisten står under, grået ut.
  const mater = [...kanVelges, ...(struktur.data?.avskrivningsmater ?? []).filter((k) => !kanVelges.includes(k))];
  const avskriv = () => {
    settFeil(null); settOpptatt(true);
    api.post<{ journalpost: Journalpost }>(`/api/arkiv/journalposter/${del(post.id)}/avskriv`, { mate, merknad })
      .then((d) => etter(d.journalpost)).catch((e: unknown) => { settFeil(feiltekst(e)); settOpptatt(false); });
  };
  return (
    <div className={c.felt}>
      <Field label={t('arkivar.avskriv.mate')} required>
        <RadioGroup value={mate} onChange={(_, d) => settMate(d.value)} className={c.valg}>
          {mater.map((k) => {
            const laast = !kanVelges.includes(k);
            const nokkel = `arkivar.avskriv.merk.${k.toLowerCase()}`;
            return (
              <div key={k}>
                <div className={c.rad}>
                  <Radio value={k} disabled={laast} label={<Avskrivningsmate kode={k} />} />
                  {/* E-post er lov, men sjelden riktig: forklaringen står bak hjelpeikonet. */}
                  {k === 'BE' && <Hjelp navn={t('arkivar.avskriv.merk.be.navn')}>{t('arkivar.avskriv.merk.be')}</Hjelp>}
                </div>
                {laast && <div className={c.forklaring}>{t(nokkel)}</div>}
              </div>
            );
          })}
        </RadioGroup>
      </Field>
      <Field label={t('arkivar.avskriv.merknad')} hint={t('arkivar.avskriv.merknad.hjelp')}>
        <Textarea lang="nb" rows={2} maxLength={2000} value={merknad} onChange={(_, d) => settMerknad(d.value)} />
      </Field>
      {feil && <Feil melding={feil} />}
      <div className={c.knapper}>
        <Button appearance="primary" disabled={opptatt || mate === ''} onClick={avskriv}>{t('arkivar.avskriv.knapp')}</Button>
        <Button disabled={opptatt} onClick={avbryt}>{t('arkivar.felles.avbryt')}</Button>
      </div>
    </div>
  );
}
