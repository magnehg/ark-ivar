// Én journalpost: opplysningene om registreringen og selve dokumentet som et ark.
import { useState } from 'react';
import { Link as Ruterlenke, useNavigate, useParams } from 'react-router-dom';
import { Button, Text, makeStyles, tokens } from '@fluentui/react-components';
import { Delete20Regular } from '@fluentui/react-icons';
import { api } from '../../api';
import { Dokument, Journalpost as Post, Sak, del } from '../../arkiv';
import { useDato, useKode, useT } from '../../i18n';
import { Ark } from '../../komponenter/Ark';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { Status } from '../../komponenter/Status';

const s = makeStyles({
  to: { display: 'grid', gridTemplateColumns: 'minmax(0, 3fr) minmax(0, 2fr)', gap: '16px', alignItems: 'start', '@media (max-width: 1100px)': { gridTemplateColumns: '1fr' } },
  fakta: { display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 16px', margin: 0, '& dt': { color: tokens.colorNeutralForeground2 }, '& dd': { margin: 0, minWidth: 0 } },
  dempet: { color: tokens.colorNeutralForeground2 },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center', marginTop: '16px' },
});

export default function Journalpost() {
  const c = s(); const t = useT(); const dato = useDato(); const kode = useKode(); const feiltekst = useFeiltekst(); const naviger = useNavigate();
  const { id = '' } = useParams();
  const hentet = useHent<{ journalpost: Post; sak: Sak; dokument: Dokument | null }>(`/api/arkiv/journalposter/${del(id)}`);
  const [feil, settFeil] = useState<string | null>(null);
  if (hentet.feil) return <Feil melding={hentet.feil} />;
  if (!hentet.data) return <Laster />;
  const { journalpost: p, sak, dokument } = hentet.data;
  const slett = () => { settFeil(null); api.slett(`/api/arkiv/journalposter/${del(id)}`).then(() => naviger(`/arkiv/saker/${sak.id}`)).catch((e: unknown) => settFeil(feiltekst(e))); };
  return (
    <>
      <Sidehode tittel={p.tittel} tittelSprak="nb" ingress={t('arkivar.journalpost.ingress', { nummer: `${sak.saksnummer}-${p.postnr}` })} />
      <div className={c.to}>
        {dokument ? <Ark dok={dokument} /> : <Kort><Text className={c.dempet}>{t(p.kan_slettes ? 'arkivar.journalpost.egen' : 'arkivar.journalpost.uten_dokument')}</Text></Kort>}
        <Kort tittel={t('arkivar.journalpost.opplysninger')} id="jp-opplysninger">
          <dl className={c.fakta}>
            <dt>{t('arkivar.registrering.sak')}</dt><dd lang="nb"><Ruterlenke to={`/arkiv/saker/${sak.id}`}>{sak.saksnummer} {sak.tittel}</Ruterlenke></dd>
            <dt>{t('arkivar.sak.arkivdel')}</dt><dd lang="nb">{sak.arkivdel_tekst}</dd>
            <dt>{t('arkivar.sak.primaer')}</dt><dd><span lang="nb">{sak.klasse_tekst}</span>{sak.skjerm_klasse && <> <Status slag="skjermet">{t('arkivar.sak.skjermet_klasse')}</Status></>}</dd>
            <dt>{t('arkivar.journalpost.type')}</dt><dd>{p.type} – {t(`arkivar.jptype.${p.type.toLowerCase()}`)}</dd>
            <dt>{t('arkivar.journalpost.status')}</dt><dd>{p.journalfort ? kode('jpstatus', p.status) : `${p.status} – ${t('arkivar.jpstatus.uten')}`}</dd>
            <dt>{t('arkivar.journalpost.part')}</dt><dd><span lang="nb">{p.part_navn}</span>{p.skjerm_part && <> <Status slag="skjermet">{t('arkivar.journalpost.skjermet')}</Status></>}</dd>
            <dt>{t('arkivar.journalpost.dokumentdato')}</dt><dd>{dato(p.dokumentdato)}</dd>
            <dt>{t('arkivar.journal.journaldato')}</dt><dd>{dato(p.journaldato)}</dd>
            <dt>{t('arkivar.journal.lopenummer')}</dt><dd>{p.lopenummer}</dd>
            <dt>{t('arkivar.sak.ansvarlig')}</dt><dd lang="nb">{p.saksbehandler_navn} ({p.enhet})</dd>
            <dt>{t('arkivar.journalpost.tilgang')}</dt><dd>{p.tilgangskode ? <Status slag="skjermet"><span lang="nb">{p.hjemmel}</span></Status> : t('arkivar.tilgang.offentlig')}</dd>
            {p.skjerm_tittel && <><dt>{t('arkivar.journalpost.offentlig_tittel')}</dt><dd lang="nb">{p.offentlig_tittel}</dd></>}
            {/* Statusen på dokumentfilen: om dokumentet er ferdigstilt, og hvilket format det ligger i. */}
            {p.dokumentstatus && <><dt>{t('arkivar.journalpost.dokumentstatus')}</dt><dd>{kode('dokstatus', p.dokumentstatus)}</dd></>}
            {p.variantformat && <><dt>{t('arkivar.journalpost.variantformat')}</dt><dd>{kode('variantformat', p.variantformat)}</dd></>}
          </dl>
          {feil && <Feil melding={feil} />}
          <div className={c.knapper}>
            {p.oppgave && <Ruterlenke to={`/arkiv/postmottak/${p.oppgave}`}>{t('arkivar.journalpost.vurdering')}</Ruterlenke>}
            {p.kan_slettes && <Button icon={<Delete20Regular />} onClick={slett}>{t('arkivar.journalpost.slett')}</Button>}
          </div>
        </Kort>
      </div>
    </>
  );
}
