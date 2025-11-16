<?php
namespace App\Model\Utils;

/**
 * Henter produktinformasjon fra URL ved å parse Open Graph, Schema.org og Twitter Cards.
 */
class UrlMetadataExtractor
{
    /**
     * Henter metadata fra en URL.
     * Returnerer array med: title, description, image_url, price
     */
    public static function extract(string $url): array
    {
        $url = trim($url);
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \InvalidArgumentException('Invalid URL', 400);
        }

        // Hent HTML
        $html = self::fetchHtml($url);
        if (!$html) {
            error_log("[UrlMetadataExtractor] Failed to fetch: $url");
            throw new \RuntimeException('Could not fetch content from URL. The website may be blocking automated requests or experiencing issues.', 500);
        }

        if (strlen($html) < 100) {
            error_log("[UrlMetadataExtractor] HTML too short: $url (length: " . strlen($html) . ")");
            throw new \RuntimeException('Received incomplete response from URL', 500);
        }

        // Parse HTML
        $dom = new \DOMDocument();
        @$dom->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $xpath = new \DOMXPath($dom);

        $metadata = [
            'url' => $url,
            'title' => null,
            'description' => null,
            'image_url' => null,
            'price' => null,
        ];

        // 1. Prøv Open Graph (mest vanlig)
        $ogData = self::extractOpenGraph($xpath);
        $metadata = array_merge($metadata, $ogData);

        // 2. Prøv Schema.org JSON-LD
        if (!$metadata['title'] || !$metadata['image_url']) {
            $schemaData = self::extractSchemaOrg($xpath);
            foreach ($schemaData as $key => $value) {
                if (!$metadata[$key] && $value) {
                    $metadata[$key] = $value;
                }
            }
        }

        // 3. Prøv Twitter Cards
        if (!$metadata['title'] || !$metadata['image_url']) {
            $twitterData = self::extractTwitterCards($xpath);
            foreach ($twitterData as $key => $value) {
                if (!$metadata[$key] && $value) {
                    $metadata[$key] = $value;
                }
            }
        }

        // 4. Fallback til logo hvis vi ikke fant noe produktbilde
        if (!$metadata['image_url'] && isset($ogData['logo_image'])) {
            $metadata['image_url'] = $ogData['logo_image'];
        }

        // 4. Fallback til vanlige meta tags og title
        if (!$metadata['title']) {
            $titleNode = $xpath->query('//title')->item(0);
            $metadata['title'] = $titleNode ? trim($titleNode->textContent) : null;
        }

        if (!$metadata['description']) {
            $descNode = $xpath->query('//meta[@name="description"]/@content')->item(0);
            $metadata['description'] = $descNode ? trim($descNode->value) : null;
        }

        // Gjør image_url absolutt hvis den er relativ
        if ($metadata['image_url']) {
            $metadata['image_url'] = self::makeAbsoluteUrl($url, $metadata['image_url']);
        }

        return $metadata;
    }

    private static function fetchHtml(string $url): ?string
    {
        // Prøv først med cURL for bedre kompatibilitet
        if (function_exists('curl_init')) {
            // Parse URL for referer header
            $parsedUrl = parse_url($url);
            $referer = ($parsedUrl['scheme'] ?? 'https') . '://' . ($parsedUrl['host'] ?? '');

            // Prøv først med vanlig browser user-agent
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 25, // Økt timeout for trege nettsteder
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false, // Noen nettsteder har SSL-problemer
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1, // Tving HTTP/1.1 (noen sider har HTTP/2 problemer)
                CURLOPT_ENCODING => '', // Støtte for gzip/deflate
                CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8',
                    'Accept-Language: nb-NO,nb;q=0.9,no;q=0.8,nn;q=0.7,en-US;q=0.6,en;q=0.5',
                    'Accept-Encoding: gzip, deflate, br',
                    'DNT: 1',
                    'Connection: keep-alive',
                    'Upgrade-Insecure-Requests: 1',
                    'Sec-Fetch-Dest: document',
                    'Sec-Fetch-Mode: navigate',
                    'Sec-Fetch-Site: none',
                    'Referer: ' . $referer,
                    'Cache-Control: max-age=0',
                ],
            ]);

            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                error_log('[UrlMetadataExtractor] cURL error: ' . $error);
            }

            if ($httpCode >= 200 && $httpCode < 300 && $html && strlen($html) > 100) {
                return $html;
            }

            // Fallback: Prøv med Facebook crawler user-agent (mange sider tillater dette)
            error_log("[UrlMetadataExtractor] First attempt failed (HTTP {$httpCode}, HTML length: " . strlen($html ?: '') . "), trying with social media user-agent");
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => 25,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_ENCODING => '',
                CURLOPT_USERAGENT => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                CURLOPT_HTTPHEADER => [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: nb-NO,nb;q=0.9,en-US;q=0.8,en;q=0.7',
                ],
            ]);

            $html = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                error_log('[UrlMetadataExtractor] Social media fallback cURL error: ' . $error);
            }

            if ($httpCode >= 200 && $httpCode < 300 && $html) {
                return $html;
            }

            // Log why social media fallback failed
            error_log("[UrlMetadataExtractor] Social media fallback also failed (HTTP {$httpCode}, HTML length: " . strlen($html ?: '') . ")");
        }

        // Fallback til file_get_contents
        $context = stream_context_create([
            'http' => [
                'timeout' => 10,
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
                'follow_location' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ]);

        try {
            $html = @file_get_contents($url, false, $context);
            return $html ?: null;
        } catch (\Throwable $e) {
            error_log('[UrlMetadataExtractor] file_get_contents error: ' . $e->getMessage());
            return null;
        }
    }

    private static function extractOpenGraph(\DOMXPath $xpath): array
    {
        $data = [
            'title' => null,
            'description' => null,
            'image_url' => null,
            'price' => null,
            'logo_image' => null, // Lagre logo som fallback
        ];

        // Open Graph tags
        $ogMapping = [
            'og:title' => 'title',
            'og:description' => 'description',
            'og:image' => 'image_url',
            'product:price:amount' => 'price',
            'product:price' => 'price', // Alternativ
        ];

        foreach ($ogMapping as $property => $key) {
            $node = $xpath->query("//meta[@property='$property']/@content")->item(0);
            if ($node && !$data[$key]) {
                $value = trim($node->value);

                // Sjekk om det er logo
                if ($key === 'image_url' && self::isProbablyLogo($value)) {
                    // Lagre som fallback, men ikke bruk som primært bilde
                    if (!$data['logo_image']) {
                        $data['logo_image'] = $value;
                    }
                    continue;
                }

                $data[$key] = $value;
            }
        }

        // Prøv også Twitter-style product data
        $twitterPrice = $xpath->query("//meta[@name='twitter:data1']/@content")->item(0);
        if (!$data['price'] && $twitterPrice) {
            $priceText = trim($twitterPrice->value);
            // Ekstraher tall fra strenger som "kr 999" eller "999,-"
            if (preg_match('/[\d\s]+/', $priceText, $matches)) {
                $data['price'] = preg_replace('/\s+/', '', $matches[0]);
            }
        }

        return $data;
    }

    private static function isProbablyLogo(string $imageUrl): bool
    {
        $lower = strtolower($imageUrl);
        $logoIndicators = ['logo', 'icon', 'favicon', 'brand', '/assets/images/logo'];

        foreach ($logoIndicators as $indicator) {
            if (strpos($lower, $indicator) !== false) {
                return true;
            }
        }

        return false;
    }

    private static function extractSchemaOrg(\DOMXPath $xpath): array
    {
        $data = [
            'title' => null,
            'description' => null,
            'image_url' => null,
            'price' => null,
        ];

        // Finn alle JSON-LD scripts
        $scripts = $xpath->query('//script[@type="application/ld+json"]');
        foreach ($scripts as $script) {
            $json = json_decode($script->textContent, true);
            if (!$json) continue;

            // Håndter @graph array
            $items = isset($json['@graph']) ? $json['@graph'] : [$json];

            foreach ($items as $item) {
                if (!isset($item['@type'])) continue;

                // Sjekk om det er Product type
                $type = is_array($item['@type']) ? $item['@type'] : [$item['@type']];
                if (in_array('Product', $type)) {
                    if (!$data['title'] && isset($item['name'])) {
                        $data['title'] = $item['name'];
                    }
                    if (!$data['description'] && isset($item['description'])) {
                        $data['description'] = $item['description'];
                    }
                    if (!$data['image_url'] && isset($item['image'])) {
                        $img = is_array($item['image']) ? ($item['image'][0] ?? null) : $item['image'];
                        $imgUrl = is_string($img) ? $img : ($img['url'] ?? null);

                        // Filtrer bort logo-bilder
                        if ($imgUrl && !self::isProbablyLogo($imgUrl)) {
                            $data['image_url'] = $imgUrl;
                        }
                    }
                    if (!$data['price'] && isset($item['offers'])) {
                        $offers = is_array($item['offers']) ? $item['offers'][0] : $item['offers'];
                        $price = $offers['price'] ?? $offers['lowPrice'] ?? null;

                        // Håndter pris som kan være string eller number
                        if ($price) {
                            $data['price'] = is_numeric($price) ? (string)$price : $price;
                        }
                    }
                }
            }
        }

        return $data;
    }

    private static function extractTwitterCards(\DOMXPath $xpath): array
    {
        $data = [
            'title' => null,
            'description' => null,
            'image_url' => null,
            'price' => null,
        ];

        $twitterMapping = [
            'twitter:title' => 'title',
            'twitter:description' => 'description',
            'twitter:image' => 'image_url',
        ];

        foreach ($twitterMapping as $name => $key) {
            $node = $xpath->query("//meta[@name='$name']/@content")->item(0);
            if ($node) {
                $data[$key] = trim($node->value);
            }
        }

        return $data;
    }

    private static function makeAbsoluteUrl(string $baseUrl, string $url): string
    {
        // Allerede absolutt URL
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';

        // Protocol-relative URL (//example.com/image.jpg)
        if (strpos($url, '//') === 0) {
            return $scheme . ':' . $url;
        }

        // Absolute path (/images/product.jpg)
        if (strpos($url, '/') === 0) {
            return $scheme . '://' . $host . $url;
        }

        // Relative path (images/product.jpg)
        $path = $base['path'] ?? '/';
        $dir = dirname($path);
        return $scheme . '://' . $host . $dir . '/' . $url;
    }
}
