// Primærklasseringen velges ved å skrive: en kode eller et stikkord i arkivnøkkelen, eller navnet, adressen eller
// nummeret i et register. Arkivnøkkelen har over 200 klasser, og registrene kan være lange, så en vanlig
// nedtrekksliste blir for tung. Listen viser det som passer med det som er skrevet.
import { useState } from 'react';
import { Combobox, Option, OptionGroup, makeStyles, tokens } from '@fluentui/react-components';
import { Arkivdel, Struktur, registertekst, registertreff, valgbareKlasser } from '../arkiv';
import { useT } from '../i18n';

const s = makeStyles({ detalj: { display: 'block', color: tokens.colorNeutralForeground2, fontSize: tokens.fontSizeBase200 } });

type Egenskaper = { struktur: Struktur; arkivdel: Arkivdel; verdi: string; velg: (kode: string) => void };

export function Klassevelger({ struktur, arkivdel, verdi, velg }: Egenskaper) {
  const c = s(); const t = useT();
  // null betyr at feltet viser det som er valgt; en tekst er det studenten holder på å skrive.
  const [sok, settSok] = useState<string | null>(null);
  const emne = arkivdel.ordning === 'emne';
  const register = struktur.registre[arkivdel.kode] ?? [];
  const valgtKlasse = emne ? struktur.klasser.find((k) => k.kode === verdi) : undefined;
  const valgtPost = emne ? undefined : register.find((p) => p.kode === verdi);
  const valgt = valgtKlasse ? `${valgtKlasse.kode} ${valgtKlasse.tittel}` : valgtPost ? registertekst(arkivdel, valgtPost) : '';
  const grupper = emne ? valgbareKlasser(struktur, sok ?? '') : [];
  const poster = emne ? [] : registertreff(register, sok ?? '');
  const tomt = emne ? grupper.length === 0 : poster.length === 0;
  return (
    <Combobox
      freeform
      placeholder={t(`arkivar.ordning.${arkivdel.ordning}.sok`)}
      value={sok ?? valgt}
      selectedOptions={verdi ? [verdi] : []}
      onChange={(e) => { settSok(e.target.value); if (e.target.value === '') velg(''); }}
      onOptionSelect={(_, d) => { if (d.optionValue) { velg(d.optionValue); settSok(null); } }}
      onBlur={() => settSok(null)}
      listbox={{ style: { maxHeight: '320px' } }}
      expandIcon={{ 'aria-label': t('arkivar.felles.aapne_liste') }}
    >
      {grupper.map((g) => (
        <OptionGroup key={g.gruppe} label={g.gruppe} lang="nb">
          {g.klasser.map((k) => <Option key={k.kode} value={k.kode} text={`${k.kode} ${k.tittel}`}>{k.kode} {k.tittel}</Option>)}
        </OptionGroup>
      ))}
      {poster.map((p) => (
        <Option key={p.kode} value={p.kode} text={registertekst(arkivdel, p)} lang="nb">
          <span>{registertekst(arkivdel, p)}<span className={c.detalj}>{p.detalj}</span></span>
        </Option>
      ))}
      {tomt && <Option key="ingen" value="" text="" disabled>{t('arkivar.registrering.klasse.ingen')}</Option>}
    </Combobox>
  );
}
