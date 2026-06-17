<?php
// ============================================================
// api_ajuda_auth.php
// Recebe a senha de acesso a aba Ajuda e marca a sessao.
// ============================================================

require_once __DIR__ . '/../../templates/auth_central.php';
verificarAutenticacaoCentral();   // bloqueia anonimos

require_once 'config.php';
if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$acao = $_POST['acao'] ?? $_GET['acao'] ?? '';

// Logout (lock) — encerra acesso a aba Ajuda
if ($acao === 'logout') {
    unset($_SESSION['ajuda_unlocked']);
    echo json_encode(['ok' => true, 'status' => 'locked']);
    exit;
}

// Status atual
if ($acao === 'status') {
    echo json_encode(['ok' => true, 'liberada' => !empty($_SESSION['ajuda_unlocked'])]);
    exit;
}

// Validacao da senha (POST)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'erro' => 'Metodo invalido. Use POST.']);
    exit;
}

// Rate limit simples: 5 tentativas / 60s por sessao
if (!isset($_SESSION['ajuda_tentativas']))      $_SESSION['ajuda_tentativas'] = 0;
if (!isset($_SESSION['ajuda_tentativas_inicio'])) $_SESSION['ajuda_tentativas_inicio'] = time();

if (time() - $_SESSION['ajuda_tentativas_inicio'] > 60) {
    $_SESSION['ajuda_tentativas'] = 0;
    $_SESSION['ajuda_tentativas_inicio'] = time();
}

if ($_SESSION['ajuda_tentativas'] >= 5) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'erro' => 'Muitas tentativas. Aguarde 60 segundos.']);
    exit;
}

$senha = $_POST['senha'] ?? '';

if (!function_exists('verificarSenhaAjuda') || !verificarSenhaAjuda($senha)) {
    $_SESSION['ajuda_tentativas']++;
    http_response_code(401);
    echo json_encode([
        'ok'             => false,
        'erro'           => 'Senha incorreta.',
        'tentativas'     => $_SESSION['ajuda_tentativas'],
        'max_tentativas' => 5
    ]);
    exit;
}

// Sucesso
$_SESSION['ajuda_unlocked']         = true;
$_SESSION['ajuda_unlocked_at']      = time();
$_SESSION['ajuda_tentativas']       = 0;
$_SESSION['ajuda_tentativas_inicio'] = time();

echo json_encode(['ok' => true, 'liberada' => true]);
exit;
