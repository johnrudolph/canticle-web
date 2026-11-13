<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class SyncCardData extends Command
{
    protected $signature = 'canticle:sync-cards';

    protected $description = "Pull Canticle's card data tabs from the Google Sheet source of truth and write them to resources/data/canticle as JSON";

    public function handle(): int
    {
        $sheetId = config('canticle.sheet_id');
        $outputPath = config('canticle.output_path');

        File::ensureDirectoryExists($outputPath);

        foreach (config('canticle.sheets') as $sheet) {
            $this->line("Fetching \"{$sheet}\"...");

            $rows = $this->fetchSheet($sheetId, $sheet);

            File::put(
                "{$outputPath}/{$sheet}.json",
                json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n"
            );

            $this->info("  wrote {$sheet}.json (".count($rows).' rows)');
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, array<string, string|bool>>
     */
    private function fetchSheet(string $sheetId, string $sheetName): array
    {
        $response = Http::get("https://docs.google.com/spreadsheets/d/{$sheetId}/gviz/tq", [
            'tqx' => 'out:csv',
            'sheet' => $sheetName,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Failed to fetch sheet \"{$sheetName}\": HTTP {$response->status()}");
        }

        return $this->parseCsv($response->body());
    }

    /**
     * @return array<int, array<string, string|bool>>
     */
    private function parseCsv(string $csv): array
    {
        $stream = fopen('php://memory', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $header = fgetcsv($stream);

        if ($header === false) {
            fclose($stream);

            return [];
        }

        $rows = [];

        while (($row = fgetcsv($stream)) !== false) {
            if ($row === [null] || $row === ['']) {
                continue;
            }

            $record = [];

            foreach ($header as $index => $_) {
                $key = $header[$index] ?? (string) $index;
                $value = $row[$index] ?? '';

                $record[$key] = match ($value) {
                    'TRUE' => true,
                    'FALSE' => false,
                    default => $value,
                };
            }

            $rows[] = $record;
        }

        fclose($stream);

        return $rows;
    }
}
