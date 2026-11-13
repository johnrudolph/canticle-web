import { useEffect, useRef } from 'react';
import type { GamePollResponse } from '@/types/game';

/**
 * Polls the game's state in place of a live push channel. Reverb is
 * already running for another app on this server; this one deliberately
 * stays on plain polling instead of adding a second websocket consumer —
 * a casual board game doesn't need sub-second sync, and polling avoids
 * that extra coupling entirely.
 */
export function useGamePolling(
    gameCode: string,
    onUpdate: (payload: GamePollResponse) => void,
    intervalMs = 2000,
) {
    const onUpdateRef = useRef(onUpdate);
    onUpdateRef.current = onUpdate;

    useEffect(() => {
        let cancelled = false;
        let timeoutId: ReturnType<typeof setTimeout>;

        async function poll() {
            try {
                const response = await fetch(`/games/${gameCode}/poll`, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });

                if (!cancelled && response.ok) {
                    const data = (await response.json()) as GamePollResponse;
                    onUpdateRef.current(data);

                    if (data.status === 'abandoned') {
                        return; // no point continuing to poll a dead game
                    }
                }
            } catch {
                // Transient network error — just try again next tick.
            }

            if (!cancelled) {
                timeoutId = setTimeout(poll, intervalMs);
            }
        }

        void poll();

        return () => {
            cancelled = true;
            clearTimeout(timeoutId);
        };
    }, [gameCode, intervalMs]);
}
