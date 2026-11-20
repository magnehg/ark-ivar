// Statusmerker: alltid ikon og tekst sammen, aldri bare farge (WCAG 1.4.1). Fargene kommer fra temaet.
import { ReactNode } from 'react';
import { makeStyles, mergeClasses, tokens } from '@fluentui/react-components';
import { CheckmarkCircle16Filled, Circle16Regular, DismissCircle16Filled, LockClosed16Regular } from '@fluentui/react-icons';

const s = makeStyles({
  merke: { display: 'inline-flex', alignItems: 'center', gap: '4px', whiteSpace: 'nowrap' },
  // Lang tekst (en hjemmel) brytes i stedet for å klippes. Ikonet står fast ved første linje.
  brytes: { whiteSpace: 'normal', alignItems: 'flex-start', '& > svg': { flexShrink: 0, marginTop: '2px' } },
  ok: { color: tokens.colorPaletteGreenForeground1 },
  feil: { color: tokens.colorPaletteRedForeground1 },
  noytral: { color: tokens.colorNeutralForeground2 },
});

export type Statusslag = 'ok' | 'feil' | 'noytral' | 'skjermet';

export function Status({ slag, children }: { slag: Statusslag; children: ReactNode }) {
  const c = s();
  const ikon = slag === 'ok' ? <CheckmarkCircle16Filled className={c.ok} /> : slag === 'feil' ? <DismissCircle16Filled className={c.feil} />
    : slag === 'skjermet' ? <LockClosed16Regular className={c.noytral} /> : <Circle16Regular className={c.noytral} />;
  return <span className={mergeClasses(c.merke, slag === 'skjermet' && c.brytes)}>{ikon}<span>{children}</span></span>;
}
