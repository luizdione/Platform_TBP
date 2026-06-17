<?php
// ============================================
// API para Gerenciamento de Locais
// api_locais.php
// ============================================

require_once 'config.php';
setSecurityHeaders();

// Aceitar POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['erro' => 'Método não permitido'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';
$id = $input['id'] ?? '';

try {
    $pdo = getDBConnection();
    
    if (empty($id)) {
        throw new Exception('ID não fornecido');
    }
    
    switch ($action) {
        case 'desativar':
            $stmt = $pdo->prepare("UPDATE locais SET ativo = FALSE WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse(['sucesso' => true, 'mensagem' => 'Local desativado']);
            break;
            
        case 'ativar':
            $stmt = $pdo->prepare("UPDATE locais SET ativo = TRUE WHERE id = ?");
            $stmt->execute([$id]);
            jsonResponse(['sucesso' => true, 'mensagem' => 'Local ativado']);
            break;
            
        case 'excluir':
            // Verificar se há dados vinculados
            $stmt = $pdo->prepare("
                SELECT 
                    (SELECT COUNT(*) FROM animais WHERE local_id = ?) as total_animais,
                    (SELECT COUNT(*) FROM coletas WHERE local_id = ?) as total_coletas
            ");
            $stmt->execute([$id, $id]);
            $vinculados = $stmt->fetch();
            
            if ($vinculados['total_animais'] > 0 || $vinculados['total_coletas'] > 0) {
                throw new Exception('Não é possível excluir: existem dados vinculados a este local');
            }
            
            // Excluir
            $stmt = $pdo->prepare("DELETE FROM locais WHERE id = ?");
            $stmt->execute([$id]);
            
            if ($stmt->rowCount() > 0) {
                jsonResponse(['sucesso' => true, 'mensagem' => 'Local excluído']);
            } else {
                throw new Exception('Local não encontrado');
            }
            break;
            
        default:
            throw new Exception('Ação não reconhecida');
    }
    
} catch (Exception $e) {
    logErro("Erro na API de locais: " . $e->getMessage());
    jsonResponse(['erro' => $e->getMessage()], 500);
}
?>
