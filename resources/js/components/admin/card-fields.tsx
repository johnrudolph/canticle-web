import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcut';
import type { CardRecord, ColumnDef } from '@/types/admin';

interface CardFieldsProps {
    card: CardRecord | null;
    columns: ColumnDef[];
    morphType: string;
    storeUrl?: string;
    updateUrl: (id: number) => string;
    destroyUrl: (id: number) => string;
    commentStoreUrl: string;
    commentResolveUrl: (id: number) => string;
    onSaved?: () => void;
    onDeleted?: () => void;
    /** Keep the Cmd+S shortcut scoped to e.g. only firing while a modal is actually open. */
    shortcutsEnabled?: boolean;
}

function initialValueFor(col: ColumnDef, card: CardRecord | null): FormDataConvertible {
    if (col.type === 'multiselect') {
        // The only multiselect in practice is Locations' `costs`, backed
        // by 4 flat DB columns rather than one — everything else would
        // just read card[col.key] directly if that ever changes.
        if (col.key === 'costs' && card) {
            return JSON.stringify(
                [card.cost_1, card.cost_2, card.cost_3, card.cost_4].filter(
                    (v): v is string => typeof v === 'string' && v !== '',
                ),
            );
        }
        return JSON.stringify(card ? (card[col.key] as string[]) ?? [] : []);
    }

    if (card) return card[col.key] as FormDataConvertible;

    if (col.type === 'boolean') return col.defaultValue === true;
    if (col.type === 'select' && col.defaultValue) return col.defaultValue;

    return '';
}

export function CardFields({
    card,
    columns,
    morphType,
    storeUrl,
    updateUrl,
    destroyUrl,
    commentStoreUrl,
    commentResolveUrl,
    onSaved,
    onDeleted,
    shortcutsEnabled = true,
}: CardFieldsProps) {
    const allColumns: ColumnDef[] = [
        { key: 'print', label: 'Print', type: 'boolean', defaultValue: true },
        { key: 'name', label: 'Name', type: 'text' },
        ...columns,
    ];

    const [data, setData] = useState<Record<string, FormDataConvertible>>(() =>
        Object.fromEntries(allColumns.map((c) => [c.key, initialValueFor(c, card)])),
    );
    const [newComment, setNewComment] = useState('');
    const [deleteReason, setDeleteReason] = useState('');
    const [confirmingDelete, setConfirmingDelete] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    // The modal reuses this same component across different cards
    // without remounting — re-sync form state whenever the target card
    // actually changes (including switching to/from "new").
    useEffect(() => {
        setData(
            Object.fromEntries(allColumns.map((c) => [c.key, initialValueFor(c, card)])),
        );
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [card?.id]);

    function set(key: string, value: FormDataConvertible) {
        setData((prev) => ({ ...prev, [key]: value }));
    }

    function multiselectValue(key: string): string[] {
        const raw = data[key];
        return typeof raw === 'string' ? (JSON.parse(raw) as string[]) : [];
    }

    function toggleMultiselect(key: string, option: string) {
        const current = multiselectValue(key);
        const next = current.includes(option)
            ? current.filter((v) => v !== option)
            : [...current, option];
        set(key, JSON.stringify(next));
    }

    function save() {
        const payload: Record<string, FormDataConvertible> = {};
        for (const [key, value] of Object.entries(data)) {
            const col = allColumns.find((c) => c.key === key);
            payload[key] = col?.type === 'multiselect' ? JSON.parse(value as string) : value;
        }

        setErrors({});
        const options = {
            onSuccess: onSaved,
            onError: (errors: Record<string, string>) => setErrors(errors),
        };

        if (card) {
            router.put(updateUrl(card.id), payload, options);
        } else if (storeUrl) {
            router.post(storeUrl, payload, options);
        }
    }

    useKeyboardShortcut('s', save, shortcutsEnabled);

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
            onSuccess: onDeleted,
        });
    }

    return (
        <div className="flex flex-col gap-4">
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
                            <Label htmlFor={col.key}>{col.label}</Label>
                        </div>
                    ) : col.type === 'select' ? (
                        <>
                            <Label htmlFor={col.key}>{col.label}</Label>
                            <Select
                                value={
                                    data[col.key]
                                        ? String(data[col.key])
                                        : '__none__'
                                }
                                onValueChange={(value) =>
                                    set(col.key, value === '__none__' ? '' : value)
                                }
                            >
                                <SelectTrigger id={col.key}>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {col.allowNone !== false && (
                                        <SelectItem value="__none__">—</SelectItem>
                                    )}
                                    {(col.options ?? []).map((option) => (
                                        <SelectItem key={option} value={option}>
                                            {option}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </>
                    ) : col.type === 'multiselect' ? (
                        <>
                            <Label>{col.label}</Label>
                            <div className="flex flex-wrap gap-3">
                                {(col.options ?? []).map((option) => (
                                    <div
                                        key={option}
                                        className="flex items-center gap-1.5"
                                    >
                                        <Checkbox
                                            id={`${col.key}-${option}`}
                                            checked={multiselectValue(
                                                col.key,
                                            ).includes(option)}
                                            onCheckedChange={() =>
                                                toggleMultiselect(
                                                    col.key,
                                                    option,
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor={`${col.key}-${option}`}
                                            className="text-sm font-normal"
                                        >
                                            {option}
                                        </Label>
                                    </div>
                                ))}
                            </div>
                        </>
                    ) : (
                        <>
                            <Label htmlFor={col.key}>{col.label}</Label>
                            {col.type === 'textarea' ? (
                                <Textarea
                                    id={col.key}
                                    value={String(data[col.key] ?? '')}
                                    onChange={(e) => set(col.key, e.target.value)}
                                />
                            ) : (
                                <Input
                                    id={col.key}
                                    value={String(data[col.key] ?? '')}
                                    onChange={(e) => set(col.key, e.target.value)}
                                />
                            )}
                        </>
                    )}
                    {errors[col.key] && (
                        <p className="text-xs text-destructive">
                            {errors[col.key]}
                        </p>
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
                                    <Badge variant="outline" className="mb-1">
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
                                    onClick={() => resolveComment(comment.id)}
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
                            onChange={(e) => setNewComment(e.target.value)}
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
                                onChange={(e) => setDeleteReason(e.target.value)}
                            />
                            <div className="flex gap-2">
                                <Button
                                    variant="destructive"
                                    onClick={confirmDelete}
                                    disabled={deleteReason.trim() === ''}
                                >
                                    Confirm delete
                                </Button>
                                <Button
                                    variant="outline"
                                    onClick={() => setConfirmingDelete(false)}
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

            {Object.keys(errors).length > 0 && (
                <p className="text-sm text-destructive">
                    Couldn't save — see the field{Object.keys(errors).length > 1 ? 's' : ''} marked above.
                </p>
            )}

            <Button onClick={save} className="self-start">
                Save
            </Button>
        </div>
    );
}
