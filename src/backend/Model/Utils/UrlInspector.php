<?php
namespace App\Model\Utils;

/**
 * Enkel URL-hjelper for validering, normalisering og "store name"-uttrekk.
 * - Normaliserer URL (tvinger https hvis skjema mangler, stripper fragmenter)
 * - Validerer vertnavn
 * - Forsøker å hente tittel fra toppnivå (https://host/) med liten timeout
 * - Faller tilbake til "pen" versjon av domenenavnet hvis tittel ikke er egnet
 * - Har overrides for kjente butikker (kort, konsist navn)
 */
class UrlInspector
{
    /** Kjente butikk-overstyringer. Nøkler matcher registrable domain (f.eks. elkjop.no) eller ender med punktum for wildcard (f.eks. amazon.). */
    private static array $OVERRIDES = [
        // Norge / Norden (legg gjerne til flere ved behov)
        'elkjop.no'        => 'Elkjøp',
        'komplett.no'      => 'Komplett',
        'power.no'         => 'POWER',
        'clasohlson.no'    => 'Clas Ohlson',
        'ikea.com'         => 'IKEA',
        'ikea.no'          => 'IKEA',
        'xxl.no'           => 'XXL',
        'cdon.no'          => 'CDON',
        'platekompaniet.no'=> 'Platekompaniet',
        'obs.no'           => 'Obs',
        'jollyroom.no'     => 'Jollyroom',
        'lekia.no'         => 'Lekia',
        'lego.com'         => 'LEGO',
        'ark.no'           => 'ARK',
        'norli.no'         => 'Norli',
        'adlibris.com'     => 'Adlibris',
        'tek.no'           => 'Tek.no',
        'megaflis.no'      => 'Megaflis.no',
        'dustinhome.no'    => 'DustinHome',
        'proshop.no'       => 'Proshop.no',

        // Internasjonale / wildcard
        'amazon.'          => 'Amazon',   // amazon.no / .com / .de ...
        'apple.com'        => 'Apple',
        'store.steampowered.com' => 'Steam',
        'ebay.'            => 'eBay',
        'aliexpress.'      => 'AliExpress',
    ];

    /** Normaliser URL. Returnerer null hvis ugyldig. */
    public static function normalize(?string $raw): ?string
    {
        if (!$raw) return null;
        $raw = trim($raw);
        if ($raw === '') return null;

        // Legg til https om mangler schema
        if (!preg_match('~^https?://~i', $raw)) {
            $raw = 'https://' . $raw;
        }

        $parts = parse_url($raw);
        if (!$parts || empty($parts['host'])) return null;

        $scheme = strtolower($parts['scheme'] ?? 'https');
        if (!in_array($scheme, ['http', 'https'], true)) return null;

        $host = strtolower($parts['host']);
        if (!self::isValidHost($host)) return null;

        // Bygg opp igjen, uten fragment
        $path   = $parts['path']   ?? '';
        $query  = isset($parts['query']) ? ('?' . $parts['query']) : '';
        $port   = isset($parts['port']) ? (':' . (int)$parts['port']) : '';
        $clean  = $scheme . '://' . $host . $port . $path . $query;

        return rtrim($clean);
    }

    /** Vurder om vertnavn er "greit nok". */
    public static function isValidHost(string $host): bool
    {
        if (strpos($host, '.') === false) return false;
        if ($host[0] === '.' || str_ends_with($host, '.')) return false;
        if (!filter_var('http://' . $host, FILTER_VALIDATE_URL)) return false;
        return true;
    }

    /** Hent "store name" fra URL. Bruker overrides først; så <title> på root; til slutt "pen" domenetekst. */
    public static function storeNameFromUrl(string $url): string
    {
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        $base = self::rootUrl($url); // scheme://host/

        // 0) Override via registrable domain (eller wildcard-suffix)
        $reg = self::registrableDomain($host);
        $override = self::matchOverride($host, $reg);
        if ($override !== null) {
            return $override;
        }

        // 1) Prøv å hente <title> fra hovedsiden (ikke produktlenken)
        $title = self::fetchSiteTitle($base);
        $title = self::cleanTitle($title);
        if ($title !== '') {
            return $title;
        }

        // 2) Fallback: pen versjon av domenet
        return self::prettyDomain($host);
    }

    /** Returner scheme://host/ */
    public static function rootUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? 'https');
        $host   = strtolower($parts['host'] ?? '');
        $port   = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
        return $scheme . '://' . $host . $port . '/';
    }

    /** Hent <title> fra gitt base-URL (kun toppside). */
    public static function fetchSiteTitle(string $rootUrl): string
    {
        if (!function_exists('curl_init')) return '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $rootUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT        => 3,
            CURLOPT_USERAGENT      => 'MyGiftsBot/1.0 (+https://example.invalid)',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RANGE          => '0-32767', // hent maks 32KB
        ]);
        $html = (string)curl_exec($ch);
        curl_close($ch);

        if ($html === '') return '';

        if (preg_match('~<title[^>]*>(.*?)</title>~is', $html, $m)) {
            $title = html_entity_decode(trim($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $title;
        }

        return '';
    }

    /** Renset/forenklet tittel: kutt vanlige suffixer, trim, kort ned. */
    public static function cleanTitle(string $title): string
    {
        $t = trim($title);
        if ($t === '') return '';

        $patterns = [
            ' - Nettbutikk', ' | Nettbutikk', ' – Nettbutikk',
            ' - Netbutikk',  ' | Netbutikk',  ' – Netbutikk',
            ' - Butikk',     ' | Butikk',     ' – Butikk',
            ' - Shop',       ' | Shop',       ' – Shop',
            ' - Store',      ' | Store',      ' – Store',
            ' - Official',   ' | Official',   ' – Official',
            ' - Offisiell',  ' | Offisiell',  ' – Offisiell',
            ' - Home',       ' | Home',       ' – Home',
            ' - Hjem',       ' | Hjem',       ' – Hjem',
            // vanlige "taglines" som ofte gjør titler lange
            ' - teknologi for en bedre hverdag',
        ];
        foreach ($patterns as $p) {
            if (stripos($t, $p) !== false) {
                $t = trim(str_ireplace($p, '', $t));
            }
        }

        if (mb_strlen($t, 'UTF-8') > 80) {
            $t = rtrim(mb_substr($t, 0, 80, 'UTF-8')) . '…';
        }

        if ($t === '' || mb_strlen($t, 'UTF-8') < 2) return '';
        return $t;
    }

    /** Gjør domenenavn mer menneskelig. */
    public static function prettyDomain(string $host): string
    {
        $h = strtolower(preg_replace('~^www\.~i', '', trim($host)));
        if ($h === '') return 'Store';

        $core = self::registrableDomain($h);
        if ($core === '') $core = $h;

        $core = str_replace(['-', '_'], ' ', $core);
        // Ta kun "navnedelen" uten TLD (enkelt kutt på siste punktum)
        $dotPos = strrpos($core, '.');
        if ($dotPos !== false) {
            $core = substr($core, 0, $dotPos);
        }

        $core = preg_replace_callback('~\b\p{L}~u', function ($m) {
            return mb_strtoupper($m[0], 'UTF-8');
        }, $core);

        return $core ?: 'Store';
    }

    /** Utleder "registrable domain" med enkel heuristikk (støtter noen vanlige 2-ledds TLD-er). */
    private static function registrableDomain(string $host): string
    {
        $h = strtolower(preg_replace('~^www\.~i', '', trim($host)));
        if ($h === '') return '';

        $parts = explode('.', $h);
        $n = count($parts);
        if ($n < 2) return $h;

        // Kjente 2-ledds public suffix (ikke komplett PSL, men dekker en del)
        $twoPartTlds = [
            'co.uk', 'ac.uk', 'gov.uk',
            'com.au','net.au','org.au',
            'co.nz', 'org.nz',
        ];
        $lastTwo = $parts[$n-2] . '.' . $parts[$n-1];
        if (in_array($lastTwo, $twoPartTlds, true) && $n >= 3) {
            return $parts[$n-3] . '.' . $lastTwo;
        }

        // Standard: siste to ledd
        return $lastTwo;
    }

    /** Sjekk override-tabellen: eksakt match på registrable domain, eller wildcard-suffix (nøkkel som slutter på "."). */
    private static function matchOverride(string $host, string $registrable): ?string
    {
        // 1) Eksakt match på registrable domain
        if (isset(self::$OVERRIDES[$registrable])) {
            return self::$OVERRIDES[$registrable];
        }

        // 2) Direkte host-match (inkl. subdomener)
        if (isset(self::$OVERRIDES[$host])) {
            return self::$OVERRIDES[$host];
        }

        // 3) Wildcard-suffix: nøkler som ender på '.'
        foreach (self::$OVERRIDES as $key => $val) {
            if (str_ends_with($key, '.')) {
                // matcher hvis host eller registrable slutter med nøkkelelementet
                if (str_ends_with($host, $key) || str_ends_with($registrable, rtrim($key, '.'))) {
                    return $val;
                }
            }
        }

        return null;
    }
}
