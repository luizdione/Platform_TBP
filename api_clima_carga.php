<?php
/**
 * api_clima_carga.php
 * Pre-carga de dados climaticos NASA POWER para todas as coletas que
 * ainda nao tem clima associado. Para cada coleta, busca media de 90 dias
 * ANTERIORES a data_coleta (T2M, PRECTOTCORR, RH2M).
 *
 * Acesso: somente em DEV via sessao manut_dev_ok.
 * Uso:
 *   ?action=preview      - mostra coletas pendentes sem aplicar
 *   ?action=carregar     - executa carga (com salvamento incremental)
 *   ?action=resumo       - estatisticas de cobertura clima x coletas
 */

require_once __DIR__ . '/config.php';
session_start();

$isDev = function_exists('isDevAmbiente') && isDevAmbiente();
$ok    = isset($_SESSION['manut_dev_ok']) && $_SESSION['manut_dev_ok'] === true;
if (!$isDev || !$ok) {
    http_response_code(403);
    exit('Acesso negado. Autentique em manutencao.php#backup primeiro.');
}

header('Content-Type: text/plain; charset=utf-8');
ini_set('max_execution_time', 600);

$pdo = getDBConnection();
$action = $_GET['action'] ?? 'resumo';

function buscarNASA($lat, $lng, $dataInicio, $dataFim) {
    // NASA POWER aceita YYYYMMDD
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
        // descartar valores -999 (NASA fillvalue)
        return array_filter($p[$k], fn($v) => $v > -990);
    };
    $t   = $col('T2M');
    $tmn = $col('T2M_MIN');
    $tmx = $col('T2M_MAX');
    $pr  = $col('PRECTOTCORR');
    $rh  = $col('RH2M');

    if (empty($t)) return null;
    return [
        't_media' => round(array_sum($t)  / count($t), 2),
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

// =============== RESUMO ===============
if ($action === 'resumo') {
    echo "=== Cobertura clima ===\n\n";
    $r = $pdo->query("
        SELECT
            COUNT(*) AS coletas_total,
            SUM(EXISTS(SELECT 1 FROM dados_climaticos dc
                       WHERE dc.coleta_id = c.id
                         AND dc.temperatura_media IS NOT NULL)) AS coletas_com_clima,
            COUNT(*) - SUM(EXISTS(SELECT 1 FROM dados_climaticos dc
                                  WHERE dc.coleta_id = c.id
                                    AND dc.temperatura_media IS NOT NULL)) AS coletas_sem_clima
        FROM coletas c
    ")->fetch();

    echo "Coletas totais:     {$r['coletas_total']}\n";
    echo "Com clima:          {$r['coletas_com_clima']}\n";
    echo "Sem clima:          {$r['coletas_sem_clima']}\n\n";

    $rc = $pdo->query("
        SELECT l.nome, COUNT(c.id) AS n_coletas,
               SUM(EXISTS(SELECT 1 FROM dados_climaticos dc
                          WHERE dc.coleta_id = c.id
                            AND dc.temperatura_media IS NOT NULL)) AS com_clima
        FROM coletas c
        LEFT JOIN animais a ON a.id = c.animal_id
        LEFT JOIN locais l  ON l.id = a.local_id
        GROUP BY l.nome ORDER BY l.nome
    ")->fetchAll();
    echo "Por fazenda:\n";
    foreach ($rc as $row) {
        echo sprintf("  %-30s  %2d coletas  %2d com clima\n",
            $row['nome'], $row['n_coletas'], $row['com_clima']);
    }
    exit;
}

// =============== PREVIEW ===============
if ($action === 'preview') {
    $stmt = $pdo->query("
        SELECT c.id AS coleta_id, c.data_coleta, c.animal_id,
               a.identificacao, l.id AS local_id, l.nome, l.latitude, l.longitude,
               EXISTS(SELECT 1 FROM dados_climaticos dc
                      WHERE dc.coleta_id = c.id
                        AND dc.temperatura_media IS NOT NULL) AS tem_clima
        FROM coletas c
        LEFT JOIN animais a ON a.id = c.animal_id
        LEFT JOIN locais l  ON l.id = a.local_id
        ORDER BY c.data_coleta DESC, l.nome
    ");

    echo "=== PREVIEW: coletas que SERIAM consultadas na NASA POWER ===\n\n";
    echo sprintf("%-12s %-30s %-12s %-10s %-9s %s\n",
        "data_coleta", "fazenda", "animal", "lat", "lng", "tem_clima");
    echo str_repeat('-', 90) . "\n";
    $n = 0; $sem = 0;
    while ($r = $stmt->fetch()) {
        $marca = $r['tem_clima'] ? 'OK' : 'PRECISA';
        echo sprintf("%-12s %-30s %-12s %-10s %-9s %s\n",
            $r['data_coleta'] ?? '-',
            substr($r['nome'] ?? '-', 0, 30),
            $r['identificacao'] ?? '-',
            $r['latitude'] ?? '-',
            $r['longitude'] ?? '-',
            $marca);
        $n++;
        if (!$r['tem_clima']) $sem++;
    }
    echo "\nTotal: $n coletas, $sem sem clima.\n";
    echo "Para carregar: ?action=carregar\n";
    exit;
}

// =============== CARREGAR ===============
if ($action === 'carregar') {
    $stmt = $pdo->query("
        SELECT c.id AS coleta_id, c.data_coleta, l.id AS local_id,
               l.nome, l.latitude, l.longitude
        FROM coletas c
        LEFT JOIN animais a ON a.id = c.animal_id
        LEFT JOIN locais l  ON l.id = a.local_id
        WHERE l.latitude IS NOT NULL AND l.longitude IS NOT NULL
          AND NOT EXISTS (
              SELECT 1 FROM dados_climaticos dc
              WHERE dc.coleta_id = c.id AND dc.temperatura_media IS NOT NULL
          )
        ORDER BY c.data_coleta DESC
    ");
    $coletas = $stmt->fetchAll();
    echo "Coletas pendentes: " . count($coletas) . "\n\n";

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

    $sucesso = 0; $falha = 0;
    foreach ($coletas as $c) {
        $dataFim = $c['data_coleta'];
        $dataIni = date('Y-m-d', strtotime($dataFim . ' -90 days'));
        echo "[" . $c['coleta_id'] . "] " . $c['nome'] . " " . $dataFim . " ... ";
        flush();
        $r = buscarNASA($c['latitude'], $c['longitude'], $dataIni, $dataFim);
        if (!$r) {
            echo "FALHA\n";
            $falha++;
            continue;
        }
        try {
            $upsert->execute([
                $c['coleta_id'], $c['local_id'], $c['data_coleta'],
                $c['latitude'], $c['longitude'],
                $dataIni, $dataFim,
                $r['t_media'], $r['t_min'], $r['t_max'],
                $r['p_total'], $r['p_media'],
                $r['r_media'], $r['r_min'], $r['r_max'],
            ]);
            echo sprintf("ok  T=%.1f°C  P=%.1fmm  RH=%.1f%%  (%dd)\n",
                $r['t_media'], $r['p_total'], $r['r_media'], $r['n_dias']);
            $sucesso++;
        } catch (Exception $e) {
            echo "ERRO sql: " . $e->getMessage() . "\n";
            $falha++;
        }
        usleep(250000); // throttle 250ms
    }
    echo "\n=== Resumo: $sucesso sucesso, $falha falha ===\n";
    exit;
}

echo "Use action=resumo, preview ou carregar.\n";
