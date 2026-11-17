import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

/**
 * Invigoration is the one Event Relic whose "you may..." choice can't
 * be supplied in the same move that found it (the Wanderer doesn't
 * know they found it until that move already returned) — so unlike
 * every other Event, it doesn't resolve on the spot. This blocks on
 * the choice instead of just notifying, matching NewRelicDialog's
 * non-dismissable pattern.
 */
export function InvigorationDialog({
    open,
    processing,
    onDecide,
}: {
    open: boolean;
    processing: boolean;
    onDecide: (take: boolean) => void;
}) {
    return (
        <Dialog open={open} onOpenChange={() => {}}>
            <DialogContent
                className="sm:max-w-md [&>button]:hidden"
                onEscapeKeyDown={(e) => e.preventDefault()}
                onPointerDownOutside={(e) => e.preventDefault()}
            >
                <DialogHeader className="min-w-0">
                    <DialogTitle className="text-2xl break-words">
                        🌑 Invigoration
                    </DialogTitle>
                    <DialogDescription className="break-words">
                        You may lose 7 Health in exchange for 2 extra actions
                        this round.
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="gap-2">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={processing}
                        onClick={() => onDecide(false)}
                    >
                        Decline
                    </Button>
                    <Button
                        size="sm"
                        disabled={processing}
                        onClick={() => onDecide(true)}
                    >
                        Take it (-7 Health, +2 actions)
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
