<?php
// ============================================
// Pesquisa por Animais - Histórico Clínico
// pesquisa_animal.php
// ============================================

require_once __DIR__ . '/../../templates/auth_central.php';
verificarAutenticacaoCentral();
require_once 'config.php';
setSecurityHeaders();

try {
    $pdo = getDBConnection();
    
    // Buscar lista de animais para o select com local
    $stmt = $pdo->query("SELECT DISTINCT a.identificacao, l.nome as local_nome 
                         FROM animais a 
                         LEFT JOIN locais l ON a.local_id = l.id 
                         ORDER BY a.identificacao");
    $animais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Buscar todos os animais (sem coletas primeiro)
    $stmt = $pdo->query("SELECT 
                            a.id,
                            a.identificacao,
                            a.local_id,
                            l.nome as local_nome,
                            a.sexo,
                            a.raca,
                            a.data_nascimento,
                            a.observacoes
                         FROM animais a
                         LEFT JOIN locais l ON a.local_id = l.id
                         ORDER BY a.identificacao");
    $todosAnimais = $stmt->fetchAll();
    
    // Para cada animal, buscar TODAS as suas coletas com resultados
    foreach ($todosAnimais as &$animal) {
        $animal['coletas'] = [];
        
        try {
            // Buscar todas as coletas do animal (mais recente primeiro)
            $stmtColetas = $pdo->prepare("SELECT 
                                            c.id as coleta_id,
                                            c.data_coleta,
                                            CASE WHEN c.observacoes LIKE '%lactação%' OR c.observacoes LIKE '%lactacao%' THEN 1 ELSE 0 END as em_lactacao
                                          FROM coletas c
                                          WHERE c.animal_id = ?
                                          ORDER BY c.data_coleta DESC");
            $stmtColetas->execute([$animal['id']]);
            $coletas = $stmtColetas->fetchAll();
            
            foreach ($coletas as $coleta) {
                $coletaData = [
                    'coleta_id' => $coleta['coleta_id'],
                    'data_coleta' => $coleta['data_coleta'],
                    'em_lactacao' => $coleta['em_lactacao'],
                    'pcr_babesia' => 0,
                    'pcr_bigemina' => 0,
                    'pcr_anaplasma' => 0,
                    'pcr_trypanosoma' => 0,
                    'lamp_18s' => 0,
                    'lamp_cytb' => 0,
                    'tt_babesia' => null,
                    'tt_bigemina' => null,
                    'tt_anaplasma' => null,
                    'tt_trypanosoma' => null,
                    'tt_18s' => null,
                    'tt_cytb' => null
                ];

                // Buscar PCR
                try {
                    $stmtPcr = $pdo->prepare("SELECT * FROM resultados_pcr WHERE coleta_id = ?");
                    $stmtPcr->execute([$coleta['coleta_id']]);
                    $pcr = $stmtPcr->fetch();
                    if ($pcr) {
                        // 1 = Positivo, 0 = Negativo, 2 = Inconclusivo/Não diagnosticado
                        $coletaData['pcr_babesia'] = ($pcr['babesia_bovis'] === 'Positivo') ? 1 : (($pcr['babesia_bovis'] === 'Negativo') ? 0 : 2);
                        if (array_key_exists('babesia_bigemina', $pcr)) {
                            $coletaData['pcr_bigemina'] = ($pcr['babesia_bigemina'] === 'Positivo') ? 1 : (($pcr['babesia_bigemina'] === 'Negativo') ? 0 : 2);
                        }
                        $coletaData['pcr_anaplasma'] = ($pcr['anaplasma_marginale'] === 'Positivo') ? 1 : (($pcr['anaplasma_marginale'] === 'Negativo') ? 0 : 2);
                        $coletaData['pcr_trypanosoma'] = ($pcr['trypanosoma_vivax'] === 'Positivo') ? 1 : (($pcr['trypanosoma_vivax'] === 'Negativo') ? 0 : 2);
                        if (array_key_exists('tempo_deteccao_babesia', $pcr))     $coletaData['tt_babesia']     = $pcr['tempo_deteccao_babesia'];
                        if (array_key_exists('tempo_deteccao_bigemina', $pcr))    $coletaData['tt_bigemina']    = $pcr['tempo_deteccao_bigemina'];
                        if (array_key_exists('tempo_deteccao_anaplasma', $pcr))   $coletaData['tt_anaplasma']   = $pcr['tempo_deteccao_anaplasma'];
                        if (array_key_exists('tempo_deteccao_trypanosoma', $pcr)) $coletaData['tt_trypanosoma'] = $pcr['tempo_deteccao_trypanosoma'];
                    } else {
                        // Se não há resultado PCR, marcar como não diagnosticado
                        $coletaData['pcr_babesia'] = 2;
                        $coletaData['pcr_bigemina'] = 2;
                        $coletaData['pcr_anaplasma'] = 2;
                        $coletaData['pcr_trypanosoma'] = 2;
                    }
                } catch (Exception $e) {
                    // Em caso de erro, marcar como não diagnosticado
                    $coletaData['pcr_babesia'] = 2;
                    $coletaData['pcr_bigemina'] = 2;
                    $coletaData['pcr_anaplasma'] = 2;
                    $coletaData['pcr_trypanosoma'] = 2;
                }
                
                // Buscar LAMP
                try {
                    $stmtLamp = $pdo->prepare("SELECT * FROM resultados_lamp WHERE coleta_id = ?");
                    $stmtLamp->execute([$coleta['coleta_id']]);
                    $lamp = $stmtLamp->fetch();
                    if ($lamp) {
                        // 1 = Positivo, 0 = Negativo, 2 = Inconclusivo/Não diagnosticado
                        $coletaData['lamp_18s'] = ($lamp['babesia_18s_lamp'] === 'Positivo') ? 1 : (($lamp['babesia_18s_lamp'] === 'Negativo') ? 0 : 2);
                        $coletaData['lamp_cytb'] = ($lamp['babesia_cytb_lamp'] === 'Positivo') ? 1 : (($lamp['babesia_cytb_lamp'] === 'Negativo') ? 0 : 2);
                        if (array_key_exists('tempo_deteccao_18s_min', $lamp))  $coletaData['tt_18s']  = $lamp['tempo_deteccao_18s_min'];
                        if (array_key_exists('tempo_deteccao_cytb_min', $lamp)) $coletaData['tt_cytb'] = $lamp['tempo_deteccao_cytb_min'];
                    } else {
                        // Se não há resultado LAMP, marcar como não diagnosticado
                        $coletaData['lamp_18s'] = 2;
                        $coletaData['lamp_cytb'] = 2;
                    }
                } catch (Exception $e) {
                    // Em caso de erro, marcar como não diagnosticado
                    $coletaData['lamp_18s'] = 2;
                    $coletaData['lamp_cytb'] = 2;
                }
                
                $animal['coletas'][] = $coletaData;
            }
        } catch (Exception $e) {}
    }
    
    // Buscar todos os locais para o select do modal
    $stmt = $pdo->query("SELECT id, nome, municipio FROM locais WHERE ativo = TRUE ORDER BY nome");
    $locais = $stmt->fetchAll();
    
} catch (Exception $e) {
    $animais = [];
    $todosAnimais = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pesquisa por Animais - Sistema de Vigilância</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; 
            background: url('static/img/segundo plano.jpg') center center fixed;
            background-size: cover;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(245, 245, 245, 0.95);
            z-index: -1;
        }
        
        .page-header {
            background: linear-gradient(135deg, #2f855a 0%, #38a169 100%);
            border-radius: 12px;
            padding: 30px 40px;
            margin: 20px auto;
            max-width: 1400px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 4px solid #276749;
        }
        
        .page-header-content {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .page-header-icon {
            font-size: 3em;
            color: white;
            opacity: 0.9;
        }
        
        .page-header-text {
            flex: 1;
        }
        
        .page-header h1 {
            color: white;
            font-size: 2em;
            margin-bottom: 8px;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .page-header p {
            color: rgba(255, 255, 255, 0.95);
            font-size: 1.1em;
            line-height: 1.5;
        }
        
        .page-header-buttons {
            display: flex;
            gap: 12px;
        }
        
        .page-header-btn {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 12px 20px;
            background: rgba(255, 255, 255, 0.95);
            color: #2f855a;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 600;
            font-size: 0.95em;
            transition: all 0.3s ease;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .page-header-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        
        .page-header-btn-icon {
            font-size: 1.2em;
        }
        .container { max-width: 1400px; margin: 14px auto; padding: 0 16px; }
        .search-box { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 14px; }
        .form-group { margin-bottom: 12px; }
        label { display: block; font-weight: 600; margin-bottom: 4px; color: #4a5568; font-size: 0.9em; }
        input, select { width: 100%; padding: 8px; border: 2px solid #e2e8f0; border-radius: 5px; font-size: 13px; }
        .btn { padding: 8px 16px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin: 4px; font-size: 0.9em; }
        .btn-primary { background: #2f855a; color: white; }
        .btn-secondary { background: #718096; color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        .result-section { background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 14px; display: none; }
        .info-card { background: #f7fafc; padding: 16px; border-radius: 6px; margin-bottom: 14px; }
        .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 12px; }
        .info-item { padding: 8px; background: white; border-radius: 4px; }
        .info-item label { font-size: 11px; color: #718096; margin-bottom: 4px; }
        .info-item .value { font-size: 16px; font-weight: 600; color: #2d3748; }
        .chart-container { background: white; padding: 16px; border-radius: 6px; margin-bottom: 14px; height: 320px; }
        table { width: 100%; border-collapse: collapse; margin-top: 12px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid #e2e8f0; font-size: 0.9em; }
        th { background: #f7fafc; font-weight: 600; color: #2d3748; }
        tbody tr:nth-child(even) { background: #f7fafc; }
        tbody tr:hover { background: #e6fffa; transition: background 0.2s; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .status-positive { background: #fed7d7; color: #c53030; }
        .status-negative { background: #c6f6d5; color: #276749; }
        .loading { text-align: center; padding: 40px; color: #718096; }
        .action-btn { padding: 6px 10px; margin: 0 2px; border: none; border-radius: 4px; cursor: pointer; font-size: 14px; transition: all 0.2s; }
        .action-btn:hover { transform: scale(1.1); }
        .btn-edit { background: #38a169; color: white; }
        .btn-edit:hover { background: #2f855a; }
        .btn-delete { background: #48bb78; color: white; }
        .btn-delete:hover { background: #38a169; }
        .modal { display: none; position: fixed; z-index: 1000; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        .modal-content { background: white; margin: 5% auto; padding: 30px; border-radius: 8px; max-width: 600px; box-shadow: 0 4px 20px rgba(0,0,0,0.3); }
        .modal-header { margin-bottom: 20px; padding-bottom: 10px; border-bottom: 2px solid #e2e8f0; }
        .modal-header h3 { color: #2d3748; margin: 0; }
        .modal-footer { margin-top: 20px; text-align: right; }
        .close { color: #718096; float: right; font-size: 28px; font-weight: bold; cursor: pointer; }
        .close:hover { color: #2d3748; }
        
        @media (max-width: 768px) {
            .page-header {
                flex-direction: column;
                padding: 20px;
                margin: 10px;
            }
            
            .page-header-content {
                flex-direction: column;
                text-align: center;
            }
            
            .page-header-icon {
                font-size: 2.5em;
            }
            
            .page-header h1 {
                font-size: 1.5em;
            }
            
            .page-header p {
                font-size: 0.95em;
            }
            
            .page-header-buttons {
                width: 100%;
                flex-direction: column;
            }
            
            .page-header-btn {
                width: 100%;
                justify-content: center;
            }
            
            .container {
                padding: 0 10px;
            }
            
            .search-box {
                padding: 15px;
            }
            
            table {
                font-size: 0.85em;
            }
            
            th, td {
                padding: 8px 4px;
            }
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="page-header-content">
            <img src="static/img/logo-ifrj.png" alt="IFRJ" class="page-header-logo" style="height:64px;width:auto;background:#fff;padding:4px 8px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.15);">
            <div class="page-header-icon">🔍</div>
            <div class="page-header-text">
                <h1>Pesquisa de Animais</h1>
                <p>Busca avançada e consulta detalhada de registros de animais com histórico completo</p>
            </div>
        </div>
        <div class="page-header-buttons">
            <a href="index.php" class="page-header-btn">
                <span class="page-header-btn-icon">🏠</span>
                <span>Início</span>
            </a>
            <a href="dashboard.php" class="page-header-btn">
                <span class="page-header-btn-icon">📈</span>
                <span>Dashboard</span>
            </a>
        </div>
    </div>
    
    <div class="container">
        <div style="margin-bottom: 20px;">
            <a href="index.php" class="btn btn-secondary">← Voltar</a>
            <a href="dashboard.php" class="btn btn-secondary">📈 Dashboard</a>
        </div>
        
        <!-- Caixa de Pesquisa de Animal -->
        <div style="background: white; padding: 20px; margin: 0 20px 20px 20px; border-radius: 8px; border: 2px solid #e2e8f0; box-shadow: 0 2px 8px rgba(0,0,0,0.1);">
            <h4 style="margin-bottom: 15px; color: #2d3748; font-size: 16px; font-weight: 600;">🔍 Pesquisar Animal Específico</h4>
            <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                <div style="flex: 1; min-width: 300px;">
                    <label for="codigo_animal" style="display: block; margin-bottom: 6px; font-size: 14px; color: #4a5568; font-weight: 500;">Código de Identificação do Animal:</label>
                    <select id="codigo_animal" style="width: 100%; padding: 10px; border: 2px solid #cbd5e0; border-radius: 6px; font-size: 14px; background: white; cursor: pointer;">
                        <option value="">Selecione um animal...</option>
                        <?php foreach ($animais as $animal): ?>
                        <option value="<?php echo htmlspecialchars($animal['identificacao']); ?>">
                            <?php echo htmlspecialchars($animal['identificacao']); ?>
                            <?php if (!empty($animal['local_nome'])): ?>
                                - <?php echo htmlspecialchars($animal['local_nome']); ?>
                            <?php endif; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div style="display: flex; gap: 10px;">
                    <button onclick="pesquisarAnimalNaTabela()" style="padding: 10px 20px; font-size: 14px; background: #4299e1; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        🔍 Mostrar Dados
                    </button>
                    <button onclick="limparPesquisaTabela()" style="padding: 10px 20px; font-size: 14px; background: #718096; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                        ↺ Mostrar Todos
                    </button>
                </div>
            </div>
            <div id="resultado-pesquisa" style="margin-top: 10px; font-size: 13px; color: #718096;"></div>
        </div>
        
        <!-- SEÇÃO DE RESULTADOS DA BUSCA -->
        <div id="result-section" class="result-section">
            <div id="loading" class="loading">
                ⏳
                <p>Carregando dados...</p>
            </div>
            
            <div id="animal-info" style="display: none;">
                <h2 style="color: #2d3748; margin-bottom: 20px;">
                    🐄 Informações do Animal
                </h2>
                
                <div class="info-card">
                    <div class="info-grid" id="info-grid"></div>
                </div>
                
                <h2 style="color: #2d3748; margin: 30px 0 20px 0;">
                    📋 Evolução Temporal das Coletas e Diagnósticos
                </h2>
                
                <div style="overflow-x: auto;">
                    <table id="tabela-historico">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th>Estação</th>
                                <th>PCR Babesia</th>
                                <th>PCR Anaplasma</th>
                                <th>PCR Trypanosoma</th>
                                <th>LAMP 18S</th>
                                <th>LAMP CytB</th>
                                <th>Observações</th>
                            </tr>
                        </thead>
                        <tbody id="tbody-historico"></tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- TABELA DE TODOS OS ANIMAIS REGISTRADOS -->
        <div class="search-box">
            <h2 style="color: #2d3748; margin-bottom: 15px;">📋 Animais Registrados</h2>
            
            <!-- Botões de Ação -->
            <div style="display: flex; gap: 8px; margin-bottom: 15px; flex-wrap: wrap;">
                <button onclick="atualizarPagina()" style="padding: 10px 20px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; min-width: 180px;">
                    🔄 Atualizar
                </button>
                <button onclick="editarSelecionados()" id="btn-editar-sel" style="padding: 10px 20px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                    ✏️ Editar Selecionados
                </button>
                <button id="btn-salvar-edicoes" onclick="salvarEdicoes()" style="padding: 10px 20px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                    💾 Salvar Alterações
                </button>
                <button id="btn-cancelar-edicoes" onclick="cancelarEdicao()" style="padding: 10px 20px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                    ❌ Cancelar
                </button>
                <button onclick="excluirSelecionados()" id="btn-excluir-sel" style="padding: 10px 20px; background: #e53e3e; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                    🗑️ Excluir Selecionados
                </button>
            </div>
            
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th style="width: 40px; text-align: center;">
                                <input type="checkbox" id="select-all" onchange="toggleSelectAll(this)" style="width: 18px; height: 18px; cursor: pointer;">
                            </th>
                            <th>Identificação</th>
                            <th>Local</th>
                            <th>Sexo</th>
                            <th>Raça</th>
                            <th>Data de Nascimento</th>
                            <th>Idade</th>
                            <th style="text-align: center;">PCR B. bovis</th>
                            <th style="text-align: center;">PCR B. bigemina</th>
                            <th style="text-align: center;">PCR Anaplasma</th>
                            <th style="text-align: center;">PCR Trypanosoma</th>
                            <th style="text-align: center;">LAMP 18S</th>
                            <th style="text-align: center;">LAMP CytB</th>
                            <th>Observações</th>
                            <th style="text-align: center; width: 100px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($todosAnimais)): ?>
                        <tr>
                            <td colspan="15" style="text-align: center; color: #718096; padding: 20px;">
                                Nenhum animal registrado
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($todosAnimais as $animal): ?>
                        <tr>
                            <td style="text-align: center;">
                                <input type="checkbox" class="checkbox-animal" value="<?php echo $animal['id']; ?>" onchange="atualizarBotoesSelecionados()" style="width: 18px; height: 18px; cursor: pointer;">
                            </td>
                            <td style="font-weight: 600; color: #2f855a;">
                                <?php echo htmlspecialchars($animal['identificacao']); ?>
                            </td>
                            <td><?php echo htmlspecialchars($animal['local_nome'] ?? 'N/A'); ?></td>
                            <td><?php echo htmlspecialchars($animal['sexo']); ?></td>
                            <td><?php echo htmlspecialchars($animal['raca'] ?? 'N/A'); ?></td>
                            <td>
                                <?php 
                                if ($animal['data_nascimento']) {
                                    $data = new DateTime($animal['data_nascimento']);
                                    echo $data->format('d/m/Y');
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <td>
                                <?php 
                                if ($animal['data_nascimento']) {
                                    $dataNasc = new DateTime($animal['data_nascimento']);
                                    $hoje = new DateTime();
                                    $idade = $dataNasc->diff($hoje);
                                    
                                    if ($idade->y > 0) {
                                        echo $idade->y . ' ano' . ($idade->y > 1 ? 's' : '');
                                        if ($idade->m > 0) {
                                            echo ' e ' . $idade->m . ' mês' . ($idade->m > 1 ? 'es' : '');
                                        }
                                    } elseif ($idade->m > 0) {
                                        echo $idade->m . ' mês' . ($idade->m > 1 ? 'es' : '');
                                    } else {
                                        echo $idade->d . ' dia' . ($idade->d > 1 ? 's' : '');
                                    }
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </td>
                            <!-- Colunas de resultado (empilhadas por data) com Tt (Tempo de Detecção) -->
                            <?php
                            $colunasResultado = [
                                ['campo' => 'pcr_babesia',     'tt' => 'tt_babesia'],
                                ['campo' => 'pcr_bigemina',    'tt' => 'tt_bigemina'],
                                ['campo' => 'pcr_anaplasma',   'tt' => 'tt_anaplasma'],
                                ['campo' => 'pcr_trypanosoma', 'tt' => 'tt_trypanosoma'],
                                ['campo' => 'lamp_18s',        'tt' => 'tt_18s'],
                                ['campo' => 'lamp_cytb',       'tt' => 'tt_cytb'],
                            ];
                            foreach ($colunasResultado as $col):
                                $campoRes = $col['campo']; $campoTt = $col['tt'];
                            ?>
                            <td style="text-align: center; padding: 4px;">
                                <?php if (count($animal['coletas']) > 0): ?>
                                    <?php foreach ($animal['coletas'] as $coleta): ?>
                                        <?php
                                            $valorRes = $coleta[$campoRes];
                                            $ttVal    = $coleta[$campoTt] ?? null;
                                            $cid      = $coleta['coleta_id'] ?? '';
                                        ?>
                                        <div class="res-cell" data-coleta-id="<?php echo htmlspecialchars((string)$cid); ?>" data-campo="<?php echo $campoRes; ?>" data-campo-tt="<?php echo $campoTt; ?>" data-valor="<?php echo (int)$valorRes; ?>" data-tt="<?php echo htmlspecialchars($ttVal === null ? '' : (string)$ttVal); ?>" style="padding: 2px 0; border-bottom: 1px solid #e2e8f0; font-size: 11px;">
                                            <div style="font-weight: 600; color: #4a5568; margin-bottom: 2px;">
                                                <?php echo date('d/m/y', strtotime($coleta['data_coleta'])); ?>
                                            </div>
                                            <?php
                                                if ($valorRes == 1) {
                                                    echo '<span style="color: #e53e3e; font-weight: bold; font-size: 18px;">+</span>';
                                                } elseif ($valorRes == 0) {
                                                    echo '<span style="color: #48bb78; font-weight: bold; font-size: 18px;">−</span>';
                                                } else {
                                                    echo '<span style="color: #f59e0b; font-weight: bold; font-size: 18px;">?</span>';
                                                }
                                                if ($valorRes == 1 && $ttVal !== null && $ttVal !== '') {
                                                    echo '<div class="res-tt" style="font-size: 10px; color: #c53030; font-weight: 600; margin-top: 1px;">Tt: ' . htmlspecialchars((string)$ttVal) . ' min</div>';
                                                }
                                            ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <span style="color: #cbd5e0;">-</span>
                                <?php endif; ?>
                            </td>
                            <?php endforeach; ?>
                            <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?php echo htmlspecialchars($animal['observacoes'] ?? '-'); ?>
                            </td>
                            <td style="text-align: center;">
                                <button onclick="selecionarEEditar(<?php echo $animal['id']; ?>)" style="padding: 6px 10px; margin: 0 2px; background: #38a169; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px;" title="Editar">
                                    ✏️
                                </button>
                                <button onclick="selecionarEExcluir(<?php echo $animal['id']; ?>)" style="padding: 6px 10px; margin: 0 2px; background: #e53e3e; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px;" title="Excluir">
                                    🗑️
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Controles de Paginação -->
            <div style="background: #f7fafc; padding: 15px 20px; border-top: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; margin-top: 10px; border-radius: 0 0 8px 8px;">
                <!-- Informações e Seletor de Itens por Página -->
                <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <label style="font-size: 14px; color: #4a5568; font-weight: 500;">Exibir:</label>
                        <select id="itens-por-pagina" onchange="mudarItensPorPagina()" style="padding: 8px 12px; border: 2px solid #cbd5e0; border-radius: 6px; font-size: 14px; cursor: pointer; background: white;">
                            <option value="50" selected>50</option>
                            <option value="100">100</option>
                            <option value="150">150</option>
                        </select>
                        <span style="font-size: 14px; color: #4a5568;">animais por página</span>
                    </div>
                    <div id="info-paginacao" style="font-size: 14px; color: #718096;">
                        Exibindo 0 de 0 registros
                    </div>
                </div>
                
                <!-- Botões de Navegação -->
                <div style="display: flex; align-items: center; gap: 10px;">
                    <button id="btn-primeira-pagina" onclick="irParaPrimeiraPagina()" style="padding: 8px 12px; font-size: 14px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer;" disabled>
                        ⏮️ Primeira
                    </button>
                    <button id="btn-pagina-anterior" onclick="irParaPaginaAnterior()" style="padding: 8px 12px; font-size: 14px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer;" disabled>
                        ◀️ Anterior
                    </button>
                    <div style="display: flex; align-items: center; gap: 8px; padding: 0 10px;">
                        <span style="font-size: 14px; color: #4a5568;">Página</span>
                        <input type="number" id="pagina-atual-input" min="1" value="1" onchange="irParaPagina(this.value)" style="width: 60px; padding: 6px 8px; border: 2px solid #cbd5e0; border-radius: 6px; text-align: center; font-size: 14px;">
                        <span style="font-size: 14px; color: #4a5568;">de <span id="total-paginas">1</span></span>
                    </div>
                    <button id="btn-proxima-pagina" onclick="irParaProximaPagina()" style="padding: 8px 12px; font-size: 14px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        Próxima ▶️
                    </button>
                    <button id="btn-ultima-pagina" onclick="irParaUltimaPagina()" style="padding: 8px 12px; font-size: 14px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer;">
                        Última ⏭️
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- MODAL EDITAR ANIMAL -->
    <div id="modalEditar" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="fecharModal('modalEditar')">&times;</span>
                <h3>✏️ Editar Animal</h3>
            </div>
            <form id="formEditar" onsubmit="salvarEdicao(event)">
                <input type="hidden" id="edit_id" name="id">
                
                <div class="form-group">
                    <label for="edit_identificacao">Identificação *</label>
                    <input type="text" id="edit_identificacao" name="identificacao" required>
                </div>
                
                <div class="form-group">
                    <label for="edit_local_id">Local *</label>
                    <select id="edit_local_id" name="local_id" required>
                        <option value="">Selecione o local...</option>
                        <?php foreach ($locais as $local): ?>
                        <option value="<?php echo $local['id']; ?>">
                            <?php echo htmlspecialchars($local['nome'] . ' - ' . $local['municipio']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_sexo">Sexo *</label>
                    <select id="edit_sexo" name="sexo" required>
                        <option value="M">Macho</option>
                        <option value="F">Fêmea</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="edit_raca">Raça</label>
                    <input type="text" id="edit_raca" name="raca">
                </div>
                
                <div class="form-group">
                    <label for="edit_data_nascimento">Data de Nascimento</label>
                    <input type="date" id="edit_data_nascimento" name="data_nascimento">
                </div>
                
                <div class="form-group">
                    <label for="edit_observacoes">Observações</label>
                    <textarea id="edit_observacoes" name="observacoes" rows="3"></textarea>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="fecharModal('modalEditar')">
                        ❌ Cancelar Modificações
                    </button>
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Modificações
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- MODAL EXCLUIR ANIMAL -->
    <div id="modalExcluir" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <span class="close" onclick="fecharModal('modalExcluir')">&times;</span>
                <h3>🗑️ Excluir Animal</h3>
            </div>
            <div style="padding: 20px 0;">
                <p style="font-size: 16px; color: #2d3748; margin-bottom: 10px;">
                    Tem certeza que deseja excluir o animal <strong id="excluir_nome"></strong>?
                </p>
                <p style="color: #e53e3e; font-size: 14px;">
                    ⚠️ Esta ação não pode ser desfeita e irá remover todas as coletas e resultados associados!
                </p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="fecharModal('modalExcluir')">
                    ❌ Cancelar
                </button>
                <button type="button" class="btn btn-delete" onclick="confirmarExclusao()">
                    🗑️ Confirmar Exclusão
                </button>
            </div>
        </div>
    </div>
    
    <script>
        let graficoTemporal = null;
        let animalParaExcluir = null;
        
        // ============================================
        // FUNÇÕES DOS MODAIS
        // ============================================
        
        function abrirModal(modalId) {
            document.getElementById(modalId).style.display = 'block';
        }
        
        function fecharModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }
        
        // Fechar modal ao clicar fora dele
        window.onclick = function(event) {
            if (event.target.classList.contains('modal')) {
                event.target.style.display = 'none';
            }
        }
        
        // ============================================
        // EDITAR ANIMAL
        // ============================================
        
        function editarAnimal(animal) {
            document.getElementById('edit_id').value = animal.id;
            document.getElementById('edit_identificacao').value = animal.identificacao;
            document.getElementById('edit_local_id').value = animal.local_id || '';
            document.getElementById('edit_sexo').value = animal.sexo;
            document.getElementById('edit_raca').value = animal.raca || '';
            document.getElementById('edit_data_nascimento').value = animal.data_nascimento || '';
            document.getElementById('edit_observacoes').value = animal.observacoes || '';
            
            abrirModal('modalEditar');
        }
        
        async function salvarEdicao(event) {
            event.preventDefault();
            
            const formData = new FormData(event.target);
            const dados = Object.fromEntries(formData.entries());
            
            const btn = event.target.querySelector('button[type="submit"]');
            const textoOriginal = btn.innerHTML;
            btn.innerHTML = '⏳ Salvando...';
            btn.disabled = true;
            
            try {
                const response = await fetch('processa_formulario.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        tipo: 'editar_animal',
                        ...dados
                    })
                });
                
                const result = await response.json();
                
                if (result.sucesso) {
                    alert('✅ Animal atualizado com sucesso!');
                    fecharModal('modalEditar');
                    location.reload();
                } else {
                    alert('❌ Erro ao atualizar: ' + (result.erro || 'Erro desconhecido'));
                }
            } catch (error) {
                console.error('Erro:', error);
                alert('❌ Erro ao salvar modificações');
            } finally {
                btn.innerHTML = textoOriginal;
                btn.disabled = false;
            }
        }
        
        // ============================================
        // EXCLUIR ANIMAL
        // ============================================
        
        function excluirAnimal(id, nome) {
            animalParaExcluir = id;
            document.getElementById('excluir_nome').textContent = nome;
            abrirModal('modalExcluir');
        }
        
        async function confirmarExclusao() {
            if (!animalParaExcluir) return;
            
            const btn = event.target;
            const textoOriginal = btn.innerHTML;
            btn.innerHTML = '⏳ Excluindo...';
            btn.disabled = true;
            
            try {
                const response = await fetch('processa_formulario.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        tipo: 'excluir_animal',
                        id: animalParaExcluir
                    })
                });
                
                const result = await response.json();
                
                if (result.sucesso) {
                    alert('✅ Animal excluído com sucesso!');
                    fecharModal('modalExcluir');
                    location.reload();
                } else {
                    alert('❌ Erro ao excluir: ' + (result.erro || 'Erro desconhecido'));
                }
            } catch (error) {
                console.error('Erro:', error);
                alert('❌ Erro ao excluir animal');
            } finally {
                btn.innerHTML = textoOriginal;
                btn.disabled = false;
            }
        }
        
        // ============================================
        // BUSCAR ANIMAL
        // ============================================
        
        async function buscarAnimal() {
            const codigo = document.getElementById('codigo_animal').value;
            
            if (!codigo) {
                alert('⚠️ Selecione um animal');
                return;
            }
            
            const resultSection = document.getElementById('result-section');
            const loading = document.getElementById('loading');
            const animalInfo = document.getElementById('animal-info');
            
            // Mostrar loading
            if (resultSection) resultSection.style.display = 'block';
            if (loading) loading.style.display = 'block';
            if (animalInfo) animalInfo.style.display = 'none';
            
            try {
                console.log('🔍 Buscando animal:', codigo);
                
                // Usar mesma API do dashboard
                const response = await fetch(`api_animal.php?codigo=${encodeURIComponent(codigo)}`);
                
                if (!response.ok) {
                    throw new Error(`Erro HTTP: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('✅ Dados recebidos:', data);
                
                // Verificar erros
                if (data.erro) {
                    alert('❌ Erro: ' + data.erro);
                    if (loading) loading.style.display = 'none';
                    return;
                }
                
                if (!data.animal) {
                    alert('❌ Animal não encontrado');
                    if (loading) loading.style.display = 'none';
                    return;
                }
                
                // Exibir informações básicas
                exibirInformacoes(data.animal);
                
                // Exibir histórico se disponível
                if (data.historico && data.historico.length > 0) {
                    console.log(`📊 ${data.historico.length} coleta(s) encontrada(s)`);
                    
                    // Exibir tabela de histórico
                    try {
                        exibirTabelaHistorico(data.historico);
                    } catch (error) {
                        console.warn('⚠️ Erro ao exibir tabela:', error);
                    }
                } else {
                    console.log('ℹ️ Nenhum histórico de coletas para este animal');
                    
                    const tbody = document.getElementById('tbody-historico');
                    if (tbody) {
                        tbody.innerHTML = '<tr><td colspan="8" style="text-align: center; padding: 20px; color: #718096;">Nenhum histórico disponível</td></tr>';
                    }
                }
                
                // Ocultar loading e mostrar resultados
                if (loading) loading.style.display = 'none';
                if (animalInfo) animalInfo.style.display = 'block';
                
                console.log('✅ Busca concluída com sucesso');
                
            } catch (error) {
                console.error('❌ Erro ao buscar animal:', error);
                alert('❌ Erro ao buscar dados do animal: ' + error.message);
                if (loading) loading.style.display = 'none';
            }
        }
        
        function limparPesquisa() {
            // Limpar seleção
            document.getElementById('codigo_animal').value = '';
            document.getElementById('resultado-pesquisa').innerHTML = '';
            
            // Ocultar seção de resultados
            const resultSection = document.getElementById('result-section');
            const animalInfo = document.getElementById('animal-info');
            if (resultSection) resultSection.style.display = 'none';
            if (animalInfo) animalInfo.style.display = 'none';
            
            console.log('🔄 Pesquisa limpa');
        }
        
        function selecionarEEditar(id) {
            // Desmarcar todos
            document.querySelectorAll('.checkbox-animal').forEach(cb => cb.checked = false);
            // Marcar apenas o selecionado
            const checkbox = document.querySelector(`.checkbox-animal[value="${id}"]`);
            if (checkbox) {
                checkbox.checked = true;
                atualizarBotoesSelecionados();
                editarSelecionados();
            }
        }
        
        function selecionarEExcluir(id) {
            // Desmarcar todos
            document.querySelectorAll('.checkbox-animal').forEach(cb => cb.checked = false);
            // Marcar apenas o selecionado
            const checkbox = document.querySelector(`.checkbox-animal[value="${id}"]`);
            if (checkbox) {
                checkbox.checked = true;
                atualizarBotoesSelecionados();
                excluirSelecionados();
            }
        }
        
        function exibirInformacoes(animal) {
            const grid = document.getElementById('info-grid');
            grid.innerHTML = `
                <div class="info-item">
                    <label>Código</label>
                    <div class="value">${animal.codigo_animal}</div>
                </div>
                <div class="info-item">
                    <label>Sexo</label>
                    <div class="value">${animal.sexo}</div>
                </div>
                <div class="info-item">
                    <label>Raça</label>
                    <div class="value">${animal.raca || 'N/A'}</div>
                </div>
                <div class="info-item">
                    <label>Data de Nascimento</label>
                    <div class="value">${animal.data_nascimento ? formatarData(animal.data_nascimento) : 'N/A'}</div>
                </div>
                <div class="info-item">
                    <label>Local</label>
                    <div class="value">${animal.local_nome || 'N/A'}</div>
                </div>
                <div class="info-item">
                    <label>Total de Coletas</label>
                    <div class="value">${animal.total_coletas}</div>
                </div>
            `;
        }
        
        function exibirTabelaHistorico(historico) {
            const tbody = document.getElementById('tbody-historico');
            if (!tbody) {
                console.warn('Elemento tbody-historico não encontrado');
                return;
            }
            
            tbody.innerHTML = '';
            
            if (!historico || historico.length === 0) {
                tbody.innerHTML = '<tr><td colspan="6" style="text-align: center; padding: 20px; color: #718096;">Nenhum histórico disponível</td></tr>';
                return;
            }
            
            historico.forEach(h => {
                const tr = document.createElement('tr');
                tr.innerHTML = `
                    <td>${formatarData(h.data_coleta)}</td>
                    <td>${h.estacao || 'N/A'}</td>
                    <td><span class="status-badge ${h.pcr_babesia ? 'status-positive' : 'status-negative'}">${h.pcr_babesia ? 'Positivo' : 'Negativo'}</span></td>
                    <td><span class="status-badge ${h.pcr_anaplasma ? 'status-positive' : 'status-negative'}">${h.pcr_anaplasma ? 'Positivo' : 'Negativo'}</span></td>
                    <td><span class="status-badge ${h.pcr_trypanosoma ? 'status-positive' : 'status-negative'}">${h.pcr_trypanosoma ? 'Positivo' : 'Negativo'}</span></td>
                    <td><span class="status-badge ${h.lamp_babesia_18s ? 'status-positive' : 'status-negative'}">${h.lamp_babesia_18s ? 'Positivo' : 'Negativo'}</span></td>
                    <td><span class="status-badge ${h.lamp_babesia_cytb ? 'status-positive' : 'status-negative'}">${h.lamp_babesia_cytb ? 'Positivo' : 'Negativo'}</span></td>
                    <td>${h.observacoes || '-'}</td>
                `;
                tbody.appendChild(tr);
            });
        }
        
        function formatarData(dataStr) {
            const data = new Date(dataStr + 'T00:00:00');
            return data.toLocaleDateString('pt-BR');
        }
        
        // ============================================
        // SISTEMA DE PAGINAÇÃO
        // ============================================
        
        let todosAnimaisData = <?php echo json_encode($todosAnimais); ?>;
        let dadosOriginaisTabela = [...todosAnimaisData]; // Cópia dos dados originais
        let paginaAtual = 1;
        let itensPorPagina = 50;
        let totalRegistros = todosAnimaisData.length;
        
        // Inicializar paginação ao carregar
        document.addEventListener('DOMContentLoaded', function() {
            renderizarTabela();
        });
        
        function renderizarTabela() {
            const tbody = document.querySelector('table tbody');
            const inicio = (paginaAtual - 1) * itensPorPagina;
            const fim = Math.min(inicio + itensPorPagina, totalRegistros);
            const dadosPagina = todosAnimaisData.slice(inicio, fim);
            
            // Limpar tbody
            tbody.innerHTML = '';
            
            if (dadosPagina.length === 0) {
                tbody.innerHTML = '<tr><td colspan="15" style="text-align: center; padding: 40px; color: #718096;">Nenhum animal encontrado</td></tr>';
                atualizarControlesPaginacao();
                return;
            }
            
            // Renderizar cada animal
            dadosPagina.forEach(animal => {
                const tr = document.createElement('tr');
                tr.innerHTML = gerarLinhaAnimal(animal);
                tbody.appendChild(tr);
            });
            
            atualizarControlesPaginacao();
        }
        
        function gerarLinhaAnimal(animal) {
            let html = `
                <td style="text-align: center;">
                    <input type="checkbox" class="checkbox-animal" value="${animal.id}" onchange="atualizarBotoesSelecionados()" style="width: 18px; height: 18px; cursor: pointer;">
                </td>
                <td style="font-weight: 600; color: #2f855a;">${animal.identificacao}</td>
                <td>${animal.local_nome || 'N/A'}</td>
                <td>${animal.sexo}</td>
                <td>${animal.raca || 'N/A'}</td>
                <td>${animal.data_nascimento ? formatarData(animal.data_nascimento) : 'N/A'}</td>
                <td>${calcularIdade(animal.data_nascimento)}</td>
            `;
            
            // Colunas de resultado (PCR/LAMP) com Tt (Tempo de Detecção). Lactação removida.
            const colunas = [
                { campo: 'pcr_babesia',     tt: 'tt_babesia' },
                { campo: 'pcr_bigemina',    tt: 'tt_bigemina' },
                { campo: 'pcr_anaplasma',   tt: 'tt_anaplasma' },
                { campo: 'pcr_trypanosoma', tt: 'tt_trypanosoma' },
                { campo: 'lamp_18s',        tt: 'tt_18s' },
                { campo: 'lamp_cytb',       tt: 'tt_cytb' }
            ];
            colunas.forEach(col => {
                html += '<td style="text-align: center; padding: 4px;">';
                if (animal.coletas && animal.coletas.length > 0) {
                    animal.coletas.forEach(coleta => {
                        const valor = coleta[col.campo];
                        const ttVal = (coleta[col.tt] === undefined || coleta[col.tt] === null) ? '' : coleta[col.tt];
                        const cid = coleta.coleta_id || '';
                        const ttHtml = (valor == 1 && ttVal !== '') ? `<div class="res-tt" style="font-size:10px;color:#c53030;font-weight:600;margin-top:1px;">Tt: ${ttVal} min</div>` : '';
                        html += `<div class="res-cell" data-coleta-id="${cid}" data-campo="${col.campo}" data-campo-tt="${col.tt}" data-valor="${parseInt(valor)||0}" data-tt="${ttVal}" style="padding: 2px 0; border-bottom: 1px solid #e2e8f0; font-size: 11px;">
                            <div style="font-weight: 600; color: #4a5568; margin-bottom: 2px;">${formatarDataCurta(coleta.data_coleta)}</div>
                            ${formatarResultado(valor, col.campo)}${ttHtml}
                        </div>`;
                    });
                } else {
                    html += '<span style="color: #cbd5e0;">-</span>';
                }
                html += '</td>';
            });
            
            html += `
                <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">${animal.observacoes || '-'}</td>
                <td style="text-align: center;">
                    <button onclick="selecionarEEditar(${animal.id})" style="padding: 6px 10px; margin: 0 2px; background: #38a169; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px;" title="Editar">
                        ✏️
                    </button>
                    <button onclick="selecionarEExcluir(${animal.id})" style="padding: 6px 10px; margin: 0 2px; background: #e53e3e; color: white; border: none; border-radius: 4px; cursor: pointer; font-size: 13px;" title="Excluir">
                        🗑️
                    </button>
                </td>
            `;
            
            return html;
        }
        
        function formatarResultado(valor, tipo) {
            if (tipo === 'em_lactacao') {
                return valor ? '✅' : '❌';
            } else {
                if (valor == 1) {
                    return '<span style="color: #e53e3e; font-weight: bold; font-size: 18px;">+</span>';
                } else if (valor == 0) {
                    return '<span style="color: #48bb78; font-weight: bold; font-size: 18px;">−</span>';
                } else {
                    return '<span style="color: #f59e0b; font-weight: bold; font-size: 18px;">?</span>';
                }
            }
        }
        
        function formatarDataCurta(dataStr) {
            const data = new Date(dataStr + 'T00:00:00');
            return data.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: '2-digit' });
        }
        
        function calcularIdade(dataNasc) {
            if (!dataNasc) return 'N/A';
            const nasc = new Date(dataNasc + 'T00:00:00');
            const hoje = new Date();
            const diff = hoje - nasc;
            const anos = Math.floor(diff / (365.25 * 24 * 60 * 60 * 1000));
            const meses = Math.floor((diff % (365.25 * 24 * 60 * 60 * 1000)) / (30.44 * 24 * 60 * 60 * 1000));
            
            if (anos > 0) {
                return anos + ' ano' + (anos > 1 ? 's' : '') + (meses > 0 ? ' e ' + meses + ' mês' + (meses > 1 ? 'es' : '') : '');
            } else if (meses > 0) {
                return meses + ' mês' + (meses > 1 ? 'es' : '');
            } else {
                const dias = Math.floor(diff / (24 * 60 * 60 * 1000));
                return dias + ' dia' + (dias > 1 ? 's' : '');
            }
        }
        
        function atualizarControlesPaginacao() {
            const totalPaginas = Math.ceil(totalRegistros / itensPorPagina);
            const inicio = (paginaAtual - 1) * itensPorPagina + 1;
            const fim = Math.min(paginaAtual * itensPorPagina, totalRegistros);
            
            // Atualizar informações
            document.getElementById('info-paginacao').textContent = 
                `Exibindo ${inicio} a ${fim} de ${totalRegistros} registros`;
            document.getElementById('total-paginas').textContent = totalPaginas;
            document.getElementById('pagina-atual-input').value = paginaAtual;
            
            // Atualizar botões
            document.getElementById('btn-primeira-pagina').disabled = (paginaAtual === 1);
            document.getElementById('btn-pagina-anterior').disabled = (paginaAtual === 1);
            document.getElementById('btn-proxima-pagina').disabled = (paginaAtual === totalPaginas);
            document.getElementById('btn-ultima-pagina').disabled = (paginaAtual === totalPaginas);
        }
        
        function mudarItensPorPagina() {
            itensPorPagina = parseInt(document.getElementById('itens-por-pagina').value);
            paginaAtual = 1;
            renderizarTabela();
        }
        
        function irParaPrimeiraPagina() {
            paginaAtual = 1;
            renderizarTabela();
        }
        
        function irParaPaginaAnterior() {
            if (paginaAtual > 1) {
                paginaAtual--;
                renderizarTabela();
            }
        }
        
        function irParaProximaPagina() {
            const totalPaginas = Math.ceil(totalRegistros / itensPorPagina);
            if (paginaAtual < totalPaginas) {
                paginaAtual++;
                renderizarTabela();
            }
        }
        
        function irParaUltimaPagina() {
            paginaAtual = Math.ceil(totalRegistros / itensPorPagina);
            renderizarTabela();
        }
        
        function irParaPagina(numeroPagina) {
            const totalPaginas = Math.ceil(totalRegistros / itensPorPagina);
            const pagina = parseInt(numeroPagina);
            
            if (pagina >= 1 && pagina <= totalPaginas) {
                paginaAtual = pagina;
                renderizarTabela();
            } else {
                document.getElementById('pagina-atual-input').value = paginaAtual;
            }
        }
        
        // ============================================
        // PESQUISA NA TABELA DE ANIMAIS
        // ============================================
        
        function pesquisarAnimalNaTabela() {
            const codigoPesquisa = document.getElementById('codigo_animal').value.trim();
            const resultadoDiv = document.getElementById('resultado-pesquisa');
            
            if (!codigoPesquisa) {
                resultadoDiv.innerHTML = '<span style="color: #f59e0b;">⚠️ Digite um código para pesquisar</span>';
                return;
            }
            
            console.log('🔍 Pesquisando animal na tabela:', codigoPesquisa);
            
            // Filtrar animais que contêm o código pesquisado
            const dadosFiltrados = dadosOriginaisTabela.filter(animal => {
                const identificacao = (animal.identificacao || '').toLowerCase();
                const local = (animal.local_nome || '').toLowerCase();
                const pesquisa = codigoPesquisa.toLowerCase();
                
                return identificacao.includes(pesquisa) || local.includes(pesquisa);
            });
            
            if (dadosFiltrados.length === 0) {
                resultadoDiv.innerHTML = '<span style="color: #e53e3e;">❌ Nenhum animal encontrado com esse código</span>';
                return;
            }
            
            // Mover animais encontrados para o topo
            const naoEncontrados = dadosOriginaisTabela.filter(animal => {
                const identificacao = (animal.identificacao || '').toLowerCase();
                const local = (animal.local_nome || '').toLowerCase();
                const pesquisa = codigoPesquisa.toLowerCase();
                
                return !identificacao.includes(pesquisa) && !local.includes(pesquisa);
            });
            
            todosAnimaisData = [...dadosFiltrados, ...naoEncontrados];
            totalRegistros = todosAnimaisData.length;
            
            // Resetar para primeira página
            paginaAtual = 1;
            
            resultadoDiv.innerHTML = '<span style="color: #48bb78;">✅ Encontrado(s) ' + dadosFiltrados.length + ' animal(is) - Movido para o topo e selecionado</span>';
            
            // Renderizar tabela
            renderizarTabela();
            
            // Selecionar automaticamente os animais encontrados
            setTimeout(() => {
                dadosFiltrados.forEach(animal => {
                    const checkbox = document.querySelector(`.checkbox-animal[value="${animal.id}"]`);
                    if (checkbox) {
                        checkbox.checked = true;
                    }
                });
                atualizarBotoesSelecionados();
            }, 100);
            
            console.log(`✅ ${dadosFiltrados.length} animal(is) encontrado(s) e movido(s) para o topo`);
        }
        
        function limparPesquisaTabela() {
            // Restaurar dados originais
            todosAnimaisData = [...dadosOriginaisTabela];
            totalRegistros = todosAnimaisData.length;
            paginaAtual = 1;
            
            document.getElementById('codigo_animal').value = '';
            document.getElementById('resultado-pesquisa').innerHTML = '';
            
            // Desmarcar todos os checkboxes
            document.querySelectorAll('.checkbox-animal').forEach(cb => cb.checked = false);
            document.getElementById('select-all').checked = false;
            atualizarBotoesSelecionados();
            
            // Renderizar tabela
            renderizarTabela();
            
            console.log('🔄 Pesquisa limpa, dados restaurados');
        }
        
        // ============================================
        // FUNÇÕES DE SELEÇÃO EM MASSA
        // ============================================
        
        function toggleSelectAll(checkbox) {
            const checkboxes = document.querySelectorAll('.checkbox-animal');
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
            });
            atualizarBotoesSelecionados();
        }
        
        function atualizarBotoesSelecionados() {
            const selecionados = document.querySelectorAll('.checkbox-animal:checked');
            const btnEditar = document.getElementById('btn-editar-sel');
            const btnExcluir = document.getElementById('btn-excluir-sel');
            
            // Mostrar/ocultar botões baseado na seleção
            if (selecionados.length > 0) {
                btnEditar.style.display = 'inline-block';
                btnExcluir.style.display = 'inline-block';
            } else {
                btnEditar.style.display = 'none';
                btnExcluir.style.display = 'none';
            }
            
            // Atualizar checkbox "selecionar todos"
            const totalCheckboxes = document.querySelectorAll('.checkbox-animal').length;
            const selectAll = document.getElementById('select-all');
            selectAll.checked = (selecionados.length === totalCheckboxes && totalCheckboxes > 0);
        }
        
        function atualizarPagina() {
            location.reload();
        }
        
        let modoEdicao = false;
        
        function editarSelecionados() {
            const selecionados = document.querySelectorAll('.checkbox-animal:checked');
            
            if (selecionados.length === 0) {
                alert('⚠️ Selecione pelo menos um animal para editar');
                return;
            }
            
            modoEdicao = true;
            
            selecionados.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const animalId = row.querySelector('.checkbox-animal').value;
                
                // Destacar linha em modo de edição
                row.style.backgroundColor = '#FFF5E6';
                row.style.border = '2px solid #f59e0b';
                
                // Tornar células editáveis
                const cells = row.querySelectorAll('td');
                
                // Identificação (índice 1)
                const identificacaoAtual = cells[1].textContent.trim();
                cells[1].innerHTML = `
                    <input type="text" class="input-edicao-animal" data-animal-id="${animalId}" data-campo="identificacao" value="${identificacaoAtual}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px; font-weight: 600; color: #2f855a;">
                `;
                
                // Local (índice 2) - manter como texto por enquanto
                const localAtual = cells[2].textContent.trim();
                cells[2].innerHTML = `
                    <input type="text" class="input-edicao-animal" data-animal-id="${animalId}" data-campo="local_nome" value="${localAtual === 'N/A' ? '' : localAtual}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;" readonly title="Edição de local não disponível nesta versão">
                `;
                
                // Sexo (índice 3)
                const sexoAtual = cells[3].textContent.trim();
                cells[3].innerHTML = `
                    <select class="input-edicao-animal" data-animal-id="${animalId}" data-campo="sexo" style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;">
                        <option value="Macho" ${sexoAtual === 'Macho' ? 'selected' : ''}>Macho</option>
                        <option value="Fêmea" ${sexoAtual === 'Fêmea' ? 'selected' : ''}>Fêmea</option>
                    </select>
                `;
                
                // Raça (índice 4)
                const racaAtual = cells[4].textContent.trim();
                cells[4].innerHTML = `
                    <input type="text" class="input-edicao-animal" data-animal-id="${animalId}" data-campo="raca" value="${racaAtual === 'N/A' ? '' : racaAtual}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;">
                `;
                
                // Data de Nascimento (índice 5)
                const dataNascTexto = cells[5].textContent.trim();
                let dataNascValue = '';
                if (dataNascTexto !== 'N/A') {
                    // Converter de dd/mm/yyyy para yyyy-mm-dd
                    const partes = dataNascTexto.split('/');
                    if (partes.length === 3) {
                        dataNascValue = `${partes[2]}-${partes[1]}-${partes[0]}`;
                    }
                }
                cells[5].innerHTML = `
                    <input type="date" class="input-edicao-animal" data-animal-id="${animalId}" data-campo="data_nascimento" value="${dataNascValue}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;">
                `;
                
                // Editar coletas empilhadas (índices 7-12: resultados PCR/LAMP)
                // Cada .res-cell carrega data-coleta-id / data-campo / data-campo-tt / data-valor / data-tt
                for (let i = 7; i <= 12; i++) {
                    const cell = cells[i];
                    const resCells = cell.querySelectorAll('.res-cell');

                    if (resCells.length > 0) {
                        const novosSelects = Array.from(resCells).map(div => {
                            const cid     = div.getAttribute('data-coleta-id') || '';
                            const campo   = div.getAttribute('data-campo') || '';
                            const campoTt = div.getAttribute('data-campo-tt') || '';
                            const valor   = div.getAttribute('data-valor');
                            const tt      = div.getAttribute('data-tt') || '';
                            const dataDiv = div.querySelector('div');
                            const data    = dataDiv ? dataDiv.textContent.trim() : '';
                            const isPos = (valor === '1');
                            const isNeg = (valor === '0');
                            return `
                                <div style="padding: 4px 0; border-bottom: 1px solid #e2e8f0;">
                                    <div style="font-weight: 600; color: #4a5568; font-size: 11px; margin-bottom: 2px;">${data}</div>
                                    <select class="input-edicao-coleta" data-coleta-id="${cid}" data-animal-id="${animalId}" data-campo="${campo}" data-orig="${valor}"
                                            style="width: 100%; padding: 3px; border: 1px solid #f59e0b; border-radius: 3px; font-size: 12px;">
                                        <option value="1" ${isPos ? 'selected' : ''}>+ Positivo</option>
                                        <option value="0" ${isNeg ? 'selected' : ''}>− Negativo</option>
                                        <option value="2" ${!isPos && !isNeg ? 'selected' : ''}>? Não diag.</option>
                                    </select>
                                    <input type="number" min="0" max="240" step="1"
                                           class="input-edicao-coleta-tt" data-coleta-id="${cid}" data-campo="${campoTt}" data-orig="${tt}"
                                           value="${tt}" placeholder="Tt (min)"
                                           title="Tempo de Detecção (min) — preencher se positivo"
                                           style="width: 100%; padding: 3px; margin-top: 3px; border: 1px solid #cbd5e0; border-radius: 3px; font-size: 11px;">
                                </div>
                            `;
                        }).join('');

                        cell.innerHTML = novosSelects;
                    }
                }

                // Observações (índice 13)
                const observacoesAtual = cells[13].textContent.trim();
                cells[13].innerHTML = `
                    <textarea class="input-edicao-animal" data-animal-id="${animalId}" data-campo="observacoes"
                              style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px; min-height: 60px; resize: vertical;">${observacoesAtual === '-' ? '' : observacoesAtual}</textarea>
                `;
            });
            
            // Alternar botões
            document.getElementById('btn-editar-sel').style.display = 'none';
            document.getElementById('btn-excluir-sel').style.display = 'none';
            document.getElementById('btn-salvar-edicoes').style.display = 'inline-block';
            document.getElementById('btn-cancelar-edicoes').style.display = 'inline-block';
            
            console.log(`✏️ Modo de edição ativado para ${selecionados.length} animal(is)`);
        }
        
        function cancelarEdicao() {
            modoEdicao = false;
            location.reload();
        }
        
        async function salvarEdicoes() {
            const inputsAnimal = document.querySelectorAll('.input-edicao-animal');
            const inputsColeta = document.querySelectorAll('.input-edicao-coleta');
            
            if (inputsAnimal.length === 0 && inputsColeta.length === 0) {
                alert('⚠️ Nenhuma edição para salvar');
                return;
            }
            
            let totalSalvos = 0;
            let erros = [];
            
            // Agrupar por animal_id
            const animaisPorId = {};
            inputsAnimal.forEach(input => {
                const animalId = input.dataset.animalId;
                if (!animaisPorId[animalId]) {
                    animaisPorId[animalId] = { id: animalId };
                }
                const campo = input.dataset.campo;
                animaisPorId[animalId][campo] = input.value;
            });
            
            // Salvar dados dos animais
            for (const animalId in animaisPorId) {
                try {
                    const resp = await fetch('api_dashboard.php?action=atualizarAnimal', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(animaisPorId[animalId])
                    });
                    const result = await resp.json();

                    if (result.sucesso) {
                        totalSalvos++;
                    } else {
                        erros.push(`Animal ${animalId}: ${result.erro || 'Erro desconhecido'}`);
                    }
                } catch (error) {
                    erros.push(`Animal ${animalId}: ${error.message}`);
                }
            }

            // Agrupar edições por coleta (resultado PCR/LAMP + Tempo de Detecção)
            // IMPORTANTE: só envia coletas REALMENTE alteradas, para não criar linhas
            // PCR/LAMP "Inconclusivo" em coletas que nunca foram diagnosticadas.
            const coletasPorId = {};
            const coletasAlteradas = {};
            inputsColeta.forEach(sel => {
                const cid = sel.dataset.coletaId;
                if (!cid) return;
                if (!coletasPorId[cid]) coletasPorId[cid] = { coleta_id: cid };
                coletasPorId[cid][sel.dataset.campo] = sel.value;
                if (String(sel.value) !== String(sel.dataset.orig)) coletasAlteradas[cid] = true;
            });
            document.querySelectorAll('.input-edicao-coleta-tt').forEach(inp => {
                const cid = inp.dataset.coletaId;
                if (!cid) return;
                if (!coletasPorId[cid]) coletasPorId[cid] = { coleta_id: cid };
                // envia string vazia quando não preenchido (a API converte para NULL)
                coletasPorId[cid][inp.dataset.campo] = inp.value;
                if (String(inp.value) !== String(inp.dataset.orig)) coletasAlteradas[cid] = true;
            });

            // Salvar apenas as coletas alteradas
            for (const cid in coletasPorId) {
                if (!coletasAlteradas[cid]) continue;
                try {
                    const resp = await fetch('api_dashboard.php?action=atualizarResultadosColeta', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(coletasPorId[cid])
                    });
                    const result = await resp.json();
                    if (result.sucesso) {
                        totalSalvos++;
                    } else {
                        erros.push(`Coleta ${cid}: ${result.erro || result.mensagem || 'Erro desconhecido'}`);
                    }
                } catch (error) {
                    erros.push(`Coleta ${cid}: ${error.message}`);
                }
            }

            // Mostrar resultado
            if (erros.length === 0) {
                alert(`✅ ${totalSalvos} registro(s) atualizado(s) com sucesso!`);
                location.reload();
            } else {
                alert(`⚠️ Salvos: ${totalSalvos} | Erros: ${erros.length}\n\n${erros.join('\n')}`);
            }
        }
        
        function excluirSelecionados() {
            const selecionados = Array.from(document.querySelectorAll('.checkbox-animal:checked')).map(cb => cb.value);
            
            if (selecionados.length === 0) {
                alert('Selecione pelo menos um animal para excluir');
                return;
            }
            
            if (!confirm(`Tem certeza que deseja excluir ${selecionados.length} animal(is)?\n\nEsta ação não pode ser desfeita!`)) {
                return;
            }
            
            // Excluir cada animal selecionado
            let excluidos = 0;
            let erros = 0;
            
            Promise.all(selecionados.map(id => 
                fetch(`api_dashboard.php?action=excluir&id=${id}`, { method: 'GET' })
                    .then(response => response.json())
                    .then(data => {
                        if (data.sucesso) {
                            excluidos++;
                        } else {
                            erros++;
                        }
                    })
                    .catch(() => erros++)
            )).then(() => {
                if (excluidos > 0) {
                    alert(`✅ ${excluidos} animal(is) excluído(s) com sucesso!${erros > 0 ? `\n⚠️ ${erros} erro(s) encontrado(s)` : ''}`);
                    location.reload();
                } else {
                    alert(`❌ Erro ao excluir animais. Tente novamente.`);
                }
            });
        }
    </script>
</body>
</html>
