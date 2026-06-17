<?php
/**
 * api_mapa_clima.php
 * Le APENAS DO CACHE clima_pixel_cache (a populacao eh feita por
 * api_clima_grid_fino.php em background). Devolve fazendas + pixels disponiveis.
 *
 * GET ?n=200  — numero de divisoes (precisa bater com grid_fino)
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = getDBConnection();
$n = max(20, min(300, (int)($_GET['n'] ?? 200)));

function wilson95($k, $n) {
    if ($n <= 0) return [0.0, 0.0, 0.0];
    $p = $k / $n; $z = 1.959963984540054;
    $denom = 1 + $z*$z/$n;
    $center = $p + $z*$z/(2*$n);
    $margin = $z * sqrt(($p*(1-$p) + $z*$z/(4*$n)) / $n);
    return [$p, max(0.0, ($center-$margin)/$denom), min(1.0, ($center+$margin)/$denom)];
}

// Bbox (mesmo calculo do grid_fino)
$row = $pdo->query("
    SELECT MIN(latitude) AS la0, MAX(latitude) AS la1,
           MIN(longitude) AS lo0, MAX(longitude) AS lo1
    FROM locais WHERE ativo=1 AND latitude IS NOT NULL
")->fetch();
if (!$row || $row['la0'] === null) {
    echo json_encode(['erro' => 'Sem fazendas com lat/lng']);
    exit;
}
$margin = 0.20;
$la0 = (float)$row['la0'] - $margin; $la1 = (float)$row['la1'] + $margin;
$lo0 = (float)$row['lo0'] - $margin; $lo1 = (float)$row['lo1'] + $margin;
$dlat = ($la1 - $la0) / $n;
$dlng = ($lo1 - $lo0) / $n;
$total = $n * $n;

// Periodo
$row2 = $pdo->query("SELECT MAX(data_coleta) AS mx FROM v_dados_cientificos")->fetch();
$dataFim    = $row2['mx'] ?? date('Y-m-d');
$dataInicio = date('Y-m-d', strtotime($dataFim . ' -90 days'));

// Pixels do cache (apenas dentro da bbox e periodo correto)
$stmt = $pdo->prepare("
    SELECT lat_grid, lng_grid, temperatura_media, pluviometria_total, umidade_media
    FROM clima_pixel_cache
    WHERE data_inicio=? AND data_fim=?
      AND lat_grid BETWEEN ? AND ?
      AND lng_grid BETWEEN ? AND ?
");
$stmt->execute([$dataInicio, $dataFim, $la0, $la1, $lo0, $lo1]);
$pixels = [];
while ($r = $stmt->fetch()) {
    $pixels[] = [
        'lat' => (float)$r['lat_grid'],
        'lng' => (float)$r['lng_grid'],
        't'   => $r['temperatura_media']  !== null ? (float)$r['temperatura_media']  : null,
        'p'   => $r['pluviometria_total'] !== null ? (float)$r['pluviometria_total'] : null,
        'r'   => $r['umidade_media']      !== null ? (float)$r['umidade_media']      : null,
    ];
}

// Fazendas com prevalencia
$temView = false;
try { $pdo->query("SELECT 1 FROM v_dados_cientificos LIMIT 1"); $temView = true; } catch (Throwable $e) {}

$fazendasOut = [];
if ($temView) {
    $rows = $pdo->query("
        SELECT
            l.id, l.nome, l.municipio, l.latitude, l.longitude,
            COUNT(DISTINCT v.codigo_animal) AS n_animais,
            COUNT(*) AS n_coletas,
            SUM(v.pcr_babesia)        AS k_pcr_babesia,
            SUM(v.pcr_anaplasma)      AS k_pcr_anaplasma,
            SUM(v.pcr_trypanosoma)    AS k_pcr_trypanosoma,
            SUM(v.lamp_babesia_18s)   AS k_lamp_18s,
            SUM(v.lamp_babesia_cytb)  AS k_lamp_cytb,
            AVG(v.temperatura_media)  AS clima_t,
            AVG(v.pluviometria_total) AS clima_p,
            AVG(v.umidade_media)      AS clima_r
        FROM v_dados_cientificos v
        INNER JOIN locais l ON l.id = v.local_id
        GROUP BY l.id, l.nome, l.municipio, l.latitude, l.longitude
        ORDER BY l.nome
    ")->fetchAll();
    foreach ($rows as $f) {
        $nC = (int)$f['n_coletas'];
        $cells = [
            ['nome'=>'PCR B. bovis',     'k'=>(int)$f['k_pcr_babesia']],
            ['nome'=>'PCR A. marginale', 'k'=>(int)$f['k_pcr_anaplasma']],
            ['nome'=>'PCR T. vivax',     'k'=>(int)$f['k_pcr_trypanosoma']],
            ['nome'=>'LAMP 18S',         'k'=>(int)$f['k_lamp_18s']],
            ['nome'=>'LAMP CytB',        'k'=>(int)$f['k_lamp_cytb']],
        ];
        foreach ($cells as &$c) {
            [$p,$lo,$hi] = wilson95($c['k'], $nC);
            $c['p']=round($p*100,1); $c['lo']=round($lo*100,1); $c['hi']=round($hi*100,1); $c['n']=$nC;
        }
        $fazendasOut[] = [
            'id'=>(int)$f['id'], 'nome'=>$f['nome'], 'municipio'=>$f['municipio'],
            'lat'=>(float)$f['latitude'], 'lng'=>(float)$f['longitude'],
            'n_animais'=>(int)$f['n_animais'], 'n_coletas'=>$nC,
            'parasitas'=>$cells,
            'clima'=>[
                'temperatura_media'  => $f['clima_t'] !== null ? round((float)$f['clima_t'],1) : null,
                'pluviometria_total' => $f['clima_p'] !== null ? round((float)$f['clima_p'],1) : null,
                'umidade_media'      => $f['clima_r'] !== null ? round((float)$f['clima_r'],1) : null,
            ],
        ];
    }
}

echo json_encode([
    'periodo'        => ['inicio'=>$dataInicio, 'fim'=>$dataFim],
    'fazendas'       => $fazendasOut,
    'pixels'         => $pixels,
    'bbox'           => [$la0, $lo0, $la1, $lo1],
    'dlat'           => $dlat,
    'dlng'           => $dlng,
    'n'              => $n,
    'total_esperado' => $total,
    'total_cacheado' => count($pixels),
    'completo'       => count($pixels) >= $total,
]);
