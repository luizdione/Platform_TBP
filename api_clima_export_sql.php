<?php
/**
 * api_clima_export_sql.php
 *
 * Exporta as duas tabelas do cache climatico ERA5-Land em um unico
 * arquivo SQL portavel, pronto para importar em outro banco MySQL
 * (servidor local, segundo Hostinger, backup off-site, etc.).
 *
 * Caracteristicas:
 *  - CREATE TABLE IF NOT EXISTS  -> nao destroi schema existente
 *  - INSERT IGNORE em lotes        -> nao duplica linhas; reentrante
 *  - Filtra por fonte='ERA5_LAND'  -> exclui lixo legado NASA_POWER
 *  - Streamado direto para download (Content-Disposition)
 *
 * Uso:
 *   GET ?download=1  -> forca download como arquivo .sql
 *   GET (sem args)   -> mostra no navegador (text/plain)
 */

ini_set('display_errors', '0');
ini_set('max_execution_time', 300);
require_once __DIR__ . '/config.php';

$pdo = getDBConnection();
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$forceDownload = !empty($_GET['download']);
$stamp = date('Ymd_His');

if ($forceDownload) {
    header('Content-Type: application/sql; charset=utf-8');
    header("Content-Disposition: attachment; filename=tpb_clima_era5land_$stamp.sql");
} else {
    header('Content-Type: text/plain; charset=utf-8');
}

// Helper p/ escapar valor SQL — NULL, numero ou string.
function sqlEsc($pdo, $v) {
    if ($v === null) return 'NULL';
    if (is_int($v) || is_float($v)) return (string)$v;
    return $pdo->quote((string)$v);
}

// ============ Cabecalho ============
echo "-- =====================================================================\n";
echo "-- TPB clima cache — dump ERA5-Land (Open-Meteo)\n";
echo "-- Gerado em: " . date('Y-m-d H:i:s') . "\n";
echo "-- Tabelas: clima_pixel_diario, clima_pixel_cobertura\n";
echo "-- Reentrante: pode rodar varias vezes sem duplicar.\n";
echo "-- =====================================================================\n\n";

echo "SET NAMES utf8mb4;\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

// ============ Schema (CREATE TABLE IF NOT EXISTS) ============
echo "-- ---------------------------------------------------------------------\n";
echo "-- Schema (idempotente — nao destroi tabelas existentes)\n";
echo "-- ---------------------------------------------------------------------\n";

echo "CREATE TABLE IF NOT EXISTS clima_pixel_diario (\n";
echo "    lat_grid     DECIMAL(6,3) NOT NULL,\n";
echo "    lng_grid     DECIMAL(6,3) NOT NULL,\n";
echo "    data         DATE NOT NULL,\n";
echo "    t2m          DECIMAL(5,2) NULL COMMENT 'Temperatura media diaria (C)',\n";
echo "    prectotcorr  DECIMAL(6,2) NULL COMMENT 'Precipitacao total diaria (mm)',\n";
echo "    rh2m         DECIMAL(5,2) NULL COMMENT 'Umidade relativa media diaria (%)',\n";
echo "    fonte        VARCHAR(20) DEFAULT 'NASA_POWER',\n";
echo "    PRIMARY KEY (lat_grid, lng_grid, data),\n";
echo "    INDEX idx_data (data),\n";
echo "    INDEX idx_data_pixel (data, lat_grid, lng_grid)\n";
echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 ROW_FORMAT=COMPRESSED\n";
echo "  COMMENT='Valores diarios brutos por pixel (NASA POWER ou ERA5-Land)';\n\n";

echo "CREATE TABLE IF NOT EXISTS clima_pixel_cobertura (\n";
echo "    lat_grid     DECIMAL(6,3) NOT NULL,\n";
echo "    lng_grid     DECIMAL(6,3) NOT NULL,\n";
echo "    ano          SMALLINT NOT NULL,\n";
echo "    data_inicio  DATE NOT NULL,\n";
echo "    data_fim     DATE NOT NULL,\n";
echo "    n_dias       INT DEFAULT 0,\n";
echo "    baixado_em   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,\n";
echo "    PRIMARY KEY (lat_grid, lng_grid, ano),\n";
echo "    INDEX idx_ano (ano)\n";
echo ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4\n";
echo "  COMMENT='Cobertura temporal por celula';\n\n";

// ============ clima_pixel_diario (apenas ERA5_LAND) ============
$n = (int)$pdo->query("SELECT COUNT(*) AS n FROM clima_pixel_diario WHERE fonte='ERA5_LAND'")->fetch()['n'];
echo "-- ---------------------------------------------------------------------\n";
echo "-- clima_pixel_diario (fonte=ERA5_LAND, $n linhas)\n";
echo "-- ---------------------------------------------------------------------\n";

if ($n > 0) {
    $stmt = $pdo->query("
        SELECT lat_grid, lng_grid, data, t2m, prectotcorr, rh2m, fonte
        FROM clima_pixel_diario
        WHERE fonte='ERA5_LAND'
        ORDER BY lat_grid, lng_grid, data
    ");
    $LOTE = 500;  // 500 linhas por INSERT (~30 KB cada)
    $buf = []; $totalBuf = 0;

    while ($r = $stmt->fetch()) {
        $buf[] = sprintf(
            "(%s,%s,%s,%s,%s,%s,%s)",
            sqlEsc($pdo, $r['lat_grid']),
            sqlEsc($pdo, $r['lng_grid']),
            sqlEsc($pdo, $r['data']),
            sqlEsc($pdo, $r['t2m']),
            sqlEsc($pdo, $r['prectotcorr']),
            sqlEsc($pdo, $r['rh2m']),
            sqlEsc($pdo, $r['fonte'])
        );
        if (count($buf) >= $LOTE) {
            echo "INSERT IGNORE INTO clima_pixel_diario\n";
            echo "  (lat_grid, lng_grid, data, t2m, prectotcorr, rh2m, fonte) VALUES\n";
            echo "  " . implode(",\n  ", $buf) . ";\n";
            $totalBuf += count($buf);
            $buf = [];
            if (!$forceDownload) { @flush(); }
        }
    }
    if (!empty($buf)) {
        echo "INSERT IGNORE INTO clima_pixel_diario\n";
        echo "  (lat_grid, lng_grid, data, t2m, prectotcorr, rh2m, fonte) VALUES\n";
        echo "  " . implode(",\n  ", $buf) . ";\n";
        $totalBuf += count($buf);
    }
    echo "-- ($totalBuf linhas inseridas em INSERT IGNORE)\n";
}
echo "\n";

// ============ clima_pixel_cobertura ============
$nC = (int)$pdo->query("SELECT COUNT(*) AS n FROM clima_pixel_cobertura")->fetch()['n'];
echo "-- ---------------------------------------------------------------------\n";
echo "-- clima_pixel_cobertura ($nC linhas)\n";
echo "-- ---------------------------------------------------------------------\n";

if ($nC > 0) {
    $stmt = $pdo->query("
        SELECT lat_grid, lng_grid, ano, data_inicio, data_fim, n_dias, baixado_em
        FROM clima_pixel_cobertura
        ORDER BY lat_grid, lng_grid, ano
    ");
    $buf = [];
    while ($r = $stmt->fetch()) {
        $buf[] = sprintf(
            "(%s,%s,%s,%s,%s,%s,%s)",
            sqlEsc($pdo, $r['lat_grid']),
            sqlEsc($pdo, $r['lng_grid']),
            sqlEsc($pdo, $r['ano']),
            sqlEsc($pdo, $r['data_inicio']),
            sqlEsc($pdo, $r['data_fim']),
            sqlEsc($pdo, $r['n_dias']),
            sqlEsc($pdo, $r['baixado_em'])
        );
    }
    if (!empty($buf)) {
        echo "INSERT IGNORE INTO clima_pixel_cobertura\n";
        echo "  (lat_grid, lng_grid, ano, data_inicio, data_fim, n_dias, baixado_em) VALUES\n";
        echo "  " . implode(",\n  ", $buf) . ";\n";
    }
}
echo "\n";

echo "SET FOREIGN_KEY_CHECKS = 1;\n";
echo "-- =====================================================================\n";
echo "-- Fim do dump. Total: $n linhas diarias + $nC linhas cobertura.\n";
echo "-- =====================================================================\n";
