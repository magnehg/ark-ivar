// Hjelpesiden: korte kort om programmet: hva Ark-Ivar er ment for, og hvor navnet kommer fra.
import { Body1, makeStyles } from '@fluentui/react-components';
import { useT } from '../i18n';
import { Kort, Sidehode } from '../komponenter/Felles';

const s = makeStyles({
  tekst: { display: 'block', maxWidth: '72ch', marginBottom: '8px' },
});

export default function Hjelp() {
  const c = s(); const t = useT();
  return (
    <>
      <Sidehode tittel={t('arkivar.hjelp.tittel')} ingress={t('arkivar.hjelp.ingress')} />
      <Kort tittel={t('arkivar.hjelp.om.tittel')} id="hjelp-om">
        <Body1 className={c.tekst}>{t('arkivar.hjelp.om.formaal')}</Body1>
        <Body1 className={c.tekst}>{t('arkivar.hjelp.om.realisme')}</Body1>
      </Kort>
      <Kort tittel={t('arkivar.hjelp.navn.tittel')} id="hjelp-navn">
        <Body1 className={c.tekst}>{t('arkivar.hjelp.navn.tekst')}</Body1>
      </Kort>
    </>
  );
}
