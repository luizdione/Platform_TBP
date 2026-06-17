<?php
/**
 * api_mapa_clima_intervalo.php  (versao DEFINITIVA, pos clima_config.php)
 *
 * Le o cache diario (clima_pixel_diario), agrega para o intervalo solicitado
 * pelo usuario e devolve:
 *
 *  - celulas[]: 2 a 4 celulas NASA cobrindo a bbox TPB com seus retangulos
 *               (la_lo,la_hi,lo_lo,lo_hi) e valores agregados (t, p, r, n_dias)
 *  - matriz: grid de DISPLAY 250m x 250m em formato denso/RLE eficiente
 *  - bbox e periodo
 *
 * Parametros:
 *   GET ?inicio=YYYY-MM-DD  (default = TPB_DATA_INICIO = 2025-06-01)
 *   GET ?fim=YYYY-MM-DD     (default = hoje)
 */

// Blindagem: NUNCA devolver HTML, sempre JSON
ini_set('display_errors', '0');
ini_set('log_errors', '1');
@ob_start();
set_error_handler(function($severity, $msg, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($msg, 0, $severity, $file, $line);
});
set_exception_handler(function($e) {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['erro' => 'PHP: ' . $e->getMessage(), 'arquivo' => basename($e->getFile()), 'linha' => $e->getLine()]);
    exit;
});
register_shutdown_function(function() {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        @ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['erro' => 'PHP FATAL: ' . $err['message'] . ' @ ' . basename($err['file']) . ':' . $err['line']]);
    }
});

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/clima_config.php';
header('Content-Type: application/json; charset=utf-8');

$pdo = getDBConnection();
// Garante tabelas — endpoint de leitura precisa nao quebrar se cache vazio.
tpb_garantir_tabelas_clima($pdo);

$inicio = $_GET['inicio'] ?? TPB_DATA_INICIO;
$fim    = $_GET['fim']    ?? tpb_data_fim();

// Validar
$d1 = DateTime::createFromFormat('Y-m-d', $inicio);
$d2 = DateTime::createFromFormat('Y-m-d', $fim);
if (!$d1 || !$d2 || $d1 > $d2) {
    http_response_code(400);
    echo json_encode(['erro' => 'Datas invalidas. Use YYYY-MM-DD com inicio<=fim.']);
    exit;
}

function wilson95($k, $n) {
    if ($n <= 0) return [0.0, 0.0, 0.0];
    $p = $k / $n; $z = 1.959963984540054;
    $denom  = 1 + $z*$z/$n;
    $center = $p + $z*$z/(2*$n);
    $margin = $z * sqrt(($p*(1-$p) + $z*$z/(4*$n)) / $n);
    return [$p, max(0.0, ($center-$margin)/$denom), min(1.0, ($center+$margin)/$denom)];
}

// =========== Celulas ERA5-Land da bbox (geometria, 0.1 deg) ===========
$cells_geom = tpb_era5_cells_in_bbox();

// =========== Agregacao por celula no intervalo ===========
// Filtra por fonte='ERA5_LAND' para nao misturar com lixo legado NASA_POWER.
$stmt = $pdo->prepare("
    SELECT AVG(t2m) AS t, SUM(prectotcorr) AS p, AVG(rh2m) AS r, COUNT(*) AS n_dias
    FROM clima_pixel_diario
    WHERE lat_grid = ? AND lng_grid = ? AND data BETWEEN ? AND ?
      AND fonte = 'ERA5_LAND'
");
$celulas = [];
foreach ($cells_geom as $cg) {
    $stmt->execute([$cg['lat'], $cg['lng'], $inicio, $fim]);
    $r = $stmt->fetch();
    $celulas[] = array_merge($cg, [
        't'      => $r['t'] !== null ? round((float)$r['t'], 2) : null,
        'p'      => $r['p'] !== null ? round((float)$r['p'], 2) : null,
        'r'      => $r['r'] !== null ? round((float)$r['r'], 2) : null,
        'n_dias' => (int)$r['n_dias'],
    ]);
}

// =========== Matriz de display 250m x 250m ===========
// Cada pixel (i,j) recebe o valor da celula NASA que contem o ponto central.
// Em vez de mandar 35 868 valores explicitos, mandamos APENAS a forma da
// matriz + uma indexacao por celula (cell_idx_at(i,j)). O cliente pinta.
[$n_rows, $n_cols, $dlat_deg, $dlng_deg] = tpb_display_grid_dims();

// Para cada pixel, qual celula NASA o contem? Geramos uma matriz compacta
// (cell_map_b64 LEGADO removido — economiza ~48 KB por requisicao.
//  Era usado para o heatmap pixel-a-pixel 250m; agora a interpolacao
//  bilinear gera o canvas direto no browser a partir das celulas.)

// =========== Fazendas + prevalencia (mesma logica do api_mapa_clima.php) ===========
$temView = false;
try { $pdo->query("SELECT 1 FROM v_dados_cientificos LIMIT 1"); $temView = true; } catch (Throwable $e) {}

$fazendasOut = [];
if ($temView) {
    $rows = $pdo->query("
        SELECT
            l.id, l.nome, l.municipio, l.latitude, l.longitude,
            COUNT(DISTINCT v.codigo_animal) AS n_animais,
            COUNT(*) AS n_coletas,
            SUM(v.pcr_babesia)       AS k_pcr_babesia,
            SUM(v.pcr_anaplasma)     AS k_pcr_anaplasma,
            SUM(v.pcr_trypanosoma)   AS k_pcr_trypanosoma,
            SUM(v.lamp_babesia_18s)  AS k_lamp_18s,
            SUM(v.lamp_babesia_cytb) AS k_lamp_cytb
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
        // Qual celula NASA contem a fazenda?
        $cellIdx = null;
        foreach ($celulas as $k => $cel) {
            if ($f['latitude'] >= $cel['la_lo'] && $f['latitude'] <= $cel['la_hi'] &&
                $f['longitude'] >= $cel['lo_lo'] && $f['longitude'] <= $cel['lo_hi']) {
                $cellIdx = $k; break;
            }
        }
        $clima = ['temperatura_media'=>null, 'pluviometria_total'=>null, 'umidade_media'=>null];
        if ($cellIdx !== null) {
            $clima['temperatura_media']  = $celulas[$cellIdx]['t'];
            $clima['pluviometria_total'] = $celulas[$cellIdx]['p'];
            $clima['umidade_media']      = $celulas[$cellIdx]['r'];
        }
        $fazendasOut[] = [
            'id'=>(int)$f['id'], 'nome'=>$f['nome'], 'municipio'=>$f['municipio'],
            'lat'=>(float)$f['latitude'], 'lng'=>(float)$f['longitude'],
            'n_animais'=>(int)$f['n_animais'], 'n_coletas'=>$nC,
            'parasitas'=>$cells, 'clima'=>$clima,
            'nasa_cell_idx'=>$cellIdx,
        ];
    }
}

echo json_encode([
    'fonte'        => ERA5_SOURCE,
    'periodo'      => ['inicio'=>$inicio, 'fim'=>$fim],
    'bbox'         => [TPB_LA_SOUTH, TPB_LO_WEST, TPB_LA_NORTH, TPB_LO_EAST],
    'celulas'      => $celulas,
    'display_grid' => [
        'n_rows'=>$n_rows, 'n_cols'=>$n_cols,
        'dlat_deg'=>$dlat_deg, 'dlng_deg'=>$dlng_deg,
        'pixel_metros'=>TPB_PIXEL_METROS,
    ],
    'fazendas'     => $fazendasOut,
]);
