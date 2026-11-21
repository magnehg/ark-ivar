// Skriveflaten: studenten skriver et dokument i en mappe fra en dokumentmal (utgående brev, foreløpig svar, internt
// notat, møtereferat eller et inngående brev på blankt ark). Arket til venstre er dokumentet, skjemaet til høyre er
// registreringen. Dokumentet kan lagres flere ganger og som nye versjoner til det ferdigstilles.
//
// Med «?svar=<journalpost>» i adressen er dokumentet et svar på et inngående dokument i mappen: malene for utgående
// dokumenter står som valg, mottakeren og tittelen er fylt ut, og det inngående avskrives når svaret er ekspedert
// (ikke av et foreløpig svar).
import { FormEvent, useEffect, useState } from 'react';
import { Link as Ruterlenke, useNavigate, useParams, useSearchParams } from 'react-router-dom';
import { Button, Caption1, Checkbox, Field, Input, MessageBar, MessageBarBody, Radio, RadioGroup, Select, Text, Textarea, makeStyles, tokens } from '@fluentui/react-components';
import { api } from '../../api';
import { Dokument, Dokumentmal, Journalpost, Sak, Struktur, del, useStruktur } from '../../arkiv';
import { useKode, useSprak, useT } from '../../i18n';
import { Avskrivningsmate, Henvisningslenke, dokumentnummer } from '../../komponenter/Avskrivning';
import { Feil, Hentefeil, Hjelp, Kort, Laster, Sidehode, useFeiltekst, useHent, useOkt } from '../../komponenter/Felles';
import { Kommunevapen } from '../../komponenter/Kommunevapen';

const s = makeStyles({
  ramme: { containerType: 'inline-size' },
  to: { display: 'grid', gridTemplateColumns: 'minmax(0, 3fr) minmax(0, 2fr)', gap: '16px', alignItems: 'start', '@container (max-width: 900px)': { gridTemplateColumns: '1fr' } },
  // Arket: samme flate som dokumentene ellers vises på, med feltene der teksten skal stå.
  ark: {
    background: tokens.colorNeutralBackground1, border: `1px solid ${tokens.colorNeutralStroke2}`, borderRadius: tokens.borderRadiusMedium, boxShadow: tokens.shadow4,
    padding: '32px', minWidth: 0, display: 'flex', flexDirection: 'column', gap: '16px', '& .fui-Input, & .fui-Textarea': { width: '100%' },
  },
  hode: { display: 'flex', gap: '12px', alignItems: 'flex-start', color: tokens.colorNeutralForeground2 },
  kolonne: { display: 'flex', flexDirection: 'column', minWidth: 0, flexGrow: 1, gap: '8px' },
  tekstfelt: { '& textarea': { minHeight: '360px', lineHeight: tokens.lineHeightBase400 } },
  skjema: { display: 'flex', flexDirection: 'column', gap: '12px', minWidth: 0, '& .fui-Input, & .fui-Select': { width: '100%', minWidth: 0 }, '& .fui-Select__select': { minWidth: 0, width: '100%', textOverflow: 'ellipsis' } },
  rad: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '12px', minWidth: 0 },
  mappe: { background: tokens.colorNeutralBackground2, borderRadius: tokens.borderRadiusMedium, padding: '10px 12px' },
  dempet: { color: tokens.colorNeutralForeground2 },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap', alignItems: 'center' },
  svar: { marginBottom: '16px' },
  malvalg: { display: 'flex', flexDirection: 'column' },
  malhjelp: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200, fontWeight: tokens.fontWeightRegular },
  statuser: { margin: 0, display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '6px 10px', '& dt': { fontWeight: tokens.fontWeightSemibold }, '& dd': { margin: 0 } },
});

/** Forslaget til et svar på et inngående dokument: det som besvares, mappen, og tittelen og mottakeren til svaret. */
type Svarforslag = { journalpost: Journalpost; sak: Sak; forslag: { tittel: string; mottaker: string } };
/** Statusene et utgående dokument går gjennom, i rekkefølgen de kommer. Forklaringene står i språkfilene. */
const UTGAENDE_STATUSER = ['R', 'F', 'E', 'J', 'A'];

type Verdier = {
  mal: string; type: string; tittel: string; dokumentdato: string; part: string; tilgangskode: string; skjermTittel: boolean; offentlig: string; skjermPart: boolean;
  emne: string; mottaker: string; tekst: string; vedlegg: string; avsenderNavn: string; avsenderAdresse: string; avsenderEpost: string;
};
const TOMT: Verdier = {
  mal: '', type: '', tittel: '', dokumentdato: '', part: '', tilgangskode: '', skjermTittel: false, offentlig: '', skjermPart: false,
  emne: '', mottaker: '', tekst: '', vedlegg: '', avsenderNavn: '', avsenderAdresse: '', avsenderEpost: '',
};

/** Avsnittene i skriveflaten: en tom linje skiller avsnitt, og en linje som begynner med «## », er en mellomtittel. */
// En mellomtittel blir alltid sitt eget avsnitt, også når det ikke står en tom linje over og under den.
const tilAvsnitt = (tekst: string): string[] => tekst.split('\n').map((l) => (l.startsWith('## ') ? `\n${l.trim()}\n` : l)).join('\n')
  .split(/\n\s*\n/).map((a) => a.trim()).filter((a) => a !== '');
const fraAvsnitt = (avsnitt: string[]): string => avsnitt.join('\n\n');
/** Navnet i mottakerfeltet: den første linjen. Avsender eller mottaker i registreringen følger det til studenten endrer selv. */
const forsteLinje = (tekst: string): string => tekst.split('\n')[0].trim();
const linjer = (tekst: string): string[] => tekst.split('\n').map((l) => l.trim()).filter((l) => l !== '');

/** Malen med saksnummeret og den som har saken, satt inn der malen har plassholdere. */
function fraMal(mal: Dokumentmal, sak: Sak, struktur: Struktur): Partial<Verdier> {
  const stilling = struktur.ansatte.find((a) => a.initialer === sak.saksansvarlig)?.tittel ?? '';
  const fyll = (tekst: string) => tekst.split('{{sak}}').join(sak.saksnummer).split('{{saksbehandler}}').join(sak.saksansvarlig_navn).split('{{stilling}}').join(stilling);
  return { mal: mal.kode, type: mal.typer[0] ?? '', emne: fyll(mal.emne), tekst: fraAvsnitt(mal.tekst.map(fyll)) };
}

export default function Skriv() {
  const c = s(); const t = useT(); const kode = useKode(); const { sprak } = useSprak(); const feiltekst = useFeiltekst(); const naviger = useNavigate(); const { oppsett } = useOkt();
  // Nytt dokument i en mappe (id), eller et dokument som er under arbeid (sakId og journalpost).
  const { id = '', sakId = '', journalpost = '' } = useParams();
  const [sok] = useSearchParams();
  const mappeId = journalpost ? sakId : id;
  // Et nytt dokument kan være et svar på et inngående dokument i mappen.
  const svarId = journalpost ? '' : sok.get('svar') ?? '';
  const svar = useHent<Svarforslag>(svarId ? `/api/arkiv/journalposter/${del(svarId)}/svar` : null);
  const [svarFylt, settSvarFylt] = useState(false);
  const strukturdata = useStruktur();
  const lagret = useHent<{ journalpost: Journalpost; sak: Sak; dokument: Dokument | null }>(journalpost ? `/api/arkiv/journalposter/${del(journalpost)}` : null);
  // Et nytt dokument hører til mappen i adressen. Et dokument under arbeid hører til mappen journalposten ligger i.
  const nySak = useHent<{ sak: Sak }>(journalpost ? null : `/api/arkiv/saker/${del(mappeId)}`);
  const sak = journalpost ? { data: lagret.data ? { sak: lagret.data.sak } : null, feil: null } : nySak;
  // Forslaget malen sist fylte inn, og om studenten er varslet om at teksten er beholdt ved bytte av mal.
  const [forslag, settForslag] = useState({ emne: '', tekst: '' });
  const [beholdt, settBeholdt] = useState(false);
  const [v, sett] = useState<Verdier>(TOMT);
  // Tittelen på journalposten følger emnet til studenten skriver i den selv.
  const [egenTittel, settEgenTittel] = useState(false);
  const [feil, settFeil] = useState<string | null>(null); const [opptatt, settOpptatt] = useState(false);
  const endre = (endring: Partial<Verdier>) => sett((forrige) => ({ ...forrige, ...endring }));
  // Et dokument under arbeid fylles inn én gang, når det er hentet.
  const hentet = lagret.data;
  useEffect(() => {
    if (!hentet?.dokument) return;
    const p = hentet.journalpost; const d = hentet.dokument;
    sett({
      mal: p.mal ?? '', type: p.type, tittel: p.tittel, dokumentdato: p.dokumentdato, part: p.part_navn, tilgangskode: p.tilgangskode ?? '', skjermTittel: p.skjerm_tittel,
      offentlig: p.offentlig_tittel ?? '', skjermPart: p.skjerm_part, emne: d.emne, mottaker: d.mottaker ?? '', tekst: fraAvsnitt(d.tekst), vedlegg: d.vedlegg.join('\n'),
      avsenderNavn: d.avsender.navn, avsenderAdresse: d.avsender.adresse ?? '', avsenderEpost: d.avsender.epost ?? '',
    });
    settEgenTittel(true);
  }, [hentet]);
  // Et svar fylles inn én gang, når forslaget, mappen og malene er hentet: den første malen for utgående brev,
  // mottakeren fra avsenderen, og tittelen «Svar på …».
  const svarforslag = svar.data; const maler = strukturdata.data; const svarmappe = nySak.data?.sak;
  useEffect(() => {
    if (svarFylt || !svarforslag || !maler || !svarmappe) return;
    const forste = maler.dokumentmaler.find((m) => m.typer.includes('U'));
    if (!forste) return;
    const fylt = fraMal(forste, svarmappe, maler);
    const f = svarforslag.forslag;
    settForslag({ emne: f.tittel, tekst: fylt.tekst ?? '' });
    sett((forrige) => ({ ...forrige, mal: forste.kode, type: 'U', emne: f.tittel, tekst: fylt.tekst ?? '', tittel: f.tittel, mottaker: f.mottaker, part: f.mottaker, dokumentdato: new Date().toLocaleDateString('sv-SE') }));
    settEgenTittel(true);
    settSvarFylt(true);
  }, [svarFylt, svarforslag, maler, svarmappe]);
  if (sak.feil && !sak.data) return <Feil melding={sak.feil} />;
  if (lagret.feil && !lagret.data) return <Feil melding={lagret.feil} />;
  if (svar.feil && !svar.data) return <Feil melding={svar.feil} />;
  if (strukturdata.feil) return <Hentefeil melding={strukturdata.feil} last={strukturdata.last} />;
  const struktur = strukturdata.data;
  if (!struktur || !sak.data || (journalpost && !lagret.data) || (svarId && !svar.data)) return <Laster />;
  const mappe = sak.data.sak;
  const mal = struktur.dokumentmaler.find((m) => m.kode === v.mal);
  if (journalpost && lagret.data && !lagret.data.journalpost.redigerbar) return <Feil melding={t('arkivar.skriv.ikke_redigerbar')} />;
  const velgMal = (kode: string) => {
    const valgt = struktur.dokumentmaler.find((m) => m.kode === kode);
    if (!valgt) { endre({ mal: '' }); return; }
    const fylt = fraMal(valgt, mappe, struktur);
    // I et svar er emnet «Svar på …», også når malen byttes.
    if (svar.data) fylt.emne = svar.data.forslag.tittel;
    // Det studenten har skrevet selv, byttes aldri ut: forslaget fylles bare inn i et felt som er tomt eller fortsatt
    // har forslaget fra forrige mal.
    const urort = (verdi: string, forrige: string) => verdi.trim() === '' || verdi === forrige;
    const emne = urort(v.emne, forslag.emne) ? fylt.emne ?? '' : v.emne;
    const tekst = urort(v.tekst, forslag.tekst) ? fylt.tekst ?? '' : v.tekst;
    settBeholdt(emne !== fylt.emne || tekst !== fylt.tekst);
    settForslag({ emne: fylt.emne ?? '', tekst: fylt.tekst ?? '' });
    endre({ mal: valgt.kode, type: fylt.type, emne, tekst, tittel: egenTittel ? v.tittel : emne });
  };
  const blank = mal?.avsender === 'blank';
  const kropp = (nyVersjon: boolean) => ({
    mal: v.mal, type: v.type, tittel: v.tittel, offentlig_tittel: v.offentlig, dokumentdato: v.dokumentdato, part_navn: v.part, tilgangskode: v.tilgangskode,
    skjerm_tittel: v.skjermTittel, skjerm_part: v.skjermPart, emne: v.emne, mottaker: v.mottaker, tekst: tilAvsnitt(v.tekst), vedlegg: linjer(v.vedlegg),
    avsender: { navn: v.avsenderNavn, adresse: v.avsenderAdresse, epost: v.avsenderEpost }, ny_versjon: nyVersjon, svar_paa: svarId,
  });
  // Lagrer dokumentet (nytt, eller endringer i et som er under arbeid) og ferdigstiller det når det er bedt om.
  const lagre = (nyVersjon: boolean, ferdigstill: boolean) => {
    settOpptatt(true); settFeil(null);
    const lagring = journalpost
      ? api.put<{ journalpost: Journalpost }>(`/api/arkiv/journalposter/${del(journalpost)}/dokument`, kropp(nyVersjon))
      : api.post<{ journalpost: Journalpost }>(`/api/arkiv/saker/${del(mappeId)}/dokumenter`, kropp(false));
    lagring
      .then((d) => (ferdigstill ? api.post<{ journalpost: Journalpost }>(`/api/arkiv/journalposter/${del(d.journalpost.id)}/ferdigstill`) : d))
      .then((d) => naviger(`/arkiv/saker/${mappe.id}/journalposter/${d.journalpost.id}`))
      .catch((e: unknown) => { settFeil(feiltekst(e)); settOpptatt(false); });
  };
  const send = (e: FormEvent) => { e.preventDefault(); lagre(false, false); };
  // Statusen saksbehandleren setter når dokumentet er ferdig. Arkivtjenesten journalfører det etterpå.
  const ferdig = v.type === 'U' ? 'E' : v.type === 'I' ? 'M' : 'F';
  // Dokumentet som besvares: fra adressen (nytt svar), eller fra journalposten (et svar under arbeid).
  const besvares = svar.data ? { ...svar.data.journalpost, saksnummer: svar.data.sak.saksnummer } : null;
  const besvarer = lagret.data?.journalpost.svar_paa ?? [];
  const erSvar = besvares !== null || besvarer.length > 0;
  // I et svar står malene for utgående dokumenter som valg. Ellers velges malen i en liste.
  const svarmaler = struktur.dokumentmaler.filter((m) => m.typer.includes('U'));
  return (
    <>
      <Sidehode tittel={t(journalpost ? 'arkivar.skriv.tittel.endre' : erSvar ? 'arkivar.svar.tittel' : 'arkivar.skriv.tittel')} ingress={t('arkivar.skriv.ingress')} />
      {erSvar && (
        <MessageBar className={c.svar} intent="info" layout="multiline">
          <MessageBarBody>
            {t('arkivar.svar.paa')}{' '}
            {besvares
              ? <><Ruterlenke to={`/arkiv/saker/${mappe.id}/journalposter/${besvares.id}`}>{dokumentnummer(besvares.saksnummer, besvares.postnr)}</Ruterlenke> <span lang="nb">«{besvares.tittel}»</span>.</>
              : <>{besvarer.map((b, i) => <span key={b.id}>{i > 0 && ', '}<Henvisningslenke til={b} /></span>)}.</>}
            <br />
            {/* Et foreløpig svar avskriver ikke: restansen står til det endelige svaret er sendt. */}
            {mal && !mal.avskriver ? t('arkivar.svar.avskriver_ikke') : <>{t('arkivar.svar.avskriver.for')} <Avskrivningsmate kode="BU" /> {t('arkivar.svar.avskriver.etter')}</>}
          </MessageBarBody>
        </MessageBar>
      )}
      <form onSubmit={send} noValidate className={c.ramme}>
        <div className={c.to}>
          <div className={c.ark} lang="nb">
            {!mal ? <Text className={c.dempet} lang={sprak}>{t('arkivar.skriv.velg_mal')}</Text> : (
              <>
                {/* Brevhodet: kommunens eget, eller avsenderen studenten skriver på et blankt ark. */}
                {blank ? (
                  <div className={c.kolonne} lang={sprak}>
                    <Field label={t('arkivar.skriv.avsender.navn')} required><Input lang="nb" value={v.avsenderNavn} onChange={(_, d) => endre({ avsenderNavn: d.value, part: v.part === v.avsenderNavn ? d.value : v.part })} /></Field>
                    <Field label={t('arkivar.skriv.avsender.adresse')}><Input lang="nb" value={v.avsenderAdresse} onChange={(_, d) => endre({ avsenderAdresse: d.value })} /></Field>
                    <Field label={t('arkivar.skriv.avsender.epost')}><Input lang="nb" value={v.avsenderEpost} onChange={(_, d) => endre({ avsenderEpost: d.value })} /></Field>
                  </div>
                ) : (
                  <div className={c.hode}>
                    <Kommunevapen hoyde={48} />
                    <div className={c.kolonne}>
                      <Text weight="semibold">{oppsett.virksomhet}, {struktur.enheter.find((e) => e.kode === mappe.enhet)?.navn ?? mappe.enhet}</Text>
                    </div>
                  </div>
                )}
                <div lang={sprak} className={c.kolonne}>
                  {!blank && <Field label={t('arkivar.skriv.mottaker')} hint={t('arkivar.skriv.mottaker.hjelp')}><Textarea lang="nb" rows={3} value={v.mottaker} onChange={(_, d) => endre({ mottaker: d.value, part: v.part === forsteLinje(v.mottaker) ? forsteLinje(d.value) : v.part })} /></Field>}
                  <Field label={t('arkivar.skriv.emne')} required>
                    <Input lang="nb" value={v.emne} onChange={(_, d) => endre({ emne: d.value, tittel: egenTittel ? v.tittel : d.value })} />
                  </Field>
                  <Field label={t('arkivar.skriv.tekst')} required hint={t('arkivar.skriv.tekst.hjelp')} className={c.tekstfelt}>
                    <Textarea lang="nb" resize="vertical" value={v.tekst} onChange={(_, d) => endre({ tekst: d.value })} />
                  </Field>
                  <Field label={t('arkivar.skriv.vedlegg')} hint={t('arkivar.skriv.vedlegg.hjelp')}><Textarea lang="nb" rows={2} value={v.vedlegg} onChange={(_, d) => endre({ vedlegg: d.value })} /></Field>
                </div>
              </>
            )}
          </div>
          <Kort tittel={t('arkivar.registrering.tittel')} id="skriv-skjema">
            <div className={c.skjema}>
              <div className={c.mappe}><Text weight="semibold" lang="nb"><Ruterlenke to={`/arkiv/saker/${mappe.id}`}>{mappe.saksnummer} {mappe.tittel}</Ruterlenke></Text></div>
              {besvares ? (
                <Field label={t('arkivar.skriv.mal')} required>
                  <RadioGroup value={v.mal} onChange={(_, d) => velgMal(d.value)} className={c.malvalg}>
                    {svarmaler.map((m) => <Radio key={m.kode} value={m.kode} label={<span lang="nb">{m.navn}<span className={c.malhjelp}>{m.beskrivelse}</span></span>} />)}
                  </RadioGroup>
                </Field>
              ) : (
                <Field label={t('arkivar.skriv.mal')} required hint={mal ? <span lang="nb">{mal.beskrivelse}</span> : undefined}>
                  {/* Malen kan ikke byttes når dokumentet er opprettet: teksten er da studentens egen. */}
                  <Select value={v.mal} disabled={journalpost !== ''} onChange={(_, d) => velgMal(d.value)}>
                    <option value="">{t('arkivar.registrering.velg')}</option>
                    {struktur.dokumentmaler.map((m) => <option key={m.kode} value={m.kode} lang="nb">{m.navn}</option>)}
                  </Select>
                </Field>
              )}
              {mal && beholdt && <MessageBar intent="info" role="status"><MessageBarBody>{t('arkivar.skriv.mal.beholdt')}</MessageBarBody></MessageBar>}
              {mal && (
                <>
                  <Field label={t('arkivar.journalpost.tittel')} required hint={t('arkivar.nyjp.tittel.hjelp')}>
                    <Input value={v.tittel} onChange={(_, d) => { settEgenTittel(true); endre({ tittel: d.value }); }} />
                  </Field>
                  <div className={c.rad}>
                    <Field label={t('arkivar.journalpost.type')} required>
                      <Select value={v.type} disabled={mal.typer.length < 2} onChange={(_, d) => endre({ type: d.value })}>
                        {mal.typer.map((ty) => <option key={ty} value={ty}>{ty} – {t(`arkivar.jptype.${ty.toLowerCase()}`)}</option>)}
                      </Select>
                    </Field>
                    <Field label={t('arkivar.journalpost.dokumentdato')} required><Input type="date" value={v.dokumentdato} onChange={(_, d) => endre({ dokumentdato: d.value })} /></Field>
                  </div>
                  <Field label={t('arkivar.journalpost.part')} required={v.type === 'I' || v.type === 'U'}><Input value={v.part} onChange={(_, d) => endre({ part: d.value })} /></Field>
                  <Field label={t('arkivar.journalpost.tilgangskode')} hint={t('arkivar.journalpost.tilgangskode.hjelp')}>
                    <Select value={v.tilgangskode} onChange={(_, d) => endre({ tilgangskode: d.value })}>
                      <option value="">{t('arkivar.tilgang.ingen')}</option>
                      {struktur.tilgangskoder.map((k) => <option key={k.kode} value={k.kode} lang="nb">{k.hjemmel} ({k.navn})</option>)}
                    </Select>
                  </Field>
                  <Checkbox checked={v.skjermTittel} onChange={(_, d) => endre({ skjermTittel: d.checked === true })} label={t('arkivar.registrering.skjerm_tittel')} />
                  {v.skjermTittel && <Field label={t('arkivar.journalpost.offentlig_tittel')} hint={t('arkivar.registrering.offentlig.hjelp')}><Input value={v.offentlig} onChange={(_, d) => endre({ offentlig: d.value })} /></Field>}
                  <Checkbox checked={v.skjermPart} onChange={(_, d) => endre({ skjermPart: d.checked === true })} label={t('arkivar.registrering.skjerm_part')} />
                  <Caption1 className={c.dempet}>
                    {t('arkivar.skriv.status', { under: kode('jpstatus', v.type === 'I' ? 'M' : 'R'), ferdig: kode('jpstatus', ferdig) })}
                    {/* Statuskodene et utgående dokument går gjennom, bak et hjelpeikon. */}
                    {v.type === 'U' && (
                      <Hjelp navn={t('arkivar.statushjelp.utgaende.tittel')}>
                        <Text weight="semibold" block>{t('arkivar.statushjelp.utgaende.tittel')}</Text>
                        <dl className={c.statuser}>
                          {UTGAENDE_STATUSER.map((k) => <div key={k} style={{ display: 'contents' }}><dt>{k}</dt><dd>{t(`arkivar.statushjelp.utgaende.${k.toLowerCase()}`)}</dd></div>)}
                        </dl>
                      </Hjelp>
                    )}
                  </Caption1>
                  {feil && <Feil melding={feil} />}
                  <div className={c.knapper}>
                    <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.skriv.lagre')}</Button>
                    {journalpost && <Button disabled={opptatt} onClick={() => lagre(true, false)}>{t('arkivar.skriv.ny_versjon')}</Button>}
                    <Button disabled={opptatt} onClick={() => lagre(false, true)}>{t('arkivar.skriv.ferdigstill')}</Button>
                    <Ruterlenke to={journalpost ? `/arkiv/saker/${mappe.id}/journalposter/${journalpost}` : `/arkiv/saker/${mappe.id}`}>{t('arkivar.felles.avbryt')}</Ruterlenke>
                  </div>
                </>
              )}
            </div>
          </Kort>
        </div>
      </form>
    </>
  );
}
