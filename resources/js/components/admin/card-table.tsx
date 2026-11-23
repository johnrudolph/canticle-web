import { useMemo, useRef, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcut';
import type { CardRecord, ColumnDef } from '@/types/admin';

interface CardTableProps {
    cards: CardRecord[];
    columns: ColumnDef[];
    onEdit: (card: CardRecord) => void;
    onNew: () => void;
}

function cellValue(card: CardRecord, key: string): string {
    const value = card[key];
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (value === null || value === undefined) return '';
    return String(value);
}

function unresolvedCount(card: CardRecord): number {
    return card.comments.filter((c) => c.resolved_at === null).length;
}

export function CardTable({ cards, columns, onEdit, onNew }: CardTableProps) {
    const [sortKey, setSortKey] = useState('name');
    const [sortDesc, setSortDesc] = useState(false);
    const [showDeleted, setShowDeleted] = useState(false);
    const [filters, setFilters] = useState<Record<string, string>>({});
    const [quickFilter, setQuickFilter] = useState('');
    const quickFilterRef = useRef<HTMLInputElement>(null);

    useKeyboardShortcut('f', () => quickFilterRef.current?.focus());

    const filterableColumns = columns.filter((c) => c.filterable);

    const filterOptions = useMemo(() => {
        const options: Record<string, string[]> = {};
        for (const col of filterableColumns) {
            const values = new Set<string>();
            for (const card of cards) {
                const v = cellValue(card, col.key);
                if (v !== '') values.add(v);
            }
            options[col.key] = Array.from(values).sort();
        }
        return options;
    }, [cards, filterableColumns]);

    const visible = useMemo(() => {
        let rows = cards;

        if (!showDeleted) {
            rows = rows.filter((c) => c.deleted_at === null);
        }

        const needle = quickFilter.trim().toLowerCase();
        if (needle !== '') {
            rows = rows.filter(
                (c) =>
                    c.name.toLowerCase().includes(needle) ||
                    c.key.toLowerCase().includes(needle),
            );
        }

        for (const [key, value] of Object.entries(filters)) {
            if (value && value !== '__all__') {
                rows = rows.filter((c) => cellValue(c, key) === value);
            }
        }

        rows = [...rows].sort((a, b) => {
            const av = cellValue(a, sortKey);
            const bv = cellValue(b, sortKey);
            const cmp = av.localeCompare(bv);
            return sortDesc ? -cmp : cmp;
        });

        return rows;
    }, [cards, filters, quickFilter, showDeleted, sortKey, sortDesc]);

    function toggleSort(key: string) {
        if (sortKey === key) {
            setSortDesc((prev) => !prev);
        } else {
            setSortKey(key);
            setSortDesc(false);
        }
    }

    const textColumn = columns.find((c) => c.showInTable);
    const headerColumns: ColumnDef[] = [
        { key: 'name', label: 'Name', type: 'text' },
        ...(textColumn ? [textColumn] : []),
        { key: 'print', label: 'Print', type: 'boolean' },
        ...columns.filter((c) => c.filterable),
    ];

    return (
        <div className="flex flex-col gap-4">
            <div className="flex flex-wrap items-end gap-3">
                <Button onClick={onNew}>New</Button>
                <div className="flex flex-col gap-1">
                    <span className="text-xs text-muted-foreground">
                        Filter (⌘F)
                    </span>
                    <Input
                        ref={quickFilterRef}
                        className="h-8 w-48"
                        placeholder="Name or key..."
                        value={quickFilter}
                        onChange={(e) => setQuickFilter(e.target.value)}
                    />
                </div>
                {filterableColumns.map((col) => (
                    <div key={col.key} className="flex flex-col gap-1">
                        <span className="text-xs text-muted-foreground">
                            {col.label}
                        </span>
                        <Select
                            value={filters[col.key] ?? '__all__'}
                            onValueChange={(value) =>
                                setFilters((prev) => ({
                                    ...prev,
                                    [col.key]: value,
                                }))
                            }
                        >
                            <SelectTrigger size="sm">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="__all__">All</SelectItem>
                                {filterOptions[col.key]?.map((value) => (
                                    <SelectItem key={value} value={value}>
                                        {value}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                ))}
                <div className="flex items-center gap-2 pb-2">
                    <Checkbox
                        id="show-deleted"
                        checked={showDeleted}
                        onCheckedChange={(checked) =>
                            setShowDeleted(checked === true)
                        }
                    />
                    <label htmlFor="show-deleted" className="text-sm">
                        Show deleted
                    </label>
                </div>
            </div>

            <div className="overflow-x-auto rounded-md border">
                <table className="w-full text-sm">
                    <thead>
                        <tr className="border-b bg-muted/50">
                            {headerColumns.map((col) => (
                                <th
                                    key={col.key}
                                    className={`cursor-pointer px-3 py-2 text-left font-medium select-none ${
                                        col.showInTable ? 'w-full min-w-xs' : 'whitespace-nowrap'
                                    }`}
                                    onClick={() => toggleSort(col.key)}
                                >
                                    {col.label}
                                    {sortKey === col.key
                                        ? sortDesc
                                            ? ' ↓'
                                            : ' ↑'
                                        : ''}
                                </th>
                            ))}
                            <th className="px-3 py-2 text-left font-medium">
                                Comments
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        {visible.map((card) => (
                            <tr
                                key={card.id}
                                className="cursor-pointer border-b last:border-0 hover:bg-accent"
                                onClick={() => onEdit(card)}
                            >
                                {headerColumns.map((col) => (
                                    <td
                                        key={col.key}
                                        className={`px-3 py-2 ${
                                            col.showInTable
                                                ? 'whitespace-normal'
                                                : 'whitespace-nowrap'
                                        }`}
                                    >
                                        {col.type === 'boolean' ? (
                                            cellValue(card, col.key) ===
                                            'Yes' ? (
                                                <Badge variant="secondary">
                                                    Yes
                                                </Badge>
                                            ) : (
                                                ''
                                            )
                                        ) : (
                                            cellValue(card, col.key)
                                        )}
                                    </td>
                                ))}
                                <td className="px-3 py-2">
                                    {unresolvedCount(card) > 0 && (
                                        <Badge variant="destructive">
                                            {unresolvedCount(card)} open
                                        </Badge>
                                    )}
                                    {card.deleted_at !== null && (
                                        <Badge
                                            variant="outline"
                                            className="ml-1"
                                        >
                                            deleted
                                        </Badge>
                                    )}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </div>
    );
}
