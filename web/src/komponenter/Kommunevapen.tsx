// Kommunevåpenet til Sirevåg kommune. Kommunen og våpenet er oppdiktet (se docs/innhold.md). Våpenet er et bilde med
// sine egne heraldiske farger (én farge og ett metall) og følger ikke fargetemaet. Det har en tynn kontur i metallet,
// så det synes både på lys og mørk bakgrunn og i toppfeltet.
import { makeStyles } from '@fluentui/react-components';
import vapen from '../bilder/sirevag-kommunevapen.svg';

const s = makeStyles({ vapen: { flex: 'none', display: 'block' } });

/** Skjoldet er tegnet i forholdet 5:6 (bredde:høyde). */
const BREDDE_PER_HOYDE = 100 / 120;

/**
 * tekst: beskrivelsen for skjermlesere. La den stå tom der navnet på kommunen står ved siden av våpenet, så navnet
 * ikke leses to ganger.
 */
export function Kommunevapen({ hoyde = 24, tekst = '' }: { hoyde?: number; tekst?: string }) {
  return <img className={s().vapen} src={vapen} alt={tekst} height={hoyde} width={Math.round(hoyde * BREDDE_PER_HOYDE)} />;
}
