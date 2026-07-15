<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SubsidiCron extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:subsidi-cron';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        Log::info("Cake Cron execution!");
        // call function in subsidicontroller
        $subsidi = new \App\Http\Controllers\SubsidiController();
        $subsidi->create();
        Log::info("Cake End execution!");
        $this->info('Cake:Cron Command is working fine!');
    }
}
