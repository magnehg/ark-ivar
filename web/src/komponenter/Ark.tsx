// Et dokument vist som et ark: brevhode med avsender og mottaker, datoer, emne, tekst og vedlegg. Brukes for
// dokumentene i postmottaket og for dokumentene som ligger i arkivet. Innholdet er norsk uansett språk i grensesnittet:
// arket er merket som norsk, og ledetekstene fra grensesnittet («Datert», «Til:») er merket med språket de står på.
import { Text, makeStyles, tokens } from '@fluentui/react-components';
import { Dokument } from '../arkiv';
import { useDato, useSprak, useT } from '../i18n';

const s = makeStyles({
  ark: { background: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusMedium, boxShadow: tokens.shadow4, padding: '32px', minWidth: 0 },
  hode: { display: 'flex', justifyContent: 'space-between', gap: '16px', flexWrap: 'wrap', marginBottom: '24px', color: tokens.colorNeutralForeground2 },
  kolonne: { display: 'flex', flexDirection: 'column', minWidth: 0 },
  mottaker: { marginBottom: '16px', color: tokens.colorNeutralForeground2 },
  emne: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 12px' },
  mellomtittel: { fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold, margin: '16px 0 6px' },
  avsnitt: { margin: '0 0 12px', maxWidth: '68ch', whiteSpace: 'pre-line' },
  vedlegg: { marginTop: '20px', paddingTop: '12px', borderTop: `1px solid ${tokens.colorNeutralStroke2}`, color: tokens.colorNeutralForeground2 },
});

/** Et avsnitt som begynner med «## », er en mellomtittel (saksframlegg og protokoller har flere deler). */
const MELLOMTITTEL = '## ';

export function Ark({ dok }: { dok: Dokument }) {
  const c = s(); const t = useT(); const dato = useDato(); const { sprak } = useSprak();
  return (
    <article className={c.ark} aria-label={t('arkivar.dokument.arket')} lang="nb">
      <div className={c.hode}>
        <div className={c.kolonne}>
          <Text weight="semibold">{dok.avsender.navn}</Text>
          {dok.avsender.adresse && <span>{dok.avsender.adresse}</span>}
          {dok.avsender.epost && <span>{dok.avsender.epost}</span>}
        </div>
        <div className={c.kolonne} lang={sprak}>
          <span>{t(`arkivar.kanal.${dok.kanal}`)}</span>
          <span>{t('arkivar.dokument.datert', { dato: dato(dok.dokumentdato) })}</span>
          {dok.mottatt && <span>{t('arkivar.dokument.mottatt', { dato: dato(dok.mottatt) })}</span>}
        </div>
      </div>
      {dok.mottaker && <div className={c.mottaker}><span lang={sprak}>{t('arkivar.dokument.til')}</span> {dok.mottaker}</div>}
      <h2 className={c.emne}>{dok.emne}</h2>
      {dok.tekst.map((avsnitt, nr) => (avsnitt.startsWith(MELLOMTITTEL)
        ? <h3 key={nr} className={c.mellomtittel}>{avsnitt.slice(MELLOMTITTEL.length)}</h3>
        : <p key={nr} className={c.avsnitt}>{avsnitt}</p>))}
      {dok.vedlegg.length > 0 && <div className={c.vedlegg}><Text weight="semibold" lang={sprak}>{t('arkivar.dokument.vedlegg')}</Text> {dok.vedlegg.join(', ')}</div>}
    </article>
  );
}
