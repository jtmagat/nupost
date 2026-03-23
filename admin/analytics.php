<?php
session_start();
require_once "../config/database.php";

if (!isset($_SESSION["role"]) || $_SESSION["role"] !== "admin") {
    header("Location: ../auth/login.php");
    exit();
}

// ══════════════════════════════════════════════════════════════════════════
//  META GRAPH API CONFIG  —  fill these two values in
// ══════════════════════════════════════════════════════════════════════════
define('META_PAGE_ID',      '103616607927902');
define('META_ACCESS_TOKEN', 'EAAT7USCesj8BRCbuZASL0Ytyknvt9sy40PZCJC07Nl7ZAbV4wBZABuQSsrdpovZAlaZCLRZCp24zyGezd2wDbJoG4PIYtfv5Xxzx9qUbhEGb6dOVt0T2bcsA79JEcbKLRDhb5p6YRP3Xmk4oN0XMAdZBXhCa1Y0jLrvGBciLBP8Ht99KeIX55Eybmp0Fz8DKrCjkLg87EZAz0WYxeZAZBQw85DZAft5a2FwFK8oZBUtPZC3SV1EwZDZD');
define('META_API_VERSION',  'v19.0');
define('META_BASE',         'https://graph.facebook.com/' . META_API_VERSION);
define('POSTS_LIMIT',       25);

// ── Helper: call Graph API ────────────────────────────────────────────────
function meta_get(string $endpoint, array $params = []): array {
    $params['access_token'] = META_ACCESS_TOKEN;
    $url = META_BASE . $endpoint . '?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    $body = curl_exec($ch);
    curl_close($ch);
    $data = json_decode($body, true);
    return (isset($data['error']) || empty($data)) ? [] : $data;
}

// ══════════════════════════════════════════════════════════════════════════
//  HANDLE AJAX SYNC REQUEST
// ══════════════════════════════════════════════════════════════════════════
if (isset($_GET['action']) && $_GET['action'] === 'sync_meta') {
    header('Content-Type: application/json');

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `post_analytics` (
        `id`              INT(11)      NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `request_id`      INT(11)      DEFAULT NULL,
        `fb_post_id`      VARCHAR(64)  DEFAULT NULL,
        `post_title`      VARCHAR(255) DEFAULT NULL,
        `platform`        VARCHAR(100) DEFAULT 'Facebook',
        `reach`           INT(11)      DEFAULT 0,
        `engagement`      INT(11)      DEFAULT 0,
        `reactions`       INT(11)      DEFAULT 0,
        `shares`          INT(11)      DEFAULT 0,
        `comments`        INT(11)      DEFAULT 0,
        `engagement_rate` DECIMAL(5,2) DEFAULT 0.00,
        `recorded_at`     DATE         DEFAULT NULL,
        `created_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
        `updated_at`      DATETIME     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");

    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `page_insights` (
        `id`        INT(11)      NOT NULL AUTO_INCREMENT PRIMARY KEY,
        `metric`    VARCHAR(100) NOT NULL,
        `period`    VARCHAR(20)  NOT NULL DEFAULT 'days_28',
        `value`     BIGINT       DEFAULT 0,
        `synced_at` DATETIME     DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY metric_period (metric, period)
    )");

    $idx = mysqli_query($conn, "SHOW INDEX FROM post_analytics WHERE Key_name = 'fb_post_id'");
    if (mysqli_num_rows($idx) === 0) {
        mysqli_query($conn, "ALTER TABLE post_analytics ADD UNIQUE KEY fb_post_id (fb_post_id)");
    }

    // ── 1. Per-post data ──────────────────────────────────────────────────
    $posts_resp = meta_get('/' . META_PAGE_ID . '/posts', [
        'fields' => 'id,message,created_time,shares',
        'limit'  => POSTS_LIMIT,
    ]);

    if (empty($posts_resp['data'])) {
        echo json_encode(['ok' => false, 'msg' => 'Meta API returned no posts. Check PAGE_ID and token permissions.']);
        exit();
    }

    $inserted = $updated = $failed = 0;

    foreach ($posts_resp['data'] as $post) {
        $fb_post_id  = $post['id'];
        $recorded_at = isset($post['created_time'])
            ? date('Y-m-d', strtotime($post['created_time'])) : date('Y-m-d');
        $post_title  = mb_substr($post['message'] ?? '(no caption)', 0, 120);
        $shares      = isset($post['shares']['count']) ? (int)$post['shares']['count'] : 0;

        $ins = meta_get("/{$fb_post_id}/insights", [
            'metric' => 'post_impressions_unique,post_engaged_users,post_reactions_by_type_total',
            'period' => 'lifetime',
        ]);
        if (empty($ins['data'])) { $failed++; continue; }

        $metrics = [];
        foreach ($ins['data'] as $item) $metrics[$item['name']] = $item['values'][0]['value'] ?? 0;

        $reach      = (int)($metrics['post_impressions_unique'] ?? 0);
        $engagement = (int)($metrics['post_engaged_users']      ?? 0);
        $rx         = $metrics['post_reactions_by_type_total']  ?? [];
        $reactions  = is_array($rx) ? (int)array_sum($rx) : 0;

        $cr       = meta_get("/{$fb_post_id}", ['fields' => 'comments.summary(true)']);
        $comments = (int)($cr['comments']['summary']['total_count'] ?? 0);
        $eng_rate = $reach > 0 ? round($engagement / $reach * 100, 2) : 0.00;

        $stmt = $conn->prepare("
            INSERT INTO post_analytics
                (fb_post_id,post_title,platform,reach,engagement,reactions,shares,comments,engagement_rate,recorded_at)
            VALUES (?,?,'Facebook',?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE
                post_title=VALUES(post_title),reach=VALUES(reach),engagement=VALUES(engagement),
                reactions=VALUES(reactions),shares=VALUES(shares),comments=VALUES(comments),
                engagement_rate=VALUES(engagement_rate),recorded_at=VALUES(recorded_at),updated_at=NOW()
        ");
        if (!$stmt) { $failed++; continue; }
        $stmt->bind_param('ssiiiiiis', $fb_post_id,$post_title,$reach,$engagement,$reactions,$shares,$comments,$eng_rate,$recorded_at);
        $stmt->execute() ? ($stmt->affected_rows === 1 ? $inserted++ : $updated++) : $failed++;
        $stmt->close();
        usleep(200_000);
    }

    // ── 2. Page-level totals (28-day) ────────────────────────────────────
    foreach (['page_impressions_unique','page_post_engagements','page_actions_post_reactions_total'] as $m) {
        $pi = meta_get('/' . META_PAGE_ID . '/insights/' . $m, ['period' => 'days_28']);
        if (!empty($pi['data'][0]['values'])) {
            $total = array_sum(array_column($pi['data'][0]['values'], 'value'));
            $s = $conn->prepare("INSERT INTO page_insights (metric,period,value,synced_at) VALUES (?,'days_28',?,NOW()) ON DUPLICATE KEY UPDATE value=VALUES(value),synced_at=NOW()");
            $s->bind_param('si', $m, $total); $s->execute(); $s->close();
        }
    }

    $pr_q = $conn->query("SELECT value FROM page_insights WHERE metric='page_impressions_unique'");
    $pe_q = $conn->query("SELECT value FROM page_insights WHERE metric='page_post_engagements'");
    $pr_v = $pr_q ? (int)$pr_q->fetch_assoc()['value'] : 0;
    $pe_v = $pe_q ? (int)$pe_q->fetch_assoc()['value'] : 0;
    $per  = $pr_v > 0 ? (int)round($pe_v / $pr_v * 10000) : 0;
    $s2   = $conn->prepare("INSERT INTO page_insights (metric,period,value,synced_at) VALUES ('page_engagement_rate_x100','days_28',?,NOW()) ON DUPLICATE KEY UPDATE value=VALUES(value),synced_at=NOW()");
    $s2->bind_param('i', $per); $s2->execute(); $s2->close();

    echo json_encode(['ok'=>true,'inserted'=>$inserted,'updated'=>$updated,'failed'=>$failed,
        'msg'=>"Sync complete — {$inserted} new, {$updated} updated, {$failed} failed."]);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════
//  TABLES + COLUMN PATCHES
// ══════════════════════════════════════════════════════════════════════════
mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `post_analytics` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `request_id` INT(11) DEFAULT NULL,
    `post_title` VARCHAR(255) DEFAULT NULL,
    `platform` VARCHAR(100) DEFAULT NULL,
    `reach` INT(11) DEFAULT 0,
    `engagement` INT(11) DEFAULT 0,
    `reactions` INT(11) DEFAULT 0,
    `shares` INT(11) DEFAULT 0,
    `comments` INT(11) DEFAULT 0,
    `engagement_rate` DECIMAL(5,2) DEFAULT 0.00,
    `recorded_at` DATE DEFAULT NULL,
    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
)");

mysqli_query($conn, "CREATE TABLE IF NOT EXISTS `page_insights` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `metric` VARCHAR(100) NOT NULL,
    `period` VARCHAR(20) NOT NULL DEFAULT 'days_28',
    `value` BIGINT DEFAULT 0,
    `synced_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY metric_period (metric, period)
)");

$patch_cols = [
    "fb_post_id"      => "ALTER TABLE `post_analytics` ADD COLUMN `fb_post_id` VARCHAR(64) DEFAULT NULL",
    "shares"          => "ALTER TABLE `post_analytics` ADD COLUMN `shares` INT(11) DEFAULT 0",
    "comments"        => "ALTER TABLE `post_analytics` ADD COLUMN `comments` INT(11) DEFAULT 0",
    "engagement_rate" => "ALTER TABLE `post_analytics` ADD COLUMN `engagement_rate` DECIMAL(5,2) DEFAULT 0.00",
    "recorded_at"     => "ALTER TABLE `post_analytics` ADD COLUMN `recorded_at` DATE DEFAULT NULL",
    "post_title"      => "ALTER TABLE `post_analytics` ADD COLUMN `post_title` VARCHAR(255) DEFAULT NULL",
    "platform"        => "ALTER TABLE `post_analytics` ADD COLUMN `platform` VARCHAR(100) DEFAULT NULL",
    "request_id"      => "ALTER TABLE `post_analytics` ADD COLUMN `request_id` INT(11) DEFAULT NULL",
    "updated_at"      => "ALTER TABLE `post_analytics` ADD COLUMN `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
];
$cols_q = mysqli_query($conn, "SHOW COLUMNS FROM `post_analytics`");
$existing_cols = [];
while ($col = mysqli_fetch_assoc($cols_q)) $existing_cols[] = $col['Field'];
foreach ($patch_cols as $col => $sql) {
    if (!in_array($col, $existing_cols)) mysqli_query($conn, $sql);
}

// ══════════════════════════════════════════════════════════════════════════
//  PAGE-LEVEL TOTALS  (true Graph API numbers — 28-day window)
// ══════════════════════════════════════════════════════════════════════════
function get_pi($conn, string $metric): int {
    $m = mysqli_real_escape_string($conn, $metric);
    $q = mysqli_query($conn, "SELECT value FROM page_insights WHERE metric='$m' LIMIT 1");
    return ($q && $r = mysqli_fetch_assoc($q)) ? (int)$r['value'] : 0;
}
$page_reach    = get_pi($conn, 'page_impressions_unique');
$page_eng      = get_pi($conn, 'page_post_engagements');
$page_react    = get_pi($conn, 'page_actions_post_reactions_total');
$page_eng_rate = round(get_pi($conn, 'page_engagement_rate_x100') / 100, 2);
$ps_q          = mysqli_query($conn, "SELECT MAX(synced_at) as s FROM page_insights");
$page_synced   = mysqli_fetch_assoc($ps_q)['s'] ?? null;
$last_sync_fmt = $page_synced ? date('M j, Y g:i A', strtotime($page_synced)) : 'Never';
$has_page_data = ($page_reach + $page_eng) > 0;

// ══════════════════════════════════════════════════════════════════════════
//  POST-LEVEL AGGREGATES  (for charts, top posts, bottom cards)
// ══════════════════════════════════════════════════════════════════════════
$totals_q = mysqli_query($conn, "SELECT
    COALESCE(SUM(reach),0) as reach, COALESCE(SUM(engagement),0) as engagement,
    COALESCE(SUM(reactions),0) as reactions, COALESCE(SUM(shares),0) as shares,
    COALESCE(SUM(comments),0) as comments, COALESCE(AVG(engagement_rate),0) as avg_engagement_rate
FROM post_analytics");
$totals         = mysqli_fetch_assoc($totals_q);
$reach          = (int)$totals['reach'];
$engagement     = (int)$totals['engagement'];
$reactions      = (int)$totals['reactions'];
$shares         = (int)$totals['shares'];
$comments_total = (int)$totals['comments'];
$eng_rate       = round((float)$totals['avg_engagement_rate'], 2);
$has_data       = ($reach + $engagement + $reactions + $shares + $comments_total) > 0;

// ── Performance over time (last 7 recorded_at dates) ─────────────────────
$perf_q = mysqli_query($conn, "SELECT recorded_at,
    SUM(reach) as reach, SUM(engagement) as engagement, SUM(reactions) as reactions
    FROM post_analytics WHERE recorded_at IS NOT NULL
    GROUP BY recorded_at ORDER BY recorded_at ASC LIMIT 7");
$perf_labels = $perf_reach = $perf_eng = $perf_react = [];
while ($p = mysqli_fetch_assoc($perf_q)) {
    $perf_labels[] = date("M j", strtotime($p['recorded_at']));
    $perf_reach[]  = (int)$p['reach'];
    $perf_eng[]    = (int)$p['engagement'];
    $perf_react[]  = (int)$p['reactions'];
}

// ── Post Performance Comparison (last 3 months grouped) ──────────────────
$comp_q = mysqli_query($conn, "SELECT DATE_FORMAT(recorded_at,'%b %Y') as month,
    SUM(reach) as reach, SUM(engagement) as engagement, SUM(reactions) as reactions
    FROM post_analytics WHERE recorded_at IS NOT NULL
    GROUP BY DATE_FORMAT(recorded_at,'%Y-%m') ORDER BY MIN(recorded_at) DESC LIMIT 3");
$comp_labels = $comp_reach = $comp_eng = $comp_react = [];
while ($c = mysqli_fetch_assoc($comp_q)) {
    $comp_labels[] = $c['month']; $comp_reach[] = (int)$c['reach'];
    $comp_eng[]    = (int)$c['engagement']; $comp_react[] = (int)$c['reactions'];
}
$comp_labels = array_reverse($comp_labels); $comp_reach = array_reverse($comp_reach);
$comp_eng    = array_reverse($comp_eng);    $comp_react = array_reverse($comp_react);

// ── Top Performing Posts ──────────────────────────────────────────────────
$top_q = mysqli_query($conn, "SELECT post_title, reach, reactions, shares, comments, engagement, engagement_rate
    FROM post_analytics ORDER BY engagement DESC LIMIT 5");
$top_posts = [];
while ($t = mysqli_fetch_assoc($top_q)) $top_posts[] = $t;

function fmt($n) {
    if ($n >= 1000000) return round($n/1000000,1).'M';
    if ($n >= 1000)    return round($n/1000,1).'K';
    return number_format($n);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Analytics – NUPost Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
:root {
    --color-primary: #002366;
    --color-primary-light: #003a8c;
    --color-bg: #f5f6fa;
    --color-border: #e5e7eb;
    --color-text: #111827;
    --color-text-muted: #6b7280;
    --font: 'Inter', sans-serif;
    --sidebar-width: 220px;
    --shadow-sm: 0 1px 3px rgba(0,0,0,0.08);
    --radius: 10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html, body { height: 100%; font-family: var(--font); background: var(--color-bg); color: var(--color-text); font-size: 14px; }
.app { display: flex; min-height: 100vh; }

/* SIDEBAR */
.sidebar { width: var(--sidebar-width); background: var(--color-primary); display: flex; flex-direction: column; flex-shrink: 0; position: fixed; top: 0; left: 0; bottom: 0; z-index: 50; }
.sidebar__logo { padding: 20px 20px 16px; border-bottom: 1px solid rgba(255,255,255,0.1); }
.sidebar__logo img { height: 32px; width: auto; }
.sidebar__logo-text { font-size: 18px; font-weight: 700; color: white; }
.sidebar__nav { padding: 12px 10px; flex: 1; }
.sidebar__item { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; margin-bottom: 2px; color: rgba(255,255,255,0.7); font-size: 13px; font-weight: 500; text-decoration: none; transition: background .15s, color .15s; }
.sidebar__item:hover { background: rgba(255,255,255,0.1); color: white; }
.sidebar__item--active { background: rgba(255,255,255,0.15); color: white; }
.sidebar__footer { padding: 14px 10px; border-top: 1px solid rgba(255,255,255,0.1); }
.sidebar__footer-info { padding: 0 12px 12px; font-size: 11px; color: rgba(255,255,255,0.4); }
.sidebar__logout { display: flex; align-items: center; gap: 10px; padding: 10px 12px; border-radius: 8px; color: rgba(255,255,255,0.6); font-size: 13px; font-weight: 500; text-decoration: none; transition: background .15s, color .15s; }
.sidebar__logout:hover { background: rgba(255,255,255,0.1); color: white; }

/* MAIN */
.main { margin-left: var(--sidebar-width); flex: 1; display: flex; flex-direction: column; min-height: 100vh; }

/* TOPBAR */
.topbar { background: white; border-bottom: 1px solid var(--color-border); padding: 0 28px; height: 56px; display: flex; align-items: center; justify-content: space-between; position: sticky; top: 0; z-index: 40; }
.topbar-left { display: flex; align-items: center; gap: 12px; }
.topbar-title { font-size: 16px; font-weight: 700; }
.topbar-right { display: flex; align-items: center; gap: 10px; }
.admin-badge { display: flex; align-items: center; gap: 7px; padding: 6px 12px; background: var(--color-bg); border-radius: 8px; border: 1px solid var(--color-border); font-size: 12.5px; font-weight: 500; }

/* CONTENT */
.content { padding: 28px; flex: 1; }

/* SYNC BAR */
.sync-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; background: white; border: 1px solid var(--color-border); border-radius: var(--radius); padding: 12px 18px; flex-wrap: wrap; }
.fb-badge { display: flex; align-items: center; gap: 6px; background: #1877f2; color: white; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 6px; }
.sync-last { font-size: 12px; color: var(--color-text-muted); }
.sync-status { font-size: 12px; font-weight: 500; }
.sync-status--ok  { color: #16a34a; }
.sync-status--err { color: #dc2626; }
.sync-btn { margin-left: auto; display: flex; align-items: center; gap: 7px; background: var(--color-primary); color: white; border: none; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 500; font-family: var(--font); cursor: pointer; transition: background .15s; }
.sync-btn:hover { background: var(--color-primary-light); }
.sync-btn:disabled { opacity: .6; cursor: not-allowed; }
@keyframes spin { to { transform: rotate(360deg); } }
.spin { display: inline-block; animation: spin 1s linear infinite; }

/* DATE RANGE */
.date-range-bar { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; }
.date-range-select { height: 36px; padding: 0 12px; border: 1px solid var(--color-border); border-radius: 8px; font-size: 13px; font-family: var(--font); background: white; color: var(--color-text); outline: none; cursor: pointer; min-width: 160px; }
.date-range-select:focus { border-color: var(--color-primary); }

/* STAT CARDS */
.stat-cards { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 18px 20px; display: flex; flex-direction: column; gap: 8px; position: relative; overflow: hidden; }
.stat-card::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; }
.stat-card--reach::before     { background: #3b82f6; }
.stat-card--engage::before    { background: #10b981; }
.stat-card--reactions::before { background: #f43f5e; }
.stat-card--rate::before      { background: #8b5cf6; }
.stat-card-top { display: flex; align-items: center; justify-content: space-between; }
.stat-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.stat-icon--reach     { background: #eff6ff; color: #3b82f6; }
.stat-icon--engage    { background: #ecfdf5; color: #10b981; }
.stat-icon--reactions { background: #fff1f2; color: #f43f5e; }
.stat-icon--rate      { background: #f5f3ff; color: #8b5cf6; }
.stat-delta { font-size: 11.5px; font-weight: 600; padding: 3px 8px; border-radius: 20px; }
.stat-delta--up   { background: #dcfce7; color: #16a34a; }
.stat-delta--live { background: #dbeafe; color: #1d4ed8; }
.stat-value { font-size: 26px; font-weight: 700; color: var(--color-text); letter-spacing: -0.5px; }
.stat-label { font-size: 12px; color: var(--color-text-muted); font-weight: 500; }
.stat-source { font-size: 10.5px; color: #1877f2; font-weight: 500; }

/* CHART CARDS */
.chart-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 22px 24px; margin-bottom: 24px; }
.chart-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px; }
.chart-title { font-size: 14px; font-weight: 700; color: var(--color-text); }
.chart-toggle { display: flex; gap: 4px; }
.toggle-btn { padding: 5px 14px; border-radius: 6px; font-size: 12px; font-weight: 500; border: 1px solid var(--color-border); background: white; color: var(--color-text-muted); cursor: pointer; font-family: var(--font); transition: all .15s; }
.toggle-btn--active { background: var(--color-primary); color: white; border-color: var(--color-primary); }
.toggle-btn:hover:not(.toggle-btn--active) { background: var(--color-bg); }
.chart-container { position: relative; height: 240px; }
.chart-legend { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.legend-item { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--color-text-muted); }
.legend-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }

/* TOP POSTS */
.top-posts-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 22px 24px; margin-bottom: 24px; }
.top-posts-title { font-size: 14px; font-weight: 700; color: var(--color-text); margin-bottom: 16px; }
.top-post-item { padding: 14px 0; border-bottom: 1px solid #f3f4f6; display: flex; align-items: center; justify-content: space-between; gap: 20px; }
.top-post-item:last-child { border-bottom: none; padding-bottom: 0; }
.top-post-left { flex: 1; min-width: 0; }
.top-post-name { font-size: 13.5px; font-weight: 600; color: var(--color-text); margin-bottom: 5px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.top-post-meta { display: flex; align-items: center; gap: 14px; font-size: 11.5px; color: var(--color-text-muted); }
.top-post-meta-item { display: flex; align-items: center; gap: 4px; }
.top-post-right { text-align: right; flex-shrink: 0; }
.top-post-engagement { font-size: 20px; font-weight: 700; color: var(--color-text); }
.top-post-eng-label { font-size: 10.5px; color: var(--color-text-muted); margin-top: 1px; }

/* BOTTOM STATS */
.bottom-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
.bottom-stat-card { background: white; border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 18px 20px; display: flex; align-items: flex-start; gap: 14px; }
.bottom-stat-icon { width: 36px; height: 36px; border-radius: 9px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.bottom-stat-icon--reactions { background: #fff1f2; color: #f43f5e; }
.bottom-stat-icon--shares    { background: #eff6ff; color: #3b82f6; }
.bottom-stat-icon--comments  { background: #ecfdf5; color: #10b981; }
.bottom-stat-value { font-size: 22px; font-weight: 700; color: var(--color-text); margin-bottom: 3px; }
.bottom-stat-label { font-size: 12px; color: var(--color-text-muted); }

/* TOAST */
.toast { position: fixed; bottom: 24px; right: 24px; z-index: 999; color: white; font-size: 13px; font-weight: 500; padding: 12px 18px; border-radius: 10px; max-width: 340px; opacity: 0; transform: translateY(8px); transition: all .25s; pointer-events: none; }
.toast.show { opacity: 1; transform: translateY(0); }
.toast--ok  { background: #064e3b; }
.toast--err { background: #7f1d1d; }
</style>
</head>
<body>
<div class="app">

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="sidebar__logo">
        <img src="../auth/assets/nupostlogo.png" alt="NUPost"
             onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
        <span class="sidebar__logo-text" style="display:none;">NUPost</span>
    </div>
    <nav class="sidebar__nav">
        <a href="index.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9.5L12 3l9 6.5V20a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9.5z"/><path d="M9 21V12h6v9"/></svg>
            Dashboard
        </a>
        <a href="request_management.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Request Management
        </a>
        <a href="scheduling_calendar.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
            Scheduling &amp; Calendar
        </a>
        <a href="analytics.php" class="sidebar__item sidebar__item--active">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
            Analytics
        </a>
        <a href="reports.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
            Reports
        </a>
        <a href="settings.php" class="sidebar__item">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
            Settings
        </a>
    </nav>
    <div class="sidebar__footer">
        <div class="sidebar__footer-info">NU Lipa Marketing Office</div>
        <a href="../auth/logout.php" class="sidebar__logout">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
            Logout
        </a>
    </div>
</aside>

<!-- MAIN -->
<div class="main">

    <!-- TOPBAR -->
    <div class="topbar">
        <div class="topbar-left">
            <span class="topbar-title">Analytics</span>
        </div>
        <div class="topbar-right">
            <div class="admin-badge">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                <?= htmlspecialchars($_SESSION["admin_email"] ?? "Admin") ?>
            </div>
        </div>
    </div>

    <!-- CONTENT -->
    <div class="content">

        <!-- SYNC BAR -->
        <div class="sync-bar">
            <div class="fb-badge">
                <svg width="13" height="13" viewBox="0 0 24 24" fill="white"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg>
                Meta Graph API
            </div>
            <span class="sync-last">Last synced: <?= htmlspecialchars($last_sync_fmt) ?></span>
            <span class="sync-status" id="sync-status" style="display:none;"></span>
            <button class="sync-btn" id="sync-btn" onclick="syncMeta()">
                <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                Sync from Facebook
            </button>
        </div>

        <!-- DATE RANGE -->
        <div class="date-range-bar">
            <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="3" y1="10" x2="21" y2="10"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="16" y1="2" x2="16" y2="6"/></svg>
            <select class="date-range-select" id="date-range">
                <option value="7">Last 7 Days</option>
                <option value="30" selected>Last 30 Days</option>
                <option value="90">Last 3 Months</option>
                <option value="365">This Year</option>
            </select>
        </div>

        <!-- STAT CARDS -->
        <!-- Shows page-level Graph API numbers when synced; falls back to post aggregates -->
        <div class="stat-cards">

            <!-- Total Reach -->
            <div class="stat-card stat-card--reach">
                <div class="stat-card-top">
                    <div class="stat-icon stat-icon--reach">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                    </div>
                    <?php if ($has_page_data): ?>
                        <span class="stat-delta stat-delta--live">28-day</span>
                    <?php elseif ($has_data): ?>
                        <span class="stat-delta stat-delta--up">&#9650; +8.5%</span>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $has_page_data ? fmt($page_reach) : ($has_data ? fmt($reach) : '—') ?></div>
                <div class="stat-label">Total Reach</div>
                <?php if ($has_page_data): ?><div class="stat-source">Page-level · Meta Graph API</div><?php endif; ?>
            </div>

            <!-- Total Engagement -->
            <div class="stat-card stat-card--engage">
                <div class="stat-card-top">
                    <div class="stat-icon stat-icon--engage">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </div>
                    <?php if ($has_page_data): ?>
                        <span class="stat-delta stat-delta--live">28-day</span>
                    <?php elseif ($has_data): ?>
                        <span class="stat-delta stat-delta--up">&#9650; +13.9%</span>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $has_page_data ? fmt($page_eng) : ($has_data ? fmt($engagement) : '—') ?></div>
                <div class="stat-label">Total Engagement</div>
                <?php if ($has_page_data): ?><div class="stat-source">Page-level · Meta Graph API</div><?php endif; ?>
            </div>

            <!-- Total Reactions -->
            <div class="stat-card stat-card--reactions">
                <div class="stat-card-top">
                    <div class="stat-icon stat-icon--reactions">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                    </div>
                    <?php if ($has_page_data): ?>
                        <span class="stat-delta stat-delta--live">28-day</span>
                    <?php elseif ($has_data): ?>
                        <span class="stat-delta stat-delta--up">&#9650; +5.2%</span>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $has_page_data ? fmt($page_react) : ($has_data ? fmt($reactions) : '—') ?></div>
                <div class="stat-label">Total Reactions</div>
                <?php if ($has_page_data): ?><div class="stat-source">Page-level · Meta Graph API</div><?php endif; ?>
            </div>

            <!-- Avg Engagement Rate -->
            <div class="stat-card stat-card--rate">
                <div class="stat-card-top">
                    <div class="stat-icon stat-icon--rate">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    </div>
                    <?php if ($has_page_data): ?>
                        <span class="stat-delta stat-delta--live">28-day</span>
                    <?php elseif ($has_data): ?>
                        <span class="stat-delta stat-delta--up">&#9650; +4.1%</span>
                    <?php endif; ?>
                </div>
                <div class="stat-value"><?= $has_page_data ? $page_eng_rate.'%' : ($has_data ? $eng_rate.'%' : '—') ?></div>
                <div class="stat-label">Avg Engagement Rate</div>
                <?php if ($has_page_data): ?><div class="stat-source">Page-level · Meta Graph API</div><?php endif; ?>
            </div>

        </div><!-- /.stat-cards -->

        <!-- PERFORMANCE OVER TIME CHART -->
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <div class="chart-title">Performance Over Time</div>
                    <div class="chart-legend" style="margin-top:6px;">
                        <div class="legend-item"><div class="legend-dot" style="background:#002366;"></div> Reach</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#10b981;"></div> Engagement</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#f43f5e;"></div> Reactions</div>
                    </div>
                </div>
                <div class="chart-toggle">
                    <button class="toggle-btn toggle-btn--active" onclick="setLineMetric('reach',this)">Reach</button>
                    <button class="toggle-btn" onclick="setLineMetric('engagement',this)">Engagement</button>
                    <button class="toggle-btn" onclick="setLineMetric('reactions',this)">Reactions</button>
                </div>
            </div>
            <div class="chart-container"><canvas id="lineChart"></canvas></div>
        </div>

        <!-- POST PERFORMANCE COMPARISON CHART -->
        <div class="chart-card">
            <div class="chart-header">
                <div>
                    <div class="chart-title">Post Performance Comparison</div>
                    <div class="chart-legend" style="margin-top:6px;">
                        <div class="legend-item"><div class="legend-dot" style="background:#002366;"></div> Reach</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#f59e0b;"></div> Engagement</div>
                        <div class="legend-item"><div class="legend-dot" style="background:#10b981;"></div> Reactions</div>
                    </div>
                </div>
            </div>
            <div class="chart-container"><canvas id="barChart"></canvas></div>
        </div>

        <!-- TOP PERFORMING POSTS -->
        <div class="top-posts-card">
            <div class="top-posts-title">Top Performing Posts</div>
            <?php if (!empty($top_posts)): ?>
                <?php foreach ($top_posts as $post): ?>
                <div class="top-post-item">
                    <div class="top-post-left">
                        <div class="top-post-name"><?= htmlspecialchars($post['post_title'] ?? 'Untitled') ?></div>
                        <div class="top-post-meta">
                            <div class="top-post-meta-item">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                                <?= fmt($post['reach']) ?> reach
                            </div>
                            <div class="top-post-meta-item">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                                <?= fmt($post['reactions']) ?> reactions
                            </div>
                            <div class="top-post-meta-item">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                                <?= fmt($post['shares']) ?> shares
                            </div>
                            <div class="top-post-meta-item">
                                <svg width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                                <?= fmt($post['comments']) ?> comments
                            </div>
                        </div>
                    </div>
                    <div class="top-post-right">
                        <div class="top-post-engagement"><?= number_format($post['engagement']) ?></div>
                        <div class="top-post-eng-label">Total engagement</div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding:40px 0;text-align:center;color:#9ca3af;">
                    <svg width="36" height="36" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24" style="display:block;margin:0 auto 10px;"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
                    <p style="font-size:13px;">No post data yet — click <strong>Sync from Facebook</strong> above to pull your page analytics.</p>
                </div>
            <?php endif; ?>
        </div>

        <!-- BOTTOM STATS ROW -->
        <div class="bottom-stats">
            <div class="bottom-stat-card">
                <div class="bottom-stat-icon bottom-stat-icon--reactions">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
                </div>
                <div>
                    <div class="bottom-stat-value"><?= ($has_page_data || $has_data) ? fmt($has_page_data ? $page_react : $reactions) : '—' ?></div>
                    <div class="bottom-stat-label">Reactions<br><span style="font-size:11px;color:#9ca3af;">Total reactions across all posts</span></div>
                </div>
            </div>
            <div class="bottom-stat-card">
                <div class="bottom-stat-icon bottom-stat-icon--shares">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg>
                </div>
                <div>
                    <div class="bottom-stat-value"><?= $has_data ? fmt($shares) : '—' ?></div>
                    <div class="bottom-stat-label">Shares<br><span style="font-size:11px;color:#9ca3af;">Total shares across all posts</span></div>
                </div>
            </div>
            <div class="bottom-stat-card">
                <div class="bottom-stat-icon bottom-stat-icon--comments">
                    <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                </div>
                <div>
                    <div class="bottom-stat-value"><?= $has_data ? fmt($comments_total) : '—' ?></div>
                    <div class="bottom-stat-label">Comments<br><span style="font-size:11px;color:#9ca3af;">Total comments across all posts</span></div>
                </div>
            </div>
        </div>

    </div><!-- /.content -->
</div><!-- /.main -->
</div><!-- /.app -->

<div class="toast" id="toast"></div>

<script>
const perfLabels = <?= json_encode($perf_labels) ?>;
const perfReach  = <?= json_encode($perf_reach)  ?>;
const perfEng    = <?= json_encode($perf_eng)    ?>;
const perfReact  = <?= json_encode($perf_react)  ?>;
const compLabels = <?= json_encode($comp_labels) ?>;
const compReach  = <?= json_encode($comp_reach)  ?>;
const compEng    = <?= json_encode($comp_eng)    ?>;
const compReact  = <?= json_encode($comp_react)  ?>;
const hasPerf = perfLabels.length > 0;
const hasComp = compLabels.length > 0;

function showToast(msg, type = 'ok') {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = `toast toast--${type} show`;
    setTimeout(() => t.classList.remove('show'), 4500);
}

async function syncMeta() {
    const btn = document.getElementById('sync-btn');
    const st  = document.getElementById('sync-status');
    btn.disabled = true;
    btn.innerHTML = `<span class="spin">&#8635;</span> Syncing…`;
    st.style.display = 'none';
    try {
        const r = await fetch('analytics.php?action=sync_meta');
        const d = await r.json();
        if (d.ok) {
            showToast(d.msg, 'ok');
            st.textContent = '✓ Synced just now';
            st.className   = 'sync-status sync-status--ok';
            st.style.display = 'inline';
            setTimeout(() => location.reload(), 1800);
        } else {
            showToast(d.msg, 'err');
            st.textContent = '✗ Sync failed';
            st.className   = 'sync-status sync-status--err';
            st.style.display = 'inline';
        }
    } catch(e) { showToast('Network error — check server logs.', 'err'); }
    btn.disabled = false;
    btn.innerHTML = `<svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="17 1 21 5 17 9"/><path d="M3 11V9a4 4 0 0 1 4-4h14"/><polyline points="7 23 3 19 7 15"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/></svg> Sync from Facebook`;
}

Chart.register({
    id: 'emptyState',
    afterDraw(chart) {
        const hasData = chart.data.datasets.some(d => d.data && d.data.some(v => v !== 0 && v !== null));
        if (!hasData) {
            const { ctx, chartArea: { left, top, right, bottom } } = chart;
            ctx.save();
            ctx.textAlign = 'center'; ctx.textBaseline = 'middle';
            ctx.fillStyle = '#9ca3af'; ctx.font = '13px Inter, sans-serif';
            ctx.fillText('No data yet — click "Sync from Facebook" to populate this chart', (left+right)/2, (top+bottom)/2);
            ctx.restore();
        }
    }
});
Chart.defaults.font.family = "'Inter', sans-serif";
Chart.defaults.font.size   = 12;
Chart.defaults.color       = '#6b7280';

const lineData = {
    reach:      { data: perfReach,  color: '#002366' },
    engagement: { data: perfEng,    color: '#10b981' },
    reactions:  { data: perfReact,  color: '#f43f5e' },
};

let lineChart = new Chart(document.getElementById('lineChart').getContext('2d'), {
    type: 'line',
    data: {
        labels: hasPerf ? perfLabels : ['—'],
        datasets: [{
            label: 'Reach', data: hasPerf ? perfReach : [0],
            borderColor: '#002366', backgroundColor: 'rgba(0,35,102,0.07)',
            borderWidth: 2.5, pointRadius: hasPerf ? 4 : 0,
            pointBackgroundColor: '#002366', pointBorderColor: 'white', pointBorderWidth: 2,
            tension: 0.4, fill: true,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { display: false },
            tooltip: {
                enabled: hasPerf, backgroundColor: 'white', titleColor: '#111827',
                bodyColor: '#6b7280', borderColor: '#e5e7eb', borderWidth: 1, padding: 10,
                callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() }
            }
        },
        scales: {
            x: { grid: { display: false }, border: { display: false } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, border: { display: false },
                 ticks: { callback: v => v >= 1000 ? (v/1000)+'K' : v } }
        }
    }
});

function setLineMetric(metric, btn) {
    document.querySelectorAll('.toggle-btn').forEach(b => b.classList.remove('toggle-btn--active'));
    btn.classList.add('toggle-btn--active');
    const d = lineData[metric];
    lineChart.data.datasets[0].data                 = hasPerf ? d.data : [0];
    lineChart.data.datasets[0].label                = btn.textContent;
    lineChart.data.datasets[0].borderColor          = d.color;
    lineChart.data.datasets[0].backgroundColor      = d.color + '12';
    lineChart.data.datasets[0].pointBackgroundColor = d.color;
    lineChart.update();
}

new Chart(document.getElementById('barChart').getContext('2d'), {
    type: 'bar',
    data: {
        labels: hasComp ? compLabels : ['—'],
        datasets: [
            { label: 'Reach',      data: hasComp ? compReach : [0], backgroundColor: '#002366', borderRadius: 4, borderSkipped: false },
            { label: 'Engagement', data: hasComp ? compEng   : [0], backgroundColor: '#f59e0b', borderRadius: 4, borderSkipped: false },
            { label: 'Reactions',  data: hasComp ? compReact : [0], backgroundColor: '#10b981', borderRadius: 4, borderSkipped: false },
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: {
                enabled: hasComp, backgroundColor: 'white', titleColor: '#111827',
                bodyColor: '#6b7280', borderColor: '#e5e7eb', borderWidth: 1, padding: 10,
                callbacks: { label: ctx => ' ' + ctx.dataset.label + ': ' + ctx.parsed.y.toLocaleString() }
            }
        },
        scales: {
            x: { grid: { display: false }, border: { display: false } },
            y: { beginAtZero: true, grid: { color: '#f3f4f6' }, border: { display: false },
                 ticks: { callback: v => v >= 1000 ? (v/1000)+'K' : v } }
        }
    }
});
</script>
</body>
</html>