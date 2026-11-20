// Ett dokument fra postmottaket: dokumentet til venstre, behandlingen til høyre. Studenten velger hva som skal skje
// med dokumentet. Skal det i arkivet, søker studenten etter mappen og registrerer dokumentet i den, eller oppretter
// en ny mappe først. Når dokumentet er behandlet, vises vurderingen mot fasiten.
import { useState } from 'react';
import { Link as Ruterlenke, useNavigate, useParams } from 'react-router-dom';
import { Button, Caption1, Radio, RadioGroup, Text, makeStyles, tokens } from '@fluentui/react-components';
import { Add20Regular, ArrowUndo20Regular } from '@fluentui/react-icons';
import { api } from '../../api';
import { Behandling, Flyt, Handling, Kriterium, Sak, del, flytSok, useStruktur } from '../../arkiv';
import { useDato, useT } from '../../i18n';
import { Ark } from '../../komponenter/Ark';
import { Feil, Hentefeil, Kort, Laster, Sidehode, useFeiltekst, useHent } from '../../komponenter/Felles';
import { Mappesok } from '../../komponenter/Mappesok';
import { Status } from '../../komponenter/Status';

const s = makeStyles({
  to: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) minmax(0, 1fr)', gap: '16px', alignItems: 'start', '@media (max-width: 1100px)': { gridTemplateColumns: '1fr' } },
  // Dokumentet blir stående ved siden av skjemaet når siden rulles. Et langt dokument ruller for seg.
  fast: { position: 'sticky', top: '16px', maxHeight: 'calc(100vh - 32px)', overflowY: 'auto', minWidth: 0, '@media (max-width: 1100px)': { position: 'static', maxHeight: 'none', overflowY: 'visible' } },
  skjema: { display: 'flex', flexDirection: 'column', gap: '12px', minWidth: 0 },
  gruppetittel: { fontSize: tokens.fontSizeBase300, fontWeight: tokens.fontWeightSemibold, margin: '8px 0 0' },
  dempet: { color: tokens.colorNeutralForeground2, margin: 0, maxWidth: '68ch' },
  valgtekst: { color: tokens.colorNeutralForeground2, display: 'block' },
  kriterium: { display: 'grid', gridTemplateColumns: 'minmax(0, 1fr) auto', gap: '4px 12px', padding: '10px 0', borderTop: `1px solid ${tokens.colorNeutralStroke2}` },
  kriterienavn: { fontWeight: tokens.fontWeightSemibold },
  verdier: { gridColumn: '1 / -1', color: tokens.colorNeutralForeground2 },
  forklaring: { gridColumn: '1 / -1', margin: 0, maxWidth: '68ch' },
  poeng: { fontSize: tokens.fontSizeBase500, fontWeight: tokens.fontWeightSemibold, margin: '0 0 8px' },
  forslag: { background: tokens.colorNeutralBackground2, borderRadius: tokens.borderRadiusMedium, padding: '12px', margin: '12px 0', display: 'flex', flexDirection: 'column', gap: '4px' },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center', marginTop: '12px' },
});

/**
 * Behandlingen av dokumentet: hva som skal skje med det. Et dokument som skal til et annet system eller holdes utenfor
 * arkivet, er ferdig når valget er registrert. Et dokument som skal i arkivet, registreres i mappen det hører til.
 */
function Behandlingsvalg({ id, struktur, ferdig }: { id: string; struktur: { handlinger: Handling[] }; ferdig: () => void }) {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst(); const naviger = useNavigate();
  const [handling, settHandling] = useState<Handling | ''>('');
  const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);
  // Studenten skal søke etter mappen før en ny opprettes.
  const [harSokt, settHarSokt] = useState(false);
  const arkiveres = handling === 'journalfor' || handling === 'arkiver';
  const flyt: Flyt = arkiveres ? { dokument: id, handling } : null;
  const registrer = () => {
    settOpptatt(true); settFeil(null);
    api.post(`/api/arkiv/postmottak/${del(id)}`, { handling }).then(ferdig).catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  const bruk = (sak: Sak) => (sak.status === 'A'
    ? <Status slag="noytral">{t('arkivar.sok.avsluttet')}</Status>
    : <Button size="small" onClick={() => naviger(`/arkiv/saker/${sak.id}/ny-journalpost${flytSok(flyt)}`)}>{t('arkivar.sok.bruk')}</Button>);
  return (
    <div className={c.skjema}>
      {feil && <Feil melding={feil} />}
      {/* Søket begynner på nytt når behandlingen byttes, og da må det søkes igjen før en ny mappe kan opprettes. */}
      <RadioGroup value={handling} onChange={(_, d) => { settHandling(d.value as Handling); settHarSokt(false); }} aria-label={t('arkivar.registrering.handling')}>
        {struktur.handlinger.map((h) => (
          <Radio key={h} value={h} label={<><Text>{t(`arkivar.handling.${h}`)}</Text><Caption1 className={c.valgtekst}>{t(`arkivar.handling.${h}.hjelp`)}</Caption1></>} />
        ))}
      </RadioGroup>
      {handling !== '' && !arkiveres && <div><Button appearance="primary" disabled={opptatt} onClick={registrer}>{t('arkivar.behandling.registrer_valget')}</Button></div>}
      {arkiveres && (
        <>
          <h3 className={c.gruppetittel}>{t('arkivar.behandling.mappe.tittel')}</h3>
          <p className={c.dempet}>{t('arkivar.behandling.mappe.ingress')}</p>
          <Mappesok key={handling} tips={t('arkivar.sok.tips.generelt')} handling={bruk} flyt={flyt} sokt={() => settHarSokt(true)} />
          <div className={c.knapper}>
            <Button icon={<Add20Regular />} disabled={!harSokt} onClick={() => naviger(`/arkiv/saker/ny${flytSok(flyt)}`)}>{t('arkivar.behandling.ny_mappe')}</Button>
            {!harSokt && <Caption1 className={c.valgtekst}>{t('arkivar.behandling.ny_mappe.sok_forst')}</Caption1>}
          </div>
        </>
      )}
    </div>
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
    if (k.id === 'skjerm_tittel' || k.id === 'skjerm_part' || k.id === 'skjerm_klasse' || k.id === 'skjerm_sakstittel') return t(`arkivar.felles.${v}`);
    if (k.id === 'dokumentdato') return dato(v);
    if (k.id === 'offentlig_tittel' || k.id === 'offentlig_sakstittel') return fasit ? t('arkivar.vurdering.uten_navn') : v;
    return v;
  };
  // Fasiten har én forklaring om skjerming per dokument. Et avvik på mappen uten egen forklaring får regelen som gjelder.
  const forklaring = (k: Kriterium): string => {
    if (k.forklaring || k.ok) return k.forklaring;
    if (k.id === 'skjerm_klasse') return t('arkivar.vurdering.hjelp.skjerm_klasse');
    return k.id === 'skjerm_sakstittel' ? t('arkivar.vurdering.hjelp.skjerm_sakstittel') : '';
  };
  const angre = () => { settFeil(null); api.slett(`/api/arkiv/postmottak/${del(id)}`).then(angret).catch((e: unknown) => settFeil(feiltekst(e))); };
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
          {k.id === 'sak' && !k.ok && k.riktig === 'ny' && b.sak && !b.sak.fulgte_med && <p className={c.forklaring}>{t('arkivar.vurdering.hjelp.samlemappe')}</p>}
          {forklaring(k) && <p className={c.forklaring} lang={k.forklaring ? 'nb' : undefined}>{forklaring(k)}</p>}
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
  const behandling = useHent<Behandling>(`/api/arkiv/postmottak/${del(id)}`);
  const struktur = useStruktur();
  if (behandling.feil && !behandling.data) return <Feil melding={behandling.feil} />;
  if (struktur.feil) return <Hentefeil melding={struktur.feil} last={struktur.last} />;
  if (!behandling.data || !struktur.data) return <Laster />;
  const b = behandling.data;
  return (
    <>
      <Sidehode tittel={b.dokument.emne} ingress={t(b.behandlet ? 'arkivar.dokument.ingress.behandlet' : 'arkivar.dokument.ingress')} />
      <div className={c.to}>
        <div className={c.fast}><Ark dok={b.dokument} /></div>
        <Kort tittel={t(b.behandlet ? 'arkivar.vurdering.tittel' : 'arkivar.behandling.tittel')} id="registrering">
          {b.behandlet ? <Vurdering id={id} b={b} angret={behandling.last} /> : <Behandlingsvalg id={id} struktur={struktur.data} ferdig={behandling.last} />}
        </Kort>
      </div>
    </>
  );
}
