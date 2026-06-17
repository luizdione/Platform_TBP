<?php
/**
 * sync_endpoint.php — Operacoes de sync executadas pelo PHP do container.
 *
 * O PHP aqui roda dentro do container Linux (Apache+PHP). Por isso nao
 * podemos chamar .bat (que eh Windows). Este endpoint implementa as
 * operacoes em PHP puro, usando:
 *  - PDO + cliente mysql do container para dump remoto
 *  - SCP/SSH via biblioteca nativa
 *
 * Acesso: somente em DEV, com sessao manut_dev_ok ativa.
 */

require_once __DIR__ . '/config.php';
session_start();

// Bloqueio de seguranca
$isDev = function_exists('isDevAmbiente') && isDevAmbiente();
$ok    = isset($_SESSION['manut_dev_ok']) && $_SESSION['manut_dev_ok'] === true;
if (!$isDev || !$ok) {
    http_response_code(403);
    exit('Acesso negado. Autentique primeiro em manutencao.php.');
}

header('Content-Type: text/plain; charset=utf-8');

$action = $_GET['action'] ?? '';
$root   = realpath(__DIR__);

// ============================================================
// Carregar credenciais do sync_hostinger.env
// ============================================================
$envPath = $root . '/sync_hostinger.env';
$env = [];
if (file_exists($envPath)) {
    foreach (file($envPath) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        if (strpos($line, '=') !== false) {
            [$k, $v] = explode('=', $line, 2);
            $env[trim($k)] = trim($v);
        }
    }
}

function need($env, $keys) {
    $miss = array_filter($keys, fn($k) => empty($env[$k]));
    if ($miss) {
        echo "Erro: chaves faltando em sync_hostinger.env: " . implode(', ', $miss) . "\n";
        echo "Crie o arquivo a partir de sync_hostinger.env.example.\n";
        exit;
    }
}

// ============================================================
// ACTION: download_sql — baixa dump do Hostinger via PDO direto
// ============================================================
if ($action === 'download_sql') {
    need($env, ['HOSTINGER_DB_HOST','HOSTINGER_DB_NAME','HOSTINGER_DB_USER','HOSTINGER_DB_PASS']);

    echo "[1/3] Conectando ao MySQL do Hostinger...\n";
    flush();

    try {
        $dsn = "mysql:host={$env['HOSTINGER_DB_HOST']};dbname={$env['HOSTINGER_DB_NAME']};charset=utf8mb4";
        $pdo = new PDO($dsn, $env['HOSTINGER_DB_USER'], $env['HOSTINGER_DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 30,
        ]);
    } catch (Exception $e) {
        echo "ERRO de conexao: " . $e->getMessage() . "\n";
        echo "Confirme que o IP do seu container Docker esta liberado em hPanel > Bancos > Hosts Remotos.\n";
        echo "Voce pode adicionar % (qualquer IP) temporariamente para testar.\n";
        exit;
    }

    echo "[2/3] Conectado. Listando tabelas...\n";
    flush();
    $tabelas = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "  " . count($tabelas) . " tabelas encontradas.\n\n";

    // Diretorio de backups
    $backupDir = $root . '/backups_sql';
    if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);

    $ts = date('Y-m-d_H-i');
    $outFile = "$backupDir/u112_$ts.sql";

    echo "[3/3] Gerando dump em $outFile ...\n";
    flush();

    $fp = fopen($outFile, 'w');
    fwrite($fp, "-- Dump gerado pelo sync_endpoint.php em " . date('c') . "\n");
    fwrite($fp, "-- Database: {$env['HOSTINGER_DB_NAME']}\n");
    fwrite($fp, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

    foreach ($tabelas as $t) {
        echo "  - $t ... ";
        flush();
        // CREATE TABLE
        $row = $pdo->query("SHOW CREATE TABLE `$t`")->fetch(PDO::FETCH_NUM);
        fwrite($fp, "DROP TABLE IF EXISTS `$t`;\n" . $row[1] . ";\n\n");

        // Dados
        $stmt = $pdo->query("SELECT * FROM `$t`");
        $rows = 0;
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $cols = implode(',', array_map(fn($c)=>"`$c`", array_keys($r)));
            $vals = implode(',', array_map(fn($v)=>($v===null?'NULL':$pdo->quote($v)), array_values($r)));
            fwrite($fp, "INSERT INTO `$t` ($cols) VALUES ($vals);\n");
            $rows++;
        }
        fwrite($fp, "\n");
        echo "$rows linhas\n";
        flush();
    }
    fwrite($fp, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fp);

    $size = round(filesize($outFile)/1024, 1);
    echo "\nOK. Backup salvo: $outFile ({$size} KB)\n";
    echo "\nPara usar este backup como atual, execute no PowerShell:\n";
    echo "  copy \"backups_sql\\u112_$ts.sql\" \"u112348440_ifrj_crj_lgm.sql\"\n";
    echo "  docker compose down -v ; docker compose up -d --build\n";
    exit;
}

// ============================================================
// ACTION: list_backups
// ============================================================
if ($action === 'list_backups') {
    $dir = $root . '/backups_sql';
    if (!is_dir($dir)) {
        echo "Sem backups ainda. Use Download primeiro.\n";
        exit;
    }
    echo "Backups em $dir:\n\n";
    $files = glob("$dir/*.sql");
    rsort($files);
    if (!$files) {
        echo "(vazio)\n";
        exit;
    }
    foreach ($files as $f) {
        $size = round(filesize($f)/1024, 1);
        $when = date('d/m/Y H:i', filemtime($f));
        echo str_pad(basename($f), 40) . " {$size} KB  ($when)\n";
    }
    exit;
}

// ============================================================
// ACTION: ping_hostinger — testa conexao sem baixar dump
// ============================================================
if ($action === 'ping_hostinger') {
    need($env, ['HOSTINGER_DB_HOST','HOSTINGER_DB_USER','HOSTINGER_DB_PASS']);
    echo "Tentando conectar em {$env['HOSTINGER_DB_HOST']} ...\n";
    flush();
    try {
        $dsn = "mysql:host={$env['HOSTINGER_DB_HOST']};dbname={$env['HOSTINGER_DB_NAME']};charset=utf8mb4";
        $pdo = new PDO($dsn, $env['HOSTINGER_DB_USER'], $env['HOSTINGER_DB_PASS'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $v = $pdo->query("SELECT VERSION() AS v")->fetch();
        echo "OK. Conectado. MySQL/MariaDB versao: {$v['v']}\n";
        $n = $pdo->query("SELECT COUNT(*) FROM animais")->fetchColumn();
        echo "Animais no banco remoto: $n\n";
    } catch (Exception $e) {
        echo "FALHA: " . $e->getMessage() . "\n\n";
        echo "Possiveis causas:\n";
        echo " 1) Credenciais erradas em sync_hostinger.env\n";
        echo " 2) IP do container Docker nao esta na lista de Hosts Remotos do hPanel\n";
        echo "    (em hPanel > Bancos > acoes > Hosts MySQL Remotos, adicione '%' temporario)\n";
        echo " 3) Hostinger limita conexoes externas em alguns planos\n";
    }
    exit;
}

// ============================================================
echo "Action invalido. Use ?action=download_sql, list_backups ou ping_hostinger.\n";
