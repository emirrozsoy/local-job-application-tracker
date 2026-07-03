<?php

declare(strict_types=1);

namespace App\Services;

final class WebsiteEmailFinder
{
    public function findFirst(?string $url): ?string
    {
        $emails = $this->findAll($url);

        return $emails[0] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function findAll(?string $url): array
    {
        if ($url === null || trim($url) === '') {
            return [];
        }

        $urls = $this->candidateUrls($url);
        $found = [];

        foreach ($urls as $candidateUrl) {
            $html = $this->fetch($candidateUrl);

            if ($html === null) {
                continue;
            }

            foreach ($this->extractEmails($html) as $email) {
                $found[$email] = true;
            }
        }

        return array_keys($found);
    }

    /**
     * @return array<int, string>
     */
    private function extractEmails(string $html): array
    {
        $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $html = preg_replace('/\s*\[at\]\s*|\s*\(at\)\s*/i', '@', $html) ?? $html;
        $html = preg_replace('/\s*\[dot\]\s*|\s*\(dot\)\s*/i', '.', $html) ?? $html;

        preg_match_all('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $html, $matches);
        $emails = array_values(array_unique(array_map('strtolower', $matches[0] ?? [])));
        $blocked = [
            'example.com',
            'domain.com',
            'wixpress.com',
            'sentry.io',
            'schema.org',
            'wordpress.org',
            'yourdomain.com',
        ];
        $valid = [];

        foreach ($emails as $email) {
            $email = trim($email, " \t\n\r\0\x0B.,;:");
            [$localPart, $domainPart] = array_pad(explode('@', $email, 2), 2, '');
            $extension = strtolower((string)pathinfo($domainPart, PATHINFO_EXTENSION));

            if ($localPart === '' || $domainPart === '' || ctype_digit(str_replace('.', '', $domainPart))) {
                continue;
            }

            if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'css', 'js'], true)) {
                continue;
            }

            $isBlocked = false;

            foreach ($blocked as $domain) {
                if (str_ends_with($email, '@' . $domain) || str_contains($email, '.' . $domain)) {
                    $isBlocked = true;
                    break;
                }
            }

            if (!$isBlocked) {
                $valid[] = $email;
            }
        }

        return array_values(array_unique($valid));
    }

    /**
     * @return array<int, string>
     */
    private function candidateUrls(string $url): array
    {
        $normalized = $this->normalizeUrl($url);

        if ($normalized === null) {
            return [];
        }

        $parts = parse_url($normalized);
        $scheme = $parts['scheme'] ?? 'https';
        $host = $parts['host'] ?? '';

        if ($host === '') {
            return [$normalized];
        }

        $base = $scheme . '://' . $host;
        $paths = [
            '',
            '/iletisim',
            '/iletisim/',
            '/contact',
            '/contact-us',
            '/contact-us/',
            '/bize-ulasin',
            '/bize-ulasin/',
            '/kurumsal/iletisim',
        ];

        $urls = [$normalized];

        foreach ($paths as $path) {
            $urls[] = $base . $path;
        }

        return array_values(array_unique($urls));
    }

    private function normalizeUrl(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        return $url;
    }

    private function fetch(string $url): ?string
    {
        $ch = curl_init($url);

        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 YourAgency Job Application Bot/1.0',
        ]);

        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if (!is_string($body) || $body === '' || $httpCode >= 400) {
            return null;
        }

        return $body;
    }
}
