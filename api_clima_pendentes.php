<?php
/**
 * api_clima_pendentes.php
 * Calcula as médias climáticas (90 dias anteriores à data de coleta) para todas
 * as coletas que ainda NÃO possuem dados climáticos processados, usando NASA POWER,
 * e grava em dados_climaticos. Retorna JSON.
 *
 * Usado pela seção "Valores climáticos de datas de coleta já realizadas" em
 * variavel_clima.php (botão "Calcular médias pendentes agora").
 *
 *   ?action=calcular  -> processa as coletas pendentes (padrão)
 *   ?action=resumo    -> apenas conta quantas estão pendentes
 */

require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');
@ini_set('max_execution_time', 600);

function buscarNASApend($lat, $lng, $dataInicio, $dataFim) {
    $startYMD = date('Ymd', strtotime($dataInicio));
    $endYMD   = date('Ymd', strtotime($dataFim));
    $params = http_build_query([
        'parameters' => 'T2M,T2M_MIN,T2M_MAX,PRECTOTCORR,RH2M',
        'community'  => 'AG',
        'longitude'  => $lng,
        'latitude'   => $lat,
        'start'      => $startYMD,
        'end'        => $endYMD,
        'format'     => 'JSON',
    ]);
    $url = "https://power.larc.nasa.gov/api/temporal/daily/point?$params";
    $ctx = stream_context_create(['http' => ['timeout' => 30, 'ignore_errors' => true]]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) return null;
    $j = json_decode($raw, true);
    if (!$j || !isset($j['properties']['parameter'])) return null;

    $p = $j['properties']['parameter'];
    $col = function ($k) use ($p) {
        if (!isset($p[$k])) return [];
        return array_filter($p[$k], fn($v) => $v > -990);
    };
    $t   = $col('T2M');
    $tmn = $col('T2M_MIN');
    $tmx = $col('T2M_MAX');
    $pr  = $col('PRECTOTCORR');
    $rh  = $col('RH2M');
    if (empty($t)) return null;
    return [
        't_media' => round(array_sum($t) / count($t), 2),
        't_min'   => $tmn ? round(min($tmn), 2) : null,
        't_max'   => $tmx ? round(max($tmx), 2) : null,
        'p_total' => $pr ? round(array_sum($pr), 2) : 0,
        'p_media' => $pr ? round(array_sum($pr) / count($pr), 2) : 0,
        'r_media' => $rh ? round(array_sum($rh) / count($rh), 2) : null,
        'r_min'   => $rh ? round(min($rh), 2) : null,
        'r_max'   => $rh ? round(max($rh), 2) : null,
        'n_dias'  => count($t),
    ];
}

try {
    $pdo = getDBConnection();
    $action = $_GET['action'] ?? 'calcular';

    // Coletas com data e coordenadas, sem clima processado
    $sqlPendentes = "
        SELECT c.id AS coleta_id, c.data_coleta,
               COALESCE(a.local_id, c.local_id) AS local_id,
               COALESCE(l.nome, l2.nome) AS nome,
               COALESCE(l.latitude, l2.latitude) AS latitude,
               COALESCE(l.longitude, l2.longitude) AS longitude
        FROM coletas c
        LEFT JOIN animais a  ON a.id = c.animal_id
        LEFT JOIN locais  l  ON l.id = a.local_id
        LEFT JOIN locais  l2 ON l2.id = c.local_id
        WHERE c.data_coleta IS NOT NULL AND c.data_coleta <> '0000-00-00'
          AND COALESCE(l.latitude, l2.latitude) IS NOT NULL
          AND COALESCE(l.longitude, l2.longitude) IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM dados_climaticos dc
              WHERE dc.coleta_id = c.id AND dc.temperatura_media IS NOT NULL
          )
        ORDER BY c.data_coleta DESC
    ";

    if ($action === 'resumo') {
        $n = $pdo->query("SELECT COUNT(*) FROM ($sqlPendentes) t")->fetchColumn();
        echo json_encode(['sucesso' => true, 'pendentes' => (int)$n]);
        exit;
    }

    $coletas = $pdo->query($sqlPendentes)->fetchAll(PDO::FETCH_ASSOC);

    $upsert = $pdo->prepare("
        INSERT INTO dados_climaticos
            (coleta_id, local_id, data_coleta, latitude, longitude,
             data_inicio_periodo, data_fim_periodo,
             temperatura_media, temperatura_minima, temperatura_maxima,
             pluviometria_total, pluviometria_media,
             umidade_media, umidade_minima, umidade_maxima,
             fonte, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'NASA_POWER', 'processado')
        ON DUPLICATE KEY UPDATE
            temperatura_media = VALUES(temperatura_media),
            temperatura_minima = VALUES(temperatura_minima),
            temperatura_maxima = VALUES(temperatura_maxima),
            pluviometria_total = VALUES(pluviometria_total),
            pluviometria_media = VALUES(pluviometria_media),
            umidade_media = VALUES(umidade_media),
            umidade_minima = VALUES(umidade_minima),
            umidade_maxima = VALUES(umidade_maxima),
            fonte = 'NASA_POWER',
            status = 'processado'
    ");

    $sucesso = 0; $falha = 0; $detalhes = [];
    foreach ($coletas as $c) {
        $dataFim = $c['data_coleta'];
        $dataIni = date('Y-m-d', strtotime($dataFim . ' -90 days'));
        $r = buscarNASApend($c['latitude'], $c['longitude'], $dataIni, $dataFim);
        if (!$r) { $falha++; continue; }
        try {
            $upsert->execute([
                $c['coleta_id'], $c['local_id'], $c['data_coleta'],
                $c['latitude'], $c['longitude'],
                $dataIni, $dataFim,
                $r['t_media'], $r['t_min'], $r['t_max'],
                $r['p_total'], $r['p_media'],
                $r['r_media'], $r['r_min'], $r['r_max'],
            ]);
            $sucesso++;
        } catch (Exception $e) {
            $falha++;
            $detalhes[] = 'Coleta ' . $c['coleta_id'] . ': ' . $e->getMessage();
        }
        usleep(200000); // throttle 200ms
    }

    echo json_encode([
        'sucesso'      => true,
        'sucesso_qtd'  => $sucesso,
        'falha_qtd'    => $falha,
        'total'        => count($coletas),
        'detalhes'     => $detalhes,
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()]);
}
