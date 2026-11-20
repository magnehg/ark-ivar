import { ComponentType, StrictMode, Suspense, lazy, useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter, Navigate, Route, Routes } from 'react-router-dom';
import { ApiFeil, Innlogget, Okt, Oppsett, api, harRolle, kanAdministrere } from './api';
import { Sprak, useT } from './i18n';
import { TemaTilbyder } from './tema';
import { Feil, Laster, OktCtx } from './komponenter/Felles';
import { Skall } from './komponenter/Skall';
import Konto from './sider/Konto';
import './grunnstil.css';

// Hver side ligger i egen fil og lastes ved behov. Etter en oppdatering kan en åpen fane be om gamle filer som er
// fjernet. Da lastes siden på nytt én gang i stedet for å bli blank; feiler det igjen innen ett minutt, viser
// feilgrensen i skallet en melding (ingen evig omlasting).
type Sidehenter = { (): Promise<{ default: ComponentType }> };
function lastSide(hent: Sidehenter) {
  return lazy(() => hent().catch((e: unknown) => {
    const nokkel = 'arkivar:lastet-pa-nytt';
    let sist = 0;
    try { sist = Number(sessionStorage.getItem(nokkel) ?? 0); } catch { /* ikke tilgjengelig */ }
    if (Date.now() - sist > 60_000) {
      try { sessionStorage.setItem(nokkel, String(Date.now())); } catch { /* ikke tilgjengelig */ }
      window.location.reload();
      return new Promise<never>(() => {});
    }
    throw e;
  }));
}
const Hjem = lastSide(() => import('./sider/Hjem'));
const MinSide = lastSide(() => import('./sider/MinSide'));
const Klasser = lastSide(() => import('./sider/Klasser'));
const Brukere = lastSide(() => import('./sider/Brukere'));
const Domener = lastSide(() => import('./sider/Domener'));
const Postmottak = lastSide(() => import('./sider/arkiv/Postmottak'));
const Dokument = lastSide(() => import('./sider/arkiv/Dokument'));
const Saker = lastSide(() => import('./sider/arkiv/Saker').then((m) => ({ default: m.Saker })));
const EnSak = lastSide(() => import('./sider/arkiv/Saker').then((m) => ({ default: m.EnSak })));
const NyMappe = lastSide(() => import('./sider/arkiv/NyMappe'));
const NyJournalpost = lastSide(() => import('./sider/arkiv/NyJournalpost'));
const Journal = lastSide(() => import('./sider/arkiv/Journal'));
const Journalpost = lastSide(() => import('./sider/arkiv/Journalpost'));
const Struktur = lastSide(() => import('./sider/arkiv/Struktur'));
const Installasjon = lazy(() => import('./sider/Installasjon'));

// Før installasjonen svarer ikke /api/oppsett. Navnet står da her; ellers kommer det fra serveren.
const APPNAVN = 'Ark-Ivar';

type Tilstand = { slag: 'laster' } | { slag: 'installasjon' } | { slag: 'feil' } | { slag: 'klar'; oppsett: Oppsett };

function App() {
  const t = useT();
  const [tilstand, settTilstand] = useState<Tilstand>({ slag: 'laster' });
  const [innlogget, settInnlogget] = useState<Innlogget | null>(null);
  useEffect(() => {
    // Økten først: den gir CSRF-nøkkelen alle endringer trenger, og sier om noen er logget inn.
    api.get<Okt>('/bivrost/api/okt')
      .then(async (okt) => { const oppsett = await api.get<Oppsett>('/api/oppsett'); settInnlogget(okt.innlogget); settTilstand({ slag: 'klar', oppsett }); })
      .catch((e: unknown) => settTilstand({ slag: e instanceof ApiFeil && e.kode === 'ikke_installert' ? 'installasjon' : 'feil' }));
  }, []);
  if (tilstand.slag === 'laster') return <Laster />;
  if (tilstand.slag === 'installasjon') return <Suspense fallback={<Laster />}><Installasjon appnavn={APPNAVN} /></Suspense>;
  if (tilstand.slag === 'feil') return <Feil melding={t('arkivar.feil.nett')} />;
  const student = harRolle(innlogget, 'student');
  const laerer = harRolle(innlogget, 'laerer');
  const admin = kanAdministrere(innlogget);
  return (
    <OktCtx.Provider value={{ innlogget, oppsett: tilstand.oppsett, settInnlogget }}>
      {!innlogget ? <Konto /> : (
        <Suspense fallback={<Laster />}>
          <Routes>
            <Route element={<Skall />}>
              <Route index element={<Hjem />} />
              {student && <Route path="min-side" element={<MinSide />} />}
              {student && <Route path="arkiv/postmottak" element={<Postmottak />} />}
              {student && <Route path="arkiv/postmottak/:id" element={<Dokument />} />}
              {student && <Route path="arkiv/saker" element={<Saker />} />}
              {student && <Route path="arkiv/saker/ny" element={<NyMappe />} />}
              {student && <Route path="arkiv/saker/:id" element={<EnSak />} />}
              {student && <Route path="arkiv/saker/:id/ny-journalpost" element={<NyJournalpost />} />}
              {student && <Route path="arkiv/journal" element={<Journal />} />}
              {student && <Route path="arkiv/journalposter/:id" element={<Journalpost />} />}
              {student && <Route path="arkiv/struktur" element={<Struktur />} />}
              {laerer && <Route path="klasser" element={<Klasser />} />}
              {admin && <Route path="administrasjon/brukere" element={<Brukere />} />}
              {admin && <Route path="administrasjon/domener" element={<Domener />} />}
              <Route path="*" element={<Navigate to="/" replace />} />
            </Route>
          </Routes>
        </Suspense>
      )}
    </OktCtx.Provider>
  );
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <Sprak>
      <TemaTilbyder>
        <BrowserRouter>
          <App />
        </BrowserRouter>
      </TemaTilbyder>
    </Sprak>
  </StrictMode>,
);
