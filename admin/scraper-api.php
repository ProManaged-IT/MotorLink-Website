<?php
/**
 * scraper-api.php — Admin API for on-demand business scraper
 *
 * Actions:
 *   start   — spawn scrape_v2.php as background job, returns {job_id}
 *   status  — read job progress JSON, returns progress data
 *   list    — list all past jobs (newest first)
 *   stop    — write stop signal to job file (scraper checks for it)
 *   db_counts — current active counts per table (for before/after comparison)
 */

declare(strict_types=1);
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

chdir(dirname(__DIR__));
require 'api-common.php';

// ── Auth: must be logged-in admin ────────────────────────────────────────────
session_start();
if (empty($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// ── Input ────────────────────────────────────────────────────────────────────
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');
$jobId  = preg_replace('/[^a-zA-Z0-9_\-]/', '', $_GET['job_id'] ?? $_POST['job_id'] ?? '');

$jobsDir  = __DIR__ . '/../scripts/scrapers/scrape_jobs';
$scriptPath = __DIR__ . '/../scripts/scrapers/scrape_v2.php';

if (!is_dir($jobsDir)) @mkdir($jobsDir, 0775, true);

// ── Helpers ──────────────────────────────────────────────────────────────────
function jobFile(string $dir, string $id): string {
    return $dir . '/' . $id . '.json';
}

function readJob(string $dir, string $id): ?array {
    $f = jobFile($dir, $id);
    if (!file_exists($f)) return null;
    return json_decode((string)file_get_contents($f), true);
}

function dbCounts(): array {
    try {
        $db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER, DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $out = [];
        foreach (['car_dealers', 'garages', 'car_hire_companies'] as $t) {
            $row = [];
            foreach (['*', 'phone != "" AND phone IS NOT NULL',
                      'website != "" AND website IS NOT NULL',
                      'facebook_url != "" AND facebook_url IS NOT NULL',
                      'instagram_url != "" AND instagram_url IS NOT NULL',
                      'twitter_url != "" AND twitter_url IS NOT NULL',
                      'whatsapp != "" AND whatsapp IS NOT NULL',
                      'logo_url IS NOT NULL'] as $i => $cond) {
                $col  = $cond === '*' ? 'active' : ['phone','website','facebook','instagram','twitter','whatsapp','logo'][$i - 1];
                $sql  = "SELECT COUNT(*) FROM `$t` WHERE status='active'" . ($cond !== '*' ? " AND $cond" : '');
                $row[$col] = (int)$db->query($sql)->fetchColumn();
            }
            $out[$t] = $row;
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

// ═════════════════════════════════════════════════════════════════════════════
switch ($action) {

// ── START ─────────────────────────────────────────────────────────────────────
case 'start':
    if (!file_exists($scriptPath)) {
        echo json_encode(['success' => false, 'error' => 'scrape_v2.php not found']);
        exit;
    }

    try {
        $db = new PDO(
            "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $keyStmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'google_places_scraper_api_key' LIMIT 1");
        $keyStmt->execute();
        $scraperKey = trim((string)($keyStmt->fetchColumn() ?: ''));
        if ($scraperKey === '') {
            echo json_encode(['success' => false, 'error' => 'Scraping is disabled. Configure google_places_scraper_api_key separately before running any billable scraper job.']);
            exit;
        }
    } catch (Throwable $e) {
        echo json_encode(['success' => false, 'error' => 'Unable to verify scraper API key before launch']);
        exit;
    }

    // Check if a job is already running
    $running = glob($jobsDir . '/*.json');
    if ($running) {
        foreach ($running as $f) {
            $j = json_decode((string)file_get_contents($f), true);
            if (($j['status'] ?? '') === 'running') {
                echo json_encode(['success' => false, 'error' => 'A job is already running', 'job_id' => $j['job_id']]);
                exit;
            }
        }
    }

    // Build flags from POST params
    if (empty($_POST['allow_google_places_cost'])) {
        echo json_encode(['success' => false, 'error' => 'Google Places scraper is billable. Confirm allow_google_places_cost=1 before starting.']);
        exit;
    }

    $flags   = ['--allow-google-places-cost'];
    $postType = preg_replace('/[^a-z_]/', '', strtolower($_POST['type'] ?? 'all'));
    if ($postType && $postType !== 'all') $flags[] = '--type=' . $postType;
    if (!empty($_POST['refresh']))      $flags[] = '--refresh';
    if (!empty($_POST['enrich_only']))  $flags[] = '--enrich-only';
    if (!empty($_POST['insert_only']))  $flags[] = '--insert-only';
    $enrichLimit = max(10, min(5000, (int)($_POST['enrich_limit'] ?? 1000)));
    $flags[] = '--enrich-limit=' . $enrichLimit;

    // Country / Cities overrides for multi-country support
    $country         = preg_replace('/[^a-zA-Z\s\-]/', '', trim($_POST['country'] ?? 'Malawi')) ?: 'Malawi';
    $primaryCities   = substr(preg_replace('/[^a-zA-Z0-9,\s\-]/', '', trim($_POST['primary_cities']   ?? '')), 0, 1000);
    $secondaryCities = substr(preg_replace('/[^a-zA-Z0-9,\s\-]/', '', trim($_POST['secondary_cities'] ?? '')), 0, 2000);
    $flags[] = '--country=' . $country;
    if ($primaryCities)   $flags[] = '--primary-cities='   . $primaryCities;
    if ($secondaryCities) $flags[] = '--secondary-cities=' . $secondaryCities;

    $newJobId  = date('Ymd_His') . '_' . substr(uniqid(), -4);
    $flags[]   = '--job-id=' . $newJobId;

    // Snapshot current DB counts BEFORE scrape
    $before = dbCounts();

    // Write initial job file
    $jobData = [
        'job_id'     => $newJobId,
        'status'     => 'starting',
        'phase'      => 0,
        'phase_label'=> 'Initializing...',
        'started_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'type'       => $postType ?: 'all',
        'options'    => [
            'refresh'         => !empty($_POST['refresh']),
            'enrich_only'     => !empty($_POST['enrich_only']),
            'insert_only'     => !empty($_POST['insert_only']),
            'enrich_limit'    => $enrichLimit,
            'country'         => $country,
            'primary_cities'  => $primaryCities,
            'secondary_cities'=> $secondaryCities,
        ],
        'discovery'  => ['dealer' => ['found' => 0, 'target' => 500], 'garage' => ['found' => 0, 'target' => 400], 'car_hire' => ['found' => 0, 'target' => 300]],
        'inserted'   => 0,
        'with_logo'  => 0,
        'skipped'    => 0,
        'enriched'   => 0,
        'log_tail'   => [],
        'before'     => $before,
        'summary'    => [],
        'error'      => null,
        'stop_signal'=> false,
    ];
    file_put_contents(jobFile($jobsDir, $newJobId), json_encode($jobData, JSON_PRETTY_PRINT));

    // Launch background process
    $phpBin  = PHP_BINARY ?: 'php';
    $logFile = $jobsDir . '/' . $newJobId . '.log';

    if (PHP_OS_FAMILY === 'Windows') {
        // On Windows, cmd `start /B` treats the first double-quoted arg as the window
        // title and then runs the .php script directly — opening it in Notepad.
        // Using proc_open with an ARRAY bypasses the shell entirely: no quoting issues,
        // no Notepad. Child processes on Windows are not tied to the parent, so the
        // scraper keeps running after we return the JSON response.
        $procArgs    = array_merge([$phpBin, $scriptPath], $flags);
        $descriptors = [
            0 => ['file', 'NUL',    'r'],
            1 => ['file', $logFile, 'w'],
            2 => ['file', $logFile, 'a'],
        ];
        $proc = proc_open($procArgs, $descriptors, $pipes);
        // Intentionally not calling proc_close — process runs independently on Windows.
        unset($proc, $pipes);
    } else {
        $cmd = escapeshellarg($phpBin) . ' ' . escapeshellarg($scriptPath) . ' ' . implode(' ', array_map('escapeshellarg', $flags));
        exec($cmd . ' > ' . escapeshellarg($logFile) . ' 2>&1 &');
    }

    echo json_encode(['success' => true, 'job_id' => $newJobId]);
    break;

// ── STATUS ────────────────────────────────────────────────────────────────────
case 'status':
    if (!$jobId) { echo json_encode(['success' => false, 'error' => 'Missing job_id']); exit; }
    $job = readJob($jobsDir, $jobId);
    if (!$job) { echo json_encode(['success' => false, 'error' => 'Job not found']); exit; }

    // If done, attach after snapshot
    if (in_array($job['status'], ['done', 'error']) && empty($job['after'])) {
        $job['after'] = dbCounts();
        file_put_contents(jobFile($jobsDir, $jobId), json_encode($job, JSON_PRETTY_PRINT));
    }

    // Normalize field names for the frontend
    $job['total_new']     = $job['inserted']  ?? 0;
    $job['total_updated'] = $job['enriched']  ?? 0;
    $job['total_skipped'] = $job['skipped']   ?? 0;
    $job['total_scanned'] = ($job['inserted'] ?? 0) + ($job['enriched'] ?? 0) + ($job['skipped'] ?? 0);

    // Phase-based progress (0-100)
    $phase = (int)($job['phase'] ?? 0);
    $job['progress'] = min(99, $phase >= 4 ? 100 : max($phase * 25, 0));
    if ($job['status'] === 'done') $job['progress'] = 100;

    // Stream log file chunk
    $logFile   = $jobsDir . '/' . $jobId . '.log';
    $logOffset = max(0, (int)($_POST['log_offset'] ?? $_GET['log_offset'] ?? 0));
    $logChunk  = '';
    $nextOffset= $logOffset;
    if (file_exists($logFile)) {
        $fh = fopen($logFile, 'rb');
        if ($fh) {
            fseek($fh, $logOffset);
            $logChunk  = fread($fh, 16384); // up to 16KB per poll
            $nextOffset = ftell($fh);
            fclose($fh);
        }
    }

    echo json_encode([
        'success'     => true,
        'job'         => $job,
        'log_chunk'   => $logChunk,
        'next_offset' => $nextOffset,
        'log_tail'    => $job['log_tail'] ?? [],
    ]);
    break;

// ── LIST / HISTORY ────────────────────────────────────────────────────────────
case 'list':
case 'history':
    $files = glob($jobsDir . '/*.json') ?: [];
    usort($files, fn($a, $b) => filemtime($b) - filemtime($a));
    $list = [];
    foreach (array_slice($files, 0, 30) as $f) {
        $j = json_decode((string)file_get_contents($f), true);
        if ($j) {
            $list[] = [
                'id'          => $j['job_id']     ?? '',
                'job_id'      => $j['job_id']     ?? '',
                'status'      => $j['status']     ?? 'unknown',
                'phase'       => $j['phase']      ?? 0,
                'phase_label' => $j['phase_label']?? '',
                'started_at'  => isset($j['started_at']) ? strtotime($j['started_at']) : 0,
                'updated_at'  => $j['updated_at'] ?? '',
                'type'        => $j['type']       ?? 'all',
                'options'     => $j['options']    ?? [],
                'total_new'   => $j['inserted']   ?? 0,
                'total_updated'=> $j['enriched']  ?? 0,
                'total_skipped'=> $j['skipped']   ?? 0,
            ];
        }
    }
    echo json_encode(['success' => true, 'jobs' => $list]);
    break;

// ── STOP ──────────────────────────────────────────────────────────────────────
case 'stop':
    if (!$jobId) { echo json_encode(['success' => false, 'error' => 'Missing job_id']); exit; }
    $job = readJob($jobsDir, $jobId);
    if (!$job) { echo json_encode(['success' => false, 'error' => 'Job not found']); exit; }
    $job['stop_signal'] = true;
    $job['updated_at']  = date('Y-m-d H:i:s');
    file_put_contents(jobFile($jobsDir, $jobId), json_encode($job, JSON_PRETTY_PRINT));
    echo json_encode(['success' => true, 'message' => 'Stop signal sent']);
    break;

// ── DB_COUNTS ─────────────────────────────────────────────────────────────────
case 'db_counts':
    echo json_encode(['success' => true, 'counts' => dbCounts()]);
    break;

default:
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
}
