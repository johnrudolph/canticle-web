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
 * Generic "pick one Wanderer (or the Golem)" prompt — reused by every
 * Relic/Glyph whose choice is a target role rather than a cell or
 * another Relic: Quantum robes (any other Wanderer), Magnetic mail
 * (an adjacent Ally), Neighborly/Cabalist brew (an Ally), Magistrate's
 * robes (either end of a Shame move, which can be the Golem), etc.
 * Callers pass in exactly the candidate list that role's own
 * condition allows — this component doesn't know or enforce adjacency,
 * same-cell, etc. itself.
 */
export function WandererPickerDialog({
    open,
    title,
    description,
    candidates,
    processing,
    onChoose,
    onCancel,
}: {
    open: boolean;
    title: string;
    description?: string;
    candidates: { role: string; label: string }[];
    processing: boolean;
    onChoose: (role: string) => void;
    onCancel?: () => void;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && onCancel) onCancel();
            }}
        >
            <DialogContent className="sm:max-w-sm">
                <DialogHeader className="min-w-0">
                    <DialogTitle className="break-words">{title}</DialogTitle>
                    {description && (
                        <DialogDescription className="break-words">
                            {description}
                        </DialogDescription>
                    )}
                </DialogHeader>

                {candidates.length === 0 ? (
                    <p className="min-w-0 text-sm break-words text-muted-foreground">
                        No eligible target right now.
                    </p>
                ) : (
                    <div className="flex flex-wrap gap-2">
                        {candidates.map(({ role, label }) => (
                            <Button
                                key={role}
                                size="sm"
                                variant="outline"
                                disabled={processing}
                                onClick={() => onChoose(role)}
                            >
                                {label}
                            </Button>
                        ))}
                    </div>
                )}

                {onCancel && (
                    <DialogFooter>
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={processing}
                            onClick={onCancel}
                        >
                            Cancel
                        </Button>
                    </DialogFooter>
                )}
            </DialogContent>
        </Dialog>
    );
}
