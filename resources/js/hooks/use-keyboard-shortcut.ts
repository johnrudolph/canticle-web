import { useEffect } from 'react';

/**
 * Cmd+<key> (Mac) / Ctrl+<key> (everyone else) triggers `handler`
 * instead of whatever the browser would otherwise do (native Save,
 * Find, etc.) — `enabled` lets callers scope it to only fire while e.g.
 * a modal is actually open.
 */
export function useKeyboardShortcut(
    key: string,
    handler: () => void,
    enabled: boolean = true,
) {
    useEffect(() => {
        if (!enabled) return;

        function handleKeyDown(event: KeyboardEvent) {
            if (
                (event.metaKey || event.ctrlKey) &&
                event.key.toLowerCase() === key.toLowerCase()
            ) {
                event.preventDefault();
                handler();
            }
        }

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [key, handler, enabled]);
}
