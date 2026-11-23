import type { RelicSummary } from '@/types/admin';

/** Counts per slot, per type, and the slot x type matrix with row/column totals. */
export function RelicSlotTypeMatrix({ summary }: { summary: RelicSummary }) {
    const { slots, types, matrix } = summary;

    const slotTotal = (slot: string) =>
        types.reduce((sum, type) => sum + (matrix[slot]?.[type] ?? 0), 0);
    const typeTotal = (type: string) =>
        slots.reduce((sum, slot) => sum + (matrix[slot]?.[type] ?? 0), 0);
    const grandTotal = slots.reduce((sum, slot) => sum + slotTotal(slot), 0);

    return (
        <div className="overflow-x-auto rounded-md border">
            <table className="text-sm">
                <thead>
                    <tr className="border-b bg-muted/50">
                        <th className="px-3 py-2 text-left font-medium">
                            Slot \ Type
                        </th>
                        {types.map((type) => (
                            <th
                                key={type}
                                className="px-3 py-2 text-left font-medium"
                            >
                                {type}
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
                            {types.map((type) => (
                                <td key={type} className="px-3 py-2">
                                    {matrix[slot]?.[type] ?? 0}
                                </td>
                            ))}
                            <td className="px-3 py-2 font-medium">
                                {slotTotal(slot)}
                            </td>
                        </tr>
                    ))}
                    <tr className="bg-muted/50">
                        <td className="px-3 py-2 font-medium">Total</td>
                        {types.map((type) => (
                            <td key={type} className="px-3 py-2 font-medium">
                                {typeTotal(type)}
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
