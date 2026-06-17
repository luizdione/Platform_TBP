<?php
// ============================================
// API para Pesquisa de Animais
// api_animal.php
// ============================================

// Habilitar exibição de erros para debug (remover em produção)
error_reporting(E_ALL);
ini_set('display_errors', 0); // Não exibir na tela, apenas logar

// Capturar erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Erro fatal no servidor',
            'detalhes' => [
                'mensagem' => $error['message'],
                'arquivo' => $error['file'],
                'linha' => $error['line']
            ]
        ], JSON_UNESCAPED_UNICODE);
    }
});

require_once __DIR__ . '/../../templates/auth_central.php';
verificarAutenticacaoCentral();
require_once 'config.php';
setSecurityHeaders();

$codigo = $_GET['codigo'] ?? '';

if (empty($codigo)) {
    jsonResponse(['erro' => 'Código do animal não fornecido'], 400);
    exit;
}

try {
    $pdo = getDBConnection();
    
    // Buscar informações básicas do animal (sem GROUP BY para evitar problemas)
    $stmt = $pdo->prepare("
        SELECT 
            a.id,
            a.identificacao,
            a.identificacao as codigo_animal,
            a.nome_animal,
            a.sexo,
            a.raca,
            a.data_nascimento,
            a.peso_kg,
            a.local_id,
            a.observacoes,
            a.obito,
            a.motivo_obito,
            a.data_cadastro,
            l.nome as local_nome,
            l.municipio as local_municipio
        FROM animais a
        LEFT JOIN locais l ON a.local_id = l.id
        WHERE a.identificacao = ?
        LIMIT 1
    ");
    $stmt->execute([$codigo]);
    $animal = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$animal) {
        jsonResponse(['erro' => 'Animal não encontrado'], 404);
        exit;
    }
    
    // Contar total de coletas
    $stmtCount = $pdo->prepare("SELECT COUNT(*) as total FROM coletas WHERE animal_id = ?");
    $stmtCount->execute([$animal['id']]);
    $countResult = $stmtCount->fetch(PDO::FETCH_ASSOC);
    $animal['total_coletas'] = $countResult['total'] ?? 0;
    
    // Buscar histórico de coletas e resultados
    $stmt = $pdo->prepare("
        SELECT 
            c.data_coleta,
            c.estacao,
            c.observacoes,
            CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END as pcr_babesia,
            CASE WHEN pcr.anaplasma_marginale = 'Positivo' THEN 1 ELSE 0 END as pcr_anaplasma,
            CASE WHEN pcr.trypanosoma_vivax = 'Positivo' THEN 1 ELSE 0 END as pcr_trypanosoma,
            CASE WHEN lamp.babesia_18s_lamp = 'Positivo' THEN 1 ELSE 0 END as lamp_babesia_18s,
            CASE WHEN lamp.babesia_cytb_lamp = 'Positivo' THEN 1 ELSE 0 END as lamp_babesia_cytb,
            lamp.tempo_deteccao_18s_min,
            lamp.tempo_deteccao_cytb_min,
            lamp.intensidade_sinal_18s,
            lamp.intensidade_sinal_cytb
        FROM coletas c
        LEFT JOIN resultados_pcr pcr ON c.id = pcr.coleta_id
        LEFT JOIN resultados_lamp lamp ON c.id = lamp.coleta_id
        WHERE c.animal_id = ?
        ORDER BY c.data_coleta ASC
    ");
    $stmt->execute([$animal['id']]);
    $historico = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    jsonResponse([
        'sucesso' => true,
        'animal' => $animal,
        'historico' => $historico
    ]);
    
} catch (Exception $e) {
    logErro('Erro na API de animais: ' . $e->getMessage());
    jsonResponse([
        'sucesso' => false,
        'erro' => $e->getMessage(),
        'detalhes' => [
            'arquivo' => $e->getFile(),
            'linha' => $e->getLine()
        ]
    ], 500);
}
?>
