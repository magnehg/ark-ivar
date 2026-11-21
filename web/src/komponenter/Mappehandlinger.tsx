// Stegene en mappe går gjennom, som én hovedknapp for det neste lovlige steget: R (opprettet av saksbehandler) blir
// kontrollert (B), B sendes til avslutning (S), og S avsluttes fra sjekklisten (A) eller sendes tilbake til
// behandling. Kontrollen kan ikke angres og bekreftes først. Etter hvert steg kommer en kort kvittering, med «Angre»
// der steget kan gjøres om.
import { ReactNode, useState } from 'react';
import { Button, MessageBar, MessageBarActions, MessageBarBody, makeStyles, tokens } from '@fluentui/react-components';
import { ArrowUndo20Regular } from '@fluentui/react-icons';
import { api } from '../api';
import { Sak, del } from '../arkiv';
import { useKode, useT } from '../i18n';
import { Bekreft, useFeiltekst } from './Felles';
import { useKontrolltall } from './Kontrolloppgaver';

const s = makeStyles({
  kvittering: { marginBottom: '12px' },
  dempet: { color: tokens.colorNeutralForeground2 },
});

type Steg = 'kontroller' | 'til-avslutning' | 'tilbake';
type Kvittering = { tekst: string; angre?: { (): void } };

/** Gir hovedknappen for en mappe og flatene den åpner (bekreftelsen og kvitteringen). etter() henter siden på nytt. */
export function useMappehandlinger({ etter }: { etter: () => void }) {
  const c = s(); const t = useT(); const kode = useKode(); const feiltekst = useFeiltekst();
  const kontroll = useKontrolltall();
  const [bekrefter, settBekrefter] = useState<Sak | null>(null);
  const [kvittering, settKvittering] = useState<Kvittering | null>(null);
  const [feil, settFeil] = useState<string | null>(null);
  const [opptatt, settOpptatt] = useState(false);
  const send = (m: Sak, steg: Steg) => {
    settFeil(null); settKvittering(null); settOpptatt(true);
    api.post(`/api/arkiv/saker/${del(m.id)}/${steg}`).then(() => {
      settBekrefter(null);
      // Sendingen til avslutning og tilbake kan gjøres om. Kontrollen kan ikke.
      settKvittering(steg === 'kontroller' ? { tekst: t('arkivar.kvittering.mappe.kontrollert', { saksnummer: m.saksnummer, status: kode('saksstatus', 'B') }) }
        : steg === 'til-avslutning' ? { tekst: t('arkivar.kvittering.mappe.til_avslutning', { saksnummer: m.saksnummer, status: kode('saksstatus', 'S') }), angre: () => send(m, 'tilbake') }
        : { tekst: t('arkivar.kvittering.mappe.tilbake', { saksnummer: m.saksnummer, status: kode('saksstatus', 'B') }), angre: () => send(m, 'til-avslutning') });
      etter(); kontroll.oppdater();
    }).catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  /** Hovedknappen for mappen i statusen den har. En mappe til avslutning avsluttes fra sjekklisten, og har «Send tilbake» her. */
  const hovedknapp = (m: Sak, storrelse: 'small' | 'medium' = 'medium'): ReactNode => {
    if (m.status === 'R') return <Button size={storrelse} appearance="primary" disabled={opptatt} onClick={() => { settFeil(null); settBekrefter(m); }}>{t('arkivar.kontroll.kontroller')}</Button>;
    if (m.status === 'B') return <Button size={storrelse} appearance="primary" disabled={opptatt} onClick={() => send(m, 'til-avslutning')}>{t('arkivar.mappe.til_avslutning')}</Button>;
    if (m.status === 'S') return <Button size={storrelse} disabled={opptatt} onClick={() => send(m, 'tilbake')}>{t('arkivar.mappe.tilbake')}</Button>;
    return null;
  };
  const flater = (
    <>
      {kvittering && (
        <MessageBar className={c.kvittering} intent="success" role="status">
          <MessageBarBody>{kvittering.tekst}</MessageBarBody>
          {kvittering.angre && <MessageBarActions><Button size="small" icon={<ArrowUndo20Regular />} disabled={opptatt} onClick={kvittering.angre}>{t('arkivar.kvittering.angre')}</Button></MessageBarActions>}
        </MessageBar>
      )}
      {feil && !bekrefter && <MessageBar className={c.kvittering} intent="error" role="alert"><MessageBarBody>{feil}</MessageBarBody></MessageBar>}
      {bekrefter && (
        <Bekreft tittel={t('arkivar.bekreft.kontroller.tittel', { saksnummer: bekrefter.saksnummer })} bekreftTekst={t('arkivar.kontroll.kontroller')} avbryt={() => settBekrefter(null)} opptatt={opptatt} feil={feil}
          bekreft={() => send(bekrefter, 'kontroller')}>
          <p>{t('arkivar.bekreft.kontroller.tekst', { fra: kode('saksstatus', 'R'), til: kode('saksstatus', 'B') })}</p>
          <p className={c.dempet}>{t('arkivar.bekreft.kan_ikke_angres')}</p>
        </Bekreft>
      )}
    </>
  );
  /** Viser en kvittering fra siden rundt (mappen er avsluttet fra sjekklisten). */
  const kvitter = (tekst: string) => settKvittering({ tekst });
  return { hovedknapp, flater, kvitter, opptatt };
}
