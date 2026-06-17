<?php
/**
 * api_clima_semanal.php
 * Carga e leitura de medias semanais por fazenda.
 *
 * GET ?action=resumo    - quantas semanas faltam por fazenda
 * GET ?action=carregar  - processa CHUNK semanas pendentes (use repetidamente)
 * GET ?action=heatmap   - retorna matriz pronta para Chart.js (sem chamar NASA)
 *
 * Periodo coberto: de 90 dias antes da PRIMEIRA coleta ate HOJE (auto-atualizavel).
 */
require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
ini_set('max_execution_time', 90);

$pdo = getDBConnection();
$action = $_GET['action'] ?? 'heatmap';
$chunk  = max(1, min(20, (int)($_GET['chunk'] ?? 6)));

// =========== Definir periodo total ===========
$row = $pdo->query("SELECT MIN(data_coleta) AS mn FROM v_dados_cientificos")->fetch();
$primeiraColeta = $row['mn'] ?? date('Y-m-d');
$dataInicio = date('Y-m-d', strtotime($primeiraColeta . ' -90 days'));
$dataFim    = date('Y-m-d');

// Lista de semanas ISO entre dataInicio e dataFim
function listarSemanas($di, $df) {
    $start = new DateTime($di);
    $end   = new DateTime($df);
    // Ajustar inicio para segunda-feira da semana ISO
    $dow = (int)$start->format('N');
    if ($dow > 1) $start->modify('-' . ($dow - 1) . ' days');
    $semanas = [];
    while ($start <= $end) {
        $fim = clone $start; $fim->modify('+6 days');
        $semanas[] = [
            'iso_year' => (int)$start->format('o'),
            'iso_week' => (int)$start->format('W'),
            'inicio'   => $start->format('Y-m-d'),
            'fim'      => $fim->format('Y-m-d'),
        ];
        $start->modify('+7 days');
    }
    return $semanas;
}

$semanas = listarSemanas($dataInicio, $dataFim);

// Fazendas ativas
$fazendas = $pdo->query("
    SELECT id, nome, latitude, longitude
    FROM locais
    WHERE ativo=1 AND latitude IS NOT NULL AND longitude IS NOT NULL
    ORDER BY nome
")->fetchAll();

// =========== ACTION: resumo ===========
if ($action === 'resumo') {
    $total_combinacoes = count($fazendas) * count($semanas);
    $cache_count = (int)$pdo->query("SELECT COUNT(*) FROM clima_semanal_cache")->fetchColumn();
    $por_fazenda = [];
    foreach ($fazendas as $f) {
        $n = (int)$pdo->query(
            "SELECT COUNT(*) FROM clima_semanal_cache WHERE local_id={$f['id']}"
        )->fetchColumn();
        $por_fazenda[] = ['fazenda' => $f['nome'], 'cacheadas' => $n, 'total' => count($semanas)];
    }
    echo json_encode([
        'periodo' => ['inicio' => $dataInicio, 'fim' => $dataFim],
        'n_semanas' => count($semanas),
        'n_fazendas' => count($fazendas),
        'total_combinacoes' => $total_combinacoes,
        'cacheadas' => $cache_count,
        'pendentes' => $total_combinacoes - $cache_count,
        'por_fazenda' => $por_fazenda,
    ]);
    exit;
}

// =========== Helpers NASA ===========
function buscarNASA($lat, $lng, $di, $df) {
    $params = http_build_query([
        'parameters' => 'T2M,PRECTOTCORR,RH2M',
        'community'  => 'AG',
        'longitude'  => $lng, 'latitude' => $lat,
        'start' => date('Ymd', strtotime($di)),
        'end'   => date('Ymd', strtotime($df)),
        'format'=> 'JSON',
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

// =========== ACTION: carregar (chunk) ===========
if ($action === 'carregar') {
    $cacheStmt = $pdo->prepare("
        SELECT 1 FROM clima_semanal_cache
        WHERE local_id=? AND iso_year=? AND iso_week=? LIMIT 1
    ");
    $insStmt = $pdo->prepare("
        INSERT IGNORE INTO clima_semanal_cache
            (local_id, iso_year, iso_week, semana_inicio, semana_fim, n_dias,
             temperatura_media, pluviometria_total, umidade_media, fonte)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'NASA_POWER')
    ");

    // Lista de pendentes (combinacoes fazenda × semana ainda nao cacheadas)
    $pendentes = [];
    foreach ($fazendas as $f) {
        foreach ($semanas as $s) {
            $cacheStmt->execute([$f['id'], $s['iso_year'], $s['iso_week']]);
            if (!$cacheStmt->fetch()) {
                $pendentes[] = ['f' => $f, 's' => $s];
                if (count($pendentes) >= $chunk) break 2;
            }
        }
    }

    $sucesso = 0; $falha = 0;
    foreach ($pendentes as $p) {
        $r = buscarNASA($p['f']['latitude'], $p['f']['longitude'], $p['s']['inicio'], $p['s']['fim']);
        if ($r) {
            $insStmt->execute([
                $p['f']['id'], $p['s']['iso_year'], $p['s']['iso_week'],
                $p['s']['inicio'], $p['s']['fim'], $r['n'],
                $r['t'], $r['p'], $r['r']
            ]);
            $sucesso++;
        } else {
            $falha++;
        }
        usleep(150000); // 150ms throttle
    }

    // Recalcular pendentes restantes
    $cache_count = (int)$pdo->query("SELECT COUNT(*) FROM clima_semanal_cache")->fetchColumn();
    $total = count($fazendas) * count($semanas);
    echo json_encode([
        'sucesso' => $sucesso, 'falha' => $falha,
        'cacheadas' => $cache_count, 'total' => $total,
        'restantes' => $total - $cache_count,
        'terminou' => ($total - $cache_count) <= 0,
    ]);
    exit;
}

// =========== ACTION: heatmap ===========
if ($action === 'heatmap') {
    // Construir matriz: para cada fazenda e cada semana, retornar t/p/r ou null
    $rows = $pdo->query("
        SELECT s.local_id, l.nome AS fazenda,
               s.iso_year, s.iso_week, s.semana_inicio,
               s.temperatura_media, s.pluviometria_total, s.umidade_media
        FROM clima_semanal_cache s
        INNER JOIN locais l ON l.id = s.local_id
        ORDER BY l.nome, s.semana_inicio
    ")->fetchAll();

    // Indexar por (fazenda, iso_year, iso_week)
    $idx = [];
    foreach ($rows as $r) {
        $k = $r['fazenda'] . '|' . $r['iso_year'] . '|' . $r['iso_week'];
        $idx[$k] = $r;
    }

    $labels_x = []; // labels das semanas (cabecalho)
    $datas_x = [];   // datas yyyy-mm-dd para deduzir mes/ano no front
    foreach ($semanas as $s) {
        $labels_x[] = date('d/m', strtotime($s['inicio'])) . '/' . substr($s['inicio'], 2, 2);
        $datas_x[]  = $s['inicio'];
    }
    // Para cada fazenda, montar 3 series (t, p, r)
    $series = [];
    foreach ($fazendas as $f) {
        $linhaT = []; $linhaP = []; $linhaR = [];
        foreach ($semanas as $s) {
            $k = $f['nome'] . '|' . $s['iso_year'] . '|' . $s['iso_week'];
            if (isset($idx[$k])) {
                $linhaT[] = $idx[$k]['temperatura_media']  !== null ? (float)$idx[$k]['temperatura_media']  : null;
                $linhaP[] = $idx[$k]['pluviometria_total'] !== null ? (float)$idx[$k]['pluviometria_total'] : null;
                $linhaR[] = $idx[$k]['umidade_media']      !== null ? (float)$idx[$k]['umidade_media']      : null;
            } else {
                $linhaT[] = null; $linhaP[] = null; $linhaR[] = null;
            }
        }
        $series[] = ['fazenda' => $f['nome'], 't' => $linhaT, 'p' => $linhaP, 'r' => $linhaR];
    }

    echo json_encode([
        'periodo'  => ['inicio' => $dataInicio, 'fim' => $dataFim],
        'semanas'  => $labels_x,
        'datas'    => $datas_x,
        'fazendas' => $series,
        'cacheadas' => count($rows),
        'total'    => count($fazendas) * count($semanas),
    ]);
    exit;
}

echo json_encode(['erro' => 'action invalido. Use resumo|carregar|heatmap']);
