<?php

declare(strict_types=1);

namespace App\Domain\Marketing\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class PhilippinesTrends
{
    public const RSS = 'https://trends.google.com/trending/rss?geo=PH';

    /** @return list<array{topic: string, source: string, checked_at: string}> */
    public function current(): array
    {
        try {
            $response = Http::connectTimeout(3)->timeout(5)->get(self::RSS);
        } catch (ConnectionException) {
            return [];
        }

        $xml = $response->successful()
            ? @simplexml_load_string($response->body(), \SimpleXMLElement::class, LIBXML_NONET)
            : false;

        if ($xml === false || ! isset($xml->channel)) {
            return [];
        }

        $checkedAt = now()->toIso8601String();
        $trends = [];

        foreach ($xml->channel->item as $item) {
            $topic = Str::limit(trim((string) $item->title), 150);
            if ($topic !== '') {
                $trends[] = ['topic' => $topic, 'source' => 'Google Trends Philippines', 'checked_at' => $checkedAt];
            }
            if (count($trends) >= 20) {
                break;
            }
        }

        return $trends;
    }
}
