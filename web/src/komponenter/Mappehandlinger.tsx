// Stegene en mappe går gjennom, som én hovedknapp for det neste lovlige steget og menyen «Mer» med resten: R
// (opprettet av saksbehandler) blir kontrollert (B), B sendes til avslutning (S), og S avsluttes fra sjekklisten (A)
// eller sendes tilbake til behandling (i menyen). Kontrollen kan ikke angres og bekreftes først. Etter hvert steg
// kommer en kort kvittering, med «Angre» der steget kan gjøres om.
import { ReactNode, useState } from 'react';
import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarBody, makeStyles, tokens } from '@fluentui/react-components';
import { ArrowUndo20Regular, Navigation20Regular } from '@fluentui/react-icons';
import { api } from '../api';
import { Sak, del } from '../arkiv';
import { useKode, useT } from '../i18n';
import { Bekreft, DeltKvittering, Kvittering, Kvitteringsdata, useFeiltekst } from './Felles';
import { useKontrolltall } from './Kontrolloppgaver';

const s = makeStyles({
  kvittering: { marginBottom: '12px' },
  dempet: { color: tokens.colorNeutralForeground2 },
});

type Steg = 'kontroller' | 'til-avslutning' | 'tilbake';

/** ID-en til knappen «Avslutt mappen» i sjekklisten til en mappe. Hovedknappen i hodet flytter fokus dit. */
export const avsluttknappId = (sakId: string): string => `avslutt-${sakId}`;

/** Gir hovedknappen for en mappe og flatene den åpner (bekreftelsen og kvitteringen). etter() henter siden på nytt. */
export function useMappehandlinger({ etter, delt }: { etter: () => void; delt?: DeltKvittering }) {
  const c = s(); const t = useT(); const kode = useKode(); const feiltekst = useFeiltekst();
  const kontroll = useKontrolltall();
  const [bekrefter, settBekrefter] = useState<Sak | null>(null);
  const egen = useState<Kvitteringsdata | null>(null);
  const kvittering = delt ? delt.kvittering : egen[0]; const settKvittering = delt ? delt.sett : egen[1];
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
  /**
   * Hovedknappen for mappen i statusen den har. En mappe til avslutning avsluttes fra sjekklisten, som sier hva som
   * mangler: hovedknappen i hodet går dit (til knappen «Avslutt mappen», som er sperret til listen er tom).
   */
  const hovedknapp = (m: Sak, storrelse: 'small' | 'medium' = 'medium'): ReactNode => {
    if (m.status === 'R') return <Button size={storrelse} appearance="primary" disabled={opptatt} onClick={() => { settFeil(null); settBekrefter(m); }}>{t('arkivar.kontroll.kontroller')}</Button>;
    if (m.status === 'B') return <Button size={storrelse} appearance="primary" disabled={opptatt} onClick={() => send(m, 'til-avslutning')}>{t('arkivar.mappe.til_avslutning')}</Button>;
    if (m.status === 'S') {
      const tilSjekklisten = () => { const knapp = document.getElementById(avsluttknappId(m.id)); knapp?.scrollIntoView({ block: 'center' }); knapp?.focus(); };
      return <Button size={storrelse} appearance="primary" disabled={opptatt} onClick={tilSjekklisten}>{t('arkivar.kontroll.avslutt')}</Button>;
    }
    return null;
  };
  /** Menyen «Mer» med det som er lov i statusen ved siden av hovedknappen. Et nytt valg legges i listen her. */
  const meny = (m: Sak, storrelse: 'small' | 'medium' = 'medium'): ReactNode => {
    const valg = m.status === 'S' ? [{ id: 'tilbake', tekst: t('arkivar.mappe.tilbake'), ikon: <ArrowUndo20Regular />, gjor: () => send(m, 'tilbake') }] : [];
    if (valg.length === 0) return null;
    return (
      <Menu>
        <MenuTrigger disableButtonEnhancement>
          <Button size={storrelse} icon={<Navigation20Regular />} disabled={opptatt} title={t('arkivar.mappemeny.navn', { saksnummer: m.saksnummer })}>{t('arkivar.jpmeny.mer')}</Button>
        </MenuTrigger>
        <MenuPopover><MenuList>{valg.map((v) => <MenuItem key={v.id} icon={v.ikon} onClick={v.gjor}>{v.tekst}</MenuItem>)}</MenuList></MenuPopover>
      </Menu>
    );
  };
  const flater = (
    <>
      {!delt && kvittering && <Kvittering kvittering={kvittering} opptatt={opptatt} />}
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
  return { hovedknapp, meny, flater, kvitter, opptatt };
}
