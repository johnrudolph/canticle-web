<?php

namespace App\Http\Controllers\Admin;

use App\Models\Cards\GolemCard;

class GolemCardController extends CardController
{
    protected function modelClass(): string
    {
        return GolemCard::class;
    }

    protected function morphType(): string
    {
        return 'golem_card';
    }

    protected function pageComponent(): string
    {
        return 'admin/golem-cards';
    }

    protected function detailPageComponent(): string
    {
        return 'admin/golem-card-detail';
    }

    protected function rules(): array
    {
        return [
            'print' => ['boolean'],
            'name' => ['required', 'string', 'max:255'],
            'action' => ['nullable', 'string'],
        ];
    }
}
