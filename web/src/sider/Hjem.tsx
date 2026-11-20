// Forsiden etter innlogging. Arkivet til Sirevåg kommune kommer her; foreløpig forteller siden hva som er klart.
import { Body1, makeStyles, tokens } from '@fluentui/react-components';
import { erDrift, harRolle } from '../api';
import { useT } from '../i18n';
import { Kort, Sidehode, useHent, useOkt } from '../komponenter/Felles';
import type { Klasse } from '../api';

const s = makeStyles({
  tekst: { display: 'block', maxWidth: '72ch', marginBottom: '8px' },
  liste: { margin: 0, paddingLeft: '20px', color: tokens.colorNeutralForeground2 },
});

export default function Hjem() {
  const c = s(); const t = useT();
  const { innlogget, oppsett } = useOkt();
  const student = harRolle(innlogget, 'student');
  const meg = useHent<{ klasser: Klasse[] }>(student ? '/api/meg' : null);
  return (
    <>
      <Sidehode tittel={t('arkivar.hjem.tittel', { navn: innlogget?.navn ?? '' })} ingress={t('arkivar.hjem.ingress', { appnavn: oppsett.appnavn, virksomhet: oppsett.virksomhet })} />
      {erDrift(innlogget) && !student && <Kort tittel={t('arkivar.hjem.drift.tittel')} id="hjem-drift"><Body1 className={c.tekst}>{t('arkivar.hjem.drift.tekst')}</Body1></Kort>}
      <Kort tittel={t('arkivar.hjem.arkivet.tittel', { virksomhet: oppsett.virksomhet })} id="hjem-arkivet">
        <Body1 className={c.tekst}>{t('arkivar.hjem.arkivet.tekst')}</Body1>
      </Kort>
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
