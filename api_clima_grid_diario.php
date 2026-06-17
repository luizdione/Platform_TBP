<?php
/**
 * api_clima_grid_diario.php  (versao ERA5-Land / Open-Meteo)
 *
 * Baixa Open-Meteo Archive API (modelo ERA5-Land, 0.1 deg ~10km) para cada
 * CELULA NATIVA que intersecta a bbox TPB fixa. Por celula faz UMA chamada
 * hourly (T+P+RH), agrega para diario em PHP e grava em clima_pixel_diario
 * com fonte='ERA5_LAND'. Cobertura por (celula, ano) em clima_pixel_cobertura.
 *
 * Janela alvo:
 *   - Inicio: TPB_DATA_INICIO (= 2025-06-01, fixo)
 *   - Fim:    hoje (Open-Meteo tem ~7 dias de defasagem; dias mais recentes
 *             podem vir com valores nulos — sera retomado na proxima execucao)
 *
 * Para cada celula:
 *   - Por ano alvo (2025, 2026, ...):
 *      target_inicio = max(TPB_DATA_INICIO, "$ano-01-01")
 *      target_fim    = min(hoje,            "$ano-12-31")
 *      se cobertura.data_fim < target_fim => baixa de max(cob.data_fim+1, target_inicio)
 *                                                  ate target_fim
 *
 * Resposta JSON:
 *   { celulas, execucoes, bbox, periodo, display_grid, fonte:"ERA5_LAND" }
 */

// ===== Blindagem: NUNCA devolver HTML, sempre JSON =====
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('max_execution_time', 300);
@ob_start();

set_error_handler(function($severity, $msg, $file, $line) {
    if (!(error_reporting() & $severity)) return false;
    throw new ErrorException($msg, 0, $severity, $file, $line);
});
set_exception_handler(function($e) {
    @ob_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'erro' => 'PHP: ' . $e->getMessage(),
        'arquivo' => basename($e->getFile()),
        'linha' => $e->getLine(),
    ]);
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

// Garante que as tabelas (clima_pixel_diario / clima_pixel_cobertura) existem.
// Idempotente: nao faz nada se ja estiverem ok.
tpb_garantir_tabelas_clima($pdo);

$dataInicio = TPB_DATA_INICIO;
$dataFim    = tpb_data_fim();

// CHUNKING — para nao estourar o limite hard de tempo do Hostinger Apache,
// processamos um pedaco de celulas por requisicao. Frontend chama em loop.
$cell_offset = max(0, (int)($_GET['cell_offset'] ?? 0));
$chunk       = max(1, min(8, (int)($_GET['chunk'] ?? 4)));

// Modo "refazer_p": para cada celula no chunk, apaga as linhas existentes do
// cache no intervalo TPB e reseta a cobertura, FORCANDO rebaixar com a logica
// nova que pega 'rain' OU 'precipitation'. Use uma vez quando alterou logica
// de parsing e quer atualizar dados velhos.
$refazer_p = !empty($_GET['refazer_p']);

$celulas_todas = tpb_era5_cells_in_bbox();
if (empty($celulas_todas)) {
    echo json_encode(['erro' => 'Bbox TPB nao contem nenhuma celula ERA5-Land — verificar clima_config.php']);
    exit;
}
$total_celulas = count($celulas_todas);
$celulas       = array_slice($celulas_todas, $cell_offset, $chunk);
$end_offset    = min($cell_offset + $chunk, $total_celulas);
$terminou      = $end_offset >= $total_celulas;

/**
 * fetchOpenMeteoERA5: chama Open-Meteo Archive API para 1 ponto, janela
 * fechada [$di, $df], pedindo hourly T2M / precipitation / RH2M do modelo
 * ERA5-Land. Agrega para diario em PHP (media de T e RH, soma de P).
 * Retorna array [{data, t, p, r}] ou null em falha.
 */
/**
 * httpGet: GET HTTPS robusto. Tenta cURL primeiro, depois file_get_contents.
 * Retorna a string da resposta ou null em falha (sem warning).
 */
function httpGet($url) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'TPB-LGM-IFRJ/1.0 (research)',
        ]);
        $raw  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $code >= 400) return null;
        return $raw;
    }
    // Fallback: file_get_contents (se allow_url_fopen estiver ON)
    if (ini_get('allow_url_fopen')) {
        $ctx = stream_context_create(['http' => [
            'timeout' => 60, 'ignore_errors' => true,
            'header'  => "User-Agent: TPB-LGM-IFRJ/1.0 (research)\r\n",
        ]]);
        $raw = @file_get_contents($url, false, $ctx);
        return $raw === false ? null : $raw;
    }
    return null;
}

function fetchOpenMeteoERA5($lat, $lng, $di, $df) {
    // IMPORTANTE: NAO especificamos models=era5_land aqui.
    // O diagnostico (api_clima_diag_om.php) mostrou que ao forcar era5_land,
    // o campo 'precipitation' vem null. Sem o parametro, o Open-Meteo usa
    // best_match — que para areas continentais EH ERA5-Land com o pipeline
    // padrao de variaveis, e 'precipitation' funciona corretamente (vem 0
    // em dias secos, valor positivo em dias chuvosos).
    $params = http_build_query([
        'latitude'   => $lat,
        'longitude'  => $lng,
        'start_date' => $di,
        'end_date'   => $df,
        'hourly'     => 'temperature_2m,precipitation,rain,relative_humidity_2m',
        'timezone'   => 'UTC',
    ]);
    $url = OPEN_METEO_URL . '?' . $params;
    $raw = httpGet($url);
    if (!$raw) return null;
    $j = json_decode($raw, true);
    if (!$j || !isset($j['hourly']['time'])) return null;

    // Helper: pega 1o array com algum valor nao-nulo dentre uma lista de chaves
    $pickArr = function(array $keys) use ($j) {
        foreach ($keys as $k) {
            $arr = $j['hourly'][$k] ?? null;
            if (!is_array($arr)) continue;
            foreach ($arr as $v) {
                if ($v !== null) return $arr;
            }
        }
        return $j['hourly'][$keys[0]] ?? [];
    };

    $times = $j['hourly']['time']                 ?? [];
    $T     = $pickArr(['temperature_2m']);
    $P     = $pickArr(['precipitation', 'rain']);  // tentativa dupla
    $R     = $pickArr(['relative_humidity_2m']);
    $n     = count($times);

    // Agregar hourly -> daily
    $bins = []; // ['2025-06-01' => ['t'=>[s,n], 'p'=>s, 'r'=>[s,n]]]
    for ($i = 0; $i < $n; $i++) {
        $iso = substr($times[$i], 0, 10);
        if (!isset($bins[$iso])) $bins[$iso] = ['ts'=>0,'tn'=>0,'ps'=>0,'pn'=>0,'rs'=>0,'rn'=>0];
        if (isset($T[$i]) && $T[$i] !== null) { $bins[$iso]['ts'] += $T[$i]; $bins[$iso]['tn']++; }
        if (isset($P[$i]) && $P[$i] !== null) { $bins[$iso]['ps'] += $P[$i]; $bins[$iso]['pn']++; }
        if (isset($R[$i]) && $R[$i] !== null) { $bins[$iso]['rs'] += $R[$i]; $bins[$iso]['rn']++; }
    }
    $out = [];
    foreach ($bins as $iso => $b) {
        $out[] = [
            'data' => $iso,
            't'    => $b['tn'] > 0 ? round($b['ts'] / $b['tn'], 2) : null,
            'p'    => $b['pn'] > 0 ? round($b['ps'], 2) : null,
            'r'    => $b['rn'] > 0 ? round($b['rs'] / $b['rn'], 2) : null,
        ];
    }
    return $out;
}

// SQL statements
$cobSelStmt = $pdo->prepare("
    SELECT data_inicio, data_fim FROM clima_pixel_cobertura
    WHERE lat_grid=? AND lng_grid=? AND ano=?
");
$cobUpsertStmt = $pdo->prepare("
    INSERT INTO clima_pixel_cobertura
        (lat_grid, lng_grid, ano, data_inicio, data_fim, n_dias)
    VALUES (?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        data_inicio = LEAST(data_inicio, VALUES(data_inicio)),
        data_fim    = GREATEST(data_fim,  VALUES(data_fim)),
        n_dias      = n_dias + VALUES(n_dias)
");
$diaInsStmt = $pdo->prepare("
    INSERT IGNORE INTO clima_pixel_diario
        (lat_grid, lng_grid, data, t2m, prectotcorr, rh2m, fonte)
    VALUES (?, ?, ?, ?, ?, ?, 'ERA5_LAND')
");

$anoInicio = (int)substr($dataInicio, 0, 4);
$anoFim    = (int)substr($dataFim,    0, 4);

// Se refazer_p: limpa cache+cobertura das celulas do chunk antes de baixar.
if ($refazer_p) {
    $delDia = $pdo->prepare("DELETE FROM clima_pixel_diario WHERE lat_grid=? AND lng_grid=? AND fonte='ERA5_LAND'");
    $delCob = $pdo->prepare("DELETE FROM clima_pixel_cobertura WHERE lat_grid=? AND lng_grid=?");
    foreach ($celulas as $cell) {
        $delDia->execute([$cell['lat'], $cell['lng']]);
        $delCob->execute([$cell['lat'], $cell['lng']]);
    }
}

$execs = [];
foreach ($celulas as $cell) {
    $lat = $cell['lat']; $lng = $cell['lng'];
    for ($ano = $anoInicio; $ano <= $anoFim; $ano++) {
        $target_ini = ($ano == $anoInicio) ? $dataInicio : "$ano-01-01";
        $target_fim = ($ano == $anoFim)    ? $dataFim    : "$ano-12-31";

        $cobSelStmt->execute([$lat, $lng, $ano]);
        $cob = $cobSelStmt->fetch();

        if ($cob) {
            $cob_fim = $cob['data_fim'];
            if ($cob_fim >= $target_fim) {
                $execs[] = ['lat'=>$lat,'lng'=>$lng,'ano'=>$ano,
                            'inicio'=>$target_ini,'fim'=>$target_fim,
                            'dias_inseridos'=>0,'ja_em_cache'=>true,'skip'=>'cobertura completa'];
                continue;
            }
            $fetch_ini = date('Y-m-d', strtotime($cob_fim . ' +1 day'));
            if ($fetch_ini < $target_ini) $fetch_ini = $target_ini;
            $fetch_fim = $target_fim;
        } else {
            $fetch_ini = $target_ini;
            $fetch_fim = $target_fim;
        }

        $dados = fetchOpenMeteoERA5($lat, $lng, $fetch_ini, $fetch_fim);
        if ($dados === null) {
            $execs[] = ['lat'=>$lat,'lng'=>$lng,'ano'=>$ano,
                        'inicio'=>$fetch_ini,'fim'=>$fetch_fim,
                        'dias_inseridos'=>0,'ja_em_cache'=>false,'erro'=>'falha Open-Meteo'];
            usleep(200000);
            continue;
        }

        // Quais dias realmente tem dados (t != null)? Pega o ultimo p/ cobertura
        $ultimo_dia_valido = null;
        foreach ($dados as $d) {
            if ($d['t'] !== null) $ultimo_dia_valido = $d['data'];
        }
        if ($ultimo_dia_valido === null) {
            // Resposta veio sem nenhum dia valido (ex.: janela toda no futuro)
            $execs[] = ['lat'=>$lat,'lng'=>$lng,'ano'=>$ano,
                        'inicio'=>$fetch_ini,'fim'=>$fetch_fim,
                        'dias_inseridos'=>0,'ja_em_cache'=>($cob !== false),
                        'aviso'=>'janela inteira sem dados (ERA5-Land tem ~7 dias de defasagem)'];
            usleep(200000);
            continue;
        }

        try {
            $pdo->beginTransaction();
            $ins = 0;
            foreach ($dados as $d) {
                $diaInsStmt->execute([$lat, $lng, $d['data'], $d['t'], $d['p'], $d['r']]);
                $ins++;
            }
            // Cobertura SO ate o ultimo dia REALMENTE recebido c/ valor (importante:
            // dias mais recentes podem voltar como null por causa da defasagem)
            $cobUpsertStmt->execute([$lat, $lng, $ano, $fetch_ini, $ultimo_dia_valido, $ins]);
            $pdo->commit();
            $execs[] = ['lat'=>$lat,'lng'=>$lng,'ano'=>$ano,
                        'inicio'=>$fetch_ini,'fim_cobertura'=>$ultimo_dia_valido,
                        'fim_pedido'=>$fetch_fim,
                        'dias_inseridos'=>$ins,'ja_em_cache'=>($cob !== false)];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $execs[] = ['lat'=>$lat,'lng'=>$lng,'ano'=>$ano,
                        'inicio'=>$fetch_ini,'fim'=>$fetch_fim,
                        'dias_inseridos'=>0,'ja_em_cache'=>false,'erro'=>$e->getMessage()];
        }
        usleep(150000); // 150ms entre chamadas (Open-Meteo permite 600/min)
    }
}

[$n_rows, $n_cols, $dlat_deg, $dlng_deg] = tpb_display_grid_dims();

echo json_encode([
    'fonte'                  => ERA5_SOURCE,
    'celulas_no_chunk'       => $celulas,
    'celulas_total_na_bbox'  => $total_celulas,
    'cell_offset_pedido'     => $cell_offset,
    'cell_offset_proximo'    => $end_offset,
    'chunk'                  => $chunk,
    'terminou'               => $terminou,
    'execucoes'              => $execs,
    'bbox'                   => [TPB_LA_SOUTH, TPB_LO_WEST, TPB_LA_NORTH, TPB_LO_EAST],
    'periodo'                => ['inicio'=>$dataInicio, 'fim'=>$dataFim],
    'display_grid' => [
        'n_rows'=>$n_rows, 'n_cols'=>$n_cols,
        'dlat_deg'=>$dlat_deg, 'dlng_deg'=>$dlng_deg,
        'pixel_metros'=>TPB_PIXEL_METROS,
    ],
]);
