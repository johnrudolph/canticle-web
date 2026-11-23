import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { CardEditModal } from '@/components/admin/card-edit-modal';
import { CardTable } from '@/components/admin/card-table';
import { GOLEM_CARD_COLUMNS } from '@/lib/admin-columns';
import comments from '@/routes/admin/comments';
import golemCards from '@/routes/admin/golem-cards';
import type { CardRecord } from '@/types/admin';

const COLUMNS = GOLEM_CARD_COLUMNS;

export default function AdminGolemCards({ cards }: { cards: CardRecord[] }) {
    const [editing, setEditing] = useState<CardRecord | null | 'new'>(null);

    return (
        <>
            <Head title="Golem Cards" />
            <AdminNav />
            <div className="mx-auto flex max-w-6xl flex-col gap-6 p-4 py-12">
                <h1 className="text-2xl font-semibold">Golem Cards</h1>
                <CardTable
                    cards={cards}
                    columns={COLUMNS}
                    onEdit={(card) => setEditing(card)}
                    onNew={() => setEditing('new')}
                />
            </div>
            <CardEditModal
                open={editing !== null}
                onClose={() => setEditing(null)}
                card={editing === 'new' ? null : editing}
                columns={COLUMNS}
                morphType="golem_card"
                storeUrl={golemCards.store().url}
                updateUrl={(id) => golemCards.update(id).url}
                destroyUrl={(id) => golemCards.destroy(id).url}
                commentStoreUrl={comments.store().url}
                commentResolveUrl={(id) => comments.resolve(id).url}
            />
        </>
    );
}
