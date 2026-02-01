<?php

namespace App\Console\Commands;

use App\Models\ExchangeRate;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CheckFxRates extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rates:check';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check CediRates API and notify via WhatsApp on change';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Ghana Date (j-n-Y format based on your example 27-1-2026)
        $date = Carbon::now('Africa/Accra')->format('j-n-Y');
        $url = "https://api.cedirates.com/api/v1/exchangeRates/{$date}";

        $this->info("Checking URL: $url");

        try {
            $response = Http::get($url);

            if ($response->failed()) {
                $this->error("API Call Failed");
                return;
            }

            $data = $response->json();

            // Map the API response by 'url' (slug) for easy lookup
            $apiRates = collect($data['exchangeRates'])->keyBy(function ($item) {
                return $item['company']['url'];
            });

            $watchList = config('rates.watch_list');
            $currencyKey = config('rates.currency_key', 'dollarRates');

            foreach ($watchList as $slug) {
                if (!isset($apiRates[$slug])) {
                    $this->warn("Channel $slug not found in today's API response.");
                    continue;
                }

                $apiData = $apiRates[$slug];
                $channelName = $apiData['company']['companyName'];

                // Get new rates
                $newBuy = $apiData[$currencyKey]['buyingRate'];
                $newSell = $apiData[$currencyKey]['sellingRate'];

                // Get stored rates from DB
                $stored = ExchangeRate::firstOrNew(['channel_slug' => $slug]);

                // Check BUY Rate
                if ($stored->exists && $stored->buy_rate != $newBuy) {
                    $this->processChange($channelName, 'Buy', $stored->buy_rate, $newBuy, $stored, 'buy_rate');
                } else {
                    // Initial seed without notification if it's a new record
                    if (!$stored->exists) $stored->buy_rate = $newBuy;
                }

                // Check SELL Rate
                if ($stored->exists && $stored->sell_rate != $newSell) {
                    $this->processChange($channelName, 'Sell', $stored->sell_rate, $newSell, $stored, 'sell_rate');
                } else {
                    // Initial seed without notification if it's a new record
                    if (!$stored->exists) $stored->sell_rate = $newSell;
                }

                // Save name and slug if it's new
                $stored->channel_name = $channelName;
                $stored->save();
            }

        } catch (\Exception $e) {
            Log::error("Rate Check Error: " . $e->getMessage());
            $this->error($e->getMessage());
        }
    }

    private function processChange($channelName, $side, $oldRate, $newRate, $model, $columnToUpdate)
    {
        // Skip if rate is 0 or null (sometimes APIs return garbage data)
        if (empty($newRate)) return;

        $this->info("Rate change detected for $channelName ($side): $oldRate -> $newRate");

        // Attempt WhatsApp Message
        $sent = $this->sendWhatsApp($channelName, $side, $oldRate, $newRate);

        if ($sent) {
            // ONLY update DB if message sent successfully
            $model->$columnToUpdate = $newRate;
            $model->save();
            $this->info("Notification sent and DB updated.");
        } else {
            $this->error("Failed to send WhatsApp. DB not updated (will retry next run).");
        }
    }

    private function sendWhatsApp($channel, $side, $oldRate, $newRate)
    {
        $token = env('WHATSAPP_TOKEN');
        $phoneId = env('WHATSAPP_PHONE_ID');
        $to = env('WHATSAPP_TO_NUMBER');

        $payload = [
            "messaging_product" => "whatsapp",
            "recipient_type" => "individual",
            "to" => $to,
            "type" => "template",
            "template" => [
                "name" => "rates_monitor",
                "language" => ["code" => "en"],
                "components" => [
                    [
                        "type" => "header",
                        "parameters" => [
                            ["type" => "text", "text" => $channel] // {{1}} Header
                        ]
                    ],
                    [
                        "type" => "body",
                        "parameters" => [
                            ["type" => "text", "text" => $side],      // {{1}} Body
                            ["type" => "text", "text" => (string)$oldRate], // {{2}} Body
                            ["type" => "text", "text" => (string)$newRate], // {{3}} Body
                            ["type" => "text", "text" => $channel]   // {{4}} Body
                        ]
                    ]
                ]
            ]
        ];

        try {
            $response = Http::withToken($token)
                ->post("https://graph.facebook.com/v17.0/$phoneId/messages", $payload);

            if ($response->successful()) {
                return true;
            } else {
                Log::error("WhatsApp API Error: " . $response->body());
                return false;
            }
        } catch (\Exception $e) {
            Log::error("WhatsApp Exception: " . $e->getMessage());
            return false;
        }
    }
}
