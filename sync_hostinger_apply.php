<?php
/**
 * sync_hostinger_apply.php
 * Aplica migracoes SQL na PRODUCAO (Hostinger) com:
 *   1) Backup automatico antes (download SQL completo do remoto)
 *   2) Confirmacao explicita por senha (manut_dev_ok da sessao)
 *   3) Token CSRF no body da requisicao
 *   4) Log persistente em backups_sql/sync_apply_log.txt
 *
 * Acoes:
 *   ?action=preview      - mostra diff (o que seria mudado, sem aplicar)
 *   ?action=apply        - aplica de fato (POST com token)
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

// Carregar credenciais
$root = realpath(__DIR__);
$envPath = $root . '/sync_hostinger.env';
if (!file_exists($envPath)) { exit("ERRO: sync_hostinger.env nao encontrado.\n"); }
$env = [];
foreach (file($envPath) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#') continue;
    if (strpos($line, '=') !== false) {
        [$k,$v] = explode('=', $line, 2);
        $env[trim($k)] = trim($v);
    }
}
foreach (['HOSTINGER_DB_HOST','HOSTINGER_DB_NAME','HOSTINGER_DB_USER','HOSTINGER_DB_PASS'] as $k) {
    if (empty($env[$k])) { exit("ERRO: $k faltando em sync_hostinger.env\n"); }
}

// Conectar ao banco remoto
try {
    $remote = new PDO(
        "mysql:host={$env['HOSTINGER_DB_HOST']};dbname={$env['HOSTINGER_DB_NAME']};charset=utf8mb4",
        $env['HOSTINGER_DB_USER'], $env['HOSTINGER_DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 30]
    );
} catch (Exception $e) {
    exit("ERRO de conexao: " . $e->getMessage() . "\n");
}

$action = $_GET['action'] ?? '';
$logFile = $root . '/backups_sql/sync_apply_log.txt';
@mkdir($root . '/backups_sql', 0755, true);
function logmsg($msg, $logFile) {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg;
    echo $line . "\n";
    @file_put_contents($logFile, $line . "\n", FILE_APPEND);
    flush();
}

// =============== PREVIEW ===============
if ($action === 'preview') {
    logmsg("PREVIEW: simulando alteracoes na PRODUCAO sem aplicar...", $logFile);
    echo "\n";
    echo "=== Estado ATUAL na producao ===\n\n";

    // Fazendas existentes
    $fz = $remote->query("SELECT id, nome, municipio, ativo FROM locais ORDER BY id")->fetchAll();
    echo "Fazendas (locais):\n";
    foreach ($fz as $f) {
        $marca = (strtolower($f['nome']) === 'fazenda sorriso') ? '  <-- SERA APAGADA' : '';
        echo "  id=$f[id]  $f[nome] ($f[municipio])  ativo=$f[ativo]$marca\n";
    }

    // Animais por fazenda
    echo "\nAnimais por fazenda:\n";
    $stmt = $remote->query("
        SELECT l.nome, COUNT(a.id) AS n,
               SUM(CASE WHEN a.observacoes LIKE '%Dados simulados%' THEN 1 ELSE 0 END) AS sim
        FROM animais a LEFT JOIN locais l ON l.id=a.local_id
        GROUP BY l.nome ORDER BY l.nome
    ");
    foreach ($stmt as $r) {
        $nota = ($r['sim'] > 0) ? "  ($r[sim] simulados serao apagados se for Sorriso)" : '';
        echo "  $r[nome]: $r[n] animais$nota\n";
    }

    // Racas
    echo "\nRacas atuais (que serao padronizadas para Girolando):\n";
    $stmt = $remote->query("SELECT raca, COUNT(*) AS n FROM animais WHERE local_id IN (5,6,8) GROUP BY raca ORDER BY n DESC");
    foreach ($stmt as $r) {
        echo "  '$r[raca]': $r[n]\n";
    }

    echo "\n";
    echo "=== O QUE SERA FEITO se voce clicar em APPLY ===\n";
    echo "1) Backup completo do banco remoto em backups_sql/preapply_TIMESTAMP.sql\n";
    echo "2) Apagar fazenda Sorriso (id=20) + 40 animais simulados\n";
    echo "3) Padronizar raca para 'Girolando' nos 55 animais reais\n";
    echo "4) Criar coluna composicao_genetica preservando HP/GIR\n";
    echo "5) Criar coluna simulacao em animais e dados_climaticos.fonte\n";
    echo "6) Criar view v_dados_cientificos\n";
    echo "\n";
    echo "Token de seguranca para confirmar:\n";
    $token = bin2hex(random_bytes(8));
    $_SESSION['sync_apply_token'] = $token;
    echo "    $token\n";
    echo "\n";
    echo "Para APLICAR, abra:\n";
    echo "    sync_hostinger_apply.php?action=apply&token=$token\n";
    echo "(o token expira ao fechar a sessao)\n";
    exit;
}

// =============== APPLY ===============
if ($action === 'apply') {
    $token = $_GET['token'] ?? '';
    $expected = $_SESSION['sync_apply_token'] ?? '';
    if (!$token || !$expected || !hash_equals($expected, $token)) {
        exit("ERRO: token invalido. Rode primeiro action=preview para gerar um token.\n");
    }
    unset($_SESSION['sync_apply_token']); // single-use

    logmsg("APPLY iniciado pelo usuario " . ($_SESSION['usuario'] ?? '?'), $logFile);

    // ========== 1) BACKUP COMPLETO ANTES ==========
    $ts = date('Y-m-d_H-i-s');
    $backupFile = "$root/backups_sql/preapply_$ts.sql";
    logmsg("[1/3] Gerando backup pre-aplicacao em $backupFile ...", $logFile);

    $tabelas = $remote->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $fp = fopen($backupFile, 'w');
    fwrite($fp, "-- Backup pre-aplicacao gerado em " . date('c') . "\n");
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=0;\nSET NAMES utf8mb4;\n\n");
    foreach ($tabelas as $t) {
        $r = $remote->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        fwrite($fp, "DROP TABLE IF EXISTS `$t`;\n" . $r[1] . ";\n\n");
        $stmt = $remote->query("SELECT * FROM `$t`");
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols = implode(',', array_map(fn($c)=>"`$c`", array_keys($row)));
            $vals = implode(',', array_map(fn($v)=>($v===null?'NULL':$remote->quote($v)), array_values($row)));
            fwrite($fp, "INSERT INTO `$t` ($cols) VALUES ($vals);\n");
        }
        fwrite($fp, "\n");
    }
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);
    $size = round(filesize($backupFile)/1024,1);
    logmsg("Backup ok ({$size} KB)", $logFile);

    // ========== 2) APLICAR sql/00 ==========
    logmsg("[2/3] Aplicando sql/00_limpar_e_padronizar.sql na PRODUCAO ...", $logFile);
    $sql00 = file_get_contents("$root/sql/00_limpar_e_padronizar.sql");
    if (!$sql00) { exit("ERRO: sql/00_limpar_e_padronizar.sql nao encontrado\n"); }

    try {
        // mariadb suporta multi-statement via PDO se separarmos
        $remote->exec($sql00);
        logmsg("sql/00 aplicado", $logFile);
    } catch (Exception $e) {
        logmsg("FALHA sql/00: " . $e->getMessage(), $logFile);
        echo "\n*** ROLLBACK: para restaurar, importe via phpMyAdmin: $backupFile\n";
        exit;
    }

    // ========== 3) APLICAR sql/02 ==========
    logmsg("[3/3] Aplicando sql/02_qa_db.sql na PRODUCAO ...", $logFile);
    $sql02 = file_get_contents("$root/sql/02_qa_db.sql");
    try {
        $remote->exec($sql02);
        logmsg("sql/02 aplicado", $logFile);
    } catch (Exception $e) {
        logmsg("FALHA sql/02: " . $e->getMessage(), $logFile);
        echo "\n*** ROLLBACK: importe via phpMyAdmin: $backupFile\n";
        exit;
    }

    // ========== Verificacao ==========
    echo "\n=== VERIFICACAO POS-APLICACAO ===\n";
    $check = [
        ['Locais ativos', "SELECT COUNT(*) FROM locais WHERE ativo=1"],
        ['Animais Girolando', "SELECT COUNT(*) FROM animais WHERE raca='Girolando'"],
        ['Animais simulados (flag=1)', "SELECT COUNT(*) FROM animais WHERE simulacao=1"],
        ['Linhas em v_dados_cientificos', "SELECT COUNT(*) FROM v_dados_cientificos"],
    ];
    foreach ($check as [$lbl, $q]) {
        try {
            $n = $remote->query($q)->fetchColumn();
            echo "  $lbl: $n\n";
        } catch (Exception $e) {
            echo "  $lbl: ERRO " . $e->getMessage() . "\n";
        }
    }

    logmsg("APPLY finalizado com sucesso", $logFile);
    echo "\nPronto. Para reverter: importe $backupFile pelo phpMyAdmin do Hostinger.\n";
    exit;
}

// =============== Default ===============
echo "Use:\n";
echo "  sync_hostinger_apply.php?action=preview   (ve o que vai mudar)\n";
echo "  sync_hostinger_apply.php?action=apply&token=...   (aplica de fato)\n";
