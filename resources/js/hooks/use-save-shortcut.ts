import { useEffect } from 'react';

/**
 * Cmd+S (Mac) / Ctrl+S (everyone else) triggers `onSave` instead of the
 * browser's native "Save page" dialog. `enabled` lets callers scope it
 * to only fire while e.g. a modal is actually open.
 */
export function useSaveShortcut(onSave: () => void, enabled: boolean = true) {
    useEffect(() => {
        if (!enabled) return;

        function handleKeyDown(event: KeyboardEvent) {
            if ((event.metaKey || event.ctrlKey) && event.key === 's') {
                event.preventDefault();
                onSave();
            }
        }

        window.addEventListener('keydown', handleKeyDown);

        return () => window.removeEventListener('keydown', handleKeyDown);
    }, [onSave, enabled]);
}
