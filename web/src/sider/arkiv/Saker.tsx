// Mappene i arkivet med søk, og én mappe med journalpostene. Fra mappen registreres nye journalposter.
import { useState } from 'react';
import { Link as Ruterlenke, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Button, Field, MessageBar, MessageBarBody, Select, Text, makeStyles, tokens } from '@fluentui/react-components';
import { Add20Regular, Delete20Regular, DocumentEdit20Regular } from '@fluentui/react-icons';
import { api } from '../../api';
import { Behandling, Journalpost, Sak, del, flytSok, lesFlyt, useStruktur } from '../../arkiv';
import { useDato, useKode, useT } from '../../i18n';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { Dokumenttittel, Journalpostfiler, Mappefiler } from '../../komponenter/Dokumentfiler';
import { Mappesok } from '../../komponenter/Mappesok';
import { Status } from '../../komponenter/Status';
import { Filtermeny, Oversikt, Teller } from '../../komponenter/Oversikt';
import { Kolonne } from '../../komponenter/Tabell';
import { useJournalpostvisning, useMappekolonner, useMappevisning } from './visninger';

const s = makeStyles({
  fakta: { display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 16px', margin: 0, '& dt': { color: tokens.colorNeutralForeground2 }, '& dd': { margin: 0 } },
  dempet: { color: tokens.colorNeutralForeground2 },
  venter: { marginBottom: '16px' },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' },
});

export function Saker() {
  const t = useT(); const dato = useDato(); const kode = useKode(); const naviger = useNavigate();
  const c = s();
  const liste = useHent<{ saker: Sak[] }>('/api/arkiv/saker');
  const struktur = useStruktur();
  // Valget står i adressen, så det følger med når studenten går inn i en mappe og tilbake igjen.
  const [sok, settSok] = useSearchParams();
  const arkivdel = sok.get('arkivdel') ?? '';
  const settArkivdel = (kode: string) => settSok(kode ? { arkivdel: kode } : {}, { replace: true });
  const alle = liste.data?.saker ?? [];
  const rader = arkivdel === '' ? alle : alle.filter((r) => r.arkivdel === arkivdel);
  const enhetsnavn = (kode: string) => struktur.data?.enheter.find((e) => e.kode === kode)?.navn ?? kode;
  const mappevalg = { sak: (r: Sak) => r, lenke: (r: Sak) => `/arkiv/saker/${r.id}`, enhetsnavn };
  const kolonner = useMappekolonner<Sak>(mappevalg);
  const visninger = useMappevisning<Sak>(mappevalg);
  return (
    <>
      <Sidehode tittel={t('arkivar.saker.tittel')} ingress={t('arkivar.saker.ingress')}
        handlinger={<Button appearance="primary" icon={<Add20Regular />} onClick={() => naviger('/arkiv/saker/ny')}>{t('arkivar.nymappe.tittel')}</Button>} />
      <Kort tittel={t('arkivar.sok.tittel')} id="mappesok">
        <Mappesok tips={t('arkivar.sok.tips.generelt')} />
      </Kort>
      <Kort tittel={t('arkivar.saker.alle')} id="saksliste">
        {liste.laster && !liste.data ? <Laster /> : liste.feil ? <Feil melding={liste.feil} /> : (
          <>
            <Oversikt rader={rader} kolonner={kolonner} visninger={visninger} nokkel={(r) => r.id} aria-labelledby="saksliste"
              forst={<>
                <Filtermeny ledetekst={t('arkivar.saker.filter')} verdi={arkivdel} velg={settArkivdel} sprak="nb"
                  valg={[{ verdi: '', navn: t('arkivar.saker.alle_arkivdeler') }, ...(struktur.data?.arkivdeler ?? []).map((a) => ({ verdi: a.kode, navn: a.tittel }))]} />
                <Teller>{t('arkivar.tabell.antall', { vist: rader.length, alle: alle.length })}</Teller>
              </>}
              startSortering={{ id: 'nummer', retning: 'descending' }}
              utvid={{ innhold: (r) => <Mappefiler sak={r.id} />, vis: t('arkivar.filer.vis'), skjul: t('arkivar.filer.skjul'), beskrevetAv: 'tittel', kan: (r) => r.antall > 0 }} />
          </>
        )}
      </Kort>
    </>
  );
}

export function EnSak() {
  const c = s(); const t = useT(); const dato = useDato(); const kode = useKode(); const feiltekst = useFeiltekst(); const naviger = useNavigate();
  const { id = '' } = useParams();
  const [sok] = useSearchParams();
  // Dokumentet fra postmottaket studenten holder på å registrere (kom hit fra søket).
  const flyt = lesFlyt(sok);
  const sak = useHent<{ sak: Sak; journalposter: Journalpost[]; kan_slettes: boolean }>(`/api/arkiv/saker/${del(id)}`);
  const venter = useHent<Behandling>(flyt ? `/api/arkiv/postmottak/${del(flyt.dokument)}` : null);
  // Dokumentet følger med videre så lenge det ikke er behandlet. Lot det seg ikke hente her, følger det også med:
  // siden for ny journalpost henter det selv og sier fra om det ikke går.
  const aktivFlyt = flyt && venter.data?.behandlet !== true ? flyt : null;
  const struktur = useStruktur();
  const [feil, settFeil] = useState<string | null>(null);
  // I mappen står det korte navnet på tilgangskoden. Hele hjemmelen står på journalposten og i journalen.
  const tilgangsnavn = (kode: string) => struktur.data?.tilgangskoder.find((k) => k.kode === kode)?.navn ?? kode;
  const tilgang = (r: Journalpost) => (r.tilgangskode ? <Status slag="skjermet"><span lang="nb">{tilgangsnavn(r.tilgangskode)}</span></Status> : t('arkivar.tilgang.offentlig'));
  // Krokene må kalles før de tidlige returene under. Mappen er ikke hentet ennå første gang.
  const postvisninger = useJournalpostvisning({ sak: sak.data?.sak, tilgang });
  if (sak.feil) return <Feil melding={sak.feil} />;
  if (!sak.data) return <Laster />;
  const d = sak.data.sak;
  const avsluttet = d.status === 'A';
  const slett = () => { settFeil(null); api.slett(`/api/arkiv/saker/${del(id)}`).then(() => naviger('/arkiv/saker')).catch((e: unknown) => settFeil(feiltekst(e))); };
  const kolonner: Kolonne<Journalpost>[] = [
    { id: 'nr', overskrift: t('arkivar.journalpost.nr'), stil: { width: '70px' }, celle: (r) => r.postnr, sorter: (r) => r.postnr },
    { id: 'type', overskrift: t('arkivar.journalpost.type'), stil: { width: '70px' }, celle: (r) => (<abbr title={t(`arkivar.jptype.${r.type.toLowerCase()}`)}>{r.type}</abbr>), sorter: (r) => r.type },
    { id: 'tittel', overskrift: t('arkivar.journalpost.tittel'), sprak: 'nb', celle: (r) => <Dokumenttittel filer={r.filer}><Ruterlenke to={`/arkiv/saker/${d.id}/journalposter/${r.id}`}>{r.tittel}</Ruterlenke></Dokumenttittel>, sorter: (r) => r.tittel },
    { id: 'part', overskrift: t('arkivar.journalpost.part'), stil: { width: '15%' }, sprak: 'nb', celle: (r) => r.part_navn, sorter: (r) => r.part_navn },
    { id: 'dato', overskrift: t('arkivar.journalpost.dokumentdato'), stil: { width: '110px' }, celle: (r) => dato(r.dokumentdato), sorter: (r) => r.dokumentdato },
    { id: 'status', overskrift: t('arkivar.journalpost.status'), stil: { width: '150px' }, celle: (r) => (r.journalfort || r.status !== 'A' ? kode('jpstatus', r.status) : `${r.status} – ${t('arkivar.jpstatus.uten')}`), sorter: (r) => r.status },
    { id: 'tilgang', overskrift: t('arkivar.journalpost.tilgang'), stil: { width: '18%' }, celle: tilgang, sorter: (r) => r.tilgangskode ?? '' },
  ];
  const nyJournalpost = (
    <div className={c.knapper}>
      <Button icon={<DocumentEdit20Regular />} disabled={avsluttet} onClick={() => naviger(`/arkiv/saker/${d.id}/nytt-dokument`)}>{t('arkivar.sak.nytt_dokument')}</Button>
      <Button appearance="primary" icon={<Add20Regular />} disabled={avsluttet} onClick={() => naviger(`/arkiv/saker/${d.id}/ny-journalpost${flytSok(aktivFlyt)}`)}>{t('arkivar.sak.ny_journalpost')}</Button>
    </div>
  );

  return (
    <>
      <Sidehode tittel={`${d.saksnummer} ${d.tittel}`} tittelSprak="nb" />
      {feil && <Feil melding={feil} />}
      {venter.data && !venter.data.behandlet && flyt && (
        <MessageBar className={c.venter} intent="info">
          <MessageBarBody>
            {t('arkivar.sak.dokument_venter')} <span lang="nb">{venter.data.dokument.emne}</span><br />
            {t('arkivar.sak.dokument_venter.hjelp')} <Ruterlenke to={`/arkiv/postmottak/${flyt.dokument}`}>{t('arkivar.sak.tilbake_dokument')}</Ruterlenke>
          </MessageBarBody>
        </MessageBar>
      )}
      <Kort tittel={t('arkivar.sak.opplysninger')} id="sak-opplysninger">
        <dl className={c.fakta}>
          <dt>{t('arkivar.sak.arkivdel')}</dt><dd lang="nb">{d.arkivdel_tekst}</dd>
          <dt>{t('arkivar.sak.primaer')}</dt><dd><span lang="nb">{d.klasse_tekst}</span>{d.skjerm_klasse && <> <Status slag="skjermet">{t('arkivar.sak.skjermet_klasse')}</Status></>}</dd>
          <dt>{t('arkivar.sak.ansvarlig')}</dt><dd lang="nb">{d.saksansvarlig_navn} ({d.enhet})</dd>
          <dt>{t('arkivar.sak.dato')}</dt><dd>{dato(d.saksdato)}</dd>
          <dt>{t('arkivar.sak.status')}</dt><dd>{kode('saksstatus', d.status)}</dd>
          {d.skjerm_tittel && <><dt>{t('arkivar.journalpost.offentlig_tittel')}</dt><dd lang="nb">{d.offentlig_tittel}</dd></>}
          {d.tilgangskode && <><dt>{t('arkivar.sak.hjemmel')}</dt><dd lang="nb"><Status slag="skjermet">{d.hjemmel}</Status></dd></>}
        </dl>
      </Kort>
      <Kort tittel={t('arkivar.sak.journalposter')} id="sak-journalposter" handlinger={nyJournalpost}>
        {avsluttet && <Text block className={c.dempet}>{t('arkivar.sak.avsluttet.hjelp')}</Text>}
        {sak.data.journalposter.length === 0
          ? <Text block className={c.dempet}>{t('arkivar.sak.tom')}</Text>
          : <Oversikt rader={sak.data.journalposter} kolonner={kolonner} visninger={postvisninger} nokkel={(r) => r.id} aria-labelledby="sak-journalposter" startSortering={{ id: 'nr', retning: 'ascending' }}
            utvid={{ innhold: (r) => <Journalpostfiler post={r} />, vis: t('arkivar.filer.vis'), skjul: t('arkivar.filer.skjul'), beskrevetAv: 'tittel', kan: (r) => r.dokumenter.length > 0 }} />}
      </Kort>
      <div className={c.knapper}>
        <Ruterlenke to="/arkiv/saker">{t('arkivar.sak.tilbake')}</Ruterlenke>
        {sak.data.kan_slettes && <Button icon={<Delete20Regular />} onClick={slett}>{t('arkivar.sak.slett')}</Button>}
      </div>
    </>
  );
}
