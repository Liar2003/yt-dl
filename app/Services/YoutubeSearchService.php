<?php

namespace App\Services;

use App\Core\Config;
use App\Helpers\Logger;

/**
 * YouTube search via the official YouTube Data API v3 (search.list).
 * Requires config('google_api_key') with the API enabled in Google
 * Cloud Console — this one IS a documented, stable API.
 */
class YoutubeSearchService
{
    private const API_URL = 'https://www.googleapis.com/youtube/v3/search';

    /** @return array{items: array<array{videoId:?string,title:string,channelTitle:string}>, nextPageToken: ?string} */
    public function search(string $query, int $maxResults = 10, ?string $pageToken = null): array
    {
        $params = [
            'part'       => 'snippet',
            'q'          => $query,
            'type'       => 'video',
            'maxResults' => $maxResults,
            'key'        => Config::get('google_api_key'),
        ];
        if ($pageToken) {
            $params['pageToken'] = $pageToken;
        }

        $ch = curl_init(self::API_URL . '?' . http_build_query($params));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $response = curl_exec($ch);
        if ($response === false) {
            Logger::write('error', 'YouTube search cURL error: ' . curl_error($ch), ['query' => $query]);
            curl_close($ch);
            return ['items' => [], 'nextPageToken' => null];
        }
        curl_close($ch);

        $data = json_decode($response, true);
        if (!isset($data['items'])) {
            Logger::write('warning', 'YouTube search API returned an error', ['query' => $query, 'response' => $data]);
            return ['items' => [], 'nextPageToken' => null];
        }

        $items = array_map(static fn(array $item) => [
            'videoId'      => $item['id']['videoId'] ?? null,
            'title'        => $item['snippet']['title'] ?? '',
            'channelTitle' => $item['snippet']['channelTitle'] ?? '',
        ], $data['items']);

        return [
            'items'         => $items,
            'nextPageToken' => $data['nextPageToken'] ?? null,
        ];
    }
}
