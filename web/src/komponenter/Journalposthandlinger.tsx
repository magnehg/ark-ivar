// Handlingene på en journalpost: hovedknappen for det neste steget i saksgangen, og menyen med resten (Rediger,
// Svar, Avskriv, Flytt …). Serveren sier hvilke handlinger som er lov i statusen journalposten har
// (journalpost.handlinger og journalpost.neste). Det som ikke kan angres, bekreftes først, og etterpå kommer en kort
// kvittering med «Angre» der det går an. Brukes i mappen, på siden for journalposten og i kontrolloppgavene.
import { ReactNode, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Button, Menu, MenuItem, MenuList, MenuPopover, MenuTrigger, MessageBar, MessageBarActions, MessageBarBody, Text, makeStyles, tokens } from '@fluentui/react-components';
import {
  ArrowForward20Regular, ArrowReply20Regular, ArrowUndo20Regular, Checkmark20Regular, CheckmarkCircle20Regular, Delete20Regular, DocumentEdit20Regular, Edit20Regular,
  FolderArrowRight20Regular, Navigation20Regular,
} from '@fluentui/react-icons';
import { api } from '../api';
import { Journalpost, Jphandling, Sak, del } from '../arkiv';
import { useDato, useKode, useT } from '../i18n';
import { Avskrivfelt, dokumentnummer } from './Avskrivning';
import { Bekreft, Dialogvindu, useFeiltekst } from './Felles';
import { useKontrolltall } from './Kontrolloppgaver';
import { Mappesok } from './Mappesok';
import { RedigerJournalpost } from './RedigerJournalpost';

const s = makeStyles({
  kvittering: { marginBottom: '12px' },
  dempet: { color: tokens.colorNeutralForeground2 },
  undertittel: { color: tokens.colorNeutralForeground2, fontVariantNumeric: 'tabular-nums' },
  rad: { display: 'inline-flex', gap: '4px', alignItems: 'center', flexWrap: 'wrap' },
});

/** Menyvalgene i den rekkefølgen de står. Et nytt valg legges til her (og i utfor() under). */
const VALG: { id: Jphandling; tekst: string; ikon: JSX.Element }[] = [
  { id: 'ferdigstill', tekst: 'arkivar.skriv.ferdigstill', ikon: <Checkmark20Regular /> },
  { id: 'journalfor', tekst: 'arkivar.kontroll.journalfor', ikon: <CheckmarkCircle20Regular /> },
  { id: 'rediger_dokument', tekst: 'arkivar.journalpost.rediger', ikon: <DocumentEdit20Regular /> },
  { id: 'rediger', tekst: 'arkivar.jpmeny.rediger', ikon: <Edit20Regular /> },
  { id: 'svar', tekst: 'arkivar.jpmeny.svar', ikon: <ArrowReply20Regular /> },
  { id: 'avskriv', tekst: 'arkivar.jpmeny.avskriv', ikon: <ArrowForward20Regular /> },
  { id: 'angre_avskrivning', tekst: 'arkivar.jpmeny.angre_avskrivning', ikon: <ArrowUndo20Regular /> },
  { id: 'angre_journalforing', tekst: 'arkivar.jpmeny.angre_journalforing', ikon: <ArrowUndo20Regular /> },
  { id: 'flytt', tekst: 'arkivar.jpmeny.flytt', ikon: <FolderArrowRight20Regular /> },
  { id: 'slett', tekst: 'arkivar.journalpost.slett', ikon: <Delete20Regular /> },
];

type Dialog = { slag: 'rediger' | 'avskriv' | 'flytt' | 'journalfor' | 'ferdigstill' | 'slett'; post: Journalpost };
/** Kvitteringen etter en handling: teksten, og det som angrer handlingen når det går an. */
type Kvittering = { tekst: string; angre?: { (): void } };

type Valg = {
  /** Kalles når noe er endret, så siden henter listen eller journalposten på nytt. */
  etter: () => void;
  /** Mappen journalpostene ligger i, når siden viser én mappe (radene har da ikke saksnummeret selv). */
  sak?: Sak;
  /** Kalles i stedet for etter() når journalposten er slettet eller flyttet (siden for én journalpost går da videre). */
  etterSlett?: () => void;
  etterFlytt?: (post: Journalpost) => void;
};

/**
 * Gir hovedknappen og menyen for en journalpost, og flatene de åpner (dialogene og kvitteringen). «flater» tegnes ett
 * sted på siden, over listen.
 */
export function useJournalposthandlinger({ etter, sak, etterSlett, etterFlytt }: Valg) {
  const c = s(); const t = useT(); const kode = useKode(); const dato = useDato(); const feiltekst = useFeiltekst(); const naviger = useNavigate();
  const kontroll = useKontrolltall();
  const [dialog, settDialog] = useState<Dialog | null>(null);
  const [kvittering, settKvittering] = useState<Kvittering | null>(null);
  const [feil, settFeil] = useState<string | null>(null);
  const [opptatt, settOpptatt] = useState(false);
  const nummer = (p: Journalpost) => dokumentnummer(sak?.saksnummer ?? p.saksnummer, p.postnr);
  const sakId = (p: Journalpost) => sak?.id ?? p.sak_id;
  const lukk = () => { settDialog(null); settFeil(null); };
  const ferdig = (ny: Kvittering | null) => { settDialog(null); settFeil(null); settKvittering(ny); etter(); kontroll.oppdater(); };
  /** Sender en handling uten skjema. Feilen står i dialogen når en er åpen, ellers der kvitteringen står. */
  const send = (metode: 'post' | 'slett', sti: string, kvitt: Kvittering | null, da: () => void = () => ferdig(kvitt)) => {
    settFeil(null); settOpptatt(true);
    (metode === 'post' ? api.post(sti) : api.slett(sti)).then(da).catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  };
  const angreJournalforing = (p: Journalpost) => send('post', `/api/arkiv/journalposter/${del(p.id)}/angre-journalforing`, { tekst: t('arkivar.kvittering.journalforing_angret', { nummer: nummer(p) }) });
  const angreAvskrivning = (p: Journalpost) => send('slett', `/api/arkiv/journalposter/${del(p.id)}/avskrivning`, { tekst: t('arkivar.kvittering.avskrivning_angret', { nummer: nummer(p) }) });
  const utfor = (id: Jphandling, p: Journalpost) => {
    settKvittering(null); settFeil(null);
    switch (id) {
      case 'rediger_dokument': naviger(`/arkiv/saker/${sakId(p)}/journalposter/${p.id}/rediger`); break;
      case 'svar': naviger(`/arkiv/saker/${sakId(p)}/nytt-dokument?svar=${encodeURIComponent(p.id)}`); break;
      case 'angre_avskrivning': angreAvskrivning(p); break;
      case 'angre_journalforing': angreJournalforing(p); break;
      default: settDialog({ slag: id, post: p });
    }
  };

  /** Hovedknappen: det neste lovlige steget i statusen journalposten har. Ingen knapp når det ikke er noe neste steg. */
  const hovedknapp = (p: Journalpost, storrelse: 'small' | 'medium' = 'medium'): ReactNode => {
    const valg = VALG.find((v) => v.id === p.neste);
    return valg ? <Button size={storrelse} appearance="primary" disabled={opptatt} onClick={() => utfor(valg.id, p)}>{t(valg.tekst)}</Button> : null;
  };
  /**
   * Menyen med handlingene. I en liste er den et ikon (hamburgermeny) med alle handlingene; ved siden av en
   * hovedknapp er den «Mer» med resten.
   */
  const meny = (p: Journalpost, utenNeste = false, storrelse: 'small' | 'medium' = 'medium'): ReactNode => {
    const valg = VALG.filter((v) => p.handlinger.includes(v.id) && !(utenNeste && v.id === p.neste));
    if (valg.length === 0) return null;
    const navn = t('arkivar.jpmeny.navn', { nummer: nummer(p) });
    return (
      <Menu>
        <MenuTrigger disableButtonEnhancement>
          {utenNeste
            ? <Button size={storrelse} icon={<Navigation20Regular />} disabled={opptatt} title={navn}>{t('arkivar.jpmeny.mer')}</Button>
            : <Button appearance="subtle" size="small" icon={<Navigation20Regular />} disabled={opptatt} aria-label={navn} title={navn} />}
        </MenuTrigger>
        <MenuPopover>
          <MenuList>
            {valg.map((v) => <MenuItem key={v.id} icon={v.ikon} onClick={() => utfor(v.id, p)}>{t(v.tekst)}</MenuItem>)}
          </MenuList>
        </MenuPopover>
      </Menu>
    );
  };
  /** Hovedknappen og menyen sammen, slik de står i en rad i kontrolloppgavene. */
  const begge = (p: Journalpost): ReactNode => <span className={c.rad}>{hovedknapp(p, 'small')}{meny(p, p.neste !== null, 'small')}</span>;

  const p = dialog?.post;
  // Statusen saksbehandleren setter når dokumentet ferdigstilles.
  const ferdigstatus = p ? (p.type === 'U' ? 'E' : p.type === 'I' ? 'M' : 'F') : '';
  const flater = (
    <>
      {kvittering && (
        <MessageBar className={c.kvittering} intent="success" role="status">
          <MessageBarBody>{kvittering.tekst}</MessageBarBody>
          {kvittering.angre && <MessageBarActions><Button size="small" icon={<ArrowUndo20Regular />} disabled={opptatt} onClick={kvittering.angre}>{t('arkivar.kvittering.angre')}</Button></MessageBarActions>}
        </MessageBar>
      )}
      {feil && !dialog && <MessageBar className={c.kvittering} intent="error" role="alert"><MessageBarBody>{feil}</MessageBarBody></MessageBar>}
      {p && dialog.slag === 'journalfor' && (
        <Bekreft tittel={t('arkivar.bekreft.journalfor.tittel', { nummer: nummer(p) })} bekreftTekst={t('arkivar.kontroll.journalfor')} avbryt={lukk} opptatt={opptatt} feil={feil}
          bekreft={() => send('post', `/api/arkiv/journalposter/${del(p.id)}/journalfor`, { tekst: t('arkivar.kvittering.journalfort', { nummer: nummer(p) }), angre: () => angreJournalforing(p) })}>
          <p>{t('arkivar.bekreft.journalfor.status', { fra: kode('jpstatus', p.status), til: kode('jpstatus', 'J'), dato: dato(new Date().toLocaleDateString('sv-SE')), lopenummer: p.lopenummer })}</p>
          <p>{t('arkivar.bekreft.journalfor.journal')}</p>
          <p className={c.dempet}>{t('arkivar.bekreft.journalfor.angre')}</p>
        </Bekreft>
      )}
      {p && dialog.slag === 'ferdigstill' && (
        <Bekreft tittel={t('arkivar.bekreft.ferdigstill.tittel', { nummer: nummer(p) })} bekreftTekst={t('arkivar.skriv.ferdigstill')} avbryt={lukk} opptatt={opptatt} feil={feil}
          bekreft={() => send('post', `/api/arkiv/journalposter/${del(p.id)}/ferdigstill`, { tekst: t('arkivar.skriv.ferdigstilt') })}>
          <p>{t('arkivar.bekreft.ferdigstill.tekst', { status: kode('jpstatus', ferdigstatus) })}</p>
          {p.svar_paa.length > 0 && p.type === 'U' && <p>{t('arkivar.bekreft.ferdigstill.svar')}</p>}
          <p className={c.dempet}>{t('arkivar.bekreft.kan_ikke_angres')}</p>
        </Bekreft>
      )}
      {p && dialog.slag === 'slett' && (
        <Bekreft tittel={t('arkivar.bekreft.slett.tittel', { nummer: nummer(p) })} bekreftTekst={t('arkivar.journalpost.slett')} avbryt={lukk} opptatt={opptatt} feil={feil}
          bekreft={() => send('slett', `/api/arkiv/journalposter/${del(p.id)}`, null, () => {
            if (etterSlett) { settDialog(null); kontroll.oppdater(); etterSlett(); } else ferdig({ tekst: t('arkivar.kvittering.slettet', { nummer: nummer(p) }) });
          })}>
          <p>{t('arkivar.bekreft.slett.tekst')}</p>
          <p className={c.dempet}>{t('arkivar.bekreft.kan_ikke_angres')}</p>
        </Bekreft>
      )}
      {p && dialog.slag === 'avskriv' && (
        <Dialogvindu tittel={t('arkivar.avskriv.tittel')} lukk={lukk}>
          <Text className={c.undertittel}>{nummer(p)} · {p.lopenummer}</Text>
          <Text lang="nb" weight="semibold">{p.tittel}</Text>
          <Avskrivfelt post={p} avbryt={lukk} etter={(ny) => ferdig({
            tekst: t('arkivar.kvittering.avskrevet', { nummer: nummer(p), mate: kode('avskrivningsmate', ny.avskrivning?.mate ?? '') }), angre: () => angreAvskrivning(p),
          })} />
        </Dialogvindu>
      )}
      {p && dialog.slag === 'rediger' && (
        <Dialogvindu tittel={t('arkivar.rediger.tittel', { nummer: nummer(p) })} lukk={lukk}>
          <Text className={c.dempet}>{t(p.rediger_felt === 'alle' ? 'arkivar.rediger.ingress.alle' : 'arkivar.rediger.ingress.tittel')}</Text>
          <RedigerJournalpost post={p} avbryt={lukk} etter={() => ferdig({ tekst: t('arkivar.kvittering.rettet', { nummer: nummer(p) }) })} />
        </Dialogvindu>
      )}
      {p && dialog.slag === 'flytt' && (
        <Dialogvindu tittel={t('arkivar.flytt.tittel', { nummer: nummer(p) })} lukk={lukk} knapper={<Button disabled={opptatt} onClick={lukk}>{t('arkivar.felles.avbryt')}</Button>}>
          <Text>{t('arkivar.flytt.ingress', { lopenummer: p.lopenummer })}</Text>
          {feil && <MessageBar intent="error" role="alert"><MessageBarBody>{feil}</MessageBarBody></MessageBar>}
          <Mappesok handling={(m) => (m.status === 'A' ? <Text className={c.dempet}>{t('arkivar.flytt.avsluttet')}</Text>
            : m.id === sakId(p) ? <Text className={c.dempet}>{t('arkivar.flytt.samme')}</Text>
            : <Button size="small" appearance="primary" disabled={opptatt} onClick={() => flytt(p, m)}>{t('arkivar.flytt.hit')}</Button>)} />
        </Dialogvindu>
      )}
    </>
  );
  /** Flytter journalposten til mappen og sier hvilket nummer den fikk. */
  function flytt(post: Journalpost, til: Sak) {
    settFeil(null); settOpptatt(true);
    api.post<{ journalpost: Journalpost }>(`/api/arkiv/journalposter/${del(post.id)}/flytt`, { sak: til.id })
      .then((d) => {
        const kvitt = { tekst: t('arkivar.kvittering.flyttet', { fra: nummer(post), til: dokumentnummer(til.saksnummer, d.journalpost.postnr), lopenummer: post.lopenummer }) };
        if (etterFlytt) { settDialog(null); settKvittering(kvitt); kontroll.oppdater(); etterFlytt(d.journalpost); } else ferdig(kvitt);
      })
      .catch((e: unknown) => settFeil(feiltekst(e))).finally(() => settOpptatt(false));
  }
  return { hovedknapp, meny, begge, flater, opptatt };
}
