// App-skallet: toppfelt, moduslinje, sidemeny, innhold og bunntekst. Hentet fra skallet i Gerð (komponenter/Skall.tsx i
// Gerð-prototypen) og satt på designpakkens variabler.
import { CSSProperties, ReactNode, Suspense } from 'react';
import { NavLink, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { Avatar, Button, Menu, MenuDivider, MenuGroup, MenuGroupHeader, MenuItem, MenuItemRadio, MenuList, MenuPopover, MenuProps, MenuTrigger, Text, makeStyles, mergeClasses, tokens } from '@fluentui/react-components';
import { AlignStretchHorizontal20Regular, ArrowAutofitWidthDotted20Regular, Desktop20Regular, DocumentBulletList20Regular, Folder20Regular, Globe20Regular, Home20Regular, MailInbox20Regular, BookOpen20Regular, TextBulletListTree20Regular, People20Regular, PeopleTeam20Regular, Person20Regular, SignOut20Regular, WeatherMoon20Regular, WeatherSunny20Regular } from '@fluentui/react-icons';
import { Innlogget, api, harRolle, kanAdministrere } from '../api';
import { SPRAK, Sprakkode, useSprak, useT } from '../i18n';
import { Kommunevapen } from './Kommunevapen';
import { Fargeprove, Logo, useTema } from '../tema';
import { TEMA_REKKEFOLGE, type TemaId } from '../../../grensesnitt/src/temaer.ts';
import { Feilgrense, Laster, useOkt } from './Felles';

// Begrenset bredde: rammen med sidemeny og innhold blir aldri bredere enn 1600 px og står midt på skjermen.
const MAKS_BREDDE = 1600;
const innrykk = (px: number) => `max(${px}px, calc((100% - ${MAKS_BREDDE}px) / 2 + ${px}px))`;

const s = makeStyles({
  hopp: { position: 'absolute', left: '-9999px', top: '8px', zIndex: 100, background: tokens.colorNeutralBackground1, padding: '8px 12px', ':focus': { left: '8px' } },
  topp: { display: 'flex', alignItems: 'center', gap: '12px', padding: '0 12px', height: '48px', boxSizing: 'border-box', background: 'var(--bivrost-farge-toppfelt-bakgrunn)', color: 'var(--bivrost-farge-toppfelt-tekst)', borderBottom: '1px solid var(--bivrost-farge-toppfelt-kant)' },
  logo: { display: 'flex', alignItems: 'center', gap: '8px', color: 'var(--bivrost-farge-toppfelt-tekst)', textDecoration: 'none', fontWeight: tokens.fontWeightSemibold, fontSize: '16px' },
  virksomhet: { whiteSpace: 'nowrap', display: 'inline-flex', alignItems: 'center', gap: '8px', '@media (max-width: 600px)': { display: 'none' } },
  fyll: { flex: 1, minWidth: 0 },
  modus: { display: 'flex', gap: '4px', padding: '0 16px', background: tokens.colorNeutralBackground1, borderBottom: `1px solid ${tokens.colorNeutralStroke2}`, overflowX: 'auto' },
  modusLenke: { padding: '12px', color: tokens.colorNeutralForeground2, textDecoration: 'none', borderBottom: '3px solid transparent', whiteSpace: 'nowrap', ':hover': { background: tokens.colorNeutralBackground3 } },
  modusAktiv: { color: tokens.colorNeutralForeground1, fontWeight: tokens.fontWeightSemibold, borderBottomColor: tokens.colorBrandStroke1 },
  ramme: { display: 'grid', gridTemplateColumns: '240px minmax(0,1fr)', minHeight: 'calc(100vh - 97px - 49px)', '@media (max-width: 800px)': { gridTemplateColumns: '1fr' } },
  side: { display: 'flex', flexDirection: 'column', background: tokens.colorNeutralBackground2, borderRight: `1px solid ${tokens.colorNeutralStroke2}`, padding: '12px 8px 0', '@media (max-width: 800px)': { borderRight: 'none', borderBottom: `1px solid ${tokens.colorNeutralStroke2}` } },
  sideTittel: { fontSize: '12px', textTransform: 'uppercase', letterSpacing: '0.04em', color: tokens.colorNeutralForeground3, margin: '12px 8px 4px' },
  sideLenke: { display: 'flex', gap: '8px', alignItems: 'center', padding: '8px', borderRadius: tokens.borderRadiusMedium, color: tokens.colorNeutralForeground1, textDecoration: 'none', minHeight: '24px', ':hover': { background: tokens.colorNeutralBackground3 } },
  // Nederst i menyen. Er siden lengre enn skjermen, står den fast nederst i venstre hjørne mens siden rulles.
  sideBunn: { marginTop: 'auto', position: 'sticky', bottom: 0, background: tokens.colorNeutralBackground2, borderTop: `1px solid ${tokens.colorNeutralStroke2}`, padding: '8px 0', marginLeft: '-8px', marginRight: '-8px', paddingLeft: '8px', paddingRight: '8px' },
  sideAktiv: { background: tokens.colorBrandBackground2, fontWeight: tokens.fontWeightSemibold, boxShadow: `inset 3px 0 0 ${tokens.colorBrandStroke1}` },
  brukerinfo: { display: 'flex', flexDirection: 'column', padding: '6px 10px 8px', color: tokens.colorNeutralForeground1 },
  brukerrolle: { fontSize: tokens.fontSizeBase200, color: tokens.colorNeutralForeground2 },
  innhold: { padding: '24px', background: tokens.colorNeutralBackground3, minWidth: 0, '@media (max-width: 800px)': { padding: '16px' } },
  bunn: { display: 'flex', flexWrap: 'wrap', gap: '8px 16px', padding: '12px 16px', borderTop: `1px solid ${tokens.colorNeutralStroke2}`, fontSize: '12px', color: tokens.colorNeutralForeground3, background: tokens.colorNeutralBackground1 },
  toppBegrenset: { paddingLeft: innrykk(12), paddingRight: innrykk(12) },
  modusBegrenset: { paddingLeft: innrykk(16), paddingRight: innrykk(16) },
  rammeBegrenset: { maxWidth: `${MAKS_BREDDE}px`, width: '100%', marginLeft: 'auto', marginRight: 'auto', boxSizing: 'border-box',
    [`@media (min-width: ${MAKS_BREDDE + 1}px)`]: { borderLeft: `1px solid ${tokens.colorNeutralStroke2}`, borderRight: `1px solid ${tokens.colorNeutralStroke2}` } },
  bunnBegrenset: { paddingLeft: innrykk(16), paddingRight: innrykk(16) },
});

type Lysmodus = 'system' | 'lys' | 'mork';
type Bredde = 'begrenset' | 'full';
const MODUS_IKON: Record<Lysmodus, JSX.Element> = { system: <Desktop20Regular />, lys: <WeatherSunny20Regular />, mork: <WeatherMoon20Regular /> };
const BREDDE_IKON: Record<Bredde, JSX.Element> = { begrenset: <ArrowAutofitWidthDotted20Regular />, full: <AlignStretchHorizontal20Regular /> };

// vis: lenken står bare i menyen for den som har tilgangen siden krever (serveren kontrollerer det uansett).
// fil: lenken går til en fil som webserveren leverer selv (utenfor appen), og laster derfor hele siden.
type Lenke = { til: string; tekst: string; ikon: ReactNode; vis?: (i: Innlogget | null) => boolean; fil?: boolean };

/** Arkivplanen til kommunen ligger som en undermappe ved siden av appen (arkivar/public/arkivplan/). */
const ARKIVPLAN = '/arkivplan/index.html';
type Modus = { id: string; til: string; tekst: string; grupper: { tittel: string; lenker: Lenke[] }[] };

function useModuser(innlogget: Innlogget | null): Modus[] {
  const t = useT();
  const student = (i: Innlogget | null) => harRolle(i, 'student');
  const moduser: Modus[] = [
    { id: 'hjem', til: '/', tekst: t('arkivar.modus.hjem'), grupper: [{ tittel: t('arkivar.modus.hjem'), lenker: [
      { til: '/', tekst: t('arkivar.nav.hjem'), ikon: <Home20Regular /> },
      { til: '/min-side', tekst: t('arkivar.nav.min_side'), ikon: <Person20Regular />, vis: student },
    ] }] },
    { id: 'arkiv', til: '/arkiv/postmottak', tekst: t('arkivar.modus.arkiv'), grupper: [{ tittel: t('arkivar.modus.arkiv'), lenker: [
      { til: '/arkiv/postmottak', tekst: t('arkivar.nav.postmottak'), ikon: <MailInbox20Regular />, vis: student },
      { til: '/arkiv/saker', tekst: t('arkivar.nav.saker'), ikon: <Folder20Regular />, vis: student },
      { til: '/arkiv/journal', tekst: t('arkivar.nav.journal'), ikon: <DocumentBulletList20Regular />, vis: student },
      { til: '/arkiv/struktur', tekst: t('arkivar.nav.struktur'), ikon: <TextBulletListTree20Regular />, vis: student },
      { til: ARKIVPLAN, tekst: t('arkivar.nav.arkivplan'), ikon: <BookOpen20Regular />, vis: student, fil: true },
    ] }] },
    { id: 'klasser', til: '/klasser', tekst: t('arkivar.modus.klasser'), grupper: [{ tittel: t('arkivar.modus.klasser'), lenker: [
      { til: '/klasser', tekst: t('arkivar.nav.klasser'), ikon: <PeopleTeam20Regular />, vis: (i) => harRolle(i, 'laerer') },
    ] }] },
    { id: 'administrasjon', til: '/administrasjon/brukere', tekst: t('arkivar.modus.administrasjon'), grupper: [{ tittel: t('arkivar.modus.administrasjon'), lenker: [
      { til: '/administrasjon/brukere', tekst: t('arkivar.nav.brukere'), ikon: <People20Regular />, vis: kanAdministrere },
      { til: '/administrasjon/domener', tekst: t('arkivar.nav.domener'), ikon: <Globe20Regular />, vis: kanAdministrere },
    ] }] },
  ];
  // Bare lenkene brukeren har tilgang til. En modus går til den første lenken som er igjen.
  return moduser.map((m) => {
    const grupper = m.grupper.map((g) => ({ ...g, lenker: g.lenker.filter((l) => !l.vis || l.vis(innlogget)) })).filter((g) => g.lenker.length > 0);
    return { ...m, grupper, til: grupper[0]?.lenker[0]?.til ?? m.til };
  }).filter((m) => m.grupper.length > 0);
}

export function Skall() {
  const c = s(); const t = useT();
  const { innlogget, oppsett, settInnlogget } = useOkt();
  const sted = useLocation(); const nav = useNavigate();
  const moduser = useModuser(innlogget);
  const aktiv = moduser.find((m) => m.id !== 'hjem' && sted.pathname.startsWith('/' + m.id)) ?? moduser[0];
  const tema = useTema();
  const { sprak, settSprak } = useSprak();
  const modus: Lysmodus = tema.utseende.modus ?? 'system';
  const bredde: Bredde = tema.utseende.bredde ?? 'begrenset';
  const velg: MenuProps['onCheckedValueChange'] = (_, d) => {
    const v = d.checkedItems[0];
    if (d.name === 'tema') tema.endre({ tema: v });
    if (d.name === 'modus') tema.endre({ modus: v as Lysmodus });
    if (d.name === 'bredde') tema.endre({ bredde: v as Bredde });
    if (d.name === 'sprak') settSprak(v as Sprakkode);
  };
  const loggUt = () => { api.post('/bivrost/api/utlogging').catch(() => {}).finally(() => { settInnlogget(null); nav('/'); }); };
  const begrenset = bredde === 'begrenset';
  const rolle = innlogget?.tittel ?? innlogget?.tilganger.map((x) => x.navn).join(', ') ?? '';
  const filer = (aktiv?.grupper ?? []).flatMap((g) => g.lenker.filter((l) => l.fil));
  return (
    <>
      <a href="#hoved" className={c.hopp}>{t('arkivar.felles.hopp_til_innhold')}</a>
      <header>
        {/* Fokusringen inne i toppfeltet bruker toppfeltets tekstfarge, så den synes mot hovedfargen. */}
        <div className={mergeClasses(c.topp, begrenset && c.toppBegrenset)} style={{ '--colorStrokeFocus2': 'var(--bivrost-farge-toppfelt-tekst)', '--colorStrokeFocus1': 'var(--bivrost-farge-toppfelt-bakgrunn)' } as CSSProperties}>
          <NavLink to="/" className={c.logo} aria-label={t('arkivar.topp.hjem', { appnavn: oppsett.appnavn })}><Logo /><span>{oppsett.appnavn}</span></NavLink>
          <span className={c.fyll} />
          <Text size={200} className={c.virksomhet}><Kommunevapen hoyde={24} />{oppsett.virksomhet}</Text>
          <Menu checkedValues={{ tema: [tema.tema], modus: [modus], bredde: [bredde], sprak: [sprak] }} onCheckedValueChange={velg}>
            <MenuTrigger disableButtonEnhancement>
              <Button appearance="transparent" aria-label={t('arkivar.topp.brukermeny', { navn: innlogget?.navn ?? '' })} icon={<Avatar name={innlogget?.navn} size={28} color="neutral" />} />
            </MenuTrigger>
            <MenuPopover>
              <div className={c.brukerinfo}><span>{innlogget?.navn}</span><span className={c.brukerrolle}>{rolle} · {oppsett.virksomhet}</span></div>
              <MenuList>
                <MenuDivider />
                <MenuGroup>
                  <MenuGroupHeader>{t('arkivar.utseende.fargetema')}</MenuGroupHeader>
                  {TEMA_REKKEFOLGE.map((id: TemaId) => <MenuItemRadio key={id} name="tema" value={id} icon={<Fargeprove id={id} storrelse={20} />}>{t(`arkivar.utseende.tema.${id}`)}</MenuItemRadio>)}
                </MenuGroup>
                <MenuDivider />
                <MenuGroup>
                  <MenuGroupHeader>{t('arkivar.utseende.modus')}</MenuGroupHeader>
                  {(['system', 'lys', 'mork'] as Lysmodus[]).map((m) => <MenuItemRadio key={m} name="modus" value={m} icon={MODUS_IKON[m]}>{t(`arkivar.utseende.modus.${m}`)}</MenuItemRadio>)}
                </MenuGroup>
                <MenuDivider />
                <MenuGroup>
                  <MenuGroupHeader>{t('arkivar.utseende.bredde')}</MenuGroupHeader>
                  {(['begrenset', 'full'] as Bredde[]).map((b) => <MenuItemRadio key={b} name="bredde" value={b} icon={BREDDE_IKON[b]}>{t(`arkivar.utseende.bredde.${b}`)}</MenuItemRadio>)}
                </MenuGroup>
                <MenuDivider />
                <MenuGroup>
                  <MenuGroupHeader>{t('arkivar.utseende.sprak')}</MenuGroupHeader>
                  {SPRAK.map((kode) => <MenuItemRadio key={kode} name="sprak" value={kode}><span lang={kode}>{t(`arkivar.sprak.${kode}`)}</span></MenuItemRadio>)}
                </MenuGroup>
                <MenuDivider />
                <MenuItem icon={<SignOut20Regular />} onClick={loggUt}>{t('arkivar.topp.logg_ut')}</MenuItem>
              </MenuList>
            </MenuPopover>
          </Menu>
        </div>
        <nav className={mergeClasses(c.modus, begrenset && c.modusBegrenset)} aria-label={t('arkivar.felles.moduser')}>
          {moduser.map((m) => <NavLink key={m.id} to={m.til} className={`${c.modusLenke} ${m === aktiv ? c.modusAktiv : ''}`} aria-current={m === aktiv ? 'page' : undefined}>{m.tekst}</NavLink>)}
        </nav>
      </header>
      <div className={mergeClasses(c.ramme, begrenset && c.rammeBegrenset)}>
        <nav className={c.side} aria-label={t('arkivar.felles.seksjoner')}>
          {aktiv?.grupper.map((g) => (
            <div key={g.tittel}>
              <h2 className={c.sideTittel}>{g.tittel}</h2>
              {g.lenker.filter((l) => !l.fil).map((l) => (
                <NavLink key={l.til} to={l.til} end={l.til === '/'} className={({ isActive }) => `${c.sideLenke} ${isActive ? c.sideAktiv : ''}`}>{l.ikon}{l.tekst}</NavLink>))}
            </div>
          ))}
          {/* Lenkene ut av appen (arkivplanen) står nederst i menyen og følger med nederst i venstre hjørne når siden rulles. */}
          {filer.length > 0 && (
            <div className={c.sideBunn}>
              {filer.map((l) => <a key={l.til} href={l.til} className={c.sideLenke}>{l.ikon}{l.tekst}</a>)}
            </div>
          )}
        </nav>
        {/* Siden lastes og tegnes inne i hovedfeltet: menyen står både mens siden lastes og hvis den feiler. */}
        <main id="hoved" tabIndex={-1} className={c.innhold}>
          <Feilgrense nullstill={sted.pathname}><Suspense fallback={<Laster />}><Outlet /></Suspense></Feilgrense>
        </main>
      </div>
      <footer className={mergeClasses(c.bunn, begrenset && c.bunnBegrenset)}>
        <span>{t('arkivar.bunn.ovingssystem', { appnavn: oppsett.appnavn, virksomhet: oppsett.virksomhet })}</span>
        <span style={{ flex: 1 }} />
        <span>{t('arkivar.bunn.versjon', { versjon: oppsett.versjon })}</span>
      </footer>
    </>
  );
}
