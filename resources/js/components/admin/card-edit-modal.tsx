import { CardFields } from '@/components/admin/card-fields';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { CardRecord, ColumnDef } from '@/types/admin';

interface CardEditModalProps {
    open: boolean;
    onClose: () => void;
    card: CardRecord | null;
    columns: ColumnDef[];
    morphType: string;
    storeUrl: string;
    updateUrl: (id: number) => string;
    destroyUrl: (id: number) => string;
    commentStoreUrl: string;
    commentResolveUrl: (id: number) => string;
}

export function CardEditModal({
    open,
    onClose,
    card,
    columns,
    morphType,
    storeUrl,
    updateUrl,
    destroyUrl,
    commentStoreUrl,
    commentResolveUrl,
}: CardEditModalProps) {
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {card ? `Edit: ${card.name}` : 'New card'}
                    </DialogTitle>
                </DialogHeader>

                <CardFields
                    card={card}
                    columns={columns}
                    morphType={morphType}
                    storeUrl={storeUrl}
                    updateUrl={updateUrl}
                    destroyUrl={destroyUrl}
                    commentStoreUrl={commentStoreUrl}
                    commentResolveUrl={commentResolveUrl}
                    onSaved={onClose}
                    onDeleted={onClose}
                    shortcutsEnabled={open}
                />
            </DialogContent>
        </Dialog>
    );
}
