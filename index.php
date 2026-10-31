<?php
/**
 * ╔══════════════════════════════════════════════════════════════╗
 * ║   Tableau de Bord - Luxeuil-les-Bains                       ║
 * ║   Météo temps réel + Historique MySQL + Mode sombre/clair   ║
 * ╚══════════════════════════════════════════════════════════════╝
 *
 * PRÉREQUIS MySQL — exécuter une seule fois :
 * ─────────────────────────────────────────────
 * CREATE DATABASE IF NOT EXISTS meteo_lxb CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
 * CREATE USER IF NOT EXISTS 'meteo_user'@'localhost' IDENTIFIED BY 'MotDePasseSecurise!';
 * GRANT ALL PRIVILEGES ON meteo_lxb.* TO 'meteo_user'@'localhost';
 * FLUSH PRIVILEGES;
 *
 * La table est créée automatiquement au premier chargement.
 */

// ══════════════════════════════════════════════════════════════
//  CONFIGURATION — à adapter selon votre environnement
// ══════════════════════════════════════════════════════════════
define('LAT',   47.8167);
define('LON',    6.3833);
define('VILLE', 'Luxeuil-les-Bains');
define('TZ',    'Europe/Paris');

// MySQL
define('DB_HOST', 'localhost');
define('DB_NAME', 'meteo_lxb');
define('DB_USER', 'meteo_user');
define('DB_PASS', 'MotDePasseSecurise!');
define('DB_PORT', 3306);

// Sauvegarde toutes les N minutes (évite le spam)
define('SAVE_INTERVAL_MIN', 30);

// Nombre de lignes affichées dans l'historique
define('HISTORY_ROWS', 20);

// ══════════════════════════════════════════════════════════════
date_default_timezone_set(TZ);

// ──────────────────────────────────────────────────────────────
//  CONNEXION MySQL (PDO)
// ──────────────────────────────────────────────────────────────
function get_pdo(): ?PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;
    try {
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                       DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } catch (PDOException $e) {
        // On log silencieusement, la page s'affiche quand même
        error_log('[MeteoLXB] PDO: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

// ──────────────────────────────────────────────────────────────
//  CRÉATION DE LA TABLE (auto, première visite)
// ──────────────────────────────────────────────────────────────
function create_table(): void {
    $pdo = get_pdo();
    if (!$pdo) return;
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS historique_meteo (
            id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
            enregistre_le   DATETIME     NOT NULL,
            ville           VARCHAR(80)  NOT NULL,
            latitude        DECIMAL(8,4) NOT NULL,
            longitude       DECIMAL(8,4) NOT NULL,
            temperature     DECIMAL(5,2) DEFAULT NULL,
            ressenti        DECIMAL(5,2) DEFAULT NULL,
            humidite        TINYINT UNSIGNED DEFAULT NULL,
            vent_kmh        DECIMAL(6,2) DEFAULT NULL,
            precipitation   DECIMAL(6,2) DEFAULT NULL,
            code_wmo        SMALLINT UNSIGNED DEFAULT NULL,
            condition_texte VARCHAR(80)  DEFAULT NULL,
            lever_soleil    TIME         DEFAULT NULL,
            coucher_soleil  TIME         DEFAULT NULL,
            PRIMARY KEY (id),
            INDEX idx_date  (enregistre_le),
            INDEX idx_ville (ville)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

// ──────────────────────────────────────────────────────────────
//  SAUVEGARDE D'UNE MESURE (respecte l'intervalle)
// ──────────────────────────────────────────────────────────────
function save_meteo(array $m, string $condition): bool {
    $pdo = get_pdo();
    if (!$pdo) return false;

    // Vérifier la dernière insertion
    $last = $pdo->query(
        "SELECT enregistre_le FROM historique_meteo
         ORDER BY enregistre_le DESC LIMIT 1"
    )->fetchColumn();

    if ($last) {
        $diff = (time() - strtotime($last)) / 60;
        if ($diff < SAVE_INTERVAL_MIN) return false; // pas encore
    }

    $stmt = $pdo->prepare("
        INSERT INTO historique_meteo
            (enregistre_le, ville, latitude, longitude,
             temperature, ressenti, humidite, vent_kmh, precipitation,
             code_wmo, condition_texte, lever_soleil, coucher_soleil)
        VALUES
            (NOW(), :ville, :lat, :lon,
             :temp, :ressenti, :hum, :vent, :precip,
             :code, :cond, :sr, :ss)
    ");

    $sunrise = ($m['sunrise'] !== '—') ? $m['sunrise'] . ':00' : null;
    $sunset  = ($m['sunset']  !== '—') ? $m['sunset']  . ':00' : null;

    $stmt->execute([
        ':ville'   => VILLE,
        ':lat'     => LAT,
        ':lon'     => LON,
        ':temp'    => is_numeric($m['temp'])     ? $m['temp']     : null,
        ':ressenti'=> is_numeric($m['apparent']) ? $m['apparent'] : null,
        ':hum'     => is_numeric($m['humidity']) ? $m['humidity'] : null,
        ':vent'    => is_numeric($m['wind'])     ? $m['wind']     : null,
        ':precip'  => is_numeric($m['precip'])   ? $m['precip']   : null,
        ':code'    => (int)$m['code'],
        ':cond'    => $condition,
        ':sr'      => $sunrise,
        ':ss'      => $sunset,
    ]);
    return true;
}

// ──────────────────────────────────────────────────────────────
//  LECTURE DE L'HISTORIQUE
// ──────────────────────────────────────────────────────────────
function get_historique(int $limit = HISTORY_ROWS): array {
    $pdo = get_pdo();
    if (!$pdo) return [];
    $stmt = $pdo->prepare("
        SELECT id, enregistre_le, temperature, ressenti, humidite,
               vent_kmh, precipitation, condition_texte, code_wmo,
               lever_soleil, coucher_soleil
        FROM historique_meteo
        WHERE ville = :ville
        ORDER BY enregistre_le DESC
        LIMIT :lim
    ");
    $stmt->bindValue(':ville', VILLE);
    $stmt->bindValue(':lim',   $limit, PDO::PARAM_INT);
    $stmt->execute();
    return $stmt->fetchAll();
}

// ──────────────────────────────────────────────────────────────
//  STATISTIQUES (min/max/moy sur 30 jours)
// ──────────────────────────────────────────────────────────────
function get_stats(): array {
    $pdo = get_pdo();
    if (!$pdo) return [];
    $stmt = $pdo->prepare("
        SELECT
            COUNT(*)                     AS nb_mesures,
            ROUND(MIN(temperature),1)    AS t_min,
            ROUND(MAX(temperature),1)    AS t_max,
            ROUND(AVG(temperature),1)    AS t_moy,
            ROUND(AVG(humidite),0)       AS h_moy,
            ROUND(MAX(vent_kmh),1)       AS vent_max,
            ROUND(SUM(precipitation),1)  AS precip_total,
            MIN(enregistre_le)           AS depuis
        FROM historique_meteo
        WHERE ville = ?
          AND enregistre_le >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute([VILLE]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
}

// ──────────────────────────────────────────────────────────────
//  DONNÉES POUR LE GRAPHIQUE (48 dernières mesures)
// ──────────────────────────────────────────────────────────────
function get_chart_data(): array {
    $pdo = get_pdo();
    if (!$pdo) return [];
    $stmt = $pdo->prepare("
        SELECT DATE_FORMAT(enregistre_le,'%d/%m %H:%i') AS label,
               temperature, humidite, precipitation
        FROM historique_meteo
        WHERE ville = ?
        ORDER BY enregistre_le DESC
        LIMIT 48
    ");
    $stmt->execute([VILLE]);
    return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
}

// ──────────────────────────────────────────────────────────────
//  API MÉTÉO Open-Meteo
// ──────────────────────────────────────────────────────────────
function fetch_url(string $url): ?array {
    $cache_dir  = sys_get_temp_dir() . '/lxb_cache/';
    $cache_file = $cache_dir . md5($url) . '.json';
    if (!is_dir($cache_dir)) @mkdir($cache_dir, 0755, true);

    if (file_exists($cache_file) && (time() - filemtime($cache_file)) < 600) {
        $raw = file_get_contents($cache_file);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 8, 'user_agent' => 'TableauBord/2.0']]);
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw !== false) file_put_contents($cache_file, $raw);
    }
    return ($raw !== false) ? json_decode($raw, true) : null;
}

function get_meteo(): array {
    $url = sprintf(
        'https://api.open-meteo.com/v1/forecast?latitude=%s&longitude=%s'
        . '&current=temperature_2m,apparent_temperature,weather_code,'
        . 'wind_speed_10m,relative_humidity_2m,precipitation'
        . '&daily=temperature_2m_max,temperature_2m_min,precipitation_sum,'
        . 'sunrise,sunset,weather_code'
        . '&timezone=%s&forecast_days=5&wind_speed_unit=kmh',
        LAT, LON, urlencode(TZ)
    );
    $data = fetch_url($url);
    $def  = ['temp'=>'—','apparent'=>'—','humidity'=>'—','wind'=>'—',
             'precip'=>'—','code'=>0,'sunrise'=>'—','sunset'=>'—','daily'=>[]];
    if (!$data || !isset($data['current'])) return $def;

    $cur = $data['current'];
    $day = $data['daily'];
    $daily = [];
    if (isset($day['time'])) {
        foreach ($day['time'] as $i => $date) {
            $daily[] = [
                'date'   => $date,
                'tmax'   => $day['temperature_2m_max'][$i] ?? '—',
                'tmin'   => $day['temperature_2m_min'][$i] ?? '—',
                'precip' => $day['precipitation_sum'][$i] ?? 0,
                'code'   => $day['weather_code'][$i] ?? 0,
            ];
        }
    }
    $sunrise = isset($day['sunrise'][0]) ? date('H:i', strtotime($day['sunrise'][0])) : '—';
    $sunset  = isset($day['sunset'][0])  ? date('H:i', strtotime($day['sunset'][0]))  : '—';

    return [
        'temp'     => round($cur['temperature_2m'] ?? 0),
        'apparent' => round($cur['apparent_temperature'] ?? 0),
        'humidity' => $cur['relative_humidity_2m'] ?? '—',
        'wind'     => round($cur['wind_speed_10m'] ?? 0),
        'precip'   => $cur['precipitation'] ?? 0,
        'code'     => $cur['weather_code'] ?? 0,
        'sunrise'  => $sunrise,
        'sunset'   => $sunset,
        'daily'    => $daily,
    ];
}

function wmo_to_label(int $code): array {
    $map = [
        0=>'Ciel dégagé|☀️', 1=>'Peu nuageux|🌤️', 2=>'Partiellement nuageux|⛅',
        3=>'Couvert|☁️', 45=>'Brouillard|🌫️', 48=>'Brouillard givrant|🌫️',
        51=>'Bruine légère|🌦️', 53=>'Bruine modérée|🌦️', 55=>'Bruine dense|🌧️',
        61=>'Pluie légère|🌧️', 63=>'Pluie modérée|🌧️', 65=>'Pluie forte|🌧️',
        71=>'Neige légère|🌨️', 73=>'Neige modérée|❄️', 75=>'Neige forte|❄️',
        80=>'Averses légères|🌦️', 81=>'Averses modérées|🌧️', 82=>'Averses fortes|⛈️',
        95=>'Orage|⛈️', 96=>'Orage avec grêle|⛈️', 99=>'Orage violent|🌩️',
    ];
    $v = $map[$code] ?? 'Inconnu|🌡️';
    return explode('|', $v);
}

function jours_restants(int $mois, int $jour): int {
    $annee = (int)date('Y');
    $ts  = mktime(0,0,0,$mois,$jour,$annee);
    $auj = mktime(0,0,0,(int)date('m'),(int)date('d'),$annee);
    if ($ts < $auj) $ts = mktime(0,0,0,$mois,$jour,$annee+1);
    return (int)round(($ts-$auj)/86400);
}

// ══════════════════════════════════════════════════════════════
//  INITIALISATION
// ══════════════════════════════════════════════════════════════
create_table();
$meteo    = get_meteo();
$wmo      = wmo_to_label((int)$meteo['code']);
$db_ok    = (get_pdo() !== null);
$saved    = false;
if ($db_ok && is_numeric($meteo['temp'])) {
    $saved = save_meteo($meteo, $wmo[0]);
}
$historique  = get_historique();
$stats       = get_stats();
$chart_data  = get_chart_data();

$fetes = [
    ['nom'=>'Fête Nationale','emoji'=>'🇫🇷','mois'=>7, 'jour'=>14,'desc'=>'14 Juillet'],
    ['nom'=>'Assomption',    'emoji'=>'🕊️', 'mois'=>8, 'jour'=>15,'desc'=>'15 Août'],
    ['nom'=>'Noël',          'emoji'=>'🎄', 'mois'=>12,'jour'=>25,'desc'=>'25 Décembre'],
    ['nom'=>'Réveillon',     'emoji'=>'🎆', 'mois'=>12,'jour'=>31,'desc'=>'31 Décembre'],
];
foreach ($fetes as &$f) { $f['jours'] = jours_restants($f['mois'],$f['jour']); } unset($f);

$now = new DateTime('now', new DateTimeZone(TZ));


// ── Phase de lune (algorithme de Conway simplifié) ──────────────
function moon_phase(): array {
    $now   = time();
    $year  = (int)date('Y', $now);
    $month = (int)date('n', $now);
    $day   = (int)date('j', $now);
    // Calcul de l'âge de la lune (en jours depuis nouvelle lune)
    $c = $e = $jd = $b = 0;
    if ($month < 3) { $year--; $month += 12; }
    $month++;
    $c   = 365.25 * $year;
    $e   = 30.6 * $month;
    $jd  = $c + $e + $day - 694039.09;
    $jd /= 29.5305882;
    $b   = (int)$jd;
    $jd -= $b;
    $age = round($jd * 29.5305882); // jours depuis nouvelle lune (0–29)

    $phases = [
        ['nom'=>'Nouvelle Lune',        'emoji'=>'🌑', 'min'=>0,  'max'=>1 ],
        ['nom'=>'Premier croissant',    'emoji'=>'🌒', 'min'=>1,  'max'=>7 ],
        ['nom'=>'Premier quartier',     'emoji'=>'🌓', 'min'=>7,  'max'=>8 ],
        ['nom'=>'Lune gibbeuse croiss.','emoji'=>'🌔', 'min'=>8,  'max'=>14],
        ['nom'=>'Pleine Lune',          'emoji'=>'🌕', 'min'=>14, 'max'=>15],
        ['nom'=>'Lune gibbeuse décr.',  'emoji'=>'🌖', 'min'=>15, 'max'=>21],
        ['nom'=>'Dernier quartier',     'emoji'=>'🌗', 'min'=>21, 'max'=>22],
        ['nom'=>'Dernier croissant',    'emoji'=>'🌘', 'min'=>22, 'max'=>29],
    ];
    $phase_courante = $phases[7]; // fallback
    foreach ($phases as $p) {
        if ($age >= $p['min'] && $age < $p['max']) { $phase_courante = $p; break; }
    }
    // Prochaine pleine lune et nouvelle lune
    $jours_pleine  = ($age <= 14) ? 14 - $age : 44 - $age;
    $jours_nouvelle = ($age == 0) ? 0 : 29 - $age;
    $illumination  = (int)round((1 - cos($age / 29.5305882 * 2 * M_PI)) / 2 * 100);
    return [
        'age'            => $age,
        'emoji'          => $phase_courante['emoji'],
        'nom'            => $phase_courante['nom'],
        'illumination'   => $illumination,
        'jours_pleine'   => $jours_pleine,
        'jours_nouvelle' => $jours_nouvelle,
        'cycle_pct'      => round($age / 29.5 * 100),
        'all_phases'     => $phases,
    ];
}

// ── Fuseaux horaires ─────────────────────────────────────────────
$villes_monde = [
    ['nom'=>'Paris',    'tz'=>'Europe/Paris',    'emoji'=>'🇫🇷', 'hiver'=>'UTC+1', 'ete'=>'UTC+2'],
    ['nom'=>'Moscou',   'tz'=>'Europe/Moscow',   'emoji'=>'🇷🇺', 'hiver'=>'UTC+3', 'ete'=>'UTC+3'],
    ['nom'=>'New York', 'tz'=>'America/New_York','emoji'=>'🇺🇸', 'hiver'=>'UTC-5', 'ete'=>'UTC-4'],
    ['nom'=>'Tokyo',    'tz'=>'Asia/Tokyo',      'emoji'=>'🇯🇵', 'hiver'=>'UTC+9', 'ete'=>'UTC+9'],
];
// Déterminer si on est en heure d'été pour Paris
$estHiver = !(bool)(new DateTime('now', new DateTimeZone('Europe/Paris')))->format('I');
$lune = moon_phase();

// JSON pour les graphiques
$chart_labels = json_encode(array_column($chart_data, 'label'));
$chart_temps  = json_encode(array_column($chart_data, 'temperature'));
$chart_hum    = json_encode(array_column($chart_data, 'humidite'));
$chart_rain   = json_encode(array_column($chart_data, 'precipitation'));
?>
<!DOCTYPE html>
<html lang="fr" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Tableau de Bord – <?= VILLE ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<style>
/* ── Variables thème ─────────────────────────────────────────── */
:root { --transition:.35s cubic-bezier(.4,0,.2,1); }

[data-theme="dark"] {
    --bg:#0e1117; --surface:#161b26; --surface2:#1e2535;
    --border:rgba(255,255,255,.08); --text:#e8ecf4; --muted:#7a8aa0;
    --accent:#4f8ef7; --accent2:#f7c94f; --sun:#f7a94f;
    --danger:#f76f6f; --success:#4fcf8e;
    --grad-card:linear-gradient(135deg,#1e2535,#161b26);
    --shadow:0 8px 32px rgba(0,0,0,.45);
    --chart-grid:rgba(255,255,255,.06);
    --table-stripe:rgba(255,255,255,.03);
}
[data-theme="light"] {
    --bg:#f0f4fc; --surface:#fff; --surface2:#e8edf8;
    --border:rgba(0,0,0,.08); --text:#1a2035; --muted:#6b7a99;
    --accent:#2563eb; --accent2:#d97706; --sun:#ea8c04;
    --danger:#dc2626; --success:#16a34a;
    --grad-card:linear-gradient(135deg,#fff,#f0f4fc);
    --shadow:0 8px 32px rgba(37,99,235,.10);
    --chart-grid:rgba(0,0,0,.06);
    --table-stripe:rgba(0,0,0,.02);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

body {
    font-family:'DM Sans',sans-serif;
    background:var(--bg); color:var(--text);
    min-height:100vh;
    transition:background var(--transition),color var(--transition);
    line-height:1.6;
}
body::before {
    content:''; position:fixed; inset:0; pointer-events:none; z-index:0;
    background:
        radial-gradient(ellipse 60% 40% at 20% 10%,rgba(79,142,247,.1) 0%,transparent 70%),
        radial-gradient(ellipse 50% 35% at 80% 80%,rgba(247,201,79,.07) 0%,transparent 70%);
}
[data-theme="light"] body::before {
    background:
        radial-gradient(ellipse 60% 40% at 20% 10%,rgba(37,99,235,.06) 0%,transparent 70%),
        radial-gradient(ellipse 50% 35% at 80% 80%,rgba(217,119,6,.05) 0%,transparent 70%);
}

/* ── Layout ─────────────────────────────────────────────────── */
.container { position:relative; z-index:1; max-width:960px; margin:0 auto; padding:2rem 1.25rem 4rem; }

/* ── Header ─────────────────────────────────────────────────── */
header { display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:2.5rem; gap:1rem; }
.header-left h1 {
    font-family:'DM Serif Display',serif; font-size:clamp(1.5rem,4vw,2.3rem);
    font-weight:400; letter-spacing:-.02em; line-height:1.2;
}
.header-left h1 span { color:var(--accent); }
.header-left .subtitle { color:var(--muted); font-size:.875rem; margin-top:.3rem; font-weight:300; }

.header-right { display:flex; flex-direction:column; align-items:flex-end; gap:.5rem; }

.theme-toggle {
    background:var(--surface2); border:1px solid var(--border); border-radius:50px;
    cursor:pointer; padding:.45rem .9rem; display:flex; align-items:center; gap:.4rem;
    font-size:.82rem; color:var(--text); font-family:'DM Sans',sans-serif; font-weight:500;
    transition:all var(--transition); white-space:nowrap;
}
.theme-toggle:hover { border-color:var(--accent); color:var(--accent); }

.db-badge {
    font-size:.72rem; padding:.25rem .65rem; border-radius:50px; font-weight:600;
    display:inline-flex; align-items:center; gap:.3rem;
}
.db-badge.ok  { background:rgba(79,207,142,.15); color:var(--success); border:1px solid rgba(79,207,142,.3); }
.db-badge.err { background:rgba(247,111,111,.12); color:var(--danger);  border:1px solid rgba(247,111,111,.25); }

/* ── Date hero ──────────────────────────────────────────────── */
.date-hero {
    background:var(--grad-card); border:1px solid var(--border); border-radius:20px;
    padding:1.75rem 2rem; margin-bottom:1.5rem;
    display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:1rem;
    box-shadow:var(--shadow);
}
.date-hero .big-date {
    font-family:'DM Serif Display',serif; font-size:clamp(1.4rem,3.5vw,2rem);
    font-weight:400; color:var(--text);
}
.date-hero .big-date em { font-style:italic; color:var(--accent); }
.date-hero .time-live {
    font-size:1.9rem; font-weight:600; color:var(--accent2);
    letter-spacing:.05em; font-variant-numeric:tabular-nums;
}

/* ── Grid 2 col ─────────────────────────────────────────────── */
.grid-2 { display:grid; grid-template-columns:1fr 1fr; gap:1.25rem; margin-bottom:1.5rem; }
@media(max-width:600px){ .grid-2{grid-template-columns:1fr} }

/* ── Card ───────────────────────────────────────────────────── */
.card {
    background:var(--grad-card); border:1px solid var(--border); border-radius:20px;
    padding:1.5rem; box-shadow:var(--shadow); transition:transform .2s,box-shadow .2s;
}
.card:hover { transform:translateY(-2px); box-shadow:var(--shadow),0 0 0 1px var(--accent); }
.card-title {
    font-size:.68rem; text-transform:uppercase; letter-spacing:.13em;
    color:var(--muted); font-weight:600; margin-bottom:1rem;
    display:flex; align-items:center; gap:.4rem;
}

/* ── Météo ──────────────────────────────────────────────────── */
.meteo-main { display:flex; align-items:center; gap:1rem; margin-bottom:1rem; }
.meteo-emoji { font-size:3.5rem; line-height:1; filter:drop-shadow(0 2px 8px rgba(0,0,0,.2)); }
.temp-big { font-family:'DM Serif Display',serif; font-size:3.2rem; font-weight:400; line-height:1; }
.temp-big sup { font-size:1.3rem; vertical-align:super; color:var(--muted); }
.meteo-desc { color:var(--muted); font-size:.875rem; margin-top:.15rem; }
.meteo-stats { display:grid; grid-template-columns:1fr 1fr; gap:.55rem; }
.stat { background:var(--surface2); border-radius:10px; padding:.55rem .8rem; font-size:.8rem; }
.stat .label { color:var(--muted); font-size:.7rem; margin-bottom:.1rem; }
.stat .val   { font-weight:600; }

/* ── Soleil ─────────────────────────────────────────────────── */
.sun-row { display:flex; flex-direction:column; gap:.7rem; }
.sun-item { display:flex; align-items:center; gap:.9rem; background:var(--surface2); border-radius:12px; padding:.85rem 1rem; }
.sun-icon { font-size:1.8rem; width:2.2rem; text-align:center; }
.sun-label { color:var(--muted); font-size:.73rem; }
.sun-time  { font-family:'DM Serif Display',serif; font-size:1.5rem; color:var(--sun); }
.sun-bar { background:var(--surface2); border-radius:99px; height:6px; margin:.4rem 0 .2rem; overflow:hidden; }
.sun-bar-fill { height:100%; background:linear-gradient(90deg,var(--sun),var(--accent2)); border-radius:99px; }
.sun-duration { text-align:center; color:var(--muted); font-size:.78rem; margin-top:.7rem; }

/* ── Fêtes ──────────────────────────────────────────────────── */
.fetes-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(185px,1fr)); gap:1.25rem; margin-bottom:1.5rem; }
.fete-card {
    background:var(--grad-card); border:1px solid var(--border); border-radius:20px;
    padding:1.4rem; box-shadow:var(--shadow); position:relative; overflow:hidden;
    transition:transform .2s,box-shadow .2s;
}
.fete-card:hover { transform:translateY(-3px); box-shadow:var(--shadow),0 0 0 1px var(--accent2); }
.fete-card::before {
    content:attr(data-emoji); position:absolute; right:-5px; bottom:-10px;
    font-size:4.5rem; opacity:.12; line-height:1; pointer-events:none;
}
.fete-nom        { font-size:.68rem; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); font-weight:600; margin-bottom:.3rem; }
.fete-date-label { font-size:.85rem; color:var(--muted); margin-bottom:.8rem; }
.fete-jours      { font-family:'DM Serif Display',serif; font-size:2.8rem; font-weight:400; color:var(--accent2); line-height:1; }
.fete-jours span { font-family:'DM Sans',sans-serif; font-size:.78rem; color:var(--muted); font-weight:400; margin-left:.25rem; vertical-align:middle; }
.fete-aujourd    { color:var(--accent); }

/* ── Prévisions ─────────────────────────────────────────────── */
.previsions { background:var(--grad-card); border:1px solid var(--border); border-radius:20px; padding:1.5rem; box-shadow:var(--shadow); margin-bottom:1.5rem; }
.prev-row { display:grid; grid-template-columns:repeat(5,1fr); gap:.7rem; margin-top:1rem; }
@media(max-width:550px){ .prev-row{grid-template-columns:repeat(3,1fr)} .prev-row .prev-day:nth-child(n+4){display:none} }
.prev-day { background:var(--surface2); border-radius:14px; padding:.9rem .6rem; text-align:center; }
.prev-day.today { background:linear-gradient(135deg,var(--accent),rgba(79,142,247,.3)); border:1px solid var(--accent); }
.prev-day .pd-label { font-size:.68rem; color:var(--muted); font-weight:600; }
.prev-day.today .pd-label { color:#fff; }
.prev-day .pd-icon  { font-size:1.6rem; margin:.4rem 0; }
.prev-day .pd-max   { font-weight:700; font-size:.9rem; }
.prev-day .pd-min   { font-size:.78rem; color:var(--muted); }
.prev-day .pd-rain  { font-size:.68rem; color:var(--accent); margin-top:.2rem; }

/* ── Section historique / graphique ─────────────────────────── */
.section-title {
    font-size:.68rem; text-transform:uppercase; letter-spacing:.13em;
    color:var(--muted); font-weight:600; margin-bottom:.75rem;
    display:flex; align-items:center; gap:.4rem;
}

/* ── Stats 30j ──────────────────────────────────────────────── */
.stats-30j {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(130px,1fr));
    gap:.75rem; margin-bottom:1.5rem;
}
.stat-box {
    background:var(--grad-card); border:1px solid var(--border); border-radius:16px;
    padding:1rem 1.1rem; box-shadow:var(--shadow); text-align:center;
}
.stat-box .sb-label { font-size:.68rem; color:var(--muted); font-weight:600; text-transform:uppercase; letter-spacing:.08em; margin-bottom:.4rem; }
.stat-box .sb-val   { font-family:'DM Serif Display',serif; font-size:1.7rem; color:var(--accent); font-weight:400; }
.stat-box .sb-unit  { font-family:'DM Sans',sans-serif; font-size:.75rem; color:var(--muted); }

/* ── Graphique ──────────────────────────────────────────────── */
.chart-wrap {
    background:var(--grad-card); border:1px solid var(--border); border-radius:20px;
    padding:1.5rem; box-shadow:var(--shadow); margin-bottom:1.5rem; position:relative;
}
.chart-tabs { display:flex; gap:.5rem; margin-bottom:1rem; flex-wrap:wrap; }
.chart-tab {
    background:var(--surface2); border:1px solid var(--border); border-radius:8px;
    padding:.35rem .8rem; font-size:.78rem; font-weight:500; cursor:pointer;
    color:var(--muted); font-family:'DM Sans',sans-serif; transition:all .2s;
}
.chart-tab.active { background:var(--accent); color:#fff; border-color:var(--accent); }
.chart-container { position:relative; height:220px; }

/* ── Tableau historique ─────────────────────────────────────── */
.histo-wrap {
    background:var(--grad-card); border:1px solid var(--border); border-radius:20px;
    padding:1.5rem; box-shadow:var(--shadow); margin-bottom:1.5rem; overflow:hidden;
}
.histo-header { display:flex; justify-content:space-between; align-items:center; margin-bottom:1rem; flex-wrap:wrap; gap:.5rem; }
.export-btn {
    background:var(--surface2); border:1px solid var(--border); border-radius:8px;
    cursor:pointer; padding:.35rem .85rem; font-size:.75rem; color:var(--muted);
    font-family:'DM Sans',sans-serif; font-weight:500; transition:all .2s;
    text-decoration:none; display:inline-flex; align-items:center; gap:.35rem;
}
.export-btn:hover { border-color:var(--success); color:var(--success); }

.table-scroll { overflow-x:auto; -webkit-overflow-scrolling:touch; }
table { width:100%; border-collapse:collapse; font-size:.8rem; white-space:nowrap; }
thead th {
    background:var(--surface2); color:var(--muted); font-weight:600;
    font-size:.68rem; text-transform:uppercase; letter-spacing:.09em;
    padding:.65rem .9rem; text-align:left; border-bottom:1px solid var(--border);
}
thead th:first-child { border-radius:10px 0 0 10px; }
thead th:last-child  { border-radius:0 10px 10px 0; }
tbody tr { transition:background .15s; }
tbody tr:hover { background:var(--surface2); }
tbody tr:nth-child(even) { background:var(--table-stripe); }
tbody td { padding:.6rem .9rem; border-bottom:1px solid var(--border); color:var(--text); }
tbody tr:last-child td { border-bottom:none; }

.badge-cond {
    display:inline-block; background:var(--surface2); border-radius:6px;
    padding:.15rem .5rem; font-size:.72rem;
}
.temp-val { font-weight:600; color:var(--accent); }
.new-row   { animation:fadeUp .4s ease; }

/* ── Save indicator ─────────────────────────────────────────── */
.save-toast {
    position:fixed; bottom:1.5rem; right:1.5rem; z-index:100;
    background:var(--success); color:#fff; border-radius:12px;
    padding:.65rem 1.2rem; font-size:.82rem; font-weight:600;
    box-shadow:0 4px 20px rgba(0,0,0,.3); display:flex; align-items:center; gap:.5rem;
    animation:slideIn .3s ease, fadeOut .4s ease 3s forwards;
}
@keyframes slideIn  { from{transform:translateY(20px);opacity:0} to{transform:translateY(0);opacity:1} }
@keyframes fadeOut  { to{opacity:0;transform:translateY(10px)} }
@keyframes fadeUp   { from{opacity:0;transform:translateY(16px)} to{opacity:1;transform:translateY(0)} }

.card,.fete-card,.date-hero,.previsions,.chart-wrap,.histo-wrap { animation:fadeUp .45s ease both; }
.fete-card:nth-child(2){animation-delay:.06s}
.fete-card:nth-child(3){animation-delay:.12s}
.fete-card:nth-child(4){animation-delay:.18s}

/* ── Lune ───────────────────────────────────────────────────── */
.lune-card {
    background:var(--grad-card); border:1px solid var(--border); border-radius:20px;
    padding:1.5rem; box-shadow:var(--shadow); margin-bottom:1.5rem;
}
.lune-inner { display:grid; grid-template-columns:auto 1fr auto; gap:1.5rem; align-items:center; }
@media(max-width:580px){ .lune-inner{ grid-template-columns:1fr; text-align:center; } }
.lune-emoji  { font-size:4.5rem; line-height:1; filter:drop-shadow(0 2px 12px rgba(0,0,0,.3)); text-align:center; }
.lune-nom    { font-family:'DM Serif Display',serif; font-size:1.5rem; color:var(--text); margin-bottom:.3rem; }
.lune-age    { color:var(--muted); font-size:.82rem; }
.lune-cycle-bar { background:var(--surface2); border-radius:99px; height:8px; margin:.75rem 0 .3rem; overflow:hidden; }
.lune-cycle-fill{ height:100%; background:linear-gradient(90deg,#6b7280,#f0f0c0,#fff8b0); border-radius:99px; }
.lune-cycle-label { font-size:.7rem; color:var(--muted); display:flex; justify-content:space-between; }
.lune-stats  { display:flex; flex-direction:column; gap:.5rem; align-items:flex-end; }
@media(max-width:580px){ .lune-stats{ align-items:center; flex-direction:row; justify-content:center; flex-wrap:wrap; } }
.lune-stat-box {
    background:var(--surface2); border-radius:10px; padding:.5rem .9rem;
    text-align:center; min-width:90px;
}
.lune-stat-box .lsb-label { font-size:.65rem; color:var(--muted); text-transform:uppercase; letter-spacing:.08em; }
.lune-stat-box .lsb-val   { font-family:'DM Serif Display',serif; font-size:1.3rem; color:var(--accent2); }
.lune-stat-box .lsb-unit  { font-size:.68rem; color:var(--muted); }
.lune-phases  { display:flex; gap:.4rem; flex-wrap:wrap; margin-top:1rem; justify-content:center; }
.lune-phase-pill {
    background:var(--surface2); border-radius:8px; padding:.3rem .65rem;
    font-size:.72rem; color:var(--muted); display:flex; align-items:center; gap:.3rem;
    border:1px solid transparent; transition:all .2s;
}
.lune-phase-pill.active {
    background:rgba(240,240,192,.15); border-color:rgba(240,240,192,.4);
    color:var(--text); font-weight:600;
}
.illumination-ring {
    position:relative; width:80px; height:80px; margin:0 auto .5rem;
}
.illumination-ring svg { transform:rotate(-90deg); }
.illumination-ring .ring-label {
    position:absolute; inset:0; display:flex; flex-direction:column;
    align-items:center; justify-content:center;
    font-size:.65rem; color:var(--muted); font-weight:600;
    line-height:1.2;
}
.illumination-ring .ring-pct { font-size:1rem; color:var(--text); font-weight:700; }

/* ── Horloges mondiales ─────────────────────────────────────── */
.world-clocks {
    display:grid; grid-template-columns:repeat(auto-fill,minmax(175px,1fr));
    gap:1rem; margin-bottom:1.5rem;
}
.clock-card {
    background:var(--grad-card); border:1px solid var(--border); border-radius:18px;
    padding:1.25rem 1.25rem 1rem; box-shadow:var(--shadow);
    transition:transform .2s,box-shadow .2s; position:relative; overflow:hidden;
}
.clock-card:hover { transform:translateY(-2px); box-shadow:var(--shadow),0 0 0 1px var(--accent); }
.clock-card.is-home { border-color:var(--accent); }
.clock-flag   { font-size:1.4rem; margin-bottom:.4rem; line-height:1; }
.clock-city   { font-size:.72rem; text-transform:uppercase; letter-spacing:.1em; color:var(--muted); font-weight:600; margin-bottom:.4rem; }
.clock-time   {
    font-family:'DM Serif Display',serif; font-size:2rem; font-weight:400;
    color:var(--text); font-variant-numeric:tabular-nums; line-height:1;
}
.clock-card.is-home .clock-time { color:var(--accent); }
.clock-date   { font-size:.72rem; color:var(--muted); margin-top:.25rem; }
.clock-offset { font-size:.65rem; margin-top:.4rem; display:inline-block;
    background:var(--surface2); border-radius:6px; padding:.1rem .45rem; }
.clock-season {
    position:absolute; top:.75rem; right:.75rem;
    font-size:.65rem; background:var(--surface2); border-radius:6px;
    padding:.1rem .4rem; color:var(--muted);
}

/* ── No DB warning ──────────────────────────────────────────── */
.db-warning {
    background:rgba(247,111,111,.1); border:1px solid rgba(247,111,111,.25);
    border-radius:14px; padding:1rem 1.25rem; margin-bottom:1.5rem;
    font-size:.85rem; color:var(--danger); display:flex; align-items:center; gap:.6rem;
}

/* ── Footer ─────────────────────────────────────────────────── */
footer { text-align:center; color:var(--muted); font-size:.72rem; margin-top:2rem; padding-top:1.5rem; border-top:1px solid var(--border); }
footer a { color:var(--accent); text-decoration:none; }
</style>
</head>
<body>

<div class="container">

<!-- ── Header ──────────────────────────────────────────────── -->
<header>
    <div class="header-left">
        <h1>📍 <span><?= VILLE ?></span></h1>
        <p class="subtitle">Tableau de bord météo &amp; historique SQL</p>
    </div>
    <div class="header-right">
        <button class="theme-toggle" onclick="toggleTheme()" aria-label="Changer le thème">
            <span id="themeIcon">🌙</span>
            <span id="themeLabel">Mode clair</span>
        </button>
        <?php if ($db_ok): ?>
            <span class="db-badge ok">🗄️ MySQL connecté</span>
        <?php else: ?>
            <span class="db-badge err">⚠️ MySQL hors ligne</span>
        <?php endif; ?>
    </div>
</header>

<?php if (!$db_ok): ?>
<div class="db-warning">
    ⚠️ <strong>Base de données non accessible.</strong>
    Vérifiez les constantes <code>DB_HOST / DB_NAME / DB_USER / DB_PASS</code> en haut du fichier PHP.
    La météo s'affiche quand même ; l'historique sera indisponible.
</div>
<?php endif; ?>

<!-- ── Date & Heure ─────────────────────────────────────────── -->
<div class="date-hero">
    <div class="big-date">
        <?php
        $labels  = ['Dimanche','Lundi','Mardi','Mercredi','Jeudi','Vendredi','Samedi'];
        $mois_fr = ['','Janvier','Février','Mars','Avril','Mai','Juin',
                    'Juillet','Août','Septembre','Octobre','Novembre','Décembre'];
        $dow = $labels[(int)$now->format('w')];
        $d   = (int)$now->format('j');
        $m   = (int)$now->format('n');
        $y   = $now->format('Y');
        echo "<em>$dow</em> $d {$mois_fr[$m]} $y";
        ?>
    </div>
    <div class="time-live" id="clock"><?= $now->format('H:i:s') ?></div>
</div>

<!-- ── Météo + Soleil ───────────────────────────────────────── -->
<div class="grid-2">
    <div class="card">
        <div class="card-title">🌡️ Météo actuelle</div>
        <div class="meteo-main">
            <div class="meteo-emoji"><?= $wmo[1] ?></div>
            <div>
                <div class="temp-big"><?= $meteo['temp'] ?><sup>°C</sup></div>
                <div class="meteo-desc"><?= $wmo[0] ?></div>
                <div class="meteo-desc" style="font-size:.75rem;margin-top:.1rem">Ressenti <?= $meteo['apparent'] ?>°C</div>
            </div>
        </div>
        <div class="meteo-stats">
            <div class="stat"><div class="label">Humidité</div><div class="val"><?= $meteo['humidity'] ?>%</div></div>
            <div class="stat"><div class="label">Vent</div><div class="val"><?= $meteo['wind'] ?> km/h</div></div>
            <div class="stat"><div class="label">Précipitations</div><div class="val"><?= $meteo['precip'] ?> mm</div></div>
            <div class="stat"><div class="label">Conditions</div><div class="val"><?= $wmo[0] ?></div></div>
        </div>
    </div>

    <div class="card">
        <div class="card-title">☀️ Soleil du jour</div>
        <div class="sun-row">
            <div class="sun-item">
                <div class="sun-icon">🌅</div>
                <div><div class="sun-label">Lever du soleil</div><div class="sun-time"><?= $meteo['sunrise'] ?></div></div>
            </div>
            <div class="sun-item">
                <div class="sun-icon">🌇</div>
                <div><div class="sun-label">Coucher du soleil</div><div class="sun-time"><?= $meteo['sunset'] ?></div></div>
            </div>
        </div>
        <?php
        $duree=''; $pct=0;
        if ($meteo['sunrise']!=='—' && $meteo['sunset']!=='—') {
            $sr=strtotime('today '.$meteo['sunrise']); $ss=strtotime('today '.$meteo['sunset']);
            $dur=$ss-$sr; $h=(int)($dur/3600); $mn=(int)(($dur%3600)/60);
            $duree="{$h}h{$mn}min de lumière";
            $now_ts=time();
            if ($now_ts>=$sr && $now_ts<=$ss) $pct=round(($now_ts-$sr)/$dur*100);
            elseif ($now_ts>$ss) $pct=100;
        }
        ?>
        <?php if ($duree): ?>
        <div class="sun-duration">
            <div class="sun-bar"><div class="sun-bar-fill" style="width:<?= $pct ?>%"></div></div>
            <?= $duree ?> · <?= $pct ?>% écoulé
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Lune ─────────────────────────────────────────────────────── -->
<div class="section-title">🌙 Phase de la Lune</div>
<div class="lune-card">
    <div class="lune-inner">
        <!-- Emoji + anneau illumination -->
        <div>
            <div class="lune-emoji"><?= $lune['emoji'] ?></div>
            <div class="illumination-ring">
                <svg width="80" height="80" viewBox="0 0 80 80">
                    <circle cx="40" cy="40" r="32" fill="none" stroke="var(--surface2)" stroke-width="7"/>
                    <circle cx="40" cy="40" r="32" fill="none" stroke="var(--accent2)" stroke-width="7"
                        stroke-dasharray="<?= round($lune['illumination'] * 2.0106) ?> 201.06"
                        stroke-linecap="round"/>
                </svg>
                <div class="ring-label">
                    <span class="ring-pct"><?= $lune['illumination'] ?>%</span>
                    <span>illum.</span>
                </div>
            </div>
        </div>
        <!-- Nom + cycle bar -->
        <div>
            <div class="lune-nom"><?= $lune['nom'] ?></div>
            <div class="lune-age">Jour <?= $lune['age'] ?> du cycle lunaire (29,5 jours)</div>
            <div class="lune-cycle-bar">
                <div class="lune-cycle-fill" style="width:<?= $lune['cycle_pct'] ?>%"></div>
            </div>
            <div class="lune-cycle-label"><span>🌑 Nlle Lune</span><span>🌕 Pleine</span><span>🌑 Nlle</span></div>
            <!-- Pastilles phases -->
            <div class="lune-phases">
                <?php foreach ($lune['all_phases'] as $p): ?>
                <div class="lune-phase-pill <?= $p['nom']===$lune['nom']?'active':'' ?>">
                    <?= $p['emoji'] ?> <?= $p['nom'] ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <!-- Stats prochaines phases -->
        <div class="lune-stats">
            <div class="lune-stat-box">
                <div class="lsb-label">🌕 Pleine Lune</div>
                <div class="lsb-val"><?= $lune['jours_pleine'] == 0 ? 'Ce soir' : $lune['jours_pleine'] ?></div>
                <?php if ($lune['jours_pleine'] > 0): ?>
                <div class="lsb-unit">jour<?= $lune['jours_pleine']>1?'s':'' ?></div>
                <?php endif; ?>
            </div>
            <div class="lune-stat-box">
                <div class="lsb-label">🌑 Nlle Lune</div>
                <div class="lsb-val"><?= $lune['jours_nouvelle'] == 0 ? 'Auj.' : $lune['jours_nouvelle'] ?></div>
                <?php if ($lune['jours_nouvelle'] > 0): ?>
                <div class="lsb-unit">jour<?= $lune['jours_nouvelle']>1?'s':'' ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ── Horloges mondiales ────────────────────────────────────────── -->
<div class="section-title">🌍 Horloges mondiales
    <span style="margin-left:.75rem;font-size:.65rem;background:var(--surface2);border-radius:6px;padding:.15rem .5rem;color:var(--muted);font-weight:400;">
        Paris : heure d'<?= $estHiver ? 'hiver (UTC+1)' : 'été (UTC+2)' ?>
    </span>
</div>
<div class="world-clocks">
    <?php foreach ($villes_monde as $v):
        $tz_obj  = new DateTimeZone($v['tz']);
        $dt      = new DateTime('now', $tz_obj);
        $offset  = $dt->format('I') ? $v['ete'] : $v['hiver']; // heure été ou hiver
        $saison  = $dt->format('I') ? 'Été' : 'Hiver';
        $is_home = ($v['tz'] === TZ);
        $dow_map = ['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];
        $day_label = $dow_map[$dt->format('D')] ?? $dt->format('D');
    ?>
    <div class="clock-card <?= $is_home?'is-home':'' ?>" data-tz="<?= $v['tz'] ?>">
        <?php if (!$is_home): ?>
        <div class="clock-season"><?= $saison ?></div>
        <?php endif; ?>
        <div class="clock-flag"><?= $v['emoji'] ?></div>
        <div class="clock-city"><?= $v['nom'] ?></div>
        <div class="clock-time" data-clock="<?= $v['tz'] ?>"><?= $dt->format('H:i:s') ?></div>
        <div class="clock-date"><?= $day_label ?> <?= $dt->format('d/m/Y') ?></div>
        <span class="clock-offset"><?= $offset ?></span>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Fêtes à venir ────────────────────────────────────────── -->
<div class="section-title">🗓️ Jours restants avant les fêtes</div>
<div class="fetes-grid">
    <?php foreach ($fetes as $f): ?>
    <div class="fete-card" data-emoji="<?= $f['emoji'] ?>">
        <div class="fete-nom"><?= $f['nom'] ?></div>
        <div class="fete-date-label"><?= $f['emoji'] ?> <?= $f['desc'] ?></div>
        <?php if ($f['jours']===0): ?>
            <div class="fete-jours fete-aujourd">C'est<span>aujourd'hui ! 🎉</span></div>
        <?php else: ?>
            <div class="fete-jours"><?= $f['jours'] ?><span>jour<?= $f['jours']>1?'s':'' ?></span></div>
        <?php endif; ?>
    </div>
    <?php endforeach; ?>
</div>

<!-- ── Prévisions 5 jours ───────────────────────────────────── -->
<?php if (!empty($meteo['daily'])): ?>
<div class="previsions">
    <div class="card-title">📅 Prévisions – 5 prochains jours</div>
    <div class="prev-row">
        <?php
        $today_str=date('Y-m-d');
        $dow_map=['Mon'=>'Lun','Tue'=>'Mar','Wed'=>'Mer','Thu'=>'Jeu','Fri'=>'Ven','Sat'=>'Sam','Sun'=>'Dim'];
        foreach ($meteo['daily'] as $d):
            $wmo_d=wmo_to_label((int)$d['code']);
            $is_today=($d['date']===$today_str);
            $dow_en=date('D',strtotime($d['date']));
            $lbl=$is_today?"Aujourd'hui":($dow_map[$dow_en]??$dow_en);
        ?>
        <div class="prev-day <?= $is_today?'today':'' ?>">
            <div class="pd-label"><?= $lbl ?></div>
            <div class="pd-icon"><?= $wmo_d[1] ?></div>
            <div class="pd-max"><?= round($d['tmax']) ?>°</div>
            <div class="pd-min"><?= round($d['tmin']) ?>°</div>
            <?php if ($d['precip']>0): ?><div class="pd-rain">💧 <?= $d['precip'] ?>mm</div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($db_ok): ?>

<!-- ── Statistiques 30 jours ────────────────────────────────── -->
<?php if (!empty($stats) && $stats['nb_mesures'] > 0): ?>
<div class="section-title">📊 Statistiques – 30 derniers jours (<?= $stats['nb_mesures'] ?> mesures)</div>
<div class="stats-30j">
    <div class="stat-box">
        <div class="sb-label">Temp. min</div>
        <div class="sb-val"><?= $stats['t_min'] ?? '—' ?><span class="sb-unit">°C</span></div>
    </div>
    <div class="stat-box">
        <div class="sb-label">Temp. max</div>
        <div class="sb-val" style="color:var(--danger)"><?= $stats['t_max'] ?? '—' ?><span class="sb-unit">°C</span></div>
    </div>
    <div class="stat-box">
        <div class="sb-label">Temp. moy.</div>
        <div class="sb-val"><?= $stats['t_moy'] ?? '—' ?><span class="sb-unit">°C</span></div>
    </div>
    <div class="stat-box">
        <div class="sb-label">Humidité moy.</div>
        <div class="sb-val" style="color:var(--accent)"><?= $stats['h_moy'] ?? '—' ?><span class="sb-unit">%</span></div>
    </div>
    <div class="stat-box">
        <div class="sb-label">Vent max</div>
        <div class="sb-val" style="color:var(--accent2)"><?= $stats['vent_max'] ?? '—' ?><span class="sb-unit">km/h</span></div>
    </div>
    <div class="stat-box">
        <div class="sb-label">Précip. totales</div>
        <div class="sb-val" style="color:var(--success)"><?= $stats['precip_total'] ?? '0' ?><span class="sb-unit">mm</span></div>
    </div>
</div>
<?php endif; ?>

<!-- ── Graphique ─────────────────────────────────────────────── -->
<?php if (!empty($chart_data)): ?>
<div class="chart-wrap">
    <div class="card-title">📈 Évolution des mesures enregistrées</div>
    <div class="chart-tabs">
        <button class="chart-tab active" onclick="showChart('temp',this)">🌡️ Température</button>
        <button class="chart-tab" onclick="showChart('hum',this)">💧 Humidité</button>
        <button class="chart-tab" onclick="showChart('rain',this)">🌧️ Précipitations</button>
    </div>
    <div class="chart-container"><canvas id="meteoChart"></canvas></div>
</div>
<?php endif; ?>

<!-- ── Historique tableau ─────────────────────────────────────── -->
<?php if (!empty($historique)): ?>
<div class="histo-wrap">
    <div class="histo-header">
        <div class="card-title" style="margin-bottom:0">🗄️ Historique MySQL – <?= count($historique) ?> dernières mesures</div>
        <a href="?export=csv" class="export-btn">⬇️ Exporter CSV</a>
    </div>
    <div class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>#</th>
                    <th>Date &amp; heure</th>
                    <th>🌡️ Temp.</th>
                    <th>🤔 Ressenti</th>
                    <th>💧 Humidité</th>
                    <th>💨 Vent</th>
                    <th>🌧️ Précip.</th>
                    <th>Conditions</th>
                    <th>🌅 Lever</th>
                    <th>🌇 Coucher</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($historique as $i => $row): ?>
                <tr <?= $i===0 && $saved ? 'class="new-row"' : '' ?>>
                    <td style="color:var(--muted)"><?= $row['id'] ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($row['enregistre_le'])) ?></td>
                    <td class="temp-val"><?= $row['temperature'] !== null ? $row['temperature'].'°C' : '—' ?></td>
                    <td><?= $row['ressenti'] !== null ? $row['ressenti'].'°C' : '—' ?></td>
                    <td><?= $row['humidite'] !== null ? $row['humidite'].'%' : '—' ?></td>
                    <td><?= $row['vent_kmh'] !== null ? $row['vent_kmh'].' km/h' : '—' ?></td>
                    <td><?= $row['precipitation'] !== null ? $row['precipitation'].' mm' : '—' ?></td>
                    <td><span class="badge-cond"><?= htmlspecialchars($row['condition_texte'] ?? '—') ?></span></td>
                    <td style="color:var(--sun)"><?= $row['lever_soleil'] ? substr($row['lever_soleil'],0,5) : '—' ?></td>
                    <td style="color:var(--sun)"><?= $row['coucher_soleil'] ? substr($row['coucher_soleil'],0,5) : '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="font-size:.72rem;color:var(--muted);margin-top:.75rem;">
        ℹ️ Une nouvelle mesure est enregistrée toutes les <?= SAVE_INTERVAL_MIN ?> minutes.
        <?php if ($saved): ?><strong style="color:var(--success)">✓ Mesure sauvegardée à l'instant.</strong><?php endif; ?>
    </p>
</div>
<?php else: ?>
<div class="histo-wrap">
    <div class="card-title">🗄️ Historique MySQL</div>
    <p style="color:var(--muted);font-size:.85rem;text-align:center;padding:2rem 0">
        Aucune mesure encore enregistrée.<br>
        La première sera sauvegardée à la prochaine visite.
    </p>
</div>
<?php endif; ?>

<?php endif; // $db_ok ?>

<!-- ── Footer ──────────────────────────────────────────────── -->
<footer>
    Données : <a href="https://open-meteo.com">Open-Meteo</a> ·
    Coordonnées <?= LAT ?>°N, <?= LON ?>°E ·
    Généré le <?= $now->format('d/m/Y à H:i:s') ?>
</footer>

</div><!-- /.container -->

<?php if ($saved): ?>
<div class="save-toast">✅ Mesure météo sauvegardée en base SQL</div>
<?php endif; ?>

<script>
/* ── Horloge locale ─────────────────────────────────────────── */
(function tick(){
    const el=document.getElementById('clock');
    if(el){const n=new Date(),p=v=>String(v).padStart(2,'0');el.textContent=p(n.getHours())+':'+p(n.getMinutes())+':'+p(n.getSeconds());}
    setTimeout(tick,1000);
})();

/* ── Horloges mondiales (Intl.DateTimeFormat) ─────────────────── */
const worldClocks = document.querySelectorAll('[data-clock]');
const dowFR = ['Dim','Lun','Mar','Mer','Jeu','Ven','Sam'];

function updateWorldClocks() {
    worldClocks.forEach(el => {
        const tz = el.getAttribute('data-clock');
        const now = new Date();
        // Heure
        const timeFmt = new Intl.DateTimeFormat('fr-FR', {
            timeZone: tz, hour:'2-digit', minute:'2-digit', second:'2-digit', hour12: false
        });
        el.textContent = timeFmt.format(now);
        // Date dans la carte parent
        const card = el.closest('.clock-card');
        if (!card) return;
        const dateFmt = new Intl.DateTimeFormat('fr-FR', {
            timeZone: tz, weekday:'short', day:'2-digit', month:'2-digit', year:'numeric'
        });
        const parts   = dateFmt.formatToParts(now);
        const get     = t => (parts.find(p=>p.type===t)||{value:''}).value;
        const dateEl  = card.querySelector('.clock-date');
        if (dateEl) {
            dateEl.textContent = get('weekday') + ' ' + get('day') + '/' + get('month') + '/' + get('year');
        }
    });
}
updateWorldClocks();
setInterval(updateWorldClocks, 1000);

/* ── Thème ───────────────────────────────────────────────────── */
const root=document.documentElement,icon=document.getElementById('themeIcon'),lbl=document.getElementById('themeLabel');
function applyUI(t){icon.textContent=t==='dark'?'🌙':'☀️';lbl.textContent=t==='dark'?'Mode clair':'Mode sombre';}
(function(){const s=localStorage.getItem('theme')||((window.matchMedia('(prefers-color-scheme:dark)').matches)?'dark':'light');root.setAttribute('data-theme',s);applyUI(s);})();
function toggleTheme(){const c=root.getAttribute('data-theme'),n=c==='dark'?'light':'dark';root.setAttribute('data-theme',n);localStorage.setItem('theme',n);applyUI(n);refreshChart();}

/* ── Graphique Chart.js ──────────────────────────────────────── */
<?php if (!empty($chart_data)): ?>
const chartLabels = <?= $chart_labels ?>;
const chartTemps  = <?= $chart_temps ?>;
const chartHum    = <?= $chart_hum ?>;
const chartRain   = <?= $chart_rain ?>;

let currentChart = null;
let currentTab   = 'temp';

function getCSSVar(v){ return getComputedStyle(document.documentElement).getPropertyValue(v).trim(); }

const datasets = {
    temp: {
        label:'Température (°C)',
        data: chartTemps,
        color: () => getCSSVar('--accent'),
        fill: true,
        yLabel:'°C',
    },
    hum: {
        label:'Humidité (%)',
        data: chartHum,
        color: () => '#4fcf8e',
        fill: true,
        yLabel:'%',
    },
    rain: {
        label:'Précipitations (mm)',
        data: chartRain,
        color: () => getCSSVar('--accent2'),
        fill: false,
        yLabel:'mm',
        type:'bar',
    },
};

function buildChart(key) {
    const canvas = document.getElementById('meteoChart');
    if (!canvas) return;
    if (currentChart) { currentChart.destroy(); }
    const ds = datasets[key];
    const color = ds.color();
    currentChart = new Chart(canvas, {
        type: ds.type || 'line',
        data: {
            labels: chartLabels,
            datasets: [{
                label: ds.label,
                data: ds.data,
                borderColor: color,
                backgroundColor: ds.fill
                    ? color.replace('rgb','rgba').replace(')',', 0.12)')
                    : color + '99',
                borderWidth: 2,
                pointRadius: chartLabels.length > 24 ? 0 : 3,
                pointHoverRadius: 5,
                fill: ds.fill || false,
                tension: 0.35,
                borderRadius: ds.type==='bar' ? 6 : 0,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode:'index', intersect:false },
            plugins: {
                legend: { display:false },
                tooltip: {
                    backgroundColor: getCSSVar('--surface'),
                    titleColor: getCSSVar('--muted'),
                    bodyColor: getCSSVar('--text'),
                    borderColor: getCSSVar('--border'),
                    borderWidth: 1,
                    padding: 10,
                },
            },
            scales: {
                x: {
                    grid: { color: getCSSVar('--chart-grid') },
                    ticks: {
                        color: getCSSVar('--muted'), font:{size:10},
                        maxTicksLimit: 8, maxRotation: 45,
                    },
                },
                y: {
                    grid: { color: getCSSVar('--chart-grid') },
                    ticks: { color: getCSSVar('--muted'), font:{size:10} },
                    title: { display:true, text: ds.yLabel, color: getCSSVar('--muted'), font:{size:11} },
                },
            },
        }
    });
}

function showChart(key, btn) {
    currentTab = key;
    document.querySelectorAll('.chart-tab').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    buildChart(key);
}

function refreshChart() { if(currentChart) buildChart(currentTab); }

// Init
buildChart('temp');
<?php endif; ?>
</script>

</body>
</html>

<?php
// ══════════════════════════════════════════════════════════════
//  EXPORT CSV (appelé via ?export=csv)
// ══════════════════════════════════════════════════════════════
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    // On ré-exécute le script mais avec output propre
    ob_end_clean();
    $pdo2 = get_pdo();
    if (!$pdo2) { http_response_code(503); exit('DB non disponible'); }

    $filename = 'meteo_' . VILLE . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache');

    $out = fopen('php://output', 'w');
    // BOM UTF-8 pour Excel
    fwrite($out, "\xEF\xBB\xBF");

    fputcsv($out, [
        'ID','Date enregistrement','Ville','Latitude','Longitude',
        'Température (°C)','Ressenti (°C)','Humidité (%)','Vent (km/h)',
        'Précipitations (mm)','Code WMO','Conditions','Lever soleil','Coucher soleil'
    ], ';');

    $stmt = $pdo2->prepare("
        SELECT * FROM historique_meteo WHERE ville=:v ORDER BY enregistre_le DESC
    ");
    $stmt->execute([':v' => VILLE]);
    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
        fputcsv($out, $row, ';');
    }
    fclose($out);
    exit;
}
?>
