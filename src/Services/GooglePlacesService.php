<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class GooglePlacesService
{
    private const TEXT_SEARCH_ENDPOINT = 'https://places.googleapis.com/v1/places:searchText';

    public function __construct(
        private string $apiKey,
        private PhoneFormatter $phoneFormatter,
        private string $languageCode = 'tr'
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $maxResults = 20): array
    {
        if ($this->apiKey === '' || $this->apiKey === 'YOUR_GOOGLE_PLACES_API_KEY') {
            throw new RuntimeException('Google Places API key is missing in config.php.');
        }

        $payload = [
            'textQuery' => trim($query),
            'languageCode' => $this->languageCode,
            'maxResultCount' => max(1, min(20, $maxResults)),
        ];

        $fieldMask = implode(',', [
            'places.id',
            'places.displayName',
            'places.formattedAddress',
            'places.nationalPhoneNumber',
            'places.internationalPhoneNumber',
            'places.websiteUri',
        ]);

        $response = $this->postJson(self::TEXT_SEARCH_ENDPOINT, $payload, $fieldMask);
        $places = is_array($response['places'] ?? null) ? $response['places'] : [];
        $places = [];

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }

            $placeId = (string)($place['id'] ?? '');
            $businessName = (string)($place['displayName']['text'] ?? '');

            if ($placeId === '' || $businessName === '') {
                continue;
            }

            $phone = $place['internationalPhoneNumber'] ?? $place['nationalPhoneNumber'] ?? null;
            $websiteUrl = is_string($place['websiteUri'] ?? null) && trim((string)$place['websiteUri']) !== ''
                ? trim((string)$place['websiteUri'])
                : null;

            $places[] = [
                'google_place_id' => $placeId,
                'business_name' => $businessName,
                'phone_number' => is_string($phone) ? trim($phone) : null,
                'whatsapp_phone' => $this->phoneFormatter->format(is_string($phone) ? $phone : null),
                'open_address' => isset($place['formattedAddress']) ? (string)$place['formattedAddress'] : null,
                'website_url' => $websiteUrl,
                'is_potential' => $websiteUrl === null ? 1 : 0,
            ];
        }

        return $places;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function postJson(string $url, array $payload, string $fieldMask): array
    {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException('Could not initialize cURL.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Goog-Api-Key: ' . $this->apiKey,
                'X-Goog-FieldMask: ' . $fieldMask,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 25,
        ]);

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($rawResponse === false) {
            throw new RuntimeException('Google Places cURL error: ' . $curlError);
        }

        $decoded = json_decode($rawResponse, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Google Places returned invalid JSON.');
        }

        if ($httpCode === 429) {
            throw new RuntimeException('Google Places API quota/rate limit exceeded.');
        }

        if ($httpCode >= 400) {
            $message = $decoded['error']['message'] ?? 'Unknown API error.';
            throw new RuntimeException(sprintf('Google Places API error HTTP %d: %s', $httpCode, $message));
        }

        return $decoded;
    }
}
