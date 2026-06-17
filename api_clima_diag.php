<?php
/**
 * api_clima_diag.php
 *
 * Diagnostico do pipeline climatico ERA5-Land/Open-Meteo. Abra no navegador
 * direto (ex: https://seusite/api_clima_diag.php) e veja onde esta o problema.
 *
 * Verifica:
 *   1. Versao PHP / extensoes (curl, openssl, pdo_mysql)
 *   2. allow_url_fopen ON/OFF
 *   3. Conexao com o banco
 *   4. Tabela clima_pixel_diario existe + tem coluna 'fonte'
 *   5. Tabela clima_pixel_cobertura existe
 *   6. Bbox TPB e celulas ERA5-Land calculadas (sanity check do clima_config)
 *   7. Teste de conectividade com Open-Meteo (1 chamada de 1 dia, 1 ponto)
 *   8. Estado atual do cache (qtos dias gravados, qual a janela)
 */

header('Content-Type: text/plain; charset=utf-8');
ini_set('display_errors', '0');

echo "================ DIAGNOSTICO CLIMA TPB ================\n\n";

// 1) PHP env
echo "[1] PHP version: " . PHP_VERSION . "\n";
echo "    curl ext:        " . (function_exists('curl_init') ? 'OK' : 'AUSENTE') . "\n";
echo "    openssl ext:     " . (extension_loaded('openssl')   ? 'OK' : 'AUSENTE') . "\n";
echo "    pdo_mysql ext:   " . (extension_loaded('pdo_mysql') ? 'OK' : 'AUSENTE') . "\n";
echo "    allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'ON' : 'OFF (vai usar curl)') . "\n";
echo "    max_exec_time:   " . ini_get('max_execution_time') . "s\n";
echo "    display_errors:  " . (ini_get('display_errors') ? 'ON (CUIDADO - polui JSON!)' : 'OFF') . "\n\n";

// 2) Carrega config
try {
    require_once __DIR__ . '/config.php';
    echo "[2] config.php: OK\n";
} catch (Throwable $e) {
    echo "[2] config.php: ERRO -> " . $e->getMessage() . "\n";
    exit;
}

try {
    require_once __DIR__ . '/clima_config.php';
    echo "    clima_config.php: OK\n";
    echo "    TPB_LA_SOUTH=" . TPB_LA_SOUTH . " TPB_LA_NORTH=" . TPB_LA_NORTH . "\n";
    echo "    TPB_LO_WEST="  . TPB_LO_WEST  . " TPB_LO_EAST="  . TPB_LO_EAST  . "\n";
    echo "    TPB_DATA_INICIO=" . TPB_DATA_INICIO . " hoje=" . tpb_data_fim() . "\n\n";
} catch (Throwable $e) {
    echo "    clima_config.php: ERRO -> " . $e->getMessage() . "\n";
    exit;
}

// 3) Banco
try {
    $pdo = getDBConnection();
    $v = $pdo->query("SELECT VERSION()")->fetchColumn();
    echo "[3] Banco conectado. MySQL " . $v . "\n\n";
} catch (Throwable $e) {
    echo "[3] Banco ERRO: " . $e->getMessage() . "\n";
    exit;
}

// 4) Schema clima_pixel_diario
echo "[4] Tabela clima_pixel_diario:\n";
$exists = $pdo->query("SHOW TABLES LIKE 'clima_pixel_diario'")->fetch();
if (!$exists) {
    echo "    NAO EXISTE. Aplique sql/07_clima_pixel_diario.sql primeiro.\n\n";
} else {
    $cols = $pdo->query("SHOW COLUMNS FROM clima_pixel_diario")->fetchAll(PDO::FETCH_COLUMN);
    echo "    Colunas: " . implode(', ', $cols) . "\n";
    $temFonte = in_array('fonte', $cols, true);
    echo "    Coluna 'fonte': " . ($temFonte ? 'OK' : "FALTANDO - rode ALTER TABLE clima_pixel_diario ADD COLUMN fonte VARCHAR(20) DEFAULT 'NASA_POWER';") . "\n";
    $n = $pdo->query("SELECT COUNT(*) FROM clima_pixel_diario")->fetchColumn();
    echo "    Total de linhas: $n\n";
    if ($temFonte) {
        $rows = $pdo->query("SELECT fonte, COUNT(*) AS n FROM clima_pixel_diario GROUP BY fonte")->fetchAll();
        foreach ($rows as $r) echo "      fonte={$r['fonte']}: {$r['n']} linhas\n";
    }
    echo "\n";
}

// 5) Schema cobertura
echo "[5] Tabela clima_pixel_cobertura:\n";
$exists = $pdo->query("SHOW TABLES LIKE 'clima_pixel_cobertura'")->fetch();
if (!$exists) {
    echo "    NAO EXISTE. Aplique sql/07_clima_pixel_diario.sql primeiro.\n\n";
} else {
    $n = $pdo->query("SELECT COUNT(*) FROM clima_pixel_cobertura")->fetchColumn();
    echo "    Total de combinacoes (celula,ano) cobertas: $n\n";
    if ($n > 0) {
        $rows = $pdo->query("SELECT lat_grid, lng_grid, ano, data_inicio, data_fim, n_dias FROM clima_pixel_cobertura ORDER BY lat_grid, lng_grid, ano LIMIT 10")->fetchAll();
        foreach ($rows as $r)
            echo "      ({$r['lat_grid']},{$r['lng_grid']}) ano={$r['ano']} {$r['data_inicio']}->{$r['data_fim']} ({$r['n_dias']} dias)\n";
    }
    echo "\n";
}

// 6) Celulas ERA5-Land
echo "[6] Celulas ERA5-Land na bbox TPB:\n";
try {
    $cells = tpb_era5_cells_in_bbox();
    echo "    Total: " . count($cells) . " celulas\n";
    foreach ($cells as $i => $c) {
        echo sprintf("      #%-2d lat=%-9s lng=%-9s [la %.4f..%.4f, lo %.4f..%.4f]\n",
            $i+1, $c['lat'], $c['lng'], $c['la_lo'], $c['la_hi'], $c['lo_lo'], $c['lo_hi']);
    }
    echo "\n";
} catch (Throwable $e) {
    echo "    ERRO: " . $e->getMessage() . "\n\n";
}

// 7) Teste real de Open-Meteo (1 chamada)
echo "[7] Teste Open-Meteo (1 chamada, ponto central, 1 dia):\n";
$lat_test = round((TPB_LA_SOUTH + TPB_LA_NORTH)/2, 3);
$lng_test = round((TPB_LO_WEST  + TPB_LO_EAST )/2, 3);
$url = OPEN_METEO_URL . '?' . http_build_query([
    'latitude'   => $lat_test,
    'longitude'  => $lng_test,
    'start_date' => '2025-06-01',
    'end_date'   => '2025-06-01',
    'hourly'     => 'temperature_2m,precipitation,relative_humidity_2m',
    'models'     => 'era5_land',
    'timezone'   => 'UTC',
]);
echo "    URL: $url\n";
$raw = null; $tDur = microtime(true);
if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_USERAGENT      => 'TPB-LGM-IFRJ/1.0 (research)',
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    $tDur = round((microtime(true) - $tDur) * 1000);
    if ($raw === false) {
        echo "    cURL FALHOU em {$tDur}ms: $err\n\n";
    } else {
        echo "    HTTP $code em {$tDur}ms, " . strlen($raw) . " bytes recebidos\n";
        $j = json_decode($raw, true);
        if (!$j) {
            echo "    Resposta NAO-JSON (primeiros 300 chars):\n    " . substr($raw, 0, 300) . "\n\n";
        } elseif (isset($j['hourly']['temperature_2m'])) {
            $t = $j['hourly']['temperature_2m'];
            $n = count($t);
            $tNotNull = array_filter($t, fn($v) => $v !== null);
            echo "    OK - {$n} valores hourly, " . count($tNotNull) . " nao-nulos\n";
            echo "    Exemplo: t[0]=" . ($t[0] ?? 'null') . " t[12]=" . ($t[12] ?? 'null') . "\n\n";
        } else {
            echo "    JSON sem 'hourly.temperature_2m'. Resposta: " . substr(json_encode($j), 0, 300) . "\n\n";
        }
    }
} else {
    echo "    cURL nao disponivel. Tentando file_get_contents...\n";
    if (ini_get('allow_url_fopen')) {
        $raw = @file_get_contents($url);
        $tDur = round((microtime(true) - $tDur) * 1000);
        echo "    " . ($raw ? "OK em {$tDur}ms" : "FALHOU em {$tDur}ms") . "\n\n";
    } else {
        echo "    allow_url_fopen=OFF e nao tem cURL. Sem como fazer requisicoes HTTPS.\n\n";
    }
}

echo "================ FIM DIAGNOSTICO ================\n";
