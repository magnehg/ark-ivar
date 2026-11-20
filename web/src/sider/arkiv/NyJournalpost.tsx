// Ny journalpost i en mappe. Journalposten gjelder vanligvis et dokument fra postmottaket: da står dokumentet ved
// siden av skjemaet, og registreringen vurderes mot fasiten. Studenten kan også lage en egen registrering uten
// dokument fra postmottaket. Dokumentet kan journalføres eller arkiveres uten journalføring.
import { FormEvent, useState } from 'react';
import { Link as Ruterlenke, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Button, Caption1, Checkbox, Field, Input, Radio, RadioGroup, Select, Text, makeStyles, tokens } from '@fluentui/react-components';
import { api } from '../../api';
import { Behandling, Journalpost, Postmottaksrad, Sak, lesFlyt, useStruktur } from '../../arkiv';
import { useDato, useT } from '../../i18n';
import { Ark } from '../../komponenter/Ark';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { Status } from '../../komponenter/Status';

const s = makeStyles({
  to: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)', gap: '16px', alignItems: 'start', '@media (max-width: 1100px)': { gridTemplateColumns: '1fr' } },
  // Dokumentet blir stående ved siden av skjemaet når siden rulles. Et langt dokument ruller for seg.
  fast: { position: 'sticky', top: '16px', maxHeight: 'calc(100vh - 32px)', overflowY: 'auto', minWidth: 0, '@media (max-width: 1100px)': { position: 'static', maxHeight: 'none', overflowY: 'visible' } },
  skjema: { display: 'flex', flexDirection: 'column', gap: '12px', minWidth: 0, '& .fui-Input, & .fui-Select': { width: '100%', minWidth: 0 }, '& .fui-Select__select': { minWidth: 0, width: '100%', textOverflow: 'ellipsis' }, '& .fui-Field': { minWidth: 0 } },
  rad: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '12px', minWidth: 0 },
  mappe: { background: tokens.colorNeutralBackground2, borderRadius: tokens.borderRadiusMedium, padding: '10px 12px', display: 'flex', flexDirection: 'column', gap: '2px' },
  dempet: { color: tokens.colorNeutralForeground2 },
  valgtekst: { color: tokens.colorNeutralForeground2, display: 'block' },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' },
});

type Verdier = { type: string; tittel: string; dokumentdato: string; part: string; tilgangskode: string; skjermTittel: boolean; offentlig: string; skjermPart: boolean };
const TOMT: Verdier = { type: '', tittel: '', dokumentdato: '', part: '', tilgangskode: '', skjermTittel: false, offentlig: '', skjermPart: false };

export default function NyJournalpost() {
  const c = s(); const t = useT(); const dato = useDato(); const feiltekst = useFeiltekst(); const naviger = useNavigate();
  const { id = '' } = useParams();
  const [sok] = useSearchParams();
  const flyt = lesFlyt(sok);
  const struktur = useStruktur();
  const sak = useHent<{ sak: Sak }>(`/api/arkiv/saker/${id}`);
  const postmottak = useHent<{ dokumenter: Postmottaksrad[] }>('/api/arkiv/postmottak');
  const [oppgave, settOppgave] = useState(flyt?.dokument ?? '');
  const [journalfores, settJournalfores] = useState(flyt?.handling !== 'arkiver');
  const dokument = useHent<Behandling>(oppgave ? `/api/arkiv/postmottak/${oppgave}` : null);
  const [v, sett] = useState<Verdier>(TOMT);
  const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);
  if (sak.feil) return <Feil melding={sak.feil} />;
  if (!struktur || !sak.data || !postmottak.data) return <Laster />;
  const mappe = sak.data.sak;
  const endre = (endring: Partial<Verdier>) => sett((forrige) => ({ ...forrige, ...endring }));
  const ubehandlet = postmottak.data.dokumenter.filter((d) => !d.behandlet);
  const dok = oppgave && dokument.data && !dokument.data.behandlet ? dokument.data.dokument : null;
  const send = (e: FormEvent) => {
    e.preventDefault();
    settOpptatt(true); settFeil(null);
    api.post<{ journalpost: Journalpost; oppgave: string | null }>(`/api/arkiv/saker/${id}/journalposter`, {
      oppgave, journalfores, type: v.type, tittel: v.tittel, offentlig_tittel: v.offentlig, dokumentdato: v.dokumentdato, part_navn: v.part,
      tilgangskode: journalfores ? v.tilgangskode : '', skjerm_tittel: journalfores && v.skjermTittel, skjerm_part: journalfores && v.skjermPart,
    })
      // Et dokument fra postmottaket får vurderingen sin; en egen registrering vises i mappen.
      .then((d) => naviger(d.oppgave ? `/arkiv/postmottak/${d.oppgave}` : `/arkiv/saker/${id}`))
      .catch((e2: unknown) => { settFeil(feiltekst(e2)); settOpptatt(false); });
  };
  return (
    <>
      <Sidehode tittel={t('arkivar.nyjp.tittel')} ingress={t('arkivar.nyjp.ingress')} />
      <div className={c.to}>
        {dok ? <div className={c.fast}><Ark dok={dok} /></div> : <Kort><Text className={c.dempet}>{t(oppgave ? 'arkivar.felles.laster' : 'arkivar.nyjp.uten_dokument')}</Text></Kort>}
        <Kort tittel={t('arkivar.registrering.tittel')} id="nyjp-skjema">
          <form onSubmit={send} className={c.skjema} lang="nb" noValidate>
            {feil && <Feil melding={feil} />}
            <div className={c.mappe}>
              <Text weight="semibold"><Ruterlenke to={`/arkiv/saker/${mappe.id}`}>{mappe.saksnummer} {mappe.tittel}</Ruterlenke></Text>
              <span className={c.dempet}>
                {mappe.arkivdel_tekst} · {mappe.klasse_tekst}{mappe.skjerm_klasse && <> <Status slag="skjermet">{t('arkivar.sak.skjermet_klasse')}</Status></>}
              </span>
            </div>
            <Field label={t('arkivar.nyjp.dokument')} hint={t('arkivar.nyjp.dokument.hjelp')}>
              <Select value={oppgave} onChange={(_, d) => settOppgave(d.value)}>
                <option value="">{t('arkivar.nyjp.dokument.ingen')}</option>
                {ubehandlet.map((d) => <option key={d.id} value={d.id}>{dato(d.mottatt)} {d.emne}</option>)}
              </Select>
            </Field>
            <RadioGroup value={journalfores ? 'journalfor' : 'arkiver'} onChange={(_, d) => settJournalfores(d.value === 'journalfor')} aria-label={t('arkivar.nyjp.maate')}>
              {(['journalfor', 'arkiver'] as const).map((h) => (
                <Radio key={h} value={h} label={<><Text>{t(`arkivar.handling.${h}`)}</Text><Caption1 className={c.valgtekst}>{t(`arkivar.handling.${h}.hjelp`)}</Caption1></>} />
              ))}
            </RadioGroup>
            <Field label={t('arkivar.journalpost.tittel')} required hint={t('arkivar.nyjp.tittel.hjelp')}><Input value={v.tittel} onChange={(_, d) => endre({ tittel: d.value })} /></Field>
            <div className={c.rad}>
              {journalfores && (
                <Field label={t('arkivar.journalpost.type')} required>
                  <Select value={v.type} onChange={(_, d) => endre({ type: d.value })}>
                    <option value="">{t('arkivar.registrering.velg')}</option>
                    {struktur.journalposttyper.map((kode) => <option key={kode} value={kode}>{kode} – {t(`arkivar.jptype.${kode.toLowerCase()}`)}</option>)}
                  </Select>
                </Field>
              )}
              <Field label={t('arkivar.journalpost.dokumentdato')} required><Input type="date" value={v.dokumentdato} onChange={(_, d) => endre({ dokumentdato: d.value })} /></Field>
            </div>
            <Field label={t('arkivar.journalpost.part')} required={journalfores && (v.type === 'I' || v.type === 'U')}><Input value={v.part} onChange={(_, d) => endre({ part: d.value })} /></Field>
            {journalfores && (
              <>
                <Field label={t('arkivar.journalpost.tilgangskode')} hint={t('arkivar.journalpost.tilgangskode.hjelp')}>
                  <Select value={v.tilgangskode} onChange={(_, d) => endre({ tilgangskode: d.value })}>
                    <option value="">{t('arkivar.tilgang.ingen')}</option>
                    {struktur.tilgangskoder.map((k) => <option key={k.kode} value={k.kode}>{k.hjemmel} ({k.navn})</option>)}
                  </Select>
                </Field>
                <Checkbox checked={v.skjermTittel} onChange={(_, d) => endre({ skjermTittel: d.checked === true })} label={t('arkivar.registrering.skjerm_tittel')} />
                {v.skjermTittel && <Field label={t('arkivar.journalpost.offentlig_tittel')} hint={t('arkivar.registrering.offentlig.hjelp')}><Input value={v.offentlig} onChange={(_, d) => endre({ offentlig: d.value })} /></Field>}
                <Checkbox checked={v.skjermPart} onChange={(_, d) => endre({ skjermPart: d.checked === true })} label={t('arkivar.registrering.skjerm_part')} />
              </>
            )}
            <div className={c.knapper}>
              <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.nyjp.registrer')}</Button>
              <Ruterlenke to={`/arkiv/saker/${mappe.id}`}>{t('arkivar.felles.avbryt')}</Ruterlenke>
            </div>
          </form>
        </Kort>
      </div>
    </>
  );
}
