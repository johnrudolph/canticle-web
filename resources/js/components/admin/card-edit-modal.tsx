import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useSaveShortcut } from '@/hooks/use-save-shortcut';
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
    const allColumns: ColumnDef[] = [
        { key: 'print', label: 'Print', type: 'boolean' },
        { key: 'name', label: 'Name', type: 'text' },
        ...columns,
    ];

    const [data, setData] = useState<Record<string, FormDataConvertible>>(
        () =>
            Object.fromEntries(
                allColumns.map((c) => [
                    c.key,
                    card
                        ? (card[c.key] as FormDataConvertible)
                        : c.type === 'boolean'
                          ? false
                          : '',
                ]),
            ),
    );
    const [newComment, setNewComment] = useState('');
    const [deleteReason, setDeleteReason] = useState('');
    const [confirmingDelete, setConfirmingDelete] = useState(false);

    function set(key: string, value: FormDataConvertible) {
        setData((prev) => ({ ...prev, [key]: value }));
    }

    function save() {
        if (card) {
            router.put(updateUrl(card.id), data, { onSuccess: onClose });
        } else {
            router.post(storeUrl, data, { onSuccess: onClose });
        }
    }

    useSaveShortcut(save, open);

    function addComment() {
        if (!card || newComment.trim() === '') return;
        router.post(
            commentStoreUrl,
            {
                commentable_type: morphType,
                commentable_id: card.id,
                body: newComment,
            },
            { onSuccess: () => setNewComment('') },
        );
    }

    function resolveComment(id: number) {
        router.put(commentResolveUrl(id), {});
    }

    function confirmDelete() {
        if (!card || deleteReason.trim() === '') return;
        router.delete(destroyUrl(card.id), {
            data: { reason: deleteReason },
            onSuccess: onClose,
        });
    }

    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent className="max-h-[85vh] max-w-2xl overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>
                        {card ? `Edit: ${card.name}` : 'New card'}
                    </DialogTitle>
                </DialogHeader>

                <div className="flex flex-col gap-4">
                    {card && (
                        <div className="text-sm text-muted-foreground">
                            Key: <code>{card.key}</code> (fixed — the game
                            engine references this by exact value)
                        </div>
                    )}

                    {allColumns.map((col) => (
                        <div key={col.key} className="flex flex-col gap-1">
                            {col.type === 'boolean' ? (
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id={col.key}
                                        checked={Boolean(data[col.key])}
                                        onCheckedChange={(checked) =>
                                            set(col.key, checked === true)
                                        }
                                    />
                                    <Label htmlFor={col.key}>
                                        {col.label}
                                    </Label>
                                </div>
                            ) : (
                                <>
                                    <Label htmlFor={col.key}>
                                        {col.label}
                                    </Label>
                                    {col.type === 'textarea' ? (
                                        <Textarea
                                            id={col.key}
                                            value={String(
                                                data[col.key] ?? '',
                                            )}
                                            onChange={(e) =>
                                                set(col.key, e.target.value)
                                            }
                                        />
                                    ) : (
                                        <Input
                                            id={col.key}
                                            value={String(
                                                data[col.key] ?? '',
                                            )}
                                            onChange={(e) =>
                                                set(col.key, e.target.value)
                                            }
                                        />
                                    )}
                                </>
                            )}
                        </div>
                    ))}

                    {card && (
                        <div className="flex flex-col gap-2 border-t pt-4">
                            <h3 className="text-sm font-medium">Comments</h3>
                            {card.comments.length === 0 && (
                                <p className="text-sm text-muted-foreground">
                                    No comments.
                                </p>
                            )}
                            {card.comments.map((comment) => (
                                <div
                                    key={comment.id}
                                    className="flex items-start justify-between gap-2 rounded-md border p-2 text-sm"
                                >
                                    <div>
                                        {comment.kind === 'deletion' && (
                                            <Badge
                                                variant="outline"
                                                className="mb-1"
                                            >
                                                deletion
                                            </Badge>
                                        )}
                                        <p>{comment.body}</p>
                                        {comment.resolved_at && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                Resolved
                                                {comment.resolved_note
                                                    ? `: ${comment.resolved_note}`
                                                    : ''}
                                            </p>
                                        )}
                                    </div>
                                    {!comment.resolved_at && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() =>
                                                resolveComment(comment.id)
                                            }
                                        >
                                            Mark resolved
                                        </Button>
                                    )}
                                </div>
                            ))}
                            <div className="flex gap-2">
                                <Input
                                    placeholder="Add a comment..."
                                    value={newComment}
                                    onChange={(e) =>
                                        setNewComment(e.target.value)
                                    }
                                />
                                <Button onClick={addComment}>Comment</Button>
                            </div>
                        </div>
                    )}

                    {card && (
                        <div className="flex flex-col gap-2 border-t pt-4">
                            {confirmingDelete ? (
                                <div className="flex flex-col gap-2">
                                    <Label htmlFor="delete-reason">
                                        Why is this card being deleted?
                                    </Label>
                                    <Input
                                        id="delete-reason"
                                        value={deleteReason}
                                        onChange={(e) =>
                                            setDeleteReason(e.target.value)
                                        }
                                    />
                                    <div className="flex gap-2">
                                        <Button
                                            variant="destructive"
                                            onClick={confirmDelete}
                                            disabled={
                                                deleteReason.trim() === ''
                                            }
                                        >
                                            Confirm delete
                                        </Button>
                                        <Button
                                            variant="outline"
                                            onClick={() =>
                                                setConfirmingDelete(false)
                                            }
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </div>
                            ) : (
                                <Button
                                    variant="destructive"
                                    className="self-start"
                                    onClick={() => setConfirmingDelete(true)}
                                >
                                    Delete card
                                </Button>
                            )}
                        </div>
                    )}
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
