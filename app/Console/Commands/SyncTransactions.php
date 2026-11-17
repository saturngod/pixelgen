<?php

namespace App\Console\Commands;

use App\Jobs\StoreGenerationImage;
use App\Models\Transaction;
use App\Services\PixelforgeService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('transactions:sync')]
#[Description('Check stuck generations against Pixelforge and retry missing image copies')]
class SyncTransactions extends Command
{
    /**
     * Stay well under the shared limit of 60 Pixelforge requests per minute.
     */
    private const int MaxSyncsPerRun = 20;

    /**
     * Execute the console command.
     */
    public function handle(PixelforgeService $pixelforge): int
    {
        $stuck = Transaction::query()->stuck()->oldest()->limit(self::MaxSyncsPerRun)->get();

        foreach ($stuck as $transaction) {
            $transaction->syncWithPixelforge($pixelforge);
        }

        $missingImages = Transaction::query()->missingImage()->pluck('id');

        foreach ($missingImages as $transactionId) {
            StoreGenerationImage::dispatch($transactionId);
        }

        $this->info("Checked {$stuck->count()} stuck generations and queued {$missingImages->count()} image copies.");

        return self::SUCCESS;
    }
}
