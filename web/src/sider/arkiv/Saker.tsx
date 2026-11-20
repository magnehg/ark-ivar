// Mappene i arkivet med søk, og én mappe med journalpostene. Fra mappen registreres nye journalposter.
import { useState } from 'react';
import { Link as Ruterlenke, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Button, Field, MessageBar, MessageBarBody, Select, Text, makeStyles, tokens } from '@fluentui/react-components';
import { Add20Regular, Delete20Regular } from '@fluentui/react-icons';
import { api } from '../../api';
import { Behandling, Journalpost, Sak, del, flytSok, lesFlyt, useStruktur } from '../../arkiv';
import { useDato, useKode, useT } from '../../i18n';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { Journalpostfiler, Mappefiler } from '../../komponenter/Dokumentfiler';
import { Mappesok } from '../../komponenter/Mappesok';
import { Mappeikon, Status } from '../../komponenter/Status';
import { Kolonne, SorterbarTabell, TabellVerktoy } from '../../komponenter/Tabell';

const s = makeStyles({
  fakta: { display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 16px', margin: 0, '& dt': { color: tokens.colorNeutralForeground2 }, '& dd': { margin: 0 } },
  filter: { maxWidth: '320px', marginBottom: '8px' },
  dempet: { color: tokens.colorNeutralForeground2 },
  under: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 },
  venter: { marginBottom: '16px' },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' },
});

/** Saksnummeret som tall til sortering: 2026/7 → 2026 0007. */
const sorterbart = (nummer: string) => nummer.split('/').map((del) => del.padStart(6, '0')).join('/');

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
  // Tittelen får mest plass. Uten en bredde på den klemmer de andre kolonnene den sammen.
  const enhetsnavn = (kode: string) => struktur.data?.enheter.find((e) => e.kode === kode)?.navn ?? kode;
  // Enheten og saksansvarlig står først, så studenten ser hvem som eier saken; klasseringen står sist.
  const kolonner: Kolonne<Sak>[] = [
    { id: 'enhet', overskrift: t('arkivar.sak.enhet.kort'), stil: { width: '64px' }, sprak: 'nb', celle: (r) => <abbr title={enhetsnavn(r.enhet)}>{r.enhet}</abbr>, sorter: (r) => r.enhet },
    { id: 'ansvarlig', overskrift: t('arkivar.sak.ansvarlig'), stil: { width: '120px' }, sprak: 'nb', celle: (r) => r.saksansvarlig_navn, sorter: (r) => r.saksansvarlig_navn },
    { id: 'nummer', overskrift: t('arkivar.sak.nummer'), stil: { width: '80px' }, celle: (r) => r.saksnummer, sorter: (r) => sorterbart(r.saksnummer) },
    { id: 'tittel', overskrift: t('arkivar.sak.tittel'), sprak: 'nb', celle: (r) => (<Mappeikon status={r.status}><Ruterlenke to={`/arkiv/saker/${r.id}`}>{r.tittel}</Ruterlenke></Mappeikon>), sorter: (r) => r.tittel },
    { id: 'dato', overskrift: t('arkivar.sak.dato'), stil: { width: '96px' }, celle: (r) => dato(r.saksdato), sorter: (r) => r.saksdato },
    { id: 'status', overskrift: t('arkivar.sak.status'), stil: { width: '64px' }, celle: (r) => <abbr title={t(`arkivar.saksstatus.${r.status.toLowerCase()}`)}>{r.status}</abbr>, sorter: (r) => r.status },
    { id: 'antall', overskrift: t('arkivar.sak.antall'), stil: { width: '92px' }, celle: (r) => r.antall, sorter: (r) => r.antall },
    // Klasseringen står sist, med arkivdelen under: arkivdelen sier hva slags klassering det er.
    { id: 'klasse', overskrift: t('arkivar.sak.primaer'), stil: { width: '230px' }, sprak: 'nb', celle: (r) => (<>{r.klasse_tekst}<span className={c.under}>{r.arkivdel_tekst}</span></>), sorter: (r) => `${r.arkivdel_tekst} ${r.klasse_tekst}` },
  ];
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
            <Field label={t('arkivar.saker.filter')} className={c.filter}>
              <Select value={arkivdel} onChange={(_, d) => settArkivdel(d.value)}>
                <option value="">{t('arkivar.saker.alle_arkivdeler')}</option>
                {(struktur.data?.arkivdeler ?? []).map((a) => <option key={a.kode} value={a.kode}>{a.tittel}</option>)}
              </Select>
            </Field>
            <TabellVerktoy tekst={t('arkivar.tabell.antall', { vist: rader.length, alle: alle.length })}>{null}</TabellVerktoy>
            <SorterbarTabell rader={rader} kolonner={kolonner} nokkel={(r) => r.id} aria-labelledby="saksliste" startSortering={{ id: 'nummer', retning: 'descending' }}
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
  if (sak.feil) return <Feil melding={sak.feil} />;
  if (!sak.data) return <Laster />;
  const d = sak.data.sak;
  const avsluttet = d.status === 'A';
  const slett = () => { settFeil(null); api.slett(`/api/arkiv/saker/${del(id)}`).then(() => naviger('/arkiv/saker')).catch((e: unknown) => settFeil(feiltekst(e))); };
  // I mappen står det korte navnet på tilgangskoden. Hele hjemmelen står på journalposten og i journalen.
  const tilgangsnavn = (kode: string) => struktur.data?.tilgangskoder.find((k) => k.kode === kode)?.navn ?? kode;
  const tilgang = (r: Journalpost) => (r.tilgangskode ? <Status slag="skjermet"><span lang="nb">{tilgangsnavn(r.tilgangskode)}</span></Status> : t('arkivar.tilgang.offentlig'));
  const kolonner: Kolonne<Journalpost>[] = [
    { id: 'nr', overskrift: t('arkivar.journalpost.nr'), stil: { width: '70px' }, celle: (r) => r.postnr, sorter: (r) => r.postnr },
    { id: 'type', overskrift: t('arkivar.journalpost.type'), stil: { width: '70px' }, celle: (r) => (<abbr title={t(`arkivar.jptype.${r.type.toLowerCase()}`)}>{r.type}</abbr>), sorter: (r) => r.type },
    { id: 'tittel', overskrift: t('arkivar.journalpost.tittel'), sprak: 'nb', celle: (r) => <Ruterlenke to={`/arkiv/saker/${d.id}/journalposter/${r.id}`}>{r.tittel}</Ruterlenke>, sorter: (r) => r.tittel },
    { id: 'part', overskrift: t('arkivar.journalpost.part'), stil: { width: '15%' }, sprak: 'nb', celle: (r) => r.part_navn, sorter: (r) => r.part_navn },
    { id: 'dato', overskrift: t('arkivar.journalpost.dokumentdato'), stil: { width: '110px' }, celle: (r) => dato(r.dokumentdato), sorter: (r) => r.dokumentdato },
    { id: 'status', overskrift: t('arkivar.journalpost.status'), stil: { width: '150px' }, celle: (r) => (r.journalfort ? kode('jpstatus', r.status) : `${r.status} – ${t('arkivar.jpstatus.uten')}`), sorter: (r) => r.status },
    { id: 'tilgang', overskrift: t('arkivar.journalpost.tilgang'), stil: { width: '18%' }, celle: tilgang, sorter: (r) => r.tilgangskode ?? '' },
  ];
  const nyJournalpost = (
    <Button appearance="primary" icon={<Add20Regular />} disabled={avsluttet} onClick={() => naviger(`/arkiv/saker/${d.id}/ny-journalpost${flytSok(aktivFlyt)}`)}>{t('arkivar.sak.ny_journalpost')}</Button>
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
          : <SorterbarTabell rader={sak.data.journalposter} kolonner={kolonner} nokkel={(r) => r.id} aria-labelledby="sak-journalposter" startSortering={{ id: 'nr', retning: 'ascending' }}
            utvid={{ innhold: (r) => <Journalpostfiler post={r} />, vis: t('arkivar.filer.vis'), skjul: t('arkivar.filer.skjul'), beskrevetAv: 'tittel', kan: (r) => r.filer.length > 0 }} />}
      </Kort>
      <div className={c.knapper}>
        <Ruterlenke to="/arkiv/saker">{t('arkivar.sak.tilbake')}</Ruterlenke>
        {sak.data.kan_slettes && <Button icon={<Delete20Regular />} onClick={slett}>{t('arkivar.sak.slett')}</Button>}
      </div>
    </>
  );
}
