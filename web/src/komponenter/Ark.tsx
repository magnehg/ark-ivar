// Et dokument vist som et ark: brevhode med avsender og mottaker, datoer, emne, tekst og vedlegg. Brukes for
// dokumentene i postmottaket og for dokumentene som ligger i arkivet. Innholdet er norsk uansett språk i grensesnittet:
// arket er merket som norsk, og ledetekstene fra grensesnittet («Datert», «Til:») er merket med språket de står på.
import { Text, makeStyles, mergeClasses, tokens } from '@fluentui/react-components';
import { Dokument } from '../arkiv';
import { useDato, useSprak, useT } from '../i18n';
import { useOkt } from './Felles';
import { Kommunevapen } from './Kommunevapen';

// Kornet i et skannet ark: et lite bilde med støy som legges over papiret (et bilde, så CSP tillater det som data:).
const KORN = "url(\"data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='160' height='160'%3E%3Cfilter id='k'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2' seed='7'/%3E%3CfeColorMatrix values='0 0 0 0 0  0 0 0 0 0  0 0 0 0 0  0 0 0 0.09 0'/%3E%3C/filter%3E%3Crect width='160' height='160' filter='url(%23k)'/%3E%3C/svg%3E\")";

const s = makeStyles({
  // Et inngående brev på papir er skannet av arkivtjenesten. Arket er et bilde av papir og har sine egne farger i
  // alle temaer (som kommunevåpenet): gulnet papir, blekk, korn, en skygge fra skanneren og litt skjev plassering.
  // Teksten er fortsatt vanlig tekst, så den kan leses av skjermlesere, søkes i og forstørres.
  skannet: {
    backgroundColor: '#f6f3ea', backgroundImage: `linear-gradient(90deg, rgba(0, 0, 0, 0.10), rgba(0, 0, 0, 0) 14px), linear-gradient(180deg, rgba(0, 0, 0, 0.05), rgba(0, 0, 0, 0) 10px), ${KORN}`,
    color: '#2a2a2e', fontFamily: "'Times New Roman', Times, 'Liberation Serif', serif", fontSize: '17px', lineHeight: '1.5',
    border: '1px solid #c9c4b6', borderRadius: '1px', boxShadow: '0 1px 2px rgba(0, 0, 0, 0.25), 0 6px 16px rgba(0, 0, 0, 0.12)',
    textShadow: '0 0 0.6px rgba(42, 42, 46, 0.55)', margin: '6px 8px',
    '& h2, & h3, & .fui-Text': { fontFamily: 'inherit', color: 'inherit', fontSize: 'inherit' },
  },
  skjevVenstre: { transform: 'rotate(-0.5deg)' },
  skjevHoyre: { transform: 'rotate(0.4deg)' },
  skannetDempet: { color: '#4a4a50' },
  // Mottaksstempelet til arkivtjenesten.
  stempel: {
    alignSelf: 'flex-end', border: '2px solid #a33a3a', color: '#a33a3a', borderRadius: '3px', padding: '2px 8px', marginTop: '6px', transform: 'rotate(-4deg)',
    fontFamily: "'Courier New', Courier, monospace", fontSize: '13px', fontWeight: 700, letterSpacing: '0.06em', textTransform: 'uppercase', opacity: 0.8, textShadow: 'none', whiteSpace: 'nowrap',
  },
  ark: { background: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusMedium, boxShadow: tokens.shadow4, padding: '32px', minWidth: 0 },
  hode: { display: 'flex', justifyContent: 'space-between', gap: '16px', flexWrap: 'wrap', marginBottom: '24px', color: tokens.colorNeutralForeground2 },
  kolonne: { display: 'flex', flexDirection: 'column', minWidth: 0 },
  // Brevhodet til kommunens egne dokumenter: våpenet står foran navnet og adressen.
  avsender: { display: 'flex', gap: '12px', alignItems: 'flex-start', minWidth: 0 },
  mottaker: { marginBottom: '16px', color: tokens.colorNeutralForeground2 },
  emne: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 12px' },
  mellomtittel: { fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold, margin: '16px 0 6px' },
  avsnitt: { margin: '0 0 12px', maxWidth: '68ch', whiteSpace: 'pre-line' },
  vedlegg: { marginTop: '20px', paddingTop: '12px', borderTop: `1px solid ${tokens.colorNeutralStroke2}`, color: tokens.colorNeutralForeground2 },
});

/** Et avsnitt som begynner med «## », er en mellomtittel (saksframlegg og protokoller har flere deler). */
const MELLOMTITTEL = '## ';

export function Ark({ dok }: { dok: Dokument }) {
  const c = s(); const t = useT(); const dato = useDato(); const { sprak } = useSprak(); const { oppsett } = useOkt();
  const fraKommunen = dok.avsender.navn.startsWith(oppsett.virksomhet);
  // Brev og skjemaer som er kommet inn på papir, er skannet. E-post og kommunens egne dokumenter er laget digitalt.
  const skannet = dok.mottatt !== null && (dok.kanal === 'brev' || dok.kanal === 'skjema');
  // Arkene ligger litt ulikt i skanneren. Skjevheten følger dokumentet, så den er lik hver gang.
  const skjevhet = skannet ? [c.skjevVenstre, c.skjevHoyre, undefined][(dok.emne.length + dok.dokumentdato.charCodeAt(9)) % 3] : undefined;
  return (
    <article className={mergeClasses(c.ark, skannet && c.skannet, skjevhet)} aria-label={t('arkivar.dokument.arket')}>
      {/* Navnet på arket er fra grensesnittet; innholdet er norsk. */}
      <div lang="nb">
        <div className={mergeClasses(c.hode, skannet && c.skannetDempet)}>
          <div className={c.avsender}>
            {fraKommunen && <Kommunevapen hoyde={48} />}
            <div className={c.kolonne}>
              <Text weight="semibold">{dok.avsender.navn}</Text>
              {dok.avsender.adresse && <span>{dok.avsender.adresse}</span>}
              {dok.avsender.epost && <span>{dok.avsender.epost}</span>}
            </div>
          </div>
          <div className={c.kolonne} lang={sprak}>
            <span>{t(`arkivar.kanal.${dok.kanal}`)}</span>
            <span>{t('arkivar.dokument.datert', { dato: dato(dok.dokumentdato) })}</span>
            {dok.mottatt && <span className={skannet ? c.stempel : undefined}>{t('arkivar.dokument.mottatt', { dato: dato(dok.mottatt) })}</span>}
          </div>
        </div>
        {dok.mottaker && <div className={c.mottaker}><span lang={sprak}>{t('arkivar.dokument.til')}</span> {dok.mottaker}</div>}
        <h2 className={c.emne}>{dok.emne}</h2>
        {dok.tekst.map((avsnitt, nr) => (avsnitt.startsWith(MELLOMTITTEL)
          ? <h3 key={nr} className={c.mellomtittel}>{avsnitt.slice(MELLOMTITTEL.length)}</h3>
          : <p key={nr} className={c.avsnitt}>{avsnitt}</p>))}
        {dok.vedlegg.length > 0 && <div className={c.vedlegg}><Text weight="semibold" lang={sprak}>{t('arkivar.dokument.vedlegg')}</Text> {dok.vedlegg.join(', ')}</div>}
      </div>
    </article>
  );
}
