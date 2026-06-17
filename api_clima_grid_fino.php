<?php
/**
 * api_clima_grid_fino.php
 * Processa um CHUNK da grid 200x200 sobre a bbox justa das fazendas.
 * Cache persistente em clima_pixel_cache.
 *
 * GET ?chunk=N   (default 8) — pixels processados por requisicao
 * GET ?offset=K  — pular os primeiros K (para retomar)
 * GET ?n=200     — numero de divisoes em cada dimensao (default 200 = 40000 celulas)
 *
 * Resposta JSON:
 *   { processados, total, miss, hit, falha, offset_proximo, terminou,
 *     periodo, bbox, dlat, dlng }
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('max_execution_time', 90);

$pdo = getDBConnection();
$chunk  = max(1, min(20, (int)($_GET['chunk']  ?? 8)));
$offset = max(0, (int)($_GET['offset'] ?? 0));
$n      = max(20, min(300, (int)($_GET['n'] ?? 200)));

// =========== Bbox justa (das fazendas + margem para englobar a regiao) ===========
$row = $pdo->query("
    SELECT MIN(latitude) AS la0, MAX(latitude) AS la1,
           MIN(longitude) AS lo0, MAX(longitude) AS lo1
    FROM locais WHERE ativo=1 AND latitude IS NOT NULL
")->fetch();
if (!$row || $row['la0'] === null) {
    echo json_encode(['erro' => 'Sem fazendas com lat/lng']);
    exit;
}
$margin = 0.20;  // ~22 km de margem ao redor das fazendas
$la0 = (float)$row['la0'] - $margin;
$la1 = (float)$row['la1'] + $margin;
$lo0 = (float)$row['lo0'] - $margin;
$lo1 = (float)$row['lo1'] + $margin;

// Passos lat/lng para grid n×n
$dlat = ($la1 - $la0) / $n;
$dlng = ($lo1 - $lo0) / $n;
$total = $n * $n;

// =========== Periodo ===========
$row2 = $pdo->query("SELECT MAX(data_coleta) AS mx FROM v_dados_cientificos")->fetch();
$dataFim    = $row2['mx'] ?? date('Y-m-d');
$dataInicio = date('Y-m-d', strtotime($dataFim . ' -90 days'));

if ($offset >= $total) {
    echo json_encode([
        'processados' => 0, 'total' => $total,
        'miss' => 0, 'hit' => 0, 'falha' => 0,
        'offset_proximo' => $offset, 'terminou' => true,
        'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
        'bbox' => [$la0, $lo0, $la1, $lo1],
        'dlat' => $dlat, 'dlng' => $dlng, 'n' => $n,
    ]);
    exit;
}

// =========== Stmts cache ===========
$cacheStmt = $pdo->prepare("
    SELECT 1 FROM clima_pixel_cache
    WHERE lat_grid=? AND lng_grid=? AND data_inicio=? AND data_fim=? LIMIT 1
");
$insertStmt = $pdo->prepare("
    INSERT IGNORE INTO clima_pixel_cache
        (lat_grid, lng_grid, data_inicio, data_fim, n_dias,
         temperatura_media, pluviometria_total, umidade_media, fonte)
    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'NASA_POWER')
");

function buscarNASA($lat, $lng, $di, $df) {
    $params = http_build_query([
        'parameters' => 'T2M,PRECTOTCORR,RH2M',
        'community'  => 'AG',
        'longitude'  => $lng,
        'latitude'   => $lat,
        'start'      => date('Ymd', strtotime($di)),
        'end'        => date('Ymd', strtotime($df)),
        'format'     => 'JSON',
    ]);
    $url = "https://power.larc.nasa.gov/api/temporal/daily/point?$params";
    $ctx = stream_context_create(['http' => ['timeout' => 18, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    if (!$j || !isset($j['properties']['parameter'])) return null;
    $clean = fn($a) => array_filter($a, fn($v) => $v > -990);
    $t  = $clean($j['properties']['parameter']['T2M']         ?? []);
    $pr = $clean($j['properties']['parameter']['PRECTOTCORR'] ?? []);
    $rh = $clean($j['properties']['parameter']['RH2M']        ?? []);
    if (empty($t)) return null;
    return [
        't' => round(array_sum($t)/count($t), 2),
        'p' => $pr ? round(array_sum($pr), 2) : 0,
        'r' => $rh ? round(array_sum($rh)/count($rh), 2) : null,
        'n' => count($t),
    ];
}

$hit = 0; $miss = 0; $falha = 0; $proc = 0;
$end = min($offset + $chunk, $total);

for ($i = $offset; $i < $end; $i++) {
    // Decompoe i em (row, col)
    $row_i = intdiv($i, $n);  // 0..n-1 (lat index)
    $col_i = $i % $n;         // 0..n-1 (lng index)
    $lat = round($la0 + ($row_i + 0.5) * $dlat, 5);
    $lng = round($lo0 + ($col_i + 0.5) * $dlng, 5);

    $cacheStmt->execute([$lat, $lng, $dataInicio, $dataFim]);
    if ($cacheStmt->fetch()) {
        $hit++; $proc++;
        continue;
    }
    $r = buscarNASA($lat, $lng, $dataInicio, $dataFim);
    if ($r) {
        $insertStmt->execute([$lat, $lng, $dataInicio, $dataFim, $r['n'], $r['t'], $r['p'], $r['r']]);
        $miss++;
    } else {
        $falha++;
    }
    $proc++;
    usleep(120000); // 120ms entre requests
}

echo json_encode([
    'processados'    => $proc,
    'total'          => $total,
    'miss'           => $miss,
    'hit'            => $hit,
    'falha'          => $falha,
    'offset_proximo' => $end,
    'terminou'       => $end >= $total,
    'periodo'        => ['inicio' => $dataInicio, 'fim' => $dataFim],
    'bbox'           => [$la0, $lo0, $la1, $lo1],
    'dlat'           => $dlat,
    'dlng'           => $dlng,
    'n'              => $n,
]);
