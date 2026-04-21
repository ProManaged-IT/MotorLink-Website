<?php
/**
 * scrape_v2.php — Expanded MotorLink Malawi Business Discovery + Enrichment
 *
 * Improvements over v1:
 *  - 50+ Malawi cities/towns (all major districts + sub-areas)
 *  - Google Places Nearby Search (GPS-radius) PLUS Text Search
 *  - Higher targets: 500 dealers / 400 garages / 300 car_hire
 *  - Better website scraping: JSON-LD sameAs, og: meta, phone + WhatsApp from text
 *  - Enriches ALL missing fields: phone, website, facebook, instagram, twitter,
 *    linkedin, whatsapp, logo — including syncing phone back to users table
 *  - --type=dealer|garage|car_hire  — process a single type
 *  - --enrich-limit=N              — cap enrichment batch size (default 1000)
 *  - Separate cache: _scrape_v2_cache.json (won't conflict with v1)
 *
 * Usage:
 *   php scripts/scrape_v2.php                       # Full run
 *   php scripts/scrape_v2.php --refresh             # Re-discover (new cache)
 *   php scripts/scrape_v2.php --enrich-only         # Phase 3 only
 *   php scripts/scrape_v2.php --insert-only         # Phase 2 only
 *   php scripts/scrape_v2.php --type=garage         # Single type only
 *   php scripts/scrape_v2.php --enrich-limit=200    # Limit enrichment rows
 *
 * Safe to run multiple times — duplicate skips are idempotent.
 */

declare(strict_types=1);
chdir(dirname(__DIR__));
require 'api-common.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
@ob_end_flush();
ob_implicit_flush(true);
set_time_limit(0);

// ── CLI ARGS ─────────────────────────────────────────────────────────────────
$args        = array_slice($argv ?? [], 1);
$doRefresh   = in_array('--refresh',      $args, true);
$insertOnly  = in_array('--insert-only',  $args, true);
$enrichOnly  = in_array('--enrich-only',  $args, true);
$enrichLimit = 1000;
$onlyType    = null;
$jobId       = null;
foreach ($args as $a) {
    if (preg_match('/^--type=(.+)$/',         $a, $m)) $onlyType    = strtolower(trim($m[1]));
    if (preg_match('/^--enrich-limit=(\d+)$/', $a, $m)) $enrichLimit = max(1, (int)$m[1]);
    if (preg_match('/^--job-id=([a-zA-Z0-9_\-]+)$/', $a, $m)) $jobId = $m[1];
}

// ── JOB TRACKING ─────────────────────────────────────────────────────────────
$jobsDir  = __DIR__ . '/scrape_jobs';
$jobFile  = $jobId ? $jobsDir . '/' . $jobId . '.json' : null;
$jobState = [];  // in-memory mirror of the JSON job file
$logTail  = [];  // last 100 lines

if ($jobFile && !is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);

/**
 * Write current job state to JSON file.
 * Also checks stop_signal so the scraper can be halted from the admin UI.
 */
function updateJob(array $patch): void
{
    global $jobFile, $jobState, $logTail;
    if (!$jobFile) return;

    // Merge patch into state
    foreach ($patch as $k => $v) $jobState[$k] = $v;
    $jobState['log_tail']   = array_slice($logTail, -100);
    $jobState['updated_at'] = date('Y-m-d H:i:s');

    // Read existing file to preserve fields we don't own (e.g. stop_signal from API)
    if (file_exists($jobFile)) {
        $existing = json_decode((string)file_get_contents($jobFile), true) ?: [];
        // Honour stop_signal written by API
        if (!empty($existing['stop_signal'])) $jobState['stop_signal'] = true;
        // Preserve 'before' snapshot written by API
        if (!empty($existing['before']) && empty($jobState['before'])) {
            $jobState['before'] = $existing['before'];
        }
    }

    file_put_contents($jobFile, json_encode($jobState, JSON_PRETTY_PRINT));
}

/** Append a line to the log tail and emit to stdout. */
function jlog(string $line): void
{
    global $logTail;
    $logTail[] = date('H:i:s') . ' ' . $line;
    echo $line . "\n";
}

/** Check stop signal — call between expensive iterations. */
function checkStop(): bool
{
    global $jobFile;
    if (!$jobFile || !file_exists($jobFile)) return false;
    $j = json_decode((string)file_get_contents($jobFile), true);
    return !empty($j['stop_signal']);
}

// Seed initial running state (only if job file already exists from API)
if ($jobFile && file_exists($jobFile)) {
    $existing   = json_decode((string)file_get_contents($jobFile), true) ?: [];
    $jobState   = $existing;
    $jobState['status'] = 'running';
    $jobState['phase']  = 0;
    $jobState['phase_label'] = 'Starting up...';
    file_put_contents($jobFile, json_encode($jobState, JSON_PRETTY_PRINT));
}

// ── DB ───────────────────────────────────────────────────────────────────────
function freshDb(): PDO
{
    return new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 30,
        ]
    );
}

$db = freshDb();

// ── API KEY ──────────────────────────────────────────────────────────────────
$stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'google_maps_api_key'");
$stmt->execute();
$apiKey = trim((string)($stmt->fetchColumn() ?: ''));
if (!$apiKey) {
    updateJob(['status' => 'error', 'error' => 'google_maps_api_key not in site_settings']);
    die("ERROR: google_maps_api_key not in site_settings.\n");
}
jlog("API key loaded.");

// ── TARGETS ──────────────────────────────────────────────────────────────────
$TARGETS = ['dealer' => 500, 'garage' => 400, 'car_hire' => 300];

// ── CITIES — Primary (searched every query template first) ───────────────────
$CITIES_PRIMARY = ['Lilongwe', 'Blantyre', 'Mzuzu', 'Zomba'];

// ── CITIES — Secondary (all major districts + urban sub-areas) ───────────────
$CITIES_SECONDARY = [
    // Central Region
    'Kasungu', 'Salima', 'Dedza', 'Mchinji', 'Nkhotakota', 'Ntchisi',
    'Dowa', 'Ntcheu', 'Balaka',
    // Lilongwe urban areas
    'Kanengo Lilongwe', 'Area 18 Lilongwe', 'Area 25 Lilongwe',
    'Area 47 Lilongwe', 'Biwi Lilongwe', 'Kawale Lilongwe',
    'Area 30 Lilongwe', 'Crossroads Lilongwe',
    // Southern Region
    'Mangochi', 'Liwonde', 'Thyolo', 'Mulanje', 'Chiradzulu',
    'Chikwawa', 'Nsanje', 'Phalombe', 'Machinga', 'Monkey Bay',
    'Limbe', 'Chileka',
    // Blantyre urban areas
    'Industrial Area Blantyre', 'Naperi Blantyre', 'Bangwe Blantyre',
    'Ndirande Blantyre', 'Sunnyside Blantyre', 'Kabula Blantyre',
    'Chirimba Blantyre',
    // Northern Region
    'Karonga', 'Nkhata Bay', 'Rumphi', 'Chitipa', 'Mzimba', 'Ekwendeni',
    // Mzuzu sub-areas
    'Chibanja Mzuzu', 'Katoto Mzuzu',
];

// ── CITY COORDINATES for Nearby Search (lat, lng) ────────────────────────────
$CITY_COORDS = [
    'Lilongwe'    => [-13.9669,  33.7873],
    'Blantyre'    => [-15.7867,  35.0045],
    'Mzuzu'       => [-11.4658,  34.0209],
    'Zomba'       => [-15.3833,  35.3167],
    'Kasungu'     => [-13.0000,  33.4667],
    'Mangochi'    => [-14.4667,  35.2500],
    'Salima'      => [-13.7833,  34.4500],
    'Karonga'     => [ -9.9333,  33.9333],
    'Nkhotakota'  => [-12.9167,  34.2500],
    'Dedza'       => [-14.3667,  34.3333],
    'Mchinji'     => [-13.7983,  32.8867],
    'Ntcheu'      => [-14.8167,  34.6333],
    'Balaka'      => [-14.9833,  34.9667],
    'Liwonde'     => [-15.0667,  35.2333],
    'Thyolo'      => [-16.0700,  35.1400],
    'Mulanje'     => [-16.0333,  35.4667],
    'Nsanje'      => [-16.9225,  35.2619],
    'Chikwawa'    => [-16.0333,  34.8000],
    'Chiradzulu'  => [-15.6667,  35.1667],
    'Nkhata Bay'  => [-11.6000,  34.2833],
    'Rumphi'      => [-10.7833,  33.8500],
    'Chitipa'     => [ -9.7050,  33.2700],
    'Limbe'       => [-15.8333,  35.0500],
    'Monkey Bay'  => [-14.0833,  34.9167],
    'Mzimba'      => [-11.9000,  33.6000],
    'Machinga'    => [-15.0000,  35.5833],
    'Phalombe'    => [-15.8000,  35.6500],
    'Dowa'        => [-13.6500,  33.9333],
    'Ntchisi'     => [-13.3667,  33.9167],
    'Ekwendeni'   => [-11.5667,  33.9667],
];

// ── QUERY TEMPLATES ──────────────────────────────────────────────────────────
$QUERY_TEMPLATES = [
    'dealer' => [
        'car dealer in %s Malawi',
        'used car dealer %s Malawi',
        'car showroom %s Malawi',
        'vehicle sales %s Malawi',
        'car sales %s Malawi',
        'second hand cars %s Malawi',
        'Japanese used cars %s Malawi',
        'imported vehicles %s Malawi',
        'Toyota dealer %s Malawi',
        'Nissan dealer %s Malawi',
        'Mazda dealer %s Malawi',
        'pickup truck sales %s Malawi',
        'car yard %s Malawi',
        'auto sales %s Malawi',
        'vehicle agency %s Malawi',
        'car mart %s Malawi',
        'motor vehicles %s Malawi',
        'pre-owned vehicles %s Malawi',
        'car bazaar %s Malawi',
        'auto dealer %s Malawi',
    ],
    'garage' => [
        'car garage %s Malawi',
        'auto repair %s Malawi',
        'mechanic shop %s Malawi',
        'car workshop %s Malawi',
        'vehicle service center %s Malawi',
        'panel beater %s Malawi',
        'tyre shop %s Malawi',
        'car parts %s Malawi',
        'auto electrical %s Malawi',
        'engine repair %s Malawi',
        'car service station %s Malawi',
        'exhaust repair %s Malawi',
        'auto spares %s Malawi',
        'transmission repair %s Malawi',
        'car body shop %s Malawi',
        'windscreen replacement %s Malawi',
        'battery shop %s Malawi',
        'car air conditioning %s Malawi',
        'oil change service %s Malawi',
        'car accessories shop %s Malawi',
    ],
    'car_hire' => [
        'car hire %s Malawi',
        'car rental %s Malawi',
        'rent a car %s Malawi',
        'vehicle hire %s Malawi',
        'self drive hire %s Malawi',
        'chauffeur service %s Malawi',
        'airport transfers %s Malawi',
        'wedding car hire %s Malawi',
        'minibus hire %s Malawi',
        'van hire %s Malawi',
        'truck hire %s Malawi',
        '4x4 hire Malawi',
        'safari vehicle hire Malawi',
        'bus hire Malawi',
        'corporate car hire Malawi',
        'staff transport hire Malawi',
        'limousine hire Malawi',
    ],
];

// ── NEARBY SEARCH TYPE KEYWORDS ──────────────────────────────────────────────
$NEARBY_KEYWORDS = [
    'dealer'   => 'car dealership',
    'garage'   => 'auto repair garage',
    'car_hire' => 'car rental',
];

// ── LOCATION MAP ─────────────────────────────────────────────────────────────
$LOCATIONS           = [];
$DEFAULT_LOCATION_ID = 1;
$stmt = $db->query("SELECT id, name FROM locations WHERE is_active = 1");
foreach ($stmt->fetchAll() as $r) {
    $LOCATIONS[strtolower($r['name'])] = (int)$r['id'];
    if (strtolower($r['name']) === 'lilongwe') $DEFAULT_LOCATION_ID = (int)$r['id'];
}
if (!$DEFAULT_LOCATION_ID && $LOCATIONS) $DEFAULT_LOCATION_ID = array_values($LOCATIONS)[0];

// ═════════════════════════════════════════════════════════════════════════════
// HELPERS
// ═════════════════════════════════════════════════════════════════════════════

function gGet(string $url): ?array
{
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'ignore_errors' => true],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $raw = @file_get_contents($url, false, $ctx);
    return ($raw !== false) ? json_decode($raw, true) : null;
}

function cityFromAddress(string $addr): string
{
    global $CITIES_PRIMARY, $CITIES_SECONDARY;
    $all = array_merge($CITIES_PRIMARY, $CITIES_SECONDARY);
    // Longer names first to avoid "Zomba" matching before "Nkhata Bay"
    usort($all, fn($a, $b) => strlen($b) - strlen($a));
    foreach ($all as $c) {
        if (stripos($addr, $c) !== false) return $c;
    }
    return 'Lilongwe';
}

function locationIdFor(string $city): int
{
    global $LOCATIONS, $DEFAULT_LOCATION_ID;
    $key = strtolower(trim($city));
    if (isset($LOCATIONS[$key])) return $LOCATIONS[$key];
    // Word-by-word fallback: "Area 18 Lilongwe" → "lilongwe"
    foreach (array_reverse(explode(' ', $key)) as $w) {
        if (strlen($w) > 3 && isset($LOCATIONS[$w])) return $LOCATIONS[$w];
    }
    return $DEFAULT_LOCATION_ID;
}

function uniqueUsername(PDO $db, string $name): string
{
    $base  = substr(trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]/i', '_', strtolower($name))), '_'), 0, 28) ?: 'biz';
    $u     = $base;
    $i     = 2;
    $check = $db->prepare("SELECT 1 FROM users WHERE username = ?");
    while (true) {
        $check->execute([$u]);
        if (!$check->fetchColumn()) return $u;
        $u = substr($base, 0, 24) . '_' . $i++;
        if ($i > 9999) return $base . '_' . substr(md5((string)mt_rand()), 0, 6);
    }
}

function randPwd(int $n = 12): string
{
    $chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789!@#';
    $out   = '';
    for ($i = 0; $i < $n; $i++) $out .= $chars[random_int(0, strlen($chars) - 1)];
    return $out;
}

/**
 * Fetch a website and extract:
 *  - Social links: facebook, instagram, twitter, linkedin
 *  - WhatsApp from wa.me links and plain-text patterns
 *  - Phone number from plain text (Malawi formats: +265xxx, 0888xxx, 0999xxx)
 *
 * Checks: href/content/data-* attributes, JSON-LD sameAs, og: meta tags,
 * and raw page text.
 */
function extractFromWebsite(string $websiteUrl): array
{
    $out = [
        'facebook'  => '',
        'instagram' => '',
        'twitter'   => '',
        'linkedin'  => '',
        'whatsapp'  => '',
        'phone'     => '',
    ];
    if (!$websiteUrl) return $out;
    if (!preg_match('#^https?://#i', $websiteUrl)) $websiteUrl = 'https://' . $websiteUrl;

    $ctx = stream_context_create([
        'http' => [
            'timeout'         => 10,
            'follow_location' => 1,
            'max_redirects'   => 4,
            'ignore_errors'   => true,
            'user_agent'      => 'Mozilla/5.0 (compatible; MotorLinkBot/2.0)',
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $html = @file_get_contents($websiteUrl, false, $ctx);
    if (!$html || strlen($html) < 50) return $out;

    // ── All attribute candidates: href, content, data-href/url/link ──────────
    preg_match_all('/(?:href|content|data-(?:href|url|link|share-url))=["\']([^"\'#\s]{5,300})["\']/', $html, $attrM);
    $candidates = $attrM[1] ?? [];

    foreach ($candidates as $raw) {
        $href = html_entity_decode(trim($raw));

        if (!$out['facebook'] && preg_match(
            '#(?:facebook\.com|fb\.com|fb\.me)/(?!sharer|share|dialog|tr\b|plugins|video\.php|photo\.php|live|events/\d|ads/|help/)([a-zA-Z0-9_.]{2,80})#i',
            $href, $fm
        )) {
            $out['facebook'] = 'https://www.facebook.com/' . $fm[1];
        }

        if (!$out['instagram'] && preg_match(
            '#instagram\.com/(?!p/|reel/|explore/|stories/|tv/)([a-zA-Z0-9_.]{2,60})/?#i',
            $href, $im
        )) {
            $out['instagram'] = 'https://www.instagram.com/' . rtrim($im[1], '/');
        }

        if (!$out['twitter'] && preg_match(
            '#(?:twitter\.com|x\.com)/(?!intent/|share|home|search|hashtag|explore|i/)([a-zA-Z0-9_]{1,50})/?#i',
            $href, $tm
        )) {
            $out['twitter'] = 'https://twitter.com/' . $tm[1];
        }

        if (!$out['linkedin'] && preg_match(
            '#linkedin\.com/(?:company|in)/([^/\?\s"\'&]{1,80})#i',
            $href, $lm
        )) {
            $out['linkedin'] = 'https://www.linkedin.com/'
                . (stripos($href, '/in/') !== false ? 'in/' : 'company/')
                . $lm[1];
        }

        if (!$out['whatsapp'] && preg_match(
            '#(?:wa\.me|api\.whatsapp\.com/send)[/\?](?:phone=)?(\+?[0-9]{7,15})#i',
            $href, $wm
        )) {
            $out['whatsapp'] = 'https://wa.me/' . ltrim($wm[1], '+');
        }
    }

    // ── JSON-LD sameAs ────────────────────────────────────────────────────────
    preg_match_all('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/si', $html, $ldM);
    foreach ($ldM[1] ?? [] as $ldBlock) {
        $ld = @json_decode($ldBlock, true);
        if (!is_array($ld)) continue;
        // Handle both single object and @graph array
        $nodes = isset($ld[0]) ? $ld : [$ld];
        foreach ($nodes as $node) {
            $sameAs = $node['sameAs'] ?? [];
            if (!is_array($sameAs)) $sameAs = [$sameAs];
            foreach ($sameAs as $u) {
                if (!is_string($u)) continue;
                if (!$out['facebook']  && stripos($u, 'facebook.com')  !== false) $out['facebook']  = $u;
                if (!$out['instagram'] && stripos($u, 'instagram.com') !== false) $out['instagram'] = $u;
                if (!$out['twitter']   && (stripos($u, 'twitter.com') !== false || stripos($u, 'x.com') !== false)) $out['twitter'] = $u;
                if (!$out['linkedin']  && stripos($u, 'linkedin.com')  !== false) $out['linkedin']  = $u;
            }
        }
    }

    // ── Plain-text phone + WhatsApp extraction ────────────────────────────────
    $text = strip_tags($html);

    // Malawi phone: +265 x xxxx xxxx  or  0888 xxx xxx  or  0999 xxx xxx
    if (!$out['phone']) {
        if (preg_match('/\+265[\s\-]?(?:1|2|7|8|9)\s?\d[\d\s\-]{5,12}\d/', $text, $pm)) {
            $out['phone'] = preg_replace('/\s{2,}/', ' ', trim($pm[0]));
        } elseif (preg_match('/\b0(?:1\d{2}|2\d{2}|888|880|88\d|99\d|97\d|98\d)[\s\-]?\d{3}[\s\-]?\d{3}\b/', $text, $pm)) {
            $out['phone'] = trim($pm[0]);
        }
    }

    // WhatsApp from text: "WhatsApp: +265 ..." or "wa: 0999..."
    if (!$out['whatsapp']) {
        if (preg_match('/[Ww]hats[Aa]pp[:\s]+(\+?265[\s\-\d]{9,14}|0[89\d][\d\s\-]{8,11})/', $text, $wpm)) {
            $num = preg_replace('/[^0-9+]/', '', $wpm[1]);
            if (strlen($num) >= 9) {
                if (!str_starts_with($num, '+')) $num = '+265' . ltrim($num, '0');
                $out['whatsapp'] = 'https://wa.me/' . ltrim($num, '+');
            }
        }
    }

    return $out;
}

/**
 * Download a Google Places photo to uploads/business_logos/.
 * Returns relative path or null.
 */
function downloadPlacePhoto(string $photoRef, string $apiKey, string $slug): ?string
{
    $url = 'https://maps.googleapis.com/maps/api/place/photo'
         . '?maxwidth=600&photo_reference=' . urlencode($photoRef)
         . '&key=' . urlencode($apiKey);
    $ctx = stream_context_create([
        'http' => ['timeout' => 25, 'follow_location' => 1, 'max_redirects' => 5,
                   'ignore_errors' => true, 'user_agent' => 'MotorLinkMalawi/2.0'],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || strlen($body) < 200) return null;

    $type = '';
    foreach ($http_response_header ?? [] as $h) {
        if (stripos($h, 'content-type:') === 0) { $type = trim(substr($h, 13)); break; }
    }
    if (!$type || stripos($type, 'image/') !== 0) {
        $sig = substr($body, 0, 4);
        if (substr($sig, 0, 3) === "\xFF\xD8\xFF") $type = 'image/jpeg';
        elseif ($sig === "\x89PNG")                $type = 'image/png';
        elseif (substr($body, 0, 4) === 'RIFF')    $type = 'image/webp';
        else return null;
    }
    $ext  = stripos($type, 'png') !== false ? 'png' : (stripos($type, 'webp') !== false ? 'webp' : 'jpg');
    $dir  = __DIR__ . '/../uploads/business_logos';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $file = $dir . '/' . $slug . '_' . substr(md5($photoRef), 0, 8) . '.' . $ext;
    if (file_put_contents($file, $body) === false) return null;
    return 'uploads/business_logos/' . basename($file);
}

function buildDescription(string $name, string $city, string $website, array $soc, string $type): string
{
    $label  = match ($type) {
        'dealer'   => 'car dealership',
        'garage'   => 'automotive garage',
        'car_hire' => 'car hire company',
        default    => 'automotive business',
    };
    $parts = [];
    if ($city) $parts[] = "Based in $city, Malawi.";
    if (array_filter([$soc['facebook'] ?? '', $soc['instagram'] ?? '', $soc['twitter'] ?? ''])) {
        $parts[] = "Connect with us on social media.";
    }
    if ($website) $parts[] = "Visit: $website";
    return $parts ? implode(' ', $parts) : "A $label in Malawi listed on MotorLink.";
}

/**
 * Text Search — up to 3 pages (60 results max per query).
 */
function textSearch(string $query, string $apiKey, array &$seenPids): array
{
    $results = [];
    $url     = 'https://maps.googleapis.com/maps/api/place/textsearch/json'
             . '?query=' . urlencode($query) . '&key=' . urlencode($apiKey);
    for ($page = 0; $page < 3; $page++) {
        $data = gGet($url);
        usleep(300000);
        if (!$data || ($data['status'] ?? '') !== 'OK') break;
        foreach ($data['results'] as $p) {
            $pid = $p['place_id'] ?? '';
            if (!$pid || isset($seenPids[$pid])) continue;
            $seenPids[$pid] = true;
            $results[] = [
                'pid'  => $pid,
                'name' => $p['name'] ?? '',
                'addr' => $p['formatted_address'] ?? '',
            ];
        }
        if (empty($data['next_page_token'])) break;
        sleep(2); // Google requires ~2s before next_page_token is ready
        $url = 'https://maps.googleapis.com/maps/api/place/textsearch/json'
             . '?pagetoken=' . urlencode($data['next_page_token'])
             . '&key=' . urlencode($apiKey);
    }
    return $results;
}

/**
 * Nearby Search — radius 15 km, up to 2 pages.
 */
function nearbySearch(float $lat, float $lng, string $keyword, string $apiKey, array &$seenPids): array
{
    $results = [];
    $url     = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json'
             . '?location=' . $lat . ',' . $lng
             . '&radius=15000'
             . '&keyword=' . urlencode($keyword)
             . '&key=' . urlencode($apiKey);
    for ($page = 0; $page < 2; $page++) {
        $data = gGet($url);
        usleep(300000);
        if (!$data || ($data['status'] ?? '') !== 'OK') break;
        foreach ($data['results'] as $p) {
            $pid = $p['place_id'] ?? '';
            if (!$pid || isset($seenPids[$pid])) continue;
            $seenPids[$pid] = true;
            $results[] = [
                'pid'  => $pid,
                'name' => $p['name'] ?? '',
                'addr' => $p['vicinity'] ?? '',   // Nearby uses 'vicinity'
            ];
        }
        if (empty($data['next_page_token'])) break;
        sleep(2);
        $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json'
             . '?pagetoken=' . urlencode($data['next_page_token'])
             . '&key=' . urlencode($apiKey);
    }
    return $results;
}

// ═════════════════════════════════════════════════════════════════════════════
// PHASE 1 — DISCOVER
// ═════════════════════════════════════════════════════════════════════════════
$cachePath  = __DIR__ . '/_scrape_v2_cache.json';
$discovered = ['dealer' => [], 'garage' => [], 'car_hire' => []];
$allCities  = array_merge($CITIES_PRIMARY, $CITIES_SECONDARY);

if (!$enrichOnly) {
    jlog("=== PHASE 1: DISCOVER ===");
    updateJob(['phase' => 1, 'phase_label' => 'Phase 1: Discovering businesses']);

    // Load existing cache unless --refresh
    if (!$doRefresh && file_exists($cachePath)) {
        $cached = json_decode((string)file_get_contents($cachePath), true);
        if (is_array($cached) && !empty($cached['dealer'])) {
            $discovered = $cached;
            jlog("  [cache] dealer=" . count($discovered['dealer'])
               . " garage=" . count($discovered['garage'])
               . " car_hire=" . count($discovered['car_hire'])
               . " — run with --refresh to re-discover");
        }
    }

    $needsDiscover = empty($discovered['dealer'])
                  && empty($discovered['garage'])
                  && empty($discovered['car_hire']);

    if ($needsDiscover) {
        $seenPids = [];

        foreach ($QUERY_TEMPLATES as $type => $templates) {
            if ($onlyType && $type !== $onlyType) {
                $discovered[$type] = [];
                continue;
            }
            if (checkStop()) { updateJob(['status' => 'stopped', 'phase_label' => 'Stopped by user']); die("Stopped.\n"); }
            $target = $TARGETS[$type];
            jlog("\n  -- $type (target: $target) --");

            // Round 1: Text Search across all cities × all templates
            foreach ($allCities as $city) {
                if (count($discovered[$type]) >= $target) break;
                if (checkStop()) { updateJob(['status' => 'stopped', 'phase_label' => 'Stopped by user']); die("Stopped.\n"); }
                foreach ($templates as $tpl) {
                    if (count($discovered[$type]) >= $target) break;
                    $q    = sprintf($tpl, $city);
                    $hits = textSearch($q, $apiKey, $seenPids);
                    foreach ($hits as $h) {
                        if (count($discovered[$type]) >= $target) break;
                        $discovered[$type][] = $h + ['city_hint' => $city];
                    }
                }
                jlog("    [text] [{$city}] $type: " . count($discovered[$type]));
                updateJob([
                    'discovery' => array_map(fn($t) => [
                        'found'  => count($discovered[$t]),
                        'target' => $TARGETS[$t],
                    ], array_combine(array_keys($TARGETS), array_keys($TARGETS))),
                ]);
            }

            // Round 2: Nearby Search (GPS radius) — fills remaining gap
            if (count($discovered[$type]) < $target) {
                jlog("  [nearby-search round for $type]");
                $keyword = $NEARBY_KEYWORDS[$type] . ' Malawi';
                foreach ($CITY_COORDS as $city => [$lat, $lng]) {
                    if (count($discovered[$type]) >= $target) break;
                    if (checkStop()) { updateJob(['status' => 'stopped', 'phase_label' => 'Stopped by user']); die("Stopped.\n"); }
                    $hits = nearbySearch((float)$lat, (float)$lng, $keyword, $apiKey, $seenPids);
                    if ($hits) {
                        foreach ($hits as $h) {
                            if (count($discovered[$type]) >= $target) break;
                            $discovered[$type][] = $h + ['city_hint' => $city];
                        }
                        jlog("    [nearby] [{$city}] +" . count($hits)
                           . " → total " . count($discovered[$type]));
                    }
                    usleep(250000);
                }
            }

            jlog("  Total $type: " . count($discovered[$type]));
        }

        file_put_contents($cachePath, json_encode($discovered, JSON_PRETTY_PRINT));
        jlog("  [cache] Saved to _scrape_v2_cache.json");
    }
}

// ═════════════════════════════════════════════════════════════════════════════
// PHASE 2 — INSERT NEW BUSINESSES
// ═════════════════════════════════════════════════════════════════════════════
$csvPath = __DIR__ . '/_scrape_v2_credentials.csv';
$csvFp   = fopen($csvPath, 'w');
fputcsv($csvFp, [
    'type', 'business_name', 'email', 'username', 'phone', 'city', 'password',
    'website', 'facebook', 'instagram', 'twitter', 'linkedin', 'whatsapp', 'logo', 'place_id',
]);

$inserted = 0;
$skipped  = 0;
$withLogo = 0;

if (!$enrichOnly) {
    jlog("=== PHASE 2: INSERT NEW BUSINESSES ===");
    updateJob(['phase' => 2, 'phase_label' => 'Phase 2: Inserting new businesses']);

    $db = freshDb();

    // Pre-load existing PID suffixes for O(1) duplicate detection
    $existingEmails   = $db->query("SELECT email FROM users WHERE email LIKE '%@motorlink.test'")->fetchAll(PDO::FETCH_COLUMN);
    $existingSuffixes = [];
    foreach ($existingEmails as $e) {
        if (preg_match('/\.([a-z0-9_\-]{5,8})@motorlink\.test$/i', $e, $m)) {
            $existingSuffixes[$m[1]] = true;
        }
    }

    // Prepared statements
    $insUserSql = "
        INSERT INTO users
            (username, email, password_hash, full_name, business_name, phone, whatsapp,
             city, address, user_type, status, email_verified, profile_image, bio, created_at)
        VALUES
            (:username, :email, :password_hash, :full_name, :business_name, :phone, :whatsapp,
             :city, :address, :user_type, 'active', 1, :profile_image, :bio, NOW())
    ";
    $insDealerSql = "
        INSERT INTO car_dealers
            (user_id, business_name, owner_name, email, phone, whatsapp, address, location_id,
             website, facebook_url, instagram_url, twitter_url, linkedin_url,
             description, logo_url, verified, certified, featured, status, created_at)
        VALUES
            (:user_id, :business_name, :owner_name, :email, :phone, :whatsapp, :address, :location_id,
             :website, :facebook_url, :instagram_url, :twitter_url, :linkedin_url,
             :description, :logo_url, 0, 0, 0, 'active', NOW())
    ";
    $insGarageSql = "
        INSERT INTO garages
            (user_id, name, owner_name, email, phone, whatsapp, address, location_id,
             website, facebook_url, instagram_url, twitter_url, linkedin_url,
             description, logo_url, verified, certified, featured, status, created_at)
        VALUES
            (:user_id, :name, :owner_name, :email, :phone, :whatsapp, :address, :location_id,
             :website, :facebook_url, :instagram_url, :twitter_url, :linkedin_url,
             :description, :logo_url, 0, 0, 0, 'active', NOW())
    ";
    $insHireSql = "
        INSERT INTO car_hire_companies
            (user_id, business_name, owner_name, email, phone, whatsapp, address, location_id,
             website, facebook_url, instagram_url, twitter_url, linkedin_url,
             hire_category, description, logo_url, verified, certified, featured, status, created_at)
        VALUES
            (:user_id, :business_name, :owner_name, :email, :phone, :whatsapp, :address, :location_id,
             :website, :facebook_url, :instagram_url, :twitter_url, :linkedin_url,
             'standard', :description, :logo_url, 0, 0, 0, 'active', NOW())
    ";

    $insUser   = $db->prepare($insUserSql);
    $insDealer = $db->prepare($insDealerSql);
    $insGarage = $db->prepare($insGarageSql);
    $insHire   = $db->prepare($insHireSql);

    foreach ($discovered as $type => $list) {
        if ($onlyType && $type !== $onlyType) continue;
        jlog("\n  Processing $type (" . count($list) . " candidates)...");

        foreach ($list as $b) {
            $pidSuffix = substr($b['pid'], -6);
            if (isset($existingSuffixes[$pidSuffix])) { $skipped++; continue; }

            // Full Place Details
            $detUrl = 'https://maps.googleapis.com/maps/api/place/details/json'
                    . '?place_id=' . urlencode($b['pid'])
                    . '&fields=name,formatted_phone_number,international_phone_number,formatted_address,website,photos'
                    . '&key=' . urlencode($apiKey);
            $det = gGet($detUrl);
            usleep(200000);

            $name     = $b['name'];
            $phone    = '';
            $address  = $b['addr'];
            $city     = $b['city_hint'];
            $website  = '';
            $photoRef = null;

            if ($det && ($det['status'] ?? '') === 'OK' && isset($det['result'])) {
                $r        = $det['result'];
                $phone    = $r['formatted_phone_number'] ?? ($r['international_phone_number'] ?? '');
                $address  = $r['formatted_address'] ?? $address;
                $website  = $r['website'] ?? '';
                $city     = cityFromAddress($address) ?: $city;
                if (!empty($r['photos'][0]['photo_reference'])) $photoRef = $r['photos'][0]['photo_reference'];
            }

            // Scrape website for social links, WhatsApp, and additional phone
            $social = ['facebook' => '', 'instagram' => '', 'twitter' => '',
                       'linkedin' => '', 'whatsapp' => '', 'phone' => ''];
            if ($website) {
                $social = extractFromWebsite($website);
                if (!$phone && $social['phone']) $phone = $social['phone'];
                jlog("    [web] $name: fb=" . ($social['facebook']  ? 'y' : '-')
                   . " ig=" . ($social['instagram'] ? 'y' : '-')
                   . " tw=" . ($social['twitter']   ? 'y' : '-')
                   . " wa=" . ($social['whatsapp']  ? 'y' : '-')
                   . " ph=" . ($phone               ? 'y' : '-'));
                usleep(400000);
            }

            // Download logo from Google Places photo
            $slug     = strtolower(preg_replace('/[^a-z0-9]/i', '', $name));
            $logoPath = null;
            if ($photoRef) {
                $logoPath = downloadPlacePhoto($photoRef, $apiKey, $slug ?: 'biz');
                usleep(150000);
            }

            $username = uniqueUsername($db, $name);
            $email    = 'biz.' . substr($slug, 0, 20) . '.' . $pidSuffix . '@motorlink.test';
            if (strlen($email) > 100) $email = 'biz.' . substr(md5($b['pid']), 0, 18) . '@motorlink.test';
            $pwd      = randPwd(12);
            $hash     = password_hash($pwd, PASSWORD_DEFAULT);
            $phone    = substr(preg_replace('/[^\d+\-() ]/', '', $phone), 0, 20);
            $addr     = substr($address, 0, 500);
            $city     = substr($city, 0, 50);
            $locId    = locationIdFor($city);
            $desc     = buildDescription($name, $city, $website, $social, $type);

            // Keep DB alive after long website fetches
            try { $db->query("SELECT 1"); } catch (Throwable $e) {
                $db = freshDb();
                $insUser   = $db->prepare($insUserSql);
                $insDealer = $db->prepare($insDealerSql);
                $insGarage = $db->prepare($insGarageSql);
                $insHire   = $db->prepare($insHireSql);
            }

            try {
                $db->beginTransaction();

                $insUser->execute([
                    ':username'      => $username,
                    ':email'         => $email,
                    ':password_hash' => $hash,
                    ':full_name'     => substr($name, 0, 100),
                    ':business_name' => substr($name, 0, 100),
                    ':phone'         => $phone,
                    ':whatsapp'      => $social['whatsapp'] ?: $phone,
                    ':city'          => $city,
                    ':address'       => $addr,
                    ':user_type'     => $type,
                    ':profile_image' => $logoPath,
                    ':bio'           => $website ? "Website: $website" : '',
                ]);
                $userId = (int)$db->lastInsertId();

                $common = [
                    ':user_id'       => $userId,
                    ':owner_name'    => substr($name, 0, 100),
                    ':email'         => $email,
                    ':phone'         => $phone,
                    ':whatsapp'      => $social['whatsapp'] ?: $phone,
                    ':address'       => $addr,
                    ':location_id'   => $locId,
                    ':website'       => substr($website, 0, 255),
                    ':facebook_url'  => substr($social['facebook'],  0, 255),
                    ':instagram_url' => substr($social['instagram'], 0, 255),
                    ':twitter_url'   => substr($social['twitter'],   0, 255),
                    ':linkedin_url'  => substr($social['linkedin'],  0, 255),
                    ':description'   => substr($desc, 0, 1000),
                    ':logo_url'      => $logoPath,
                ];

                if ($type === 'dealer') {
                    $insDealer->execute($common + [':business_name' => substr($name, 0, 200)]);
                } elseif ($type === 'garage') {
                    $insGarage->execute($common + [':name' => substr($name, 0, 200)]);
                } else {
                    $insHire->execute($common + [':business_name' => substr($name, 0, 200)]);
                }

                $bizId = (int)$db->lastInsertId();
                $db->prepare("UPDATE users SET business_id = ? WHERE id = ?")->execute([$bizId, $userId]);
                $db->commit();

                $inserted++;
                if ($logoPath) $withLogo++;
                $existingSuffixes[$pidSuffix] = true;

                fputcsv($csvFp, [
                    $type, $name, $email, $username, $phone, $city, $pwd,
                    $website, $social['facebook'], $social['instagram'],
                    $social['twitter'], $social['linkedin'], $social['whatsapp'],
                    $logoPath ? 'yes' : 'no', $b['pid'],
                ]);
                jlog(sprintf("    + [%s] %s%s%s",
                    $type, $name,
                    $logoPath ? ' [logo]' : '',
                    ($social['facebook'] || $social['instagram'] || $social['whatsapp']) ? ' [social]' : ''
                ));
                updateJob(['inserted' => $inserted, 'with_logo' => $withLogo, 'skipped' => $skipped]);

            } catch (PDOException $e) {
                if ($db->inTransaction()) $db->rollBack();
                $skipped++;
                // Only show non-routine errors (suppress duplicate key noise)
                if (strpos($e->getMessage(), '1062') === false) {
                    jlog("    ! FAIL $name: " . $e->getMessage());
                }
            }
        }
    }

    jlog("\n  Inserted: $inserted  |  With logo: $withLogo  |  Skipped/duplicate: $skipped");
    updateJob(['inserted' => $inserted, 'with_logo' => $withLogo, 'skipped' => $skipped]);
}

fclose($csvFp);
@chmod($csvPath, 0600);

// ═════════════════════════════════════════════════════════════════════════════
// PHASE 3 — ENRICH EXISTING BUSINESSES
// ═════════════════════════════════════════════════════════════════════════════
if (!$insertOnly) {
    jlog("=== PHASE 3: ENRICH EXISTING RECORDS ===");
    jlog("    (limit: $enrichLimit rows per table)");
    updateJob(['phase' => 3, 'phase_label' => 'Phase 3: Enriching existing records']);

    $db = freshDb();

    $enrichTables = [
        ['table' => 'car_dealers',        'name_col' => 'business_name', 'type' => 'dealer'],
        ['table' => 'garages',            'name_col' => 'name',          'type' => 'garage'],
        ['table' => 'car_hire_companies', 'name_col' => 'business_name', 'type' => 'car_hire'],
    ];

    $enriched   = 0;
    $enrichSkip = 0;

    foreach ($enrichTables as $et) {
        if ($onlyType && $et['type'] !== $onlyType) continue;

        $tbl     = $et['table'];
        $nameCol = $et['name_col'];

        // Fetch rows missing ANY useful contact/social/website data
        $rows = $db->query("
            SELECT id, `{$nameCol}` AS biz_name, phone, website,
                   facebook_url, instagram_url, twitter_url, linkedin_url,
                   whatsapp, address, logo_url
            FROM `{$tbl}`
            WHERE status = 'active'
              AND (
                  (phone        IS NULL OR phone        = '')
                  OR (website   IS NULL OR website      = '')
                  OR (facebook_url  IS NULL OR facebook_url  = '')
                  OR (instagram_url IS NULL OR instagram_url = '')
                  OR (twitter_url   IS NULL OR twitter_url   = '')
                  OR (whatsapp  IS NULL OR whatsapp  = '')
              )
            ORDER BY id
            LIMIT {$enrichLimit}
        ")->fetchAll();

        jlog("\n  $tbl: " . count($rows) . " rows need enrichment")

        foreach ($rows as $row) {
            $bizId       = (int)$row['id'];
            $bizName     = (string)$row['biz_name'];
            $curPhone    = (string)($row['phone']        ?? '');
            $curWebsite  = (string)($row['website']      ?? '');
            $curFb       = (string)($row['facebook_url'] ?? '');
            $curIg       = (string)($row['instagram_url'] ?? '');
            $curTw       = (string)($row['twitter_url']  ?? '');
            $curLi       = (string)($row['linkedin_url'] ?? '');
            $curWa       = (string)($row['whatsapp']     ?? '');
            $city        = cityFromAddress((string)($row['address'] ?? ''));

            $newPhone    = $curPhone;
            $newWebsite  = $curWebsite;
            $newFb       = $curFb;
            $newIg       = $curIg;
            $newTw       = $curTw;
            $newLi       = $curLi;
            $newWa       = $curWa;

            // ── Step 1: Google Places — get phone + website if missing ─────────
            if (!$newPhone || !$newWebsite) {
                $q   = $bizName . ' ' . ($city ?: 'Malawi');
                $url = 'https://maps.googleapis.com/maps/api/place/findplacefromtext/json'
                     . '?input=' . urlencode($q) . '&inputtype=textquery'
                     . '&fields=place_id&key=' . urlencode($apiKey);
                $found = gGet($url);
                usleep(200000);

                if ($found && ($found['status'] ?? '') === 'OK' && !empty($found['candidates'][0]['place_id'])) {
                    $pid    = $found['candidates'][0]['place_id'];
                    $detUrl = 'https://maps.googleapis.com/maps/api/place/details/json'
                            . '?place_id=' . urlencode($pid)
                            . '&fields=formatted_phone_number,international_phone_number,website'
                            . '&key=' . urlencode($apiKey);
                    $det = gGet($detUrl);
                    usleep(200000);

                    if ($det && ($det['status'] ?? '') === 'OK' && isset($det['result'])) {
                        $r = $det['result'];
                        if (!$newPhone) {
                            $raw = $r['formatted_phone_number'] ?? ($r['international_phone_number'] ?? '');
                            $newPhone = substr(preg_replace('/[^\d+\-() ]/', '', $raw), 0, 20);
                        }
                        if (!$newWebsite) $newWebsite = substr($r['website'] ?? '', 0, 255);
                    }
                }
            }

            // ── Step 2: Scrape website for social + phone + WhatsApp ──────────
            $needMore = !$newFb || !$newIg || !$newTw || !$newWa || !$newPhone;
            if ($needMore && $newWebsite) {
                $soc = extractFromWebsite($newWebsite);
                usleep(500000);
                if (!$newFb    && $soc['facebook'])  $newFb    = $soc['facebook'];
                if (!$newIg    && $soc['instagram']) $newIg    = $soc['instagram'];
                if (!$newTw    && $soc['twitter'])   $newTw    = $soc['twitter'];
                if (!$newLi    && $soc['linkedin'])  $newLi    = $soc['linkedin'];
                if (!$newWa    && $soc['whatsapp'])  $newWa    = $soc['whatsapp'];
                if (!$newPhone && $soc['phone'])     $newPhone = $soc['phone'];
            }

            // ── Step 3: Build UPDATE only for changed fields ───────────────────
            $updates = [];
            $params  = [];
            if ($newPhone   && $newPhone   !== $curPhone)   { $updates[] = 'phone = ?';        $params[] = $newPhone; }
            if ($newWebsite && $newWebsite !== $curWebsite) { $updates[] = 'website = ?';       $params[] = $newWebsite; }
            if ($newFb      && $newFb      !== $curFb)      { $updates[] = 'facebook_url = ?';  $params[] = $newFb; }
            if ($newIg      && $newIg      !== $curIg)      { $updates[] = 'instagram_url = ?'; $params[] = $newIg; }
            if ($newTw      && $newTw      !== $curTw)      { $updates[] = 'twitter_url = ?';   $params[] = $newTw; }
            if ($newLi      && $newLi      !== $curLi)      { $updates[] = 'linkedin_url = ?';  $params[] = $newLi; }
            if ($newWa      && $newWa      !== $curWa)      { $updates[] = 'whatsapp = ?';      $params[] = $newWa; }

            if ($updates) {
                $params[] = $bizId;
                try {
                    $db->prepare("UPDATE `{$tbl}` SET " . implode(', ', $updates) . " WHERE id = ?")->execute($params);

                    // Sync phone + whatsapp back to users row
                    if ($newPhone && $newPhone !== $curPhone) {
                        $db->prepare("
                            UPDATE users u
                            INNER JOIN `{$tbl}` b ON b.user_id = u.id
                            SET u.phone = ?
                            WHERE b.id = ? AND (u.phone IS NULL OR u.phone = '')
                        ")->execute([$newPhone, $bizId]);
                    }
                    if ($newWa && $newWa !== $curWa) {
                        $db->prepare("
                            UPDATE users u
                            INNER JOIN `{$tbl}` b ON b.user_id = u.id
                            SET u.whatsapp = ?
                            WHERE b.id = ? AND (u.whatsapp IS NULL OR u.whatsapp = '')
                        ")->execute([$newWa, $bizId]);
                    }

                    $enriched++;
                    jlog(sprintf("    ~ %-32s | ph:%s web:%s fb:%s ig:%s tw:%s wa:%s",
                        substr($bizName, 0, 32),
                        $newPhone ? 'y' : '-',
                        $newWebsite ? 'y' : '-',
                        $newFb ? 'y' : '-',
                        $newIg ? 'y' : '-',
                        $newTw ? 'y' : '-',
                        $newWa ? 'y' : '-'
                    ));
                    updateJob(['enriched' => $enriched]));
                    updateJob(['enriched' => $enriched]);
                } catch (PDOException $ex) {
                    jlog("    ! FAIL $bizName: " . $ex->getMessage())
                    jlog("    ! FAIL $bizName: " . $ex->getMessage());
                }
            } else {
                $enrichSkip++;
            }

            try { $db->query("SELECT 1"); } catch (Throwable $e) { $db = freshDb(); }
        }
    }
jlog("\n  Enriched: $enriched  |  No change / no data found: $enrichSkip");
    updateJob(['enriched' => $enriched])
    jlog("\n  Enriched: $enriched  |  No change / no data found: $enrichSkip");
    updateJob(['enriched' => $enriched]);
}
// SUMMARY
// ═══════════════════════════════════════════════════════════════════════════
jlog("=== SUMMARY ===");
$db = freshDb();
$summaryData = [];
foreach (['car_dealers', 'garages', 'car_hire_companies'] as $t) {
    $tot = $db->query("SELECT COUNT(*) FROM `$t` WHERE status='active'")->fetchColumn();
    $ph  = $db->query("SELECT COUNT(*) FROM `$t` WHERE status='active' AND phone != '' AND phone IS NOT NULL")->fetchColumn();
    $web = $db->query("SELECT COUNT(*) FROM `$t` WHERE status='active' AND website != '' AND website IS NOT NULL")->fetchColumn();
    $fb  = $db->query("SELECT COUNT(*) FROM `$t` WHERE status='active' AND facebook_url != '' AND facebook_url IS NOT NULL")->fetchColumn();
    $ig  = $db->query("SELECT COUNT(*) FROM `$t` WHERE status='active' AND instagram_url != '' AND instagram_url IS NOT NULL")->fetchColumn();
    $wa  = $db->query("SELECT COUNT(*) FROM `$t` WHERE status='active' AND whatsapp != '' AND whatsapp IS NOT NULL")->fetchColumn();
    $lg  = $db->query("SELECT COUNT(*) FROM `$t` WHERE status='active' AND logo_url IS NOT NULL")->fetchColumn();
    $summaryData[$t] = ['active' => (int)$tot, 'phone' => (int)$ph, 'website' => (int)$web, 'facebook' => (int)$fb, 'instagram' => (int)$ig, 'whatsapp' => (int)$wa, 'logo' => (int)$lg];
    jlog(sprintf("  %-24s  active:%3d | phone:%3d | web:%3d | fb:%3d | ig:%3d | wa:%3d | logo:%3d",
        $t, $tot, $ph, $web, $fb, $ig, $wa, $lg));
}

jlog("Credentials saved to: scripts/_scrape_v2_credentials.csv");
jlog("Done.");
updateJob(['status' => 'done', 'phase' => 4, 'phase_label' => 'Completed', 'summary' => $summaryData])ot, $ph, $web, $fb, $ig, $wa, $lg));
}

jlog("Credentials saved to: scripts/_scrape_v2_credentials.csv");
jlog("Done.");
updateJob(['status' => 'done', 'phase' => 4, 'phase_label' => 'Completed', 'summary' => $summaryData]);
