import MDEditor from '@uiw/react-md-editor';
import '@uiw/react-md-editor/markdown-editor.css';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { AdminNav } from '@/components/admin/admin-nav';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useKeyboardShortcut } from '@/hooks/use-keyboard-shortcut';
import rulebook from '@/routes/admin/rulebook';

export default function AdminRuleBook({ content }: { content: string }) {
    const [original, setOriginal] = useState(content);
    const [value, setValue] = useState(content);
    const [justifying, setJustifying] = useState(false);
    const [justification, setJustification] = useState('');

    function save() {
        if (value !== original) {
            setJustifying(true);
            return;
        }

        router.put(rulebook.update().url, { content: value });
    }

    function confirmSaveWithJustification() {
        if (justification.trim() === '') return;

        router.put(
            rulebook.update().url,
            { content: value, justification },
            {
                onSuccess: () => {
                    setOriginal(value);
                    setJustifying(false);
                    setJustification('');
                },
            },
        );
    }

    useKeyboardShortcut('s', save, !justifying);
    useKeyboardShortcut('s', confirmSaveWithJustification, justifying);

    return (
        <>
            <Head title="Rulebook" />
            <AdminNav />
            <div className="mx-auto flex max-w-4xl flex-col gap-4 p-4 py-12">
                <div className="flex items-center justify-between">
                    <h1 className="text-2xl font-semibold">Rulebook</h1>
                    <Button onClick={save}>Save</Button>
                </div>
                <MDEditor
                    value={value}
                    onChange={(next) => setValue(next ?? '')}
                    height={700}
                />
            </div>

            <Dialog open={justifying} onOpenChange={setJustifying}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Why is this rule changing?</DialogTitle>
                    </DialogHeader>
                    <div className="flex flex-col gap-2">
                        <Label htmlFor="justification">
                            This gets attached to the change so it can be
                            pulled into the PR that updates the actual game
                            logic to match.
                        </Label>
                        <Textarea
                            id="justification"
                            value={justification}
                            onChange={(e) =>
                                setJustification(e.target.value)
                            }
                            autoFocus
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            onClick={() => setJustifying(false)}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={confirmSaveWithJustification}
                            disabled={justification.trim() === ''}
                        >
                            Save change
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </>
    );
}
