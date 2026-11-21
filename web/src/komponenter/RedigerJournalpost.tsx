// Redigering av registreringen på en journalpost: tittelen, skjermingen og tilgangskoden, avsenderen eller mottakeren
// og dokumentdatoen. Statusen avgjør hva som kan rettes: når journalposten er journalført, står dokumentdatoen og
// avsenderen eller mottakeren fast. Skjemaet er laget for å brukes flere steder (i en dialog fra menyen på
// journalposten, og som hurtigredigering der en liste trenger det).
import { FormEvent, useState } from 'react';
import { Button, Checkbox, Field, Input, Select, makeStyles } from '@fluentui/react-components';
import { api } from '../api';
import { Journalpost, del, useStruktur } from '../arkiv';
import { useT } from '../i18n';
import { Feil, Hentefeil, Laster, useFeiltekst } from './Felles';

const s = makeStyles({
  skjema: { display: 'flex', flexDirection: 'column', gap: '12px', minWidth: 0, '& .fui-Input, & .fui-Select': { width: '100%', minWidth: 0 }, '& .fui-Select__select': { minWidth: 0, width: '100%', textOverflow: 'ellipsis' } },
  rad: { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(160px, 1fr))', gap: '12px', minWidth: 0 },
  knapper: { display: 'flex', gap: '8px', flexWrap: 'wrap' },
});

type Egenskaper = {
  post: Journalpost;
  /** Kalles når registreringen er lagret, med journalposten slik den er nå. */
  etter: (post: Journalpost) => void;
  avbryt: () => void;
};

export function RedigerJournalpost({ post, etter, avbryt }: Egenskaper) {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const struktur = useStruktur();
  const [v, sett] = useState({
    tittel: post.tittel, dokumentdato: post.dokumentdato, part: post.part_navn, tilgangskode: post.tilgangskode ?? '',
    skjermTittel: post.skjerm_tittel, offentlig: post.offentlig_tittel ?? '', skjermPart: post.skjerm_part,
  });
  const [feil, settFeil] = useState<string | null>(null);
  const [opptatt, settOpptatt] = useState(false);
  const endre = (endring: Partial<typeof v>) => sett((forrige) => ({ ...forrige, ...endring }));
  if (struktur.feil) return <Hentefeil melding={struktur.feil} last={struktur.last} />;
  if (!struktur.data) return <Laster />;
  // Etter journalføringen kan bare tittelen og skjermingen rettes.
  const alle = post.rediger_felt === 'alle';
  const send = (e: FormEvent) => {
    e.preventDefault(); settFeil(null); settOpptatt(true);
    api.put<{ journalpost: Journalpost }>(`/api/arkiv/journalposter/${del(post.id)}`, {
      tittel: v.tittel, offentlig_tittel: v.offentlig, dokumentdato: v.dokumentdato, part_navn: v.part, tilgangskode: v.tilgangskode, skjerm_tittel: v.skjermTittel, skjerm_part: v.skjermPart,
    }).then((d) => etter(d.journalpost)).catch((e2: unknown) => { settFeil(feiltekst(e2)); settOpptatt(false); });
  };
  const laast = alle ? undefined : t('arkivar.rediger.laast');
  return (
    <form onSubmit={send} noValidate className={c.skjema}>
      <Field label={t('arkivar.journalpost.tittel')} required hint={t('arkivar.nyjp.tittel.hjelp')}>
        <Input lang="nb" value={v.tittel} onChange={(_, d) => endre({ tittel: d.value })} />
      </Field>
      <div className={c.rad}>
        <Field label={t(post.type === 'U' ? 'arkivar.rediger.mottaker' : 'arkivar.rediger.avsender')} required={post.type === 'I' || post.type === 'U'} hint={laast}>
          <Input lang="nb" value={v.part} disabled={!alle} onChange={(_, d) => endre({ part: d.value })} />
        </Field>
        <Field label={t('arkivar.journalpost.dokumentdato')} required hint={laast}>
          <Input type="date" value={v.dokumentdato} disabled={!alle} onChange={(_, d) => endre({ dokumentdato: d.value })} />
        </Field>
      </div>
      <Field label={t('arkivar.journalpost.tilgangskode')} hint={t('arkivar.journalpost.tilgangskode.hjelp')}>
        <Select value={v.tilgangskode} onChange={(_, d) => endre({ tilgangskode: d.value })}>
          <option value="">{t('arkivar.tilgang.ingen')}</option>
          {struktur.data.tilgangskoder.map((k) => <option key={k.kode} value={k.kode} lang="nb">{k.hjemmel} ({k.navn})</option>)}
        </Select>
      </Field>
      <Checkbox checked={v.skjermTittel} onChange={(_, d) => endre({ skjermTittel: d.checked === true })} label={t('arkivar.registrering.skjerm_tittel')} />
      {v.skjermTittel && (
        <Field label={t('arkivar.journalpost.offentlig_tittel')} hint={t('arkivar.registrering.offentlig.hjelp')}>
          <Input lang="nb" value={v.offentlig} onChange={(_, d) => endre({ offentlig: d.value })} />
        </Field>
      )}
      <Checkbox checked={v.skjermPart} onChange={(_, d) => endre({ skjermPart: d.checked === true })} label={t(post.type === 'U' ? 'arkivar.registrering.skjerm_mottaker' : 'arkivar.registrering.skjerm_part')} />
      {feil && <Feil melding={feil} />}
      <div className={c.knapper}>
        <Button appearance="primary" type="submit" disabled={opptatt}>{t('arkivar.skriv.lagre')}</Button>
        <Button disabled={opptatt} onClick={avbryt}>{t('arkivar.felles.avbryt')}</Button>
      </div>
    </form>
  );
}
