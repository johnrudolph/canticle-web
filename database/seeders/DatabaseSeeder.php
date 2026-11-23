<?php

namespace Database\Seeders;

use App\Models\Cards\RuleBook;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database. Also runs on every test suite
     * boot (see tests/Pest.php) — canticle:seed-cards, the admin user
     * upsert, and the rulebook import all need to be safe to run
     * repeatedly.
     */
    public function run(): void
    {
        Artisan::call('canticle:seed-cards');

        User::updateOrCreate(
            ['email' => 'hello@catacombian.com'],
            [
                'name' => 'Catacombian',
                'password' => Hash::make('Molina4lyfe'),
                'is_admin' => true,
                'is_guest' => false,
            ]
        );

        // Only fills the rulebook from rules/rules.md if it's still
        // empty — once someone's edited it via the admin editor, that
        // DB content is the source of truth and re-seeding must not
        // clobber it.
        $ruleBook = RuleBook::current();
        $rulesPath = base_path('rules/rules.md');

        if (($ruleBook->content === null || $ruleBook->content === '') && File::exists($rulesPath)) {
            $ruleBook->update(['content' => File::get($rulesPath)]);
        }
    }
}
