<?php
/**
 * api_clima_diag_om.php
 *
 * Diagnostico Open-Meteo: faz UMA chamada real e devolve a estrutura
 * exata do JSON retornado, listando todos os campos disponiveis e
 * suas primeiras amostras de valores.
 *
 * Abre direto no navegador.
 */
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/clima_config.php';

echo "==== DIAG OPEN-METEO ERA5-LAND ====\n\n";

$lat = -22.6;
$lng = -43.7;
$di  = '2026-01-08';
$df  = '2026-01-10';

// Tentativa 1: COM models=era5_land
$params1 = http_build_query([
    'latitude'   => $lat,
    'longitude'  => $lng,
    'start_date' => $di,
    'end_date'   => $df,
    'hourly'     => 'temperature_2m,precipitation,rain,total_precipitation,relative_humidity_2m,surface_pressure',
    'models'     => 'era5_land',
    'timezone'   => 'UTC',
]);
$url1 = OPEN_METEO_URL . '?' . $params1;
echo "[1] Com models=era5_land:\n";
echo "    URL: $url1\n";

$ch = curl_init($url1);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_USERAGENT      => 'TPB-LGM-IFRJ/1.0 (research)',
]);
$raw1 = curl_exec($ch);
$code1 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "    HTTP $code1, " . strlen((string)$raw1) . " bytes\n";
if ($raw1) {
    $j = json_decode($raw1, true);
    if ($j && isset($j['hourly'])) {
        echo "    hourly.time count: " . count($j['hourly']['time'] ?? []) . "\n";
        echo "    Campos disponiveis em 'hourly':\n";
        foreach ($j['hourly'] as $k => $v) {
            $sample = is_array($v) ? array_slice($v, 0, 5) : $v;
            $hasNonNull = false;
            if (is_array($v)) {
                foreach ($v as $x) { if ($x !== null) { $hasNonNull = true; break; } }
            }
            echo sprintf("      %-35s n=%-4d nao-nulos=%s  primeiros5=%s\n",
                $k,
                is_array($v) ? count($v) : 1,
                $hasNonNull ? 'SIM' : 'NAO',
                json_encode($sample));
        }
        echo "\n    Campos disponiveis em 'hourly_units':\n";
        foreach (($j['hourly_units'] ?? []) as $k => $v) {
            echo "      $k = $v\n";
        }
    } else {
        echo "    Resposta sem 'hourly'. Conteudo:\n";
        echo "    " . substr($raw1, 0, 500) . "\n";
    }
}

echo "\n\n[2] Sem models (best_match):\n";
$params2 = http_build_query([
    'latitude'   => $lat,
    'longitude'  => $lng,
    'start_date' => $di,
    'end_date'   => $df,
    'hourly'     => 'temperature_2m,precipitation,rain,relative_humidity_2m',
    'timezone'   => 'UTC',
]);
$url2 = OPEN_METEO_URL . '?' . $params2;
echo "    URL: $url2\n";

$ch = curl_init($url2);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT        => 30,
]);
$raw2 = curl_exec($ch);
$code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "    HTTP $code2, " . strlen((string)$raw2) . " bytes\n";
if ($raw2) {
    $j = json_decode($raw2, true);
    if ($j && isset($j['hourly'])) {
        echo "    Campos em 'hourly':\n";
        foreach ($j['hourly'] as $k => $v) {
            $hasNonNull = false;
            if (is_array($v)) {
                foreach ($v as $x) { if ($x !== null) { $hasNonNull = true; break; } }
            }
            $sample = is_array($v) ? array_slice($v, 0, 5) : $v;
            echo sprintf("      %-35s nao-nulos=%s  primeiros5=%s\n",
                $k, $hasNonNull ? 'SIM' : 'NAO', json_encode($sample));
        }
    }
}

echo "\n==== FIM ====\n";
