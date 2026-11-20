// Forsiden etter innlogging. Arkivet til Sirevåg kommune kommer her; foreløpig forteller siden hva som er klart.
import { Link as Ruterlenke } from 'react-router-dom';
import { Body1, MessageBar, MessageBarBody, makeStyles, tokens } from '@fluentui/react-components';
import { erDrift, harRolle } from '../api';
import { useT } from '../i18n';
import { Kort, Sidehode, useHent, useOkt } from '../komponenter/Felles';
import type { Klasse } from '../api';
import type { Oversikt } from '../arkiv';

const s = makeStyles({
  tekst: { display: 'block', maxWidth: '72ch', marginBottom: '8px' },
  liste: { margin: 0, paddingLeft: '20px', color: tokens.colorNeutralForeground2 },
  utdatert: { marginBottom: '12px' },
});

export default function Hjem() {
  const c = s(); const t = useT();
  const { innlogget, oppsett } = useOkt();
  const student = harRolle(innlogget, 'student');
  const meg = useHent<{ klasser: Klasse[] }>(student ? '/api/meg' : null);
  const arkiv = useHent<{ oversikt: Oversikt }>(student ? '/api/arkiv' : null);
  const o = arkiv.data?.oversikt;
  return (
    <>
      <Sidehode tittel={t('arkivar.hjem.tittel', { navn: innlogget?.navn ?? '' })} ingress={t('arkivar.hjem.ingress', { appnavn: oppsett.appnavn, virksomhet: oppsett.virksomhet })} />
      {erDrift(innlogget) && !student && <Kort tittel={t('arkivar.hjem.drift.tittel')} id="hjem-drift"><Body1 className={c.tekst}>{t('arkivar.hjem.drift.tekst')}</Body1></Kort>}
      {student && (
        <Kort tittel={t('arkivar.hjem.arkivet.tittel', { virksomhet: oppsett.virksomhet })} id="hjem-arkivet">
          <Body1 className={c.tekst}>{t('arkivar.hjem.arkivet.tekst')}</Body1>
          {o?.utdatert && (
            <MessageBar className={c.utdatert} intent="warning" id="hjem-utdatert">
              <MessageBarBody>{t('arkivar.hjem.arkivet.utdatert')} <Ruterlenke to="/min-side">{t('arkivar.hjem.arkivet.utdatert.lenke')}</Ruterlenke></MessageBarBody>
            </MessageBar>
          )}
          {o && (
            <ul className={c.liste}>
              <li><Ruterlenke to="/arkiv/postmottak">{t('arkivar.hjem.arkivet.postmottak', { venter: o.dokumenter - o.behandlet, alle: o.dokumenter })}</Ruterlenke></li>
              {o.maks > 0 && <li>{t('arkivar.hjem.arkivet.poeng', { poeng: o.poeng, maks: o.maks })}</li>}
              <li><Ruterlenke to="/arkiv/saker">{t('arkivar.hjem.arkivet.saker', { saker: o.saker, journalposter: o.journalposter })}</Ruterlenke></li>
            </ul>
          )}
        </Kort>
      )}
      {student && (
        <Kort tittel={t('arkivar.hjem.klasser.tittel')} id="hjem-klasser">
          {meg.data && meg.data.klasser.length > 0
            ? <ul className={c.liste}>{meg.data.klasser.map((k) => <li key={k.id}>{k.laerer ? t('arkivar.hjem.klasser.rad', { navn: k.navn, laerer: k.laerer }) : k.navn}</li>)}</ul>
            : <Body1 className={c.tekst}>{t('arkivar.hjem.klasser.ingen')}</Body1>}
        </Kort>
      )}
    </>
  );
}
