import type { RelicSummary } from '@/types/admin';

/** Counts per slot, per symbol (branch), and the slot x symbol matrix with row/column totals. */
export function RelicSlotTypeMatrix({ summary }: { summary: RelicSummary }) {
    const { slots, columns, matrix } = summary;

    const slotTotal = (slot: string) =>
        columns.reduce((sum, column) => sum + (matrix[slot]?.[column] ?? 0), 0);
    const columnTotal = (column: string) =>
        slots.reduce((sum, slot) => sum + (matrix[slot]?.[column] ?? 0), 0);
    const grandTotal = slots.reduce((sum, slot) => sum + slotTotal(slot), 0);

    return (
        <div className="overflow-x-auto rounded-md border">
            <table className="text-sm">
                <thead>
                    <tr className="border-b bg-muted/50">
                        <th className="px-3 py-2 text-left font-medium">
                            Slot \ Symbol
                        </th>
                        {columns.map((column) => (
                            <th
                                key={column}
                                className="px-3 py-2 text-left font-medium"
                            >
                                {column}
                            </th>
                        ))}
                        <th className="px-3 py-2 text-left font-medium">
                            Total
                        </th>
                    </tr>
                </thead>
                <tbody>
                    {slots.map((slot) => (
                        <tr key={slot} className="border-b last:border-0">
                            <td className="px-3 py-2 font-medium">
                                {slot === '' ? '(none)' : slot}
                            </td>
                            {columns.map((column) => (
                                <td key={column} className="px-3 py-2">
                                    {matrix[slot]?.[column] ?? 0}
                                </td>
                            ))}
                            <td className="px-3 py-2 font-medium">
                                {slotTotal(slot)}
                            </td>
                        </tr>
                    ))}
                    <tr className="bg-muted/50">
                        <td className="px-3 py-2 font-medium">Total</td>
                        {columns.map((column) => (
                            <td key={column} className="px-3 py-2 font-medium">
                                {columnTotal(column)}
                            </td>
                        ))}
                        <td className="px-3 py-2 font-medium">
                            {grandTotal}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    );
}
