<?php
// ============================================
// Processamento Dashboard - Vigilância Parasitária Bovina
// Arquivo: processa_dashboard.php
// ============================================

require_once 'config.php';
setSecurityHeaders();

// Verificar método
validarMetodo('POST');

$input = json_decode(file_get_contents('php://input'), true);

try {
    $pdo = getDBConnection();
    
    // Validações obrigatórias
    if (empty($input['codigo_animal']) || empty($input['data_coleta']) || empty($input['sexo'])) {
        throw new Exception('Código do animal, data da coleta e sexo são obrigatórios');
    }
    
    // Verificar se o código já existe na tabela animais
    $stmt = $pdo->prepare("SELECT id FROM animais WHERE identificacao = ?");
    $stmt->execute([$input['codigo_animal']]);
    $animalExistente = $stmt->fetch();
    
    $animalId = null;
    
    if (!$animalExistente) {
        // Criar animal se não existir
        $queryAnimal = "INSERT INTO animais (local_id, identificacao, raca, sexo, data_nascimento, observacoes, carrapaticida_pouron, carrapaticida_pulverizacao, carrapaticida_injetavel, observacoes_tratamento) 
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        $stmtAnimal = $pdo->prepare($queryAnimal);
        $stmtAnimal->execute([
            !empty($input['local_id']) ? $input['local_id'] : null,
            $input['codigo_animal'],
            !empty($input['raca']) ? $input['raca'] : null,
            $input['sexo'],
            !empty($input['data_nascimento']) ? $input['data_nascimento'] : null,
            'Criado via dashboard',
            !empty($input['carrapaticida_pouron']) ? 1 : 0,
            !empty($input['carrapaticida_pulverizacao']) ? 1 : 0,
            !empty($input['carrapaticida_injetavel']) ? 1 : 0,
            !empty($input['observacoes_tratamento']) ? $input['observacoes_tratamento'] : null
        ]);
        $animalId = $pdo->lastInsertId();
    } else {
        // Atualizar dados do animal existente
        $animalId = $animalExistente['id'];
        $queryUpdate = "UPDATE animais SET local_id = ?, raca = ?, sexo = ?, data_nascimento = ?, carrapaticida_pouron = ?, carrapaticida_pulverizacao = ?, carrapaticida_injetavel = ?, observacoes_tratamento = ? WHERE id = ?";
        $stmtUpdate = $pdo->prepare($queryUpdate);
        $stmtUpdate->execute([
            !empty($input['local_id']) ? $input['local_id'] : null,
            !empty($input['raca']) ? $input['raca'] : null,
            $input['sexo'],
            !empty($input['data_nascimento']) ? $input['data_nascimento'] : null,
            !empty($input['carrapaticida_pouron']) ? 1 : 0,
            !empty($input['carrapaticida_pulverizacao']) ? 1 : 0,
            !empty($input['carrapaticida_injetavel']) ? 1 : 0,
            !empty($input['observacoes_tratamento']) ? $input['observacoes_tratamento'] : null,
            $animalId
        ]);
    }
    
    // Verificar se já existe coleta para este animal nesta data
    $stmt = $pdo->prepare("SELECT id FROM coletas WHERE animal_id = ? AND data_coleta = ?");
    $stmt->execute([$animalId, $input['data_coleta']]);
    $coletaExistente = $stmt->fetch();
    
    $coletaId = null;
    
    // Determinar estação baseado na data
    $mes = (int)date('m', strtotime($input['data_coleta']));
    $estacao = 'Verão'; // default
    if ($mes >= 3 && $mes <= 5) $estacao = 'Outono';
    elseif ($mes >= 6 && $mes <= 8) $estacao = 'Inverno';
    elseif ($mes >= 9 && $mes <= 11) $estacao = 'Primavera';
    
    if (!$coletaExistente) {
        // Criar nova coleta
        $queryColeta = "INSERT INTO coletas (animal_id, data_coleta, estacao, tipo_amostra, observacoes) 
                       VALUES (?, ?, ?, ?, ?)";
        $stmtColeta = $pdo->prepare($queryColeta);
        $stmtColeta->execute([
            $animalId,
            $input['data_coleta'],
            $estacao,
            'Sangue',
            !empty($input['em_lactacao']) ? 'Animal em lactação' : null
        ]);
        $coletaId = $pdo->lastInsertId();
    } else {
        // Atualizar coleta existente
        $coletaId = $coletaExistente['id'];
        $queryUpdateColeta = "UPDATE coletas SET estacao = ?, observacoes = ? WHERE id = ?";
        $stmtUpdateColeta = $pdo->prepare($queryUpdateColeta);
        $stmtUpdateColeta->execute([
            $estacao,
            !empty($input['em_lactacao']) ? 'Animal em lactação' : null,
            $coletaId
        ]);
    }
    
    // Verificar e atualizar/inserir resultados PCR
    $stmt = $pdo->prepare("SELECT id FROM resultados_pcr WHERE coleta_id = ?");
    $stmt->execute([$coletaId]);
    $pcrExistente = $stmt->fetch();
    
    if ($pcrExistente) {
        // Atualizar PCR existente
        $queryUpdatePCR = "UPDATE resultados_pcr SET babesia_bovis = ?, anaplasma_marginale = ?, trypanosoma_vivax = ? WHERE coleta_id = ?";
        $stmtUpdatePCR = $pdo->prepare($queryUpdatePCR);
        $stmtUpdatePCR->execute([
            !empty($input['pcr_babesia']) ? 'Positivo' : 'Negativo',
            !empty($input['pcr_anaplasma']) ? 'Positivo' : 'Negativo',
            !empty($input['pcr_trypanosoma']) ? 'Positivo' : 'Negativo',
            $coletaId
        ]);
    } else {
        // Inserir novo PCR
        $queryPCR = "INSERT INTO resultados_pcr (coleta_id, data_analise, babesia_bovis, anaplasma_marginale, trypanosoma_vivax) 
                    VALUES (?, ?, ?, ?, ?)";
        $stmtPCR = $pdo->prepare($queryPCR);
        $stmtPCR->execute([
            $coletaId,
            $input['data_coleta'],
            !empty($input['pcr_babesia']) ? 'Positivo' : 'Negativo',
            !empty($input['pcr_anaplasma']) ? 'Positivo' : 'Negativo',
            !empty($input['pcr_trypanosoma']) ? 'Positivo' : 'Negativo'
        ]);
    }
    
    // Verificar e atualizar/inserir resultados LAMP
    $stmt = $pdo->prepare("SELECT id FROM resultados_lamp WHERE coleta_id = ?");
    $stmt->execute([$coletaId]);
    $lampExistente = $stmt->fetch();
    
    if ($lampExistente) {
        // Atualizar LAMP existente
        $queryUpdateLAMP = "UPDATE resultados_lamp SET babesia_18s_lamp = ?, babesia_cytb_lamp = ? WHERE coleta_id = ?";
        $stmtUpdateLAMP = $pdo->prepare($queryUpdateLAMP);
        $stmtUpdateLAMP->execute([
            !empty($input['lamp_babesia_18s']) ? 'Positivo' : 'Negativo',
            !empty($input['lamp_babesia_cytb']) ? 'Positivo' : 'Negativo',
            $coletaId
        ]);
    } else {
        // Inserir novo LAMP
        $queryLAMP = "INSERT INTO resultados_lamp (coleta_id, data_analise, babesia_18s_lamp, babesia_cytb_lamp) 
                     VALUES (?, ?, ?, ?)";
        $stmtLAMP = $pdo->prepare($queryLAMP);
        $stmtLAMP->execute([
            $coletaId,
            $input['data_coleta'],
            !empty($input['lamp_babesia_18s']) ? 'Positivo' : 'Negativo',
            !empty($input['lamp_babesia_cytb']) ? 'Positivo' : 'Negativo'
        ]);
    }
    
    jsonResponse(['sucesso' => true, 'animal_id' => $animalId, 'coleta_id' => $coletaId]);
    
} catch (Exception $e) {
    logErro("Erro no processamento dashboard: " . $e->getMessage());
    jsonResponse(['erro' => $e->getMessage()], 500);
}
?>
