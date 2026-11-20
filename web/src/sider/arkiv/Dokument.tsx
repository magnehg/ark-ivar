// Ett dokument fra postmottaket: dokumentet til venstre, registreringen til høyre. Når dokumentet er behandlet, vises
// vurderingen mot fasiten i stedet for skjemaet.
import { useState } from 'react';
import { Link as Ruterlenke, useParams } from 'react-router-dom';
import { Button, Caption1, Checkbox, Field, Input, Radio, RadioGroup, Select, Text, makeStyles, tokens } from '@fluentui/react-components';
import { ArrowUndo20Regular } from '@fluentui/react-icons';
import { api } from '../../api';
import { Behandling, Dokument as Dok, Handling, Kriterium, Sak, Struktur, useStruktur } from '../../arkiv';
import { useDato, useT } from '../../i18n';
import { Feil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { Status } from '../../komponenter/Status';

const s = makeStyles({
  to: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)', gap: '16px', alignItems: 'start', '@media (max-width: 1100px)': { gridTemplateColumns: '1fr' } },
  ark: { background: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusMedium, boxShadow: tokens.shadow4, padding: '32px', minWidth: 0 },
  hode: { display: 'flex', justifyContent: 'space-between', gap: '16px', flexWrap: 'wrap', marginBottom: '24px', color: tokens.colorNeutralForeground2 },
  avsender: { display: 'flex', flexDirection: 'column' },
  emne: { fontSize: tokens.fontSizeBase400, fontWeight: tokens.fontWeightSemibold, margin: '0 0 12px' },
  avsnitt: { margin: '0 0 12px', maxWidth: '68ch' },
  vedlegg: { marginTop: '20px', paddingTop: '12px', borderTop: `1px solid ${tokens.colorNeutralStroke2}`, color: tokens.colorNeutralForeground2 },
  // En nedtrekksliste er ellers like bred som det lengste valget (hjemlene er lange) og stikker ut av kortet.
  skjema: { display: 'flex', flexDirection: 'column', gap: '12px', minWidth: 0, '& .fui-Input, & .fui-Select': { width: '100%', minWidth: 0 }, '& .fui-Select__select': { minWidth: 0, width: '100%', textOverflow: 'ellipsis' }, '& .fui-Field': { minWidth: 0 } },
  gruppe: { border: 'none', padding: 0, margin: 0, display: 'flex', flexDirection: 'column', gap: '12px', minWidth: 0 },
  gruppetittel: { fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold, padding: 0, marginBottom: '4px' },
  rad: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(180px, 1fr))', gap: '12px', minWidth: 0 },
  valgtekst: { color: tokens.colorNeutralForeground2, display: 'block' },
  kriterium: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto', gap: '4px 12px', padding: '10px 0', borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
  kriterienavn: { fontWeight: tokens.fontWeightSemibold },
  verdier: { gridColumn: '1 / -1', color: tokens.colorNeutralForeground2 },
  forklaring: { gridColumn: '1 / -1', margin: 0, maxWidth: '68ch' },
  poeng: { fontSize: tokens.fontSizeBase500, fontWeight: tokens.fontWeightSemibold, margin: '0 0 8px' },
  forslag: { background: tokens.colorNeutralBackground2, borderRadius: tokens.borderRadiusMedium, padding: '12px', margin: '12px 0', display: 'flex', flexDirection: 'column', gap: '4px' },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', marginTop: '12px' },
});

/** Dokumentet slik det kom inn, som et ark. */
function Ark({ dok }: { dok: Dok }) {
  const c = s(); const t = useT(); const dato = useDato();
  return (
    <article className={c.ark} aria-label={t('arkivar.dokument.arket')} lang="nb">
      <div className={c.hode}>
        <div className={c.avsender}>
          <Text weight="semibold">{dok.avsender.navn}</Text>
          {dok.avsender.adresse && <span>{dok.avsender.adresse}</span>}
          {dok.avsender.epost && <span>{dok.avsender.epost}</span>}
        </div>
        <div className={c.avsender}>
          <span>{t(`arkivar.kanal.${dok.kanal}`)}</span>
          <span>{t('arkivar.dokument.datert', { dato: dato(dok.dokumentdato) })}</span>
          <span>{t('arkivar.dokument.mottatt', { dato: dato(dok.mottatt) })}</span>
        </div>
      </div>
      <h2 className={c.emne}>{dok.emne}</h2>
      {dok.tekst.map((avsnitt, nr) => <p key={nr} className={c.avsnitt}>{avsnitt}</p>)}
      {dok.vedlegg.length > 0 && <div className={c.vedlegg}><Text weight="semibold">{t('arkivar.dokument.vedlegg')}</Text> {dok.vedlegg.join(', ')}</div>}
    </article>
  );
}

type Skjemaverdier = {
  handling: Handling | ''; sakstype: 'ny' | 'eksisterende'; sakId: string; sakstittel: string; arkivdel: string; klasse: string; enhet: string; skjermSak: boolean; offentligSak: string;
  type: string; tittel: string; dokumentdato: string; part: string; tilgangskode: string; skjermTittel: boolean; offentligTittel: string; skjermPart: boolean;
};
const TOMT: Skjemaverdier = {
  handling: '', sakstype: 'ny', sakId: '', sakstittel: '', arkivdel: '', klasse: '', enhet: '', skjermSak: false, offentligSak: '',
  type: '', tittel: '', dokumentdato: '', part: '', tilgangskode: '', skjermTittel: false, offentligTittel: '', skjermPart: false,
};

/** Skjemaet arkivtjenesten fyller ut: hva som skal skje med dokumentet, saken og journalposten. */
function Registrering({ id, struktur, saker, ferdig }: { id: string; struktur: Struktur; saker: Sak[]; ferdig: () => void }) {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const [v, sett] = useState<Skjemaverdier>(TOMT);
  const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);
  const endre = (endring: Partial<Skjemaverdier>) => sett((forrige) => ({ ...forrige, ...endring }));
  const arkiveres = v.handling === 'journalfor' || v.handling === 'arkiver';
  const journalfores = v.handling === 'journalfor';
  const hovedgrupper = struktur.klasser.filter((k) => k.forelder === null);

  const send = () => {
    settOpptatt(true); settFeil(null);
    const kropp = !arkiveres ? { handling: v.handling } : {
      handling: v.handling,
      sak: v.sakstype === 'eksisterende' ? { type: 'eksisterende', id: v.sakId }
        : { type: 'ny', tittel: v.sakstittel, arkivdel: v.arkivdel, klasse: v.klasse, enhet: v.enhet, skjerm_tittel: v.skjermSak, offentlig_tittel: v.offentligSak },
      journalpost: { type: v.type, tittel: v.tittel, dokumentdato: v.dokumentdato, part_navn: v.part, tilgangskode: journalfores ? v.tilgangskode : '',
        skjerm_tittel: journalfores && v.skjermTittel, offentlig_tittel: v.offentligTittel, skjerm_part: journalfores && v.skjermPart },
    };
    api.post(`/api/arkiv/postmottak/${id}`, kropp).then(ferdig).catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };

  return (
    <form className={c.skjema} noValidate onSubmit={(e) => { e.preventDefault(); send(); }}>
      {feil && <Feil melding={feil} />}
      <Field label={t('arkivar.registrering.handling')} required>
        <RadioGroup value={v.handling} onChange={(_, d) => endre({ handling: d.value as Handling })}>
          {struktur.handlinger.map((h) => (
            <Radio key={h} value={h} label={<><Text>{t(`arkivar.handling.${h}`)}</Text><Caption1 className={c.valgtekst}>{t(`arkivar.handling.${h}.hjelp`)}</Caption1></>} />
          ))}
        </RadioGroup>
      </Field>
      {arkiveres && (
        <>
          <fieldset className={c.gruppe}>
            <legend className={c.gruppetittel}>{t('arkivar.registrering.sak')}</legend>
            <RadioGroup layout="horizontal" value={v.sakstype} aria-label={t('arkivar.registrering.sak')} onChange={(_, d) => endre({ sakstype: d.value as 'ny' | 'eksisterende' })}>
              <Radio value="ny" label={t('arkivar.registrering.sak.ny')} />
              <Radio value="eksisterende" label={t('arkivar.registrering.sak.eksisterende')} />
            </RadioGroup>
            {v.sakstype === 'eksisterende' ? (
              <Field label={t('arkivar.registrering.sak.velg')} required>
                <Select value={v.sakId} onChange={(_, d) => endre({ sakId: d.value })}>
                  <option value="">{t('arkivar.registrering.velg')}</option>
                  {saker.map((sak) => <option key={sak.id} value={sak.id}>{sak.saksnummer} {sak.tittel}</option>)}
                </Select>
              </Field>
            ) : (
              <>
                <Field label={t('arkivar.registrering.sakstittel')} required hint={t('arkivar.registrering.sakstittel.hjelp')}><Input value={v.sakstittel} onChange={(_, d) => endre({ sakstittel: d.value })} /></Field>
                <div className={c.rad}>
                  <Field label={t('arkivar.sak.arkivdel')} required>
                    <Select value={v.arkivdel} onChange={(_, d) => endre({ arkivdel: d.value })}>
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
                <Field label={t('arkivar.sak.klasse')} required>
                  <Select value={v.klasse} onChange={(_, d) => endre({ klasse: d.value })}>
                    <option value="">{t('arkivar.registrering.velg')}</option>
                    {hovedgrupper.map((g) => (
                      <optgroup key={g.kode} label={`${g.kode} ${g.tittel}`}>
                        {struktur.klasser.filter((k) => k.forelder === g.kode).map((k) => <option key={k.kode} value={k.kode}>{k.kode} {k.tittel}</option>)}
                      </optgroup>
                    ))}
                  </Select>
                </Field>
                {journalfores && <Checkbox checked={v.skjermSak} onChange={(_, d) => endre({ skjermSak: d.checked === true })} label={t('arkivar.registrering.skjerm_sak')} />}
                {journalfores && v.skjermSak && <Field label={t('arkivar.registrering.offentlig_sakstittel')} hint={t('arkivar.registrering.offentlig.hjelp')}><Input value={v.offentligSak} onChange={(_, d) => endre({ offentligSak: d.value })} /></Field>}
              </>
            )}
          </fieldset>
          <fieldset className={c.gruppe}>
            <legend className={c.gruppetittel}>{t(journalfores ? 'arkivar.registrering.journalpost' : 'arkivar.registrering.registrering')}</legend>
            <Field label={t('arkivar.journalpost.tittel')} required><Input value={v.tittel} onChange={(_, d) => endre({ tittel: d.value })} /></Field>
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
            <Field label={t('arkivar.journalpost.part')} required={journalfores}><Input value={v.part} onChange={(_, d) => endre({ part: d.value })} /></Field>
            {journalfores && (
              <>
                <Field label={t('arkivar.journalpost.tilgangskode')} hint={t('arkivar.journalpost.tilgangskode.hjelp')}>
                  <Select value={v.tilgangskode} onChange={(_, d) => endre({ tilgangskode: d.value })}>
                    <option value="">{t('arkivar.tilgang.ingen')}</option>
                    {struktur.tilgangskoder.map((k) => <option key={k.kode} value={k.kode}>{k.hjemmel} ({k.navn})</option>)}
                  </Select>
                </Field>
                <Checkbox checked={v.skjermTittel} onChange={(_, d) => endre({ skjermTittel: d.checked === true })} label={t('arkivar.registrering.skjerm_tittel')} />
                {v.skjermTittel && <Field label={t('arkivar.journalpost.offentlig_tittel')} hint={t('arkivar.registrering.offentlig.hjelp')}><Input value={v.offentligTittel} onChange={(_, d) => endre({ offentligTittel: d.value })} /></Field>}
                <Checkbox checked={v.skjermPart} onChange={(_, d) => endre({ skjermPart: d.checked === true })} label={t('arkivar.registrering.skjerm_part')} />
              </>
            )}
          </fieldset>
        </>
      )}
      <div><Button appearance="primary" type="submit" disabled={opptatt || v.handling === ''}>{t(arkiveres ? 'arkivar.registrering.knapp.registrer' : 'arkivar.registrering.knapp.ferdig')}</Button></div>
    </form>
  );
}

/** Vurderingen mot fasiten, kriterium for kriterium, med forklaringen fra fasiten. */
function Vurdering({ id, b, angret }: { id: string; b: Behandling; angret: () => void }) {
  const c = s(); const t = useT(); const dato = useDato(); const feiltekst = useFeiltekst();
  const [feil, settFeil] = useState<string | null>(null);
  const ingen = t('arkivar.tilgang.ingen');
  const verdi = (k: Kriterium, v: string, fasit: boolean): string => {
    if (k.id === 'handling') return t(`arkivar.handling.${v}`);
    if (k.id === 'sak') return v === 'ny' ? t('arkivar.registrering.sak.ny') : fasit ? (b.forslag?.sak ?? t('arkivar.registrering.sak.eksisterende')) : v;
    if (k.id === 'type') return `${v} – ${t(`arkivar.jptype.${v.toLowerCase()}`)}`;
    if (k.id === 'tilgang') return v === '' ? ingen : v;
    if (k.id === 'skjerm_tittel' || k.id === 'skjerm_part') return t(`arkivar.felles.${v}`);
    if (k.id === 'dokumentdato') return dato(v);
    if (k.id === 'offentlig_tittel' || k.id === 'offentlig_sakstittel') return fasit ? t('arkivar.vurdering.uten_navn') : v;
    return v;
  };
  const angre = () => { settFeil(null); api.slett(`/api/arkiv/postmottak/${id}`).then(angret).catch((e: unknown) => settFeil(feiltekst(e))); };
  return (
    <div>
      {feil && <Feil melding={feil} />}
      <p className={c.poeng}>{t('arkivar.vurdering.poeng', { poeng: b.poeng ?? 0, maks: b.maks ?? 0 })}</p>
      {b.sak && b.journalpost && <Text block>{t('arkivar.vurdering.registrert', { nummer: `${b.sak.saksnummer}-${b.journalpost.postnr}` })} <Ruterlenke to={`/arkiv/saker/${b.sak.id}`}>{b.sak.tittel}</Ruterlenke></Text>}
      {(b.vurdering ?? []).map((k) => (
        <div key={k.id} className={c.kriterium}>
          <span className={c.kriterienavn}>{t(`arkivar.kriterium.${k.id}`)}</span>
          <Status slag={k.ok ? 'ok' : 'feil'}>{t(k.ok ? 'arkivar.vurdering.riktig' : 'arkivar.vurdering.avvik')}</Status>
          <span className={c.verdier}>{t('arkivar.vurdering.ditt', { verdi: verdi(k, k.ditt, false) })}{!k.ok && <> · {t('arkivar.vurdering.fasit', { verdi: verdi(k, k.riktig, true) })}</>}</span>
          {k.forklaring && <p className={c.forklaring} lang="nb">{k.forklaring}</p>}
        </div>
      ))}
      {b.forslag && (
        <div className={c.forslag} lang="nb">
          <Text weight="semibold">{t('arkivar.vurdering.forslag')}</Text>
          {b.forslag.sak && <span>{t('arkivar.vurdering.forslag.sak', { tittel: b.forslag.sak })}</span>}
          {b.forslag.sak_offentlig && <span>{t('arkivar.vurdering.forslag.sak_offentlig', { tittel: b.forslag.sak_offentlig })}</span>}
          {b.forslag.tittel && <span>{t('arkivar.vurdering.forslag.tittel', { tittel: b.forslag.tittel })}</span>}
          {b.forslag.offentlig_tittel && <span>{t('arkivar.vurdering.forslag.offentlig', { tittel: b.forslag.offentlig_tittel })}</span>}
        </div>
      )}
      <div className={c.knapper}>
        <Button icon={<ArrowUndo20Regular />} onClick={angre}>{t('arkivar.vurdering.angre')}</Button>
        <Ruterlenke to="/arkiv/postmottak">{t('arkivar.vurdering.tilbake')}</Ruterlenke>
      </div>
    </div>
  );
}

export default function Dokument() {
  const c = s(); const t = useT();
  const { id = '' } = useParams();
  const behandling = useHent<Behandling>(`/api/arkiv/postmottak/${id}`);
  const saker = useHent<{ saker: Sak[] }>('/api/arkiv/saker');
  const struktur = useStruktur();
  if (behandling.feil) return <Feil melding={behandling.feil} />;
  if (!behandling.data || !struktur || !saker.data) return <Laster />;
  const b = behandling.data;
  return (
    <>
      <Sidehode tittel={b.dokument.emne} ingress={t(b.behandlet ? 'arkivar.dokument.ingress.behandlet' : 'arkivar.dokument.ingress')} />
      <div className={c.to}>
        <Ark dok={b.dokument} />
        <Kort tittel={t(b.behandlet ? 'arkivar.vurdering.tittel' : 'arkivar.registrering.tittel')} id="registrering">
          {b.behandlet
            ? <Vurdering id={id} b={b} angret={() => { behandling.last(); saker.last(); }} />
            : <Registrering id={id} struktur={struktur} saker={saker.data.saker} ferdig={() => { behandling.last(); saker.last(); }} />}
        </Kort>
      </div>
    </>
  );
}
