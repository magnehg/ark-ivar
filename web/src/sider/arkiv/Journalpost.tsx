// Én journalpost: opplysningene om registreringen og selve dokumentet som et ark. I hodet står hovedknappen for det
// neste steget i saksgangen (ferdigstill, journalfør, avskriv) og menyen «Mer» med resten av det som er lov i
// statusen journalposten har.
import { Link as Ruterlenke, useNavigate, useParams } from 'react-router-dom';
import { Text, makeStyles, tokens } from '@fluentui/react-components';
import { Dokument, Journalpost as Post, Merknad, Sak, del } from '../../arkiv';
import { useDato, useKode, useT, useTidspunkt } from '../../i18n';
import { Ark } from '../../komponenter/Ark';
import { Avskrivningsmerke, dokumentnummer, harAvskrivning } from '../../komponenter/Avskrivning';
import { Dokumentliste } from '../../komponenter/Dokumentfiler';
import { Feil, Kort, Laster, Sidehode, useHent } from '../../komponenter/Felles';
import { useJournalposthandlinger } from '../../komponenter/Journalposthandlinger';
import { Status } from '../../komponenter/Status';

const s = makeStyles({
  // Kolonnene følger plassen siden har, ikke skjermen: med lesevinduet åpent er siden smalere, og da står de under hverandre.
  ramme: { containerType: 'inline-size' },
  to: { display: 'grid', gridTemplateColumns: 'minmax(0, 3fr) minmax(0, 2fr)', gap: '16px', alignItems: 'start', '@container (max-width: 820px)': { gridTemplateColumns: '1fr' } },
  fakta: { display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, '& dt': { color: tokens.colorNeutralForeground2 }, '& dd': { margin: 0, minWidth: 0 } },
  dempet: { color: tokens.colorNeutralForeground2 },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center', marginTop: '16px' },
  hode: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' },
  neste: { display: 'block', color: tokens.colorNeutralForeground2, marginBottom: '12px' },
  merknader: { listStyleType: 'none', margin: 0, padding: 0, display: 'flex', flexDirection: 'column', gap: '8px' },
  merknad: { borderLeft: `2px solid ${tokens.colorNeutralStroke2}`, paddingLeft: '10px' },
  liten: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
});

export default function Journalpost() {
  const c = s(); const t = useT(); const dato = useDato(); const kode = useKode(); const tidspunkt = useTidspunkt(); const naviger = useNavigate();
  const { id = '' } = useParams();
  const hentet = useHent<{ journalpost: Post; sak: Sak; dokument: Dokument | null; merknader: Merknad[] }>(`/api/arkiv/journalposter/${del(id)}`);
  const mappe = hentet.data?.sak;
  // Krokene må kalles før de tidlige returene under. Journalposten er ikke hentet ennå første gang.
  const handlinger = useJournalposthandlinger({
    etter: hentet.last, sak: mappe,
    // Slettet: tilbake til mappen. Flyttet: journalposten har fått ny adresse i mappen den kom til.
    etterSlett: (kvittering) => naviger(mappe ? `/arkiv/saker/${mappe.id}` : '/arkiv/saker', { state: { kvittering } }),
    etterFlytt: (ny) => naviger(`/arkiv/saker/${ny.sak_id}/journalposter/${ny.id}`, { replace: true }),
  });
  if (hentet.feil && !hentet.data) return <Feil melding={hentet.feil} />;
  if (!hentet.data) return <Laster />;
  const { journalpost: p, sak, dokument, merknader } = hentet.data;
  return (
    <>
      <Sidehode tittel={p.tittel} tittelSprak="nb" ingress={t('arkivar.journalpost.ingress', { nummer: dokumentnummer(sak.saksnummer, p.postnr) })}
        handlinger={<div className={c.hode}>{handlinger.hovedknapp(p)}{handlinger.meny(p, true)}</div>} />
      {/* Hva hovedknappen gjør: det neste steget i statusen journalposten har nå. */}
      {p.neste && <Text className={c.neste}>{t(`arkivar.neste.${p.neste}`, { status: kode('jpstatus', p.status) })}</Text>}
      {hentet.feil && <Feil melding={hentet.feil} />}
      {handlinger.flater}
      <div className={c.ramme}><div className={c.to}>
        {dokument ? <Ark dok={dokument} /> : <Kort><Text className={c.dempet}>{t(p.kan_slettes ? 'arkivar.journalpost.egen' : 'arkivar.journalpost.uten_dokument')}</Text></Kort>}
        <Kort tittel={t('arkivar.journalpost.opplysninger')} id="jp-opplysninger">
          <dl className={c.fakta}>
            <dt>{t('arkivar.registrering.sak')}</dt><dd lang="nb"><Ruterlenke to={`/arkiv/saker/${sak.id}`}>{sak.saksnummer} {sak.tittel}</Ruterlenke></dd>
            <dt>{t('arkivar.sak.arkivdel')}</dt><dd lang="nb">{sak.arkivdel_tekst}</dd>
            <dt>{t('arkivar.sak.primaer')}</dt><dd><span lang="nb">{sak.klasse_tekst}</span>{sak.skjerm_klasse && <> <Status slag="skjermet">{t('arkivar.sak.skjermet_klasse')}</Status></>}</dd>
            <dt>{t('arkivar.journalpost.type')}</dt><dd>{p.type} – {t(`arkivar.jptype.${p.type.toLowerCase()}`)}</dd>
            <dt>{t('arkivar.journalpost.status')}</dt><dd>{p.journalfort || p.status !== 'A' ? kode('jpstatus', p.status) : `${p.status} – ${t('arkivar.jpstatus.uten')}`}</dd>
            {harAvskrivning(p) && <><dt>{t('arkivar.avskrivning.kolonne')}</dt><dd><Avskrivningsmerke post={p} sakId={sak.id} /></dd></>}
            <dt>{t('arkivar.journalpost.part')}</dt><dd><span lang="nb">{p.part_navn}</span>{p.skjerm_part && <> <Status slag="skjermet">{t('arkivar.journalpost.skjermet')}</Status></>}</dd>
            <dt>{t('arkivar.journalpost.dokumentdato')}</dt><dd>{dato(p.dokumentdato)}</dd>
            <dt>{t('arkivar.journal.journaldato')}</dt><dd>{dato(p.journaldato)}</dd>
            <dt>{t('arkivar.journal.lopenummer')}</dt><dd>{p.lopenummer}{!p.journalfort && p.status !== 'A' && <span className={c.liten}>{t('arkivar.journalpost.ikke_journalfort')}</span>}</dd>
            <dt>{t('arkivar.sak.ansvarlig')}</dt><dd lang="nb">{p.saksbehandler_navn} ({p.enhet})</dd>
            <dt>{t('arkivar.journalpost.tilgang')}</dt><dd>{p.tilgangskode ? <Status slag="skjermet"><span lang="nb">{p.hjemmel}</span></Status> : t('arkivar.tilgang.offentlig')}</dd>
            {p.skjerm_tittel && <><dt>{t('arkivar.journalpost.offentlig_tittel')}</dt><dd lang="nb">{p.offentlig_tittel}</dd></>}
            {/* Statusen på dokumentfilen: om dokumentet er ferdigstilt, og hvilket format det ligger i. */}
            {p.dokumentstatus && <><dt>{t('arkivar.journalpost.dokumentstatus')}</dt><dd>{kode('dokstatus', p.dokumentstatus)}</dd></>}
            {p.variantformat && <><dt>{t('arkivar.journalpost.variantformat')}</dt><dd>{kode('variantformat', p.variantformat)}</dd></>}
            {p.dokumenter.length > 0 && <><dt>{t('arkivar.dokumenter.tittel')}</dt><dd><Dokumentliste post={p} /></dd></>}
            {merknader.length > 0 && (
              <>
                <dt>{t('arkivar.merknader.tittel')}</dt>
                <dd>
                  <ul className={c.merknader}>
                    {merknader.map((m) => (
                      <li key={m.id} className={c.merknad}>
                        <span lang="nb">{m.tekst}</span>
                        <span className={c.liten}><span lang="nb">{m.av}</span>{m.av && ', '}{tidspunkt(m.tidspunkt)}</span>
                      </li>
                    ))}
                  </ul>
                </dd>
              </>
            )}
          </dl>
          {p.oppgave && <div className={c.knapper}><Ruterlenke to={`/arkiv/postmottak/${p.oppgave}`}>{t('arkivar.journalpost.vurdering')}</Ruterlenke></div>}
        </Kort>
      </div></div>
    </>
  );
}
