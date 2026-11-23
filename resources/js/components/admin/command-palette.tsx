import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { Dialog, DialogContent } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcut';
import glyphs from '@/routes/admin/glyphs';
import golemCards from '@/routes/admin/golem-cards';
import locations from '@/routes/admin/locations';
import relics from '@/routes/admin/relics';

interface SearchItem {
    id: number;
    key: string;
    name: string;
    typeLabel: string;
    href: string;
}

type CardsApiResponse = Record<
    'locations' | 'relics' | 'glyphs' | 'golem_cards',
    { id: number; key: string; name: string }[]
>;

/**
 * Global ⌘K search across every card, mounted once in AdminNav so it's
 * available from any /admin page. Fetches the public, unauthenticated
 * GET /api/cards once per session (lazily, on first open) — it's the
 * same data the Figma plugin gets, nothing sensitive, and it's already
 * the full current card set with no extra endpoint needed.
 */
export function CommandPalette() {
    const [open, setOpen] = useState(false);
    const [query, setQuery] = useState('');
    const [selectedIndex, setSelectedIndex] = useState(0);
    const [items, setItems] = useState<SearchItem[] | null>(null);
    const inputRef = useRef<HTMLInputElement>(null);

    useKeyboardShortcut('k', () => setOpen((prev) => !prev));

    useEffect(() => {
        if (!open || items !== null) return;

        fetch('/api/cards')
            .then((res) => res.json() as Promise<CardsApiResponse>)
            .then((data) => {
                setItems([
                    ...data.locations.map((c) => toItem(c, 'Location', locations.show(c.id).url)),
                    ...data.relics.map((c) => toItem(c, 'Relic', relics.show(c.id).url)),
                    ...data.glyphs.map((c) => toItem(c, 'Glyph', glyphs.show(c.id).url)),
                    ...data.golem_cards.map((c) =>
                        toItem(c, 'Golem Card', golemCards.show(c.id).url),
                    ),
                ]);
            });
    }, [open, items]);

    useEffect(() => {
        if (open) {
            setQuery('');
            setSelectedIndex(0);
            // The Dialog's own open transition needs a tick before the
            // input actually exists/can take focus.
            setTimeout(() => inputRef.current?.focus(), 0);
        }
    }, [open]);

    const results = useMemo(() => {
        if (!items) return [];
        const needle = query.trim().toLowerCase();
        if (needle === '') return items.slice(0, 20);

        return items
            .filter(
                (item) =>
                    item.name.toLowerCase().includes(needle) ||
                    item.key.toLowerCase().includes(needle),
            )
            .slice(0, 20);
    }, [items, query]);

    useEffect(() => setSelectedIndex(0), [results]);

    function navigate(item: SearchItem | undefined) {
        if (!item) return;
        setOpen(false);
        router.visit(item.href);
    }

    function handleKeyDown(event: React.KeyboardEvent) {
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setSelectedIndex((i) => Math.min(i + 1, results.length - 1));
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setSelectedIndex((i) => Math.max(i - 1, 0));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            navigate(results[selectedIndex]);
        }
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogContent className="top-[20%] max-w-lg translate-y-0 gap-0 p-0">
                <Input
                    ref={inputRef}
                    value={query}
                    onChange={(e) => setQuery(e.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder="Search all cards..."
                    className="h-12 rounded-none border-0 border-b px-4 text-base shadow-none focus-visible:ring-0"
                />
                <div className="max-h-80 overflow-y-auto p-1">
                    {items === null && (
                        <p className="p-3 text-sm text-muted-foreground">
                            Loading...
                        </p>
                    )}
                    {items !== null && results.length === 0 && (
                        <p className="p-3 text-sm text-muted-foreground">
                            No matches.
                        </p>
                    )}
                    {results.map((item, index) => (
                        <button
                            key={`${item.typeLabel}-${item.id}`}
                            type="button"
                            className={`flex w-full items-center justify-between rounded-sm px-3 py-2 text-left text-sm ${
                                index === selectedIndex
                                    ? 'bg-accent'
                                    : 'hover:bg-accent/50'
                            }`}
                            onMouseEnter={() => setSelectedIndex(index)}
                            onClick={() => navigate(item)}
                        >
                            <span>{item.name}</span>
                            <span className="text-xs text-muted-foreground">
                                {item.typeLabel}
                            </span>
                        </button>
                    ))}
                </div>
            </DialogContent>
        </Dialog>
    );
}

function toItem(
    card: { id: number; key: string; name: string },
    typeLabel: string,
    href: string,
): SearchItem {
    return { id: card.id, key: card.key, name: card.name, typeLabel, href };
}
