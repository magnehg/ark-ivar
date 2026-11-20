// Ny mappe. Studenten velger en saksmal (arkivdelen og et forslag til tittel er da fylt inn), søker for å se at mappen
// ikke finnes fra før, og fyller ut opplysningene. Dokumentene registreres i mappen etterpå. Kommer studenten fra et
// dokument i postmottaket, står dokumentet ved siden av, og neste steg er å registrere det i den nye mappen.
import { FormEvent, useState } from 'react';
import { Link as Ruterlenke, useNavigate, useSearchParams } from 'react-router-dom';
import { Button, Checkbox, Field, Input, Select, Text, makeStyles, tokens } from '@fluentui/react-components';
import { api } from '../../api';
import { Behandling, Sak, Saksmal, flytSok, lesFlyt, useStruktur } from '../../arkiv';
import { useT } from '../../i18n';
import { Ark } from '../../komponenter/Ark';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { Klassevelger } from '../../komponenter/Klassevelger';
import { Mappesok } from '../../komponenter/Mappesok';
import { Status } from '../../komponenter/Status';

const s = makeStyles({
  to: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)', gap: '16px', alignItems: 'start', '@media (max-width: 1100px)': { gridTemplateColumns: '1fr' } },
  // Dokumentet blir stående ved siden av skjemaet når siden rulles. Et langt dokument ruller for seg.
  fast: { position: 'sticky', top: '16px', maxHeight: 'calc(100vh - 32px)', overflowY: 'auto', minWidth: 0, '@media (max-width: 1100px)': { position: 'static', maxHeight: 'none', overflowY: 'visible' } },
  en: { maxWidth: '860px' },
  // En nedtrekksliste er ellers like bred som det lengste valget (hjemlene er lange) og stikker ut av kortet.
  skjema: { display: 'flex', flexDirection: 'column', gap: '12px', minWidth: 0, '& .fui-Input, & .fui-Select, & .fui-Combobox': { width: '100%', minWidth: 0 }, '& .fui-Select__select': { minWidth: 0, width: '100%', textOverflow: 'ellipsis' }, '& .fui-Field': { minWidth: 0 } },
  rad: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(200px, 1fr))', gap: '12px', minWidth: 0 },
  dempet: { color: tokens.colorNeutralForeground2, margin: '0 0 12px', maxWidth: '68ch' },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' },
  etterSok: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center', marginTop: '12px', color: tokens.colorNeutralForeground2 },
});

type Verdier = { tittel: string; arkivdel: string; klasse: string; enhet: string; skjermTittel: boolean; offentlig: string; skjermKlasse: boolean; tilgangskode: string };
const TOMT: Verdier = { tittel: '', arkivdel: '', klasse: '', enhet: '', skjermTittel: false, offentlig: '', skjermKlasse: false, tilgangskode: '' };

export default function NyMappe() {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst(); const naviger = useNavigate();
  const [sok] = useSearchParams();
  const flyt = lesFlyt(sok);
  const struktur = useStruktur();
  const dokument = useHent<Behandling>(flyt ? `/api/arkiv/postmottak/${flyt.dokument}` : null);
  const [mal, settMal] = useState<Saksmal | null>(null);
  const [v, sett] = useState<Verdier>(TOMT);
  // Skjemaet kommer fram når studenten har søkt og sagt at mappen ikke finnes.
  const [harSokt, settHarSokt] = useState(false);
  const [finnesIkke, settFinnesIkke] = useState(false);
  const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);
  if (!struktur || (flyt && !dokument.data && !dokument.feil)) return <Laster />;
  const endre = (endring: Partial<Verdier>) => sett((forrige) => ({ ...forrige, ...endring }));
  const velgMal = (kode: string) => {
    const valgt = struktur.saksmaler.find((m) => m.kode === kode) ?? null;
    settMal(valgt); settHarSokt(false); settFinnesIkke(false);
    sett({ ...TOMT, tittel: valgt?.tittel ?? '', arkivdel: valgt?.arkivdel ?? '' });
  };
  const arkivdel = struktur.arkivdeler.find((a) => a.kode === v.arkivdel);
  const skjermer = v.skjermTittel || v.skjermKlasse;
  const send = (e: FormEvent) => {
    e.preventDefault();
    settOpptatt(true); settFeil(null);
    api.post<{ sak: Sak }>('/api/arkiv/saker', {
      mal: mal?.kode ?? '', tittel: v.tittel, arkivdel: v.arkivdel, klasse: v.klasse, enhet: v.enhet,
      skjerm_tittel: v.skjermTittel, offentlig_tittel: v.offentlig, skjerm_klasse: v.skjermKlasse, tilgangskode: skjermer ? v.tilgangskode : '',
    })
      // Kommer studenten fra et dokument, er neste steg å registrere det i mappen.
      .then((d) => naviger(flyt ? `/arkiv/saker/${d.sak.id}/ny-journalpost${flytSok(flyt)}` : `/arkiv/saker/${d.sak.id}`))
      .catch((e2: unknown) => { settFeil(feiltekst(e2)); settOpptatt(false); });
  };
  const bruk = (sak: Sak) => (sak.status === 'A'
    ? <Status slag="noytral">{t('arkivar.sok.avsluttet')}</Status>
    : <Button size="small" onClick={() => naviger(flyt ? `/arkiv/saker/${sak.id}/ny-journalpost${flytSok(flyt)}` : `/arkiv/saker/${sak.id}`)}>{t(flyt ? 'arkivar.sok.bruk' : 'arkivar.sok.aapne')}</Button>);

  const innhold = (
    <div>
      <Kort tittel={t('arkivar.nymappe.mal')} id="nymappe-mal">
        <div className={c.skjema}>
          <Field label={t('arkivar.nymappe.mal.velg')} hint={mal ? mal.beskrivelse : t('arkivar.nymappe.mal.hjelp')}>
            <Select value={mal?.kode ?? ''} onChange={(_, d) => velgMal(d.value)}>
              <option value="">{t('arkivar.registrering.velg')}</option>
              {struktur.saksmaler.map((m) => <option key={m.kode} value={m.kode}>{m.navn}</option>)}
            </Select>
          </Field>
        </div>
      </Kort>
      {mal && (
        <Kort tittel={t('arkivar.nymappe.sok.tittel')} id="nymappe-sok">
          <p className={c.dempet}>{t('arkivar.nymappe.sok.ingress')}</p>
          <Mappesok key={mal.kode} tips={mal.soketips} handling={bruk} flyt={flyt} sokt={() => settHarSokt(true)} />
          {!finnesIkke && (
            <div className={c.etterSok}>
              <Button disabled={!harSokt} onClick={() => settFinnesIkke(true)}>{t('arkivar.nymappe.finnes_ikke')}</Button>
              {!harSokt && <Text>{t('arkivar.behandling.ny_mappe.sok_forst')}</Text>}
            </div>
          )}
        </Kort>
      )}
      {mal && finnesIkke && (
        <Kort tittel={t('arkivar.nymappe.skjema')} id="nymappe-skjema">
          <form onSubmit={send} className={c.skjema} lang="nb" noValidate>
            {feil && <Feil melding={feil} />}
            <Field label={t('arkivar.registrering.sakstittel')} required hint={mal.tittel ? t('arkivar.nymappe.tittel.hjelp') : t('arkivar.registrering.sakstittel.hjelp')}>
              <Input value={v.tittel} onChange={(_, d) => endre({ tittel: d.value })} />
            </Field>
            <div className={c.rad}>
              <Field label={t('arkivar.sak.arkivdel')} required>
                <Select value={v.arkivdel} onChange={(_, d) => endre({ arkivdel: d.value, klasse: '' })}>
                  <option value="">{t('arkivar.registrering.velg')}</option>
                  {struktur.arkivdeler.map((a) => <option key={a.kode} value={a.kode}>{a.tittel}</option>)}
                </Select>
              </Field>
              <Field label={t('arkivar.sak.enhet')} required>
                <Select value={v.enhet} onChange={(_, d) => endre({ enhet: d.value })}>
                  <option value="">{t('arkivar.registrering.velg')}</option>
                  {struktur.enheter.map((e) => <option key={e.kode} value={e.kode}>{e.navn}</option>)}
                </Select>
              </Field>
            </div>
            <Field label={arkivdel ? t('arkivar.sak.primaer.ordning', { ordning: t(`arkivar.ordning.${arkivdel.ordning}.navn`) }) : t('arkivar.sak.primaer')} required
              hint={arkivdel ? t(`arkivar.ordning.${arkivdel.ordning}.hjelp`) : t('arkivar.registrering.klasse.velg_arkivdel')}>
              {arkivdel
                ? <Klassevelger key={arkivdel.kode} struktur={struktur} arkivdel={arkivdel} verdi={v.klasse} velg={(kode) => endre({ klasse: kode })} />
                : <Input disabled value="" />}
            </Field>
            <Checkbox checked={v.skjermKlasse} onChange={(_, d) => endre({ skjermKlasse: d.checked === true })} label={t('arkivar.nymappe.skjerm_klasse')} />
            <Checkbox checked={v.skjermTittel} onChange={(_, d) => endre({ skjermTittel: d.checked === true })} label={t('arkivar.registrering.skjerm_sak')} />
            {v.skjermTittel && <Field label={t('arkivar.registrering.offentlig_sakstittel')} hint={t('arkivar.registrering.offentlig.hjelp')}><Input value={v.offentlig} onChange={(_, d) => endre({ offentlig: d.value })} /></Field>}
            {skjermer && (
              <Field label={t('arkivar.nymappe.hjemmel')} required hint={t('arkivar.nymappe.hjemmel.hjelp')}>
                <Select value={v.tilgangskode} onChange={(_, d) => endre({ tilgangskode: d.value })}>
                  <option value="">{t('arkivar.registrering.velg')}</option>
                  {struktur.tilgangskoder.map((k) => <option key={k.kode} value={k.kode}>{k.hjemmel} ({k.navn})</option>)}
                </Select>
              </Field>
            )}
            <div className={c.knapper}>
              <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.nymappe.opprett')}</Button>
              <Ruterlenke to={flyt ? `/arkiv/postmottak/${flyt.dokument}` : '/arkiv/saker'}>{t('arkivar.felles.avbryt')}</Ruterlenke>
            </div>
          </form>
        </Kort>
      )}
    </div>
  );
  return (
    <>
      <Sidehode tittel={t('arkivar.nymappe.tittel')} ingress={dokument.data ? t('arkivar.nymappe.for_dokument', { emne: dokument.data.dokument.emne }) : t('arkivar.nymappe.ingress')} />
      {dokument.feil && <Feil melding={dokument.feil} />}
      {dokument.data ? <div className={c.to}><div className={c.fast}><Ark dok={dokument.data.dokument} /></div>{innhold}</div> : <div className={c.en}>{innhold}</div>}
    </>
  );
}
