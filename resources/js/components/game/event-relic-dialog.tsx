import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { RoleKey, RelicCatalogEntry } from '@/types/game';

function roleLabel(role: RoleKey): string {
    if (role === 'emperor') return 'Emperor';
    if (role === 'golem') return 'Golem';
    return `Wanderer ${role.replace('wanderer_', '').toUpperCase()}`;
}

/**
 * Event Relics (Time spasm, etc.) never enter anyone's `holding` — they
 * resolve and trash themselves the instant they're found. Without this,
 * the only trace of one firing was a line buried in the scrolling
 * activity log, easy to miss, and nothing told the other players
 * anything happened at all even though the effect (e.g. shifting the
 * round counter) affects everyone.
 */
export function EventRelicDialog({
    open,
    onDismiss,
    role,
    relicKey,
    entry,
}: {
    open: boolean;
    onDismiss: () => void;
    role: RoleKey | null;
    relicKey: string | null;
    entry: RelicCatalogEntry | undefined;
}) {
    if (!relicKey) return null;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onDismiss()}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader className="min-w-0">
                    <DialogTitle className="text-2xl break-words">
                        ⚡ {entry ? `${entry.symbol} ${entry.name}` : relicKey}
                    </DialogTitle>
                    <DialogDescription className="break-words">
                        {role && roleLabel(role)} found an Event. It resolved
                        immediately and was trashed — the action is refunded.
                    </DialogDescription>
                </DialogHeader>

                {entry?.effect && (
                    <p className="min-w-0 rounded-md border bg-muted/40 p-3 text-sm break-words">
                        {entry.effect}
                    </p>
                )}

                <DialogFooter>
                    <Button size="sm" onClick={onDismiss}>
                        Got it
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
