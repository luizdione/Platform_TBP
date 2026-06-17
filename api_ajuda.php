<?php
// ============================================================
// api_ajuda.php — Proxy autenticado para arquivos de ajuda.
// Acesso permitido apenas se:
//   1) usuario passou pelo auth_central
//   2) sessao tem $_SESSION['ajuda_unlocked'] = true
//      (definido por api_ajuda_auth.php apos validar senha)
// ============================================================

require_once __DIR__ . '/../../templates/auth_central.php';
verificarAutenticacaoCentral();  // bloqueia anonimos

require_once 'config.php';
if (function_exists('setSecurityHeaders')) {
    setSecurityHeaders();
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

// Verifica liberacao por senha
if (empty($_SESSION['ajuda_unlocked'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "# Acesso restrito\nDigite a senha de acesso a aba Ajuda para visualizar este conteudo.";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');

$arquivo = $_GET['file'] ?? '';

// Whitelist: somente arquivos .md, apenas letras/numeros/underscore/hifen
if (!preg_match('/^[A-Za-z0-9_\-]+\.md$/', $arquivo)) {
    http_response_code(400);
    echo "# Erro\nNome de arquivo invalido: " . htmlspecialchars($arquivo);
    exit;
}

$base = __DIR__ . DIRECTORY_SEPARATOR . 'ajuda_tutoriais';
$path = realpath($base . DIRECTORY_SEPARATOR . $arquivo);

// Garante que o caminho resolvido fique DENTRO de ajuda_tutoriais/
if ($path === false || strpos($path, realpath($base)) !== 0 || !is_file($path)) {
    http_response_code(404);
    echo "# Erro\nArquivo de ajuda nao encontrado.";
    exit;
}

readfile($path);
exit;
