// Min side: navnet, klassene (meld inn med klassekode) og bytte av passord.
import { useState } from 'react';
import { Button, Field, Input, makeStyles, tokens } from '@fluentui/react-components';
import { Bruker, Klasse, api } from '../api';
import { useT } from '../i18n';
import { Feil, Kort, Laster, Ok, Sidehode, useFeiltekst, useHent, useOkt } from '../komponenter/Felles';

const s = makeStyles({
  skjema: { display: 'flex', flexDirection: 'column', gap: '12px', maxWidth: '420px', alignItems: 'flex-start', '& .fui-Field': { width: '100%' } },
  liste: { margin: '0 0 12px', paddingLeft: '20px' },
  dempet: { color: tokens.colorNeutralForeground2, margin: '0 0 12px' },
  fakta: { display: 'grid', gridTemplateColumns: 'max-content 1fr', gap: '4px 16px', margin: 0, '& dt': { color: tokens.colorNeutralForeground2 }, '& dd': { margin: 0 } },
});

export default function MinSide() {
  const c = s(); const t = useT(); const feiltekst = useFeiltekst();
  const { oppsett } = useOkt();
  const meg = useHent<{ bruker: Bruker; klasser: Klasse[] }>('/api/meg');
  const [kode, settKode] = useState('');
  const [gammelt, settGammelt] = useState(''); const [nytt, settNytt] = useState('');
  const [klassefeil, settKlassefeil] = useState<string | null>(null); const [klasseOk, settKlasseOk] = useState<string | null>(null);
  const [passordfeil, settPassordfeil] = useState<string | null>(null); const [passordOk, settPassordOk] = useState(false);
  const [opptatt, settOpptatt] = useState(false);
  const [bekreft, settBekreft] = useState(false); const [nullstilt, settNullstilt] = useState(false); const [arkivfeil, settArkivfeil] = useState<string | null>(null);
  const nullstill = () => {
    settOpptatt(true); settArkivfeil(null);
    api.post('/api/arkiv/nullstill').then(() => { settNullstilt(true); settBekreft(false); }).catch((e: unknown) => settArkivfeil(feiltekst(e))).finally(() => settOpptatt(false));
  };

  const meldInn = () => {
    settOpptatt(true); settKlassefeil(null); settKlasseOk(null);
    api.post<{ klasse: Klasse }>('/api/meg/klasser', { kode })
      .then((d) => { settKlasseOk(t('arkivar.min_side.klasser.innmeldt', { navn: d.klasse.navn })); settKode(''); meg.last(); })
      .catch((e: unknown) => settKlassefeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  const byttPassord = () => {
    settOpptatt(true); settPassordfeil(null); settPassordOk(false);
    api.post('/api/meg/passord', { gammelt, nytt })
      .then(() => { settPassordOk(true); settGammelt(''); settNytt(''); })
      .catch((e: unknown) => settPassordfeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  if (meg.laster && !meg.data) return <Laster />;
  if (meg.feil || !meg.data) return <Feil melding={meg.feil ?? ''} />;
  const b = meg.data.bruker;
  return (
    <>
      <Sidehode tittel={t('arkivar.min_side.tittel')} />
      <Kort tittel={t('arkivar.min_side.konto.tittel')} id="min-konto">
        <dl className={c.fakta}>
          <dt>{t('arkivar.konto.navn')}</dt><dd>{b.navn}</dd>
          <dt>{t('arkivar.konto.epost')}</dt><dd>{b.epost}</dd>
          <dt>{t('arkivar.brukere.rolle')}</dt><dd>{t(`arkivar.rolle.${b.rolle}`)}</dd>
        </dl>
      </Kort>
      <Kort tittel={t('arkivar.min_side.klasser.tittel')} id="mine-klasser">
        {meg.data.klasser.length > 0
          ? <ul className={c.liste}>{meg.data.klasser.map((k) => <li key={k.id}>{k.laerer ? t('arkivar.hjem.klasser.rad', { navn: k.navn, laerer: k.laerer }) : k.navn}</li>)}</ul>
          : <p className={c.dempet}>{t('arkivar.hjem.klasser.ingen')}</p>}
        <form className={c.skjema} noValidate onSubmit={(e) => { e.preventDefault(); meldInn(); }}>
          {klassefeil && <Feil melding={klassefeil} />}
          {klasseOk && <Ok melding={klasseOk} />}
          <Field label={t('arkivar.konto.klassekode')} hint={t('arkivar.min_side.klasser.hjelp')}><Input value={kode} onChange={(_, d) => settKode(d.value)} autoComplete="off" /></Field>
          <Button type="submit" disabled={opptatt || kode.trim() === ''}>{t('arkivar.min_side.klasser.knapp')}</Button>
        </form>
      </Kort>
      <Kort tittel={t('arkivar.min_side.arkiv.tittel')} id="mitt-arkiv">
        <p className={c.dempet}>{t('arkivar.min_side.arkiv.tekst')}</p>
        {arkivfeil && <Feil melding={arkivfeil} />}
        {nullstilt && <Ok melding={t('arkivar.min_side.arkiv.nullstilt')} />}
        {bekreft ? (
          <div className={c.skjema}>
            <span>{t('arkivar.min_side.arkiv.bekreft')}</span>
            <div><Button appearance="primary" disabled={opptatt} onClick={nullstill}>{t('arkivar.min_side.arkiv.ja')}</Button> <Button onClick={() => settBekreft(false)}>{t('arkivar.min_side.arkiv.avbryt')}</Button></div>
          </div>
        ) : <Button onClick={() => { settBekreft(true); settNullstilt(false); }}>{t('arkivar.min_side.arkiv.knapp')}</Button>}
      </Kort>
      <Kort tittel={t('arkivar.min_side.passord.tittel')} id="mitt-passord">
        <form className={c.skjema} noValidate onSubmit={(e) => { e.preventDefault(); byttPassord(); }}>
          {passordfeil && <Feil melding={passordfeil} />}
          {passordOk && <Ok melding={t('arkivar.min_side.passord.byttet')} />}
          <Field label={t('arkivar.min_side.passord.gammelt')} required><Input type="password" value={gammelt} onChange={(_, d) => settGammelt(d.value)} autoComplete="current-password" /></Field>
          <Field label={t('arkivar.konto.nytt_passord')} required hint={t('arkivar.konto.passordhjelp', { min: oppsett.passord.min })}><Input type="password" value={nytt} onChange={(_, d) => settNytt(d.value)} autoComplete="new-password" /></Field>
          <Button type="submit" disabled={opptatt}>{t('arkivar.min_side.passord.knapp')}</Button>
        </form>
      </Kort>
    </>
  );
}
