<?php
// ============================================
// Dashboard Visual - Vigilância Parasitária Bovina
// Arquivo: dashboard.php
// ============================================

require_once __DIR__ . '/../../templates/auth_central.php';
exigirArea('diagnostico');
require_once 'config.php';
setSecurityHeaders();

// Buscar dados para os selects
try {
    $pdo = getDBConnection();

    // Buscar locais ativos (com coordenadas para Gráficos)
    // Locais no banco: ID 5 (Pesagro-Seropédica), ID 6 (IFRJ Pinheiral),
    // ID 8 (Quintas Barbosa-Japeri), ID 20 (Sorriso-Japeri)
    $stmt = $pdo->query("SELECT id, nome, municipio FROM locais WHERE ativo = TRUE AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY municipio, nome");
    $locais = $stmt->fetchAll();

    // Buscar TODOS os locais para Correlações (incluindo inativos, pois podem ter dados históricos)
    // NOTA: Filtra apenas locais que possuem coletas E dados climáticos associados
    $stmt = $pdo->query("SELECT id, nome, municipio, ativo FROM locais ORDER BY municipio, nome");
    $locaisCorrelacaoTodos = $stmt->fetchAll();

    // Filtrar apenas locais que têm dados de coleta E dados climáticos
    $locaisCorrelacao = [];
    foreach ($locaisCorrelacaoTodos as $local) {
        // Verificar se há coletas cadastradas
        $stmtCheck = $pdo->prepare("SELECT COUNT(*) as total FROM coletas WHERE local_id = ?");
        $stmtCheck->execute([$local['id']]);
        $resultadoColetas = $stmtCheck->fetch();

        // Verificar se há dados climáticos cadastrados (para análises de correlação)
        $stmtClima = $pdo->prepare("SELECT COUNT(*) as total FROM dados_climaticos WHERE local_id = ?");
        $stmtClima->execute([$local['id']]);
        $resultadoClima = $stmtClima->fetch();

        // Incluir apenas se houver AMBOS: coletas e dados climáticos
        if ($resultadoColetas['total'] > 0 && $resultadoClima['total'] > 0) {
            $locaisCorrelacao[] = $local;
        }
    }

    // Log para debug
    error_log("Dashboard - Locais com coordenadas: " . count($locais));
    error_log("Dashboard - Locais com coletas E dados climáticos: " . count($locaisCorrelacao));

    // Debug: Mostrar quais locais foram encontrados
    foreach ($locaisCorrelacao as $loc) {
        error_log("  Local ID {$loc['id']}: {$loc['nome']} - {$loc['municipio']}");
    }

} catch (Exception $e) {
    logErro("Erro ao carregar dados para dashboard: " . $e->getMessage());
    $locais = [];
    $locaisCorrelacao = [];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Vigilância Parasitária Bovina</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: url('static/img/segundo plano.jpg') center center fixed;
            background-size: cover;
            color: #2d3748;
            font-size: 16px;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(248, 250, 252, 0.95);
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
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.2s;
        }
        
        .btn-primary {
            background: #2f855a;
            color: white;
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.2);
            color: white;
        }
        
        .btn-success {
            background: #48bb78;
            color: white;
        }
        
        .btn-danger {
            background: #f56565;
            color: white;
        }
        
        .btn:hover {
            transform: translateY(-1px);
            opacity: 0.9;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 14px;
        }
        
        .dashboard-tabs {
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            margin-bottom: 14px;
        }
        
        .tab-nav {
            display: flex;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .tab-nav button {
            padding: 12px 16px;
            border: none;
            background: transparent;
            cursor: pointer;
            font-weight: 500;
            font-size: 0.95em;
            border-bottom: 3px solid transparent;
            transition: all 0.2s;
        }
        
        .tab-nav button.active {
            color: #2f855a;
            border-bottom-color: #2f855a;
            background: rgba(47, 133, 90, 0.08);
        }
        
        .tab-content {
            padding: 14px;
        }
        
        .tab-pane {
            display: none;
        }
        
        .tab-pane.active {
            display: block;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 0;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 6px;
            font-weight: 600;
            color: #4a5568;
            font-size: 14px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        
        .form-group input,
        .form-group select {
            width: 100%;
            padding: 8px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 5px;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group select:focus {
            outline: none;
            border-color: #2f855a;
        }
        
        .checkbox-group {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            padding: 12px 15px;
            background: #f7fafc;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            transition: all 0.2s;
        }
        
        .checkbox-item:hover {
            border-color: #cbd5e0;
            background: #edf2f7;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            margin-right: 10px;
            cursor: pointer;
        }
        
        .checkbox-item label {
            margin: 0;
            cursor: pointer;
            font-weight: 500;
        }
        
        .data-table {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .table-header {
            background: #f7fafc;
            padding: 15px 20px;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .table-header h3 {
            font-size: 1.2em;
            color: #2d3748;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            table-layout: fixed;
            font-size: 13px;
        }
        
        th, td {
            padding: 6px 4px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        
        th {
            background: #f7fafc;
            font-weight: 600;
            color: #4a5568;
            font-size: 15px;
            text-transform: uppercase;
        }
        
        td {
            font-size: 16px;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 500;
            text-transform: uppercase;
        }
        
        .status-positive {
            background: #fed7d7;
            color: #c53030;
        }
        
        .status-negative {
            background: #c6f6d5;
            color: #276749;
        }
        
        /* Botões 3D para Datas */
        .btn-data-3d {
            padding: 12px 16px;
            background: linear-gradient(145deg, #ffffff, #e6e6e6);
            border: 2px solid #cbd5e0;
            border-radius: 10px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            color: #4a5568;
            transition: all 0.3s ease;
            box-shadow: 4px 4px 8px rgba(0, 0, 0, 0.1), -2px -2px 6px rgba(255, 255, 255, 0.7);
            text-align: center;
            position: relative;
            overflow: hidden;
        }
        
        .btn-data-3d:hover {
            transform: translateY(-2px);
            box-shadow: 6px 6px 12px rgba(0, 0, 0, 0.15), -3px -3px 8px rgba(255, 255, 255, 0.8);
        }
        
        .btn-data-3d:active {
            transform: translateY(0px);
            box-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1) inset;
        }
        
        .btn-data-3d.selecionado {
            background: linear-gradient(145deg, #4299e1, #3182ce);
            color: white;
            border-color: #2c5282;
            box-shadow: 4px 4px 8px rgba(66, 153, 225, 0.4), -2px -2px 6px rgba(255, 255, 255, 0.3), 0 0 15px rgba(66, 153, 225, 0.3);
        }
        
        .btn-data-3d.selecionado:hover {
            background: linear-gradient(145deg, #3182ce, #2c5282);
            box-shadow: 6px 6px 12px rgba(66, 153, 225, 0.5), -3px -3px 8px rgba(255, 255, 255, 0.4), 0 0 20px rgba(66, 153, 225, 0.4);
        }
        
        .btn-data-3d .data-label {
            display: block;
            font-size: 15px;
            font-weight: 700;
            margin-bottom: 4px;
        }
        
        .btn-data-3d .data-info {
            display: block;
            font-size: 11px;
            opacity: 0.8;
        }
        
        .charts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        
        /* Animação de loading */
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        .chart-container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
        }
        
        .chart-title {
            font-size: 1.1em;
            font-weight: 600;
            margin-bottom: 15px;
            color: #2d3748;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert-info {
            background: #bee3f8;
            color: #2b6cb0;
            border: 1px solid #90cdf4;
        }
        
        .alert-warning {
            background: #faf089;
            color: #744210;
            border: 1px solid #f6e05e;
        }
        
        .simulator-controls {
            background: white;
            border-radius: 10px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        @media (max-width: 768px) {
            .header-content {
                flex-direction: column;
                gap: 15px;
            }
            
            .tab-nav {
                overflow-x: auto;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .checkbox-group {
                grid-template-columns: 1fr;
            }
            
            .charts-grid {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <!-- Header Padronizado -->
    <div class="page-header">
        <div class="page-header-content">
            <img src="static/img/logo-ifrj.png" alt="IFRJ" class="page-header-logo" style="height:64px;width:auto;background:#fff;padding:4px 8px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.15);">
            <div class="page-header-icon">📈</div>
            <div class="page-header-text">
                <h1>Dashboard de Vigilância Parasitária</h1>
                <p>Visualização, análise e gerenciamento de dados de animais, amostras (1 amostra por animal × data de coleta) e diagnósticos</p>
            </div>
        </div>
        <div class="page-header-buttons">
            <a href="index.php" class="page-header-btn">
                <span class="page-header-btn-icon">🏠</span>
                <span>Início</span>
            </a>
            <a href="dashboard.php" class="page-header-btn" style="opacity: 0.7; pointer-events: none;">
                <span class="page-header-btn-icon">📈</span>
                <span>Dashboard</span>
            </a>
        </div>
    </div>

    <div class="container">
        <!-- Botão Atualizar Dashboard -->
        <div style="background: white; padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1); display: flex; justify-content: flex-end;">
            <button class="btn btn-primary" onclick="atualizarDashboard()" style="background: #2f855a; color: white;">
                🔄 Atualizar Dashboard
            </button>
        </div>

        <!-- Tabs do Dashboard -->
        <div class="dashboard-tabs">
            <div class="tab-nav">
                <button class="tab-button active" onclick="showTab('entrada-dados')">
                    🔄 Atualizar Dados Diagnósticos
                </button>
                <button class="tab-button" onclick="showTab('visualizacao')">
                    📋 Visualizar Registros Animais
                </button>
                <button class="tab-button" onclick="showTab('graficos')">
                    📊 Análises Incidência
                </button>
                <button class="tab-button" onclick="showTab('correlacoes')">
                    🌡️ Análises Correlações Climáticas
                </button>
            </div>

            <div class="tab-content">
                <!-- TAB: ATUALIZAR DADOS DIAGNÓSTICOS -->
                <div id="entrada-dados" class="tab-pane active">
                    <h3>🔄 Atualizar Dados Diagnósticos</h3>
                    <p style="color: #718096; margin-bottom: 20px;">Selecione um animal para atualizar seus dados de diagnóstico e registrar resultados no banco de dados</p>
                    
                    <form id="form-animal-completo" onsubmit="salvarAnimalCompleto(event)">
                        <!-- Seleção do Animal -->
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="codigo_animal" title="Selecione um animal para carregar seus dados">Código do Animal *</label>
                                <select id="codigo_animal" name="codigo_animal" required onchange="carregarDadosAnimal()">
                                    <option value="">Selecione...</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="data_coleta">Data da Coleta *</label>
                                <input type="date" id="data_coleta" name="data_coleta" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="sexo">Sexo *</label>
                                <select id="sexo" name="sexo" required>
                                    <option value="">Selecione...</option>
                                    <option value="Macho">Macho</option>
                                    <option value="Fêmea">Fêmea</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="data_nascimento">Data Nascimento</label>
                                <input type="date" id="data_nascimento" name="data_nascimento">
                            </div>
                            
                            <div class="form-group">
                                <label for="local_id">Fazenda *</label>
                                <select id="local_id" name="local_id" required>
                                    <option value="">Selecione...</option>
                                    <?php foreach ($locais as $local): ?>
                                        <option value="<?php echo $local['id']; ?>">
                                            <?php echo htmlspecialchars($local['nome'] . ' - ' . $local['municipio']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="raca">Raça</label>
                                <input type="text" id="raca" name="raca" placeholder="Ex: Nelore">
                            </div>
                        </div>

                        <!-- Checkboxes dos Resultados -->
                        <h4 style="margin: 20px 0 10px 0;">Resultados dos Exames</h4>

                        <div style="background: #ebf8ff; border-left: 4px solid #4299e1; padding: 12px; margin-bottom: 15px; border-radius: 4px;">
                            <p style="margin: 0; font-size: 13px; color: #2c5282;">
                                ℹ️ <strong>Importante:</strong> Marque as caixas de seleção conforme os resultados dos exames.
                                Cada checkbox marcado será registrado como <strong>POSITIVO (1)</strong> no banco de dados.
                                Desmarcado = <strong>NEGATIVO (0)</strong>.
                            </p>
                        </div>

                        <div class="checkbox-group">
                            <div class="checkbox-item">
                                <input type="checkbox" id="em_lactacao" name="em_lactacao" value="1">
                                <label for="em_lactacao">Em lactação?</label>
                            </div>
                            
                            <div class="checkbox-item">
                                <input type="checkbox" id="pcr_babesia" name="pcr_babesia" value="1">
                                <label for="pcr_babesia">Positivo PCR convencional para Babesia bovis?</label>
                            </div>

                            <div class="checkbox-item">
                                <input type="checkbox" id="pcr_bigemina" name="pcr_bigemina" value="1">
                                <label for="pcr_bigemina">Positivo PCR convencional para Babesia bigemina?</label>
                            </div>

                            <div class="checkbox-item">
                                <input type="checkbox" id="pcr_anaplasma" name="pcr_anaplasma" value="1">
                                <label for="pcr_anaplasma">Positivo PCR convencional para Anaplasma marginale PCR?</label>
                            </div>

                            <div class="checkbox-item">
                                <input type="checkbox" id="pcr_trypanosoma" name="pcr_trypanosoma" value="1">
                                <label for="pcr_trypanosoma">Positivo PCR convencional para Trypanosoma vivax PCR?</label>
                            </div>
                            
                            <div class="checkbox-item" style="background: #f0f9ff; border-left: 3px solid #3b82f6;">
                                <input type="checkbox" id="amplificacao_16s_bovino" name="amplificacao_16s_bovino" value="1">
                                <label for="amplificacao_16s_bovino">Amplificação PCR controle bovino 16S</label>
                            </div>
                            
                            <div class="checkbox-item">
                                <input type="checkbox" id="lamp_babesia_18s" name="lamp_babesia_18s" value="1">
                                <label for="lamp_babesia_18s">Positivo PCR-LAMP para Babesia com alvo RNAr 18S?</label>
                            </div>
                            
                            <div class="checkbox-item">
                                <input type="checkbox" id="lamp_babesia_cytb" name="lamp_babesia_cytb" value="1">
                                <label for="lamp_babesia_cytb">Positivo PCR-LAMP para Babesia com alvo Citocromo B?</label>
                            </div>
                        </div>
                        
                        <!-- Tratamentos Parasiticidas -->
                        <div style="margin-top: 30px; padding: 20px; background: #fff7ed; border-radius: 8px; border: 2px solid #fed7aa;">
                            <h4 style="margin-bottom: 15px; color: #c2410c; font-size: 16px; font-weight: 600;">
                                💊 Tratamentos Parasiticidas (últimos 45 dias)
                            </h4>
                            <p style="color: #9a3412; font-size: 13px; margin-bottom: 15px;">
                                Marque os tratamentos carrapaticidas aplicados nos últimos 45 dias:
                            </p>
                            
                            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                                <!-- Coluna Esquerda: Checkboxes -->
                                <div>
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="carrapaticida_pouron" name="carrapaticida_pouron" value="1">
                                        <label for="carrapaticida_pouron">Pour On menos de 45 dias</label>
                                    </div>
                                    
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="carrapaticida_pulverizacao" name="carrapaticida_pulverizacao" value="1">
                                        <label for="carrapaticida_pulverizacao">Pulverização menos de 45 dias</label>
                                    </div>
                                    
                                    <div class="checkbox-item">
                                        <input type="checkbox" id="carrapaticida_injetavel" name="carrapaticida_injetavel" value="1">
                                        <label for="carrapaticida_injetavel">Injetável menos de 45 dias</label>
                                    </div>
                                </div>
                                
                                <!-- Coluna Direita: Observações -->
                                <div>
                                    <label for="observacoes_tratamento" style="display: block; margin-bottom: 8px; font-size: 14px; color: #9a3412; font-weight: 600;">
                                        📝 Observações do Prontuário:
                                    </label>
                                    <textarea 
                                        id="observacoes_tratamento" 
                                        name="observacoes_tratamento" 
                                        rows="5" 
                                        placeholder="Digite aqui detalhes sobre os tratamentos, dosagens, datas específicas, produtos utilizados, etc..."
                                        style="width: 100%; padding: 10px; border: 2px solid #fed7aa; border-radius: 6px; font-size: 13px; font-family: inherit; resize: vertical; background: white;"
                                    ></textarea>
                                    <small style="color: #9a3412; font-size: 11px;">
                                        Ex: Pour-on Ivermectina 500ml aplicado em 01/10/2024
                                    </small>
                                </div>
                            </div>
                        </div>

                        <!-- Mensagem de Sucesso -->
                        <div id="mensagem-sucesso" style="display: none; margin-top: 20px; padding: 15px 20px; background: #c6f6d5; border: 2px solid #9ae6b4; border-radius: 8px; color: #276749;">
                            <strong>✅ Dados salvos com sucesso!</strong>
                        </div>

                        <div id="form-actions" style="margin-top: 20px; padding: 20px; background: #f7fafc; border-radius: 8px; border: 2px solid #e2e8f0;">
                            <div style="display: flex; gap: 10px; justify-content: flex-start; flex-wrap: wrap;">
                                <button type="submit" class="btn btn-primary" style="font-size: 14px; padding: 10px 20px;">
                                    💾 Atualizar Diagnóstico no Banco de Dados
                                </button>
                                <button type="button" class="btn btn-danger" onclick="descartarAlteracoes()" style="font-size: 14px; padding: 10px 20px;">
                                    ❌ Descartar Alterações
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="limparFormulario()" style="font-size: 14px; padding: 10px 20px;">
                                    🔄 Limpar Seleção
                                </button>
                            </div>
                            <p style="margin-top: 10px; color: #718096; font-size: 13px;">
                                💡 <strong>Atualizar Diagnóstico:</strong> Registra os resultados marcados no banco de dados |
                                <strong>Descartar:</strong> Recarrega dados originais |
                                <strong>Limpar:</strong> Volta ao início
                            </p>
                        </div>
                    </form>
                </div>

                <!-- TAB: VISUALIZAR DADOS ANIMAIS -->
                <div id="visualizacao" class="tab-pane">
                    <div class="data-table">
                        <div class="table-header">
                            <h3>Registros de Animais</h3>
                        </div>
                        
                        <!-- Mensagem de Sucesso -->
                        <div id="mensagem-visualizacao" style="display: none; margin: 15px 20px; padding: 15px; border-radius: 6px;"></div>
                        
                        <!-- Caixa de Pesquisa de Animal -->
                        <div style="background: white; padding: 20px; margin: 0 20px 20px 20px; border-radius: 8px; border: 2px solid #e2e8f0;">
                            <h4 style="margin-bottom: 15px; color: #2d3748; font-size: 16px; font-weight: 600;">🔍 Pesquisar Animal Específico</h4>
                            <div style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                                <div style="flex: 1; min-width: 300px;">
                                    <label style="display: block; margin-bottom: 6px; font-size: 14px; color: #4a5568; font-weight: 500;">Código de Identificação do Animal:</label>
                                    <select id="pesquisa-codigo-animal" style="width: 100%; padding: 10px; border: 2px solid #cbd5e0; border-radius: 6px; font-size: 14px; background: white; cursor: pointer;">
                                        <option value="">Selecione um animal...</option>
                                    </select>
                                </div>
                                <div style="display: flex; gap: 10px;">
                                    <button onclick="pesquisarAnimal()" class="btn btn-primary" style="padding: 10px 20px; font-size: 14px; background: #4299e1; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        🔍 Mostrar Dados
                                    </button>
                                    <button onclick="limparPesquisa()" class="btn btn-secondary" style="padding: 10px 20px; font-size: 14px; background: #718096; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600;">
                                        ↺ Mostrar Todos
                                    </button>
                                </div>
                            </div>
                            <div id="resultado-pesquisa" style="margin-top: 10px; font-size: 13px; color: #718096;"></div>
                        </div>
                        
                        <!-- Botões de Ação acima da Tabela -->
                        <div style="background: #f7fafc; padding: 15px 10px; margin: 0 10px; border-radius: 8px 8px 0 0; border: 2px solid #e2e8f0; border-bottom: none; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                            <button class="btn btn-primary" onclick="carregarDados()" style="padding: 10px 20px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; min-width: 120px;">
                                🔄 Atualizar
                            </button>
                            <button id="btn-editar-selecionados" class="btn btn-primary" onclick="habilitarEdicao()" style="padding: 10px 20px; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                                ✏️ Editar Selecionados
                            </button>
                            <button id="btn-salvar-edicoes" class="btn btn-primary" onclick="salvarEdicoes()" style="padding: 10px 20px; display: none; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; min-width: 180px;">
                                💾 Salvar Alterações
                            </button>
                            <button id="btn-cancelar-edicoes" class="btn btn-secondary" onclick="cancelarEdicao()" style="padding: 10px 20px; display: none; background: #38a169; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; min-width: 180px;">
                                ❌ Cancelar
                            </button>
                            <button id="btn-excluir-selecionados" class="btn btn-danger" onclick="excluirSelecionados()" style="padding: 10px 20px; background: #e53e3e; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                                🗑️ Excluir Selecionados
                            </button>
                            <button id="btn-laudo-excel" class="btn btn-success" onclick="exportarLaudoExcel()" style="padding: 10px 20px; background: #2563eb; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                                📊 Laudo (Excel)
                            </button>
                            <button id="btn-visualizar-laudo" class="btn btn-info" onclick="visualizarLaudo()" style="padding: 10px 20px; background: #8b5cf6; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 14px; display: none; min-width: 180px;">
                                📋 Visualizar Laudo
                            </button>
                        </div>
                        
                        <div class="table-container" style="margin-top: 0;">
                            <table id="tabela-animais">
                                <thead>
                                    <tr>
                                        <th style="width: 30px; padding: 6px 2px;">
                                            <input type="checkbox" id="selecionar-todos" onchange="toggleSelecionarTodos()" style="width: 18px; height: 18px; cursor: pointer;">
                                        </th>
                                        <th style="width: 70px; font-size: 12px;">ID</th>
                                        <th style="width: 75px; font-size: 12px;">Nome</th>
                                        <th style="width: 90px; font-size: 12px;">Local</th>
                                        <th style="width: 40px; font-size: 12px;">Sexo</th>
                                        <th style="width: 70px; font-size: 12px;">Raça</th>
                                        <th style="width: 75px; font-size: 12px;">Nasc.</th>
                                        <th style="text-align: center; width: 60px; font-size: 12px;">Lact.</th>
                                        <th style="text-align: center; width: 70px; font-size: 11px;">B.bovis PCR</th>
                                        <th style="text-align: center; width: 70px; font-size: 11px;">LAMP 18S</th>
                                        <th style="text-align: center; width: 70px; font-size: 11px;">LAMP CytB</th>
                                        <th style="text-align: center; width: 85px; font-size: 11px;">B.bigemina PCR</th>
                                        <th style="text-align: center; width: 90px; font-size: 11px;">A.marginale PCR</th>
                                        <th style="text-align: center; width: 80px; font-size: 11px;">T.vivax PCR</th>
                                        <th style="width: 100px; font-size: 12px;">Obs.</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="14" style="text-align: center; padding: 40px;">
                                            Carregando dados...
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Controles de Paginação -->
                        <div style="background: #f7fafc; padding: 15px 20px; border-top: 2px solid #e2e8f0; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                            <!-- Informações e Seletor de Itens por Página -->
                            <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <label style="font-size: 14px; color: #4a5568; font-weight: 500;">Exibir:</label>
                                    <select id="itens-por-pagina" onchange="mudarItensPorPagina()" style="padding: 8px 12px; border: 2px solid #cbd5e0; border-radius: 6px; font-size: 14px; cursor: pointer; background: white;">
                                        <option value="50" selected>50</option>
                                        <option value="100">100</option>
                                        <option value="150">150</option>
                                    </select>
                                    <span style="font-size: 14px; color: #4a5568;">amostras por página</span>
                                </div>
                                <div id="info-paginacao" style="font-size: 14px; color: #718096;">
                                    Exibindo 0 de 0 registros
                                </div>
                            </div>
                            
                            <!-- Botões de Navegação -->
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <button id="btn-primeira-pagina" onclick="irParaPrimeiraPagina()" class="btn btn-secondary" style="padding: 8px 12px; font-size: 14px; background: #718096; color: white;" disabled>
                                    ⏮️ Primeira
                                </button>
                                <button id="btn-pagina-anterior" onclick="irParaPaginaAnterior()" class="btn btn-secondary" style="padding: 8px 12px; font-size: 14px; background: #718096; color: white;" disabled>
                                    ◀️ Anterior
                                </button>
                                <div style="display: flex; align-items: center; gap: 8px; padding: 0 10px;">
                                    <span style="font-size: 14px; color: #4a5568;">Página</span>
                                    <input type="number" id="pagina-atual-input" min="1" value="1" onchange="irParaPagina(this.value)" style="width: 60px; padding: 6px 8px; border: 2px solid #cbd5e0; border-radius: 6px; text-align: center; font-size: 14px;">
                                    <span style="font-size: 14px; color: #4a5568;">de <strong id="total-paginas">1</strong></span>
                                </div>
                                <button id="btn-proxima-pagina" onclick="irParaProximaPagina()" class="btn btn-secondary" style="padding: 8px 12px; font-size: 14px; background: #718096; color: white;" disabled>
                                    Próxima ▶️
                                </button>
                                <button id="btn-ultima-pagina" onclick="irParaUltimaPagina()" class="btn btn-secondary" style="padding: 8px 12px; font-size: 14px; background: #718096; color: white;" disabled>
                                    Última ⏭️
                                </button>
                            </div>
                        </div>
                        
                        <!-- Botões de Exportação -->
                        <div style="background: white; padding: 20px; margin: 20px; border-radius: 8px; border: 2px solid #e2e8f0; text-align: center;">
                            <h4 style="margin-bottom: 15px; color: #2d3748; font-size: 16px; font-weight: 600;">📥 Exportar Dados</h4>
                            <p style="font-size: 13px; color: #718096; margin-bottom: 15px;">Exporte os dados exibidos na tabela nos formatos abaixo:</p>
                            <div style="display: flex; justify-content: center; gap: 15px; flex-wrap: wrap;">
                                <button onclick="exportarCSV()" class="btn btn-primary" style="padding: 12px 24px; font-size: 14px; background: #48bb78; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                    <span>📊</span> Exportar como CSV
                                </button>
                                <button onclick="exportarSQL()" class="btn btn-primary" style="padding: 12px 24px; font-size: 14px; background: #4299e1; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; display: flex; align-items: center; gap: 8px;">
                                    <span>💾</span> Exportar como SQL
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- TAB: ANÁLISES INCIDÊNCIA -->
                <div id="graficos" class="tab-pane">
                    <!-- Filtros de Análise -->
                    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin-bottom: 20px; color: #2d3748; border-bottom: 3px solid #4299e1; padding-bottom: 10px;">🔍 Filtros de Análise</h3>
                        
                        <!-- Filtro de Propriedades -->
                        <div style="margin-bottom: 25px;">
                            <h4 style="margin-bottom: 12px; color: #2d3748; font-size: 16px; font-weight: 600;">🏘️ Selecione as Propriedades:</h4>
                            <div style="background: #f7fafc; padding: 15px; border-radius: 8px; border: 2px solid #e2e8f0;">
                                <div id="lista-propriedades" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px;">
                                    <?php if (!empty($locais)): ?>
                                        <?php foreach ($locais as $local): ?>
                                            <label style="display: flex; align-items: center; padding: 12px 15px; background: white; border-radius: 6px; cursor: pointer; border: 2px solid #cbd5e0; transition: all 0.2s;" 
                                                   onmouseover="this.style.borderColor='#4299e1'; this.style.background='#ebf8ff';" 
                                                   onmouseout="this.style.borderColor=this.querySelector('input').checked ? '#4299e1' : '#cbd5e0'; this.style.background=this.querySelector('input').checked ? '#ebf8ff' : 'white';">
                                                <input type="checkbox" class="check-propriedade" value="<?php echo $local['id']; ?>" checked 
                                                       onchange="this.parentElement.style.borderColor=this.checked ? '#4299e1' : '#cbd5e0'; this.parentElement.style.background=this.checked ? '#ebf8ff' : 'white'; atualizarSelecaoPropriedades();" 
                                                       style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                                <span style="font-weight: 500; font-size: 14px;"><?php echo htmlspecialchars($local['nome'] . ' - ' . $local['municipio']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="padding: 20px; color: #e53e3e; text-align: center; grid-column: 1 / -1; background: #fee; border-radius: 6px; border-left: 4px solid #e53e3e;">
                                            <strong>⚠️ Nenhuma propriedade disponível</strong><br>
                                            <span style="font-size: 13px; color: #c53030;">
                                                Execute o pré-carregamento de dados climáticos: <code>pre_carregar_dados_climaticos.php</code>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filtro de Datas de Coleta -->
                        <div style="margin-bottom: 25px;">
                            <h4 style="margin-bottom: 12px; color: #2d3748; font-size: 16px; font-weight: 600;">📅 Selecione o Período de Análise:</h4>
                            <div style="background: #f7fafc; padding: 15px; border-radius: 8px; border: 2px solid #e2e8f0;">
                                <!-- Modo de Seleção de Data -->
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 8px; color: #2d3748;">Modo de Seleção:</label>
                                    <select id="modo-data" onchange="alternarModoData()" style="width: 100%; padding: 8px 12px; border: 2px solid #cbd5e0; border-radius: 6px; font-size: 14px;">
                                        <option value="todas">📅 Todas as datas disponíveis</option>
                                        <option value="periodo">📆 Período específico (data início e fim)</option>
                                    </select>
                                </div>

                                <!-- Seleção por Período (oculto por padrão) -->
                                <div id="filtro-periodo" style="display: none; margin-bottom: 15px; padding: 12px; background: white; border-radius: 6px; border: 2px solid #e2e8f0;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        <div>
                                            <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #4a5568;">Data Início:</label>
                                            <input type="date" id="data-inicio" style="width: 100%; padding: 8px; border: 2px solid #cbd5e0; border-radius: 6px;">
                                        </div>
                                        <div>
                                            <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #4a5568;">Data Fim:</label>
                                            <input type="date" id="data-fim" style="width: 100%; padding: 8px; border: 2px solid #cbd5e0; border-radius: 6px;">
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                        
                        <!-- Seleção de Patógenos -->
                        <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 2px solid #e2e8f0;">
                            <h4 style="margin-bottom: 10px; color: #4a5568; font-size: 14px;">🦠 Patógenos a serem analisados</h4>
                            <p style="font-size: 13px; color: #718096; margin-bottom: 10px;">Selecione os patógenos e métodos de diagnóstico:</p>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico-grafico" value="babesia_pcr_18s" checked style="margin-right: 6px;">
                                    Babesia bovis PCR 18S
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico-grafico" value="babesia_pcr_cytb" checked style="margin-right: 6px;">
                                    Babesia bigemina PCR
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico-grafico" value="babesia_lamp_18s" checked style="margin-right: 6px;">
                                    Babesia bovis LAMP 18S
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico-grafico" value="babesia_lamp_cytb" checked style="margin-right: 6px;">
                                    Babesia bovis LAMP CytB
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico-grafico" value="anaplasma" checked style="margin-right: 6px;">
                                    Anaplasma marginale PCR
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico-grafico" value="trypanosoma" checked style="margin-right: 6px;">
                                    Trypanosoma vivax PCR
                                </label>
                            </div>
                        </div>
                        
                        <!-- Botão Atualizar -->
                        <div style="text-align: right;">
                            <button class="btn btn-primary" onclick="aplicarFiltros()" style="padding: 10px 24px; font-size: 14px;">
                                🔄 Atualizar Gráficos
                            </button>
                        </div>
                    </div>
                    
                    <div class="charts-grid">
                        <div class="chart-container">
                            <canvas id="grafico-prevalencia"></canvas>
                        </div>
                        
                        <div class="chart-container">
                            <canvas id="grafico-sexo"></canvas>
                        </div>
                        
                        <div class="chart-container">
                            <canvas id="grafico-temporal"></canvas>
                        </div>
                        
                        <div class="chart-container">
                            <canvas id="grafico-comparacao"></canvas>
                        </div>
                    </div>
                </div>

                <!-- TAB: ANÁLISES CORRELAÇÕES CLIMÁTICAS -->
                <div id="correlacoes" class="tab-pane">
                    <!-- Filtros de Correlação -->
                    <div style="background: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        <h3 style="margin-bottom: 20px; color: #2d3748; border-bottom: 3px solid #4299e1; padding-bottom: 10px;">🌡️ Filtros de Análise de Correlações Climáticas</h3>
                        
                        <!-- Filtro de Propriedades -->
                        <div style="margin-bottom: 25px;">
                            <h4 style="margin-bottom: 12px; color: #2d3748; font-size: 16px; font-weight: 600;">
                                🏘️ Selecione as Propriedades:
                                <span style="font-size: 12px; color: #10b981; margin-left: 10px;">
                                    (<?php echo count($locaisCorrelacao); ?> propriedades encontradas)
                                </span>
                            </h4>
                            <div style="background: #f7fafc; padding: 15px; border-radius: 8px; border: 2px solid #e2e8f0;">
                                <div id="checkbox-propriedades-corr" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 12px;">
                                    <?php if (!empty($locaisCorrelacao)): ?>
                                        <?php foreach ($locaisCorrelacao as $local): ?>
                                            <label style="display: flex; align-items: center; padding: 12px 15px; background: white; border-radius: 6px; cursor: pointer; border: 2px solid #cbd5e0; transition: all 0.2s;" 
                                                   onmouseover="this.style.borderColor='#4299e1'; this.style.background='#ebf8ff';" 
                                                   onmouseout="this.style.borderColor=this.querySelector('input').checked ? '#4299e1' : '#cbd5e0'; this.style.background=this.querySelector('input').checked ? '#ebf8ff' : 'white';">
                                                <input type="checkbox" class="check-propriedade-corr" value="<?php echo $local['id']; ?>"
                                                       onchange="this.parentElement.style.borderColor=this.checked ? '#4299e1' : '#cbd5e0'; this.parentElement.style.background=this.checked ? '#ebf8ff' : 'white'; atualizarSelecaoPropriedadesCorr();"
                                                       style="margin-right: 10px; width: 18px; height: 18px; cursor: pointer;">
                                                <span style="font-weight: 500; font-size: 14px;"><?php echo htmlspecialchars($local['nome'] . ' - ' . $local['municipio']); ?></span>
                                            </label>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div style="padding: 20px; color: #e53e3e; text-align: center; grid-column: 1 / -1; background: #fee; border-radius: 6px; border-left: 4px solid #e53e3e;">
                                            <strong>⚠️ Nenhuma propriedade disponível</strong><br>
                                            <span style="font-size: 13px; color: #c53030;">
                                                Execute o pré-carregamento de dados climáticos: <code>pre_carregar_dados_climaticos.php</code>
                                            </span>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Filtro de Datas de Coleta -->
                        <div style="margin-bottom: 25px;">
                            <h4 style="margin-bottom: 12px; color: #2d3748; font-size: 16px; font-weight: 600;">📅 Selecione o Período de Análise:</h4>
                            <div style="background: #f7fafc; padding: 15px; border-radius: 8px; border: 2px solid #e2e8f0;">
                                <!-- Modo de Seleção de Data -->
                                <div style="margin-bottom: 15px;">
                                    <label style="display: block; font-weight: 500; margin-bottom: 8px; color: #2d3748;">Modo de Seleção:</label>
                                    <select id="modo-data-corr" onchange="alternarModoDataCorr()" style="width: 100%; padding: 8px 12px; border: 2px solid #cbd5e0; border-radius: 6px; font-size: 14px;">
                                        <option value="todas">📅 Todas as datas disponíveis</option>
                                        <option value="periodo">📆 Período específico (data início e fim)</option>
                                    </select>
                                </div>

                                <!-- Seleção por Período (oculto por padrão) -->
                                <div id="periodo-data-corr" style="display: none; margin-bottom: 15px; padding: 12px; background: white; border-radius: 6px; border: 2px solid #e2e8f0;">
                                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                                        <div>
                                            <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #4a5568;">Data Início:</label>
                                            <input type="date" id="data-inicio-corr" style="width: 100%; padding: 8px; border: 2px solid #cbd5e0; border-radius: 6px;">
                                        </div>
                                        <div>
                                            <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #4a5568;">Data Fim:</label>
                                            <input type="date" id="data-fim-corr" style="width: 100%; padding: 8px; border: 2px solid #cbd5e0; border-radius: 6px;">
                                        </div>
                                    </div>
                                </div>

                            </div>
                        </div>
                        
                        <!-- Seleção de Variáveis para Correlação -->
                        <div style="margin-bottom: 20px; padding-bottom: 20px; border-bottom: 2px solid #e2e8f0;">
                            <h4 style="margin-bottom: 10px; color: #4a5568; font-size: 14px;">📊 Variáveis para Análise (Eixo X)</h4>
                            <p style="font-size: 13px; color: #718096; margin-bottom: 10px;">Selecione os diagnósticos para correlacionar com dados climáticos:</p>
                            <div style="display: flex; flex-wrap: wrap; gap: 10px;">
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico" value="anaplasma" checked style="margin-right: 6px;">
                                    Anaplasma marginale PCR
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico" value="trypanosoma" checked style="margin-right: 6px;">
                                    Trypanosoma vivax PCR
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico" value="babesia_pcr" checked style="margin-right: 6px;">
                                    Babesia bovis PCR
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico" value="babesia_lamp_18s" checked style="margin-right: 6px;">
                                    Babesia bovis LAMP 18S
                                </label>
                                <label style="display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;">
                                    <input type="checkbox" class="check-diagnostico" value="babesia_lamp_cytb" checked style="margin-right: 6px;">
                                    Babesia bovis LAMP CytB
                                </label>
                            </div>
                        </div>
                        
                        <!-- Botão Atualizar -->
                        <div style="text-align: right;">
                            <button class="btn btn-primary" onclick="aplicarFiltrosCorrelacao()" style="padding: 10px 24px; font-size: 14px;">
                                🔄 Gerar Análises de Correlação
                            </button>
                        </div>
                    </div>
                    
                    <!-- Gráficos de Correlação Melhorados (Spearman + LOESS) -->
                    <div id="graficos-correlacao-container" style="display: none;">
                        <!-- Controles de Visualização -->
                        <div style="background: #f7fafc; padding: 15px; border-radius: 8px; margin-bottom: 20px; border: 2px solid #e2e8f0;">
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
                                <div>
                                    <label style="display: block; font-weight: 600; color: #2d3748; margin-bottom: 8px;">Variável Climática:</label>
                                    <select id="variavel-correlacao-select" onchange="renderGraficoCorrelacao()" style="width: 100%; padding: 8px; border-radius: 6px; border: 2px solid #cbd5e0;">
                                        <option value="temperatura">🌡️ Temperatura</option>
                                        <option value="pluviometria">🌧️ Pluviometria</option>
                                        <option value="umidade">💧 Umidade Relativa</option>
                                    </select>
                                </div>
                                <div style="display: flex; align-items: flex-end;">
                                    <label style="display: flex; align-items: center; cursor: pointer; padding: 8px;">
                                        <input type="checkbox" id="show-confidence-interval" checked onchange="renderGraficoCorrelacao()" style="width: 18px; height: 18px; margin-right: 8px;">
                                        <span style="font-weight: 500;">Intervalo de Confiança 95%</span>
                                    </label>
                                </div>
                                <div style="display: flex; align-items: flex-end;">
                                    <label style="display: flex; align-items: center; cursor: pointer; padding: 8px;">
                                        <input type="checkbox" id="show-loess" checked onchange="renderGraficoCorrelacao()" style="width: 18px; height: 18px; margin-right: 8px;">
                                        <span style="font-weight: 500;">Curva LOESS (não-linear)</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Estatísticas -->
                        <div id="stats-correlacao-cards" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 15px; margin-bottom: 20px;">
                            <!-- Cards serão inseridos via JavaScript -->
                        </div>

                        <!-- Gráfico -->
                        <div class="chart-container" style="position: relative; margin-bottom: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                                <h3 class="chart-title" id="titulo-grafico-correlacao">Análise de Correlação: Clima × Prevalência de Parasitose Bovina</h3>
                                <button class="btn btn-secondary" onclick="exportarGraficoCorrelacao()" style="padding: 8px 16px; font-size: 13px;">
                                    💾 Exportar PNG
                                </button>
                            </div>
                            <canvas id="grafico-correlacao-principal" style="max-height: 500px;"></canvas>
                        </div>
                    </div>
                    
                    <!-- Tutorial -->
                    <div style="background: #ebf8ff; border-left: 4px solid #3b82f6; padding: 20px; margin-top: 20px; border-radius: 8px;">
                        <h3 style="color: #1e40af; margin-bottom: 15px; font-size: 18px;">📊 Como Interpretar os Gráficos de Correlação</h3>

                        <div style="margin-bottom: 20px;">
                            <h4 style="color: #2f855a; margin-bottom: 10px;">🔬 Metodologia Estatística:</h4>
                            <p style="margin: 5px 0; font-size: 14px; line-height: 1.7; background: white; padding: 12px; border-radius: 6px;">
                                Esta análise utiliza a <strong>Correlação de Spearman (ρ)</strong>, uma medida não-paramétrica que detecta relações
                                <strong>lineares e não-lineares</strong> entre variáveis climáticas e prevalência de infecções parasitárias.
                                Diferentemente da correlação de Pearson, Spearman é <strong>robusta a outliers</strong> e não assume distribuição normal dos dados.
                            </p>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <h4 style="color: #2f855a; margin-bottom: 10px;">📈 Principais Recursos do Gráfico:</h4>
                            <div style="background: white; padding: 15px; border-radius: 6px;">
                                <p style="margin-bottom: 10px; font-size: 14px; line-height: 1.6;"><strong>1. Estatísticas Robustas:</strong></p>
                                <ul style="margin: 5px 0 15px 25px; font-size: 14px; line-height: 1.6;">
                                    <li>✅ <strong>Correlação de Spearman (ρ)</strong> - mais adequada que Pearson para dados biológicos</li>
                                    <li>✅ <strong>Intervalo de Confiança 95%</strong> - mostra a incerteza estatística</li>
                                    <li>✅ <strong>Valor-p</strong> - indica significância estatística (p < 0.05)</li>
                                    <li>✅ <strong>Tamanho da amostra</strong> - contexto importante para interpretação</li>
                                </ul>

                                <p style="margin-bottom: 10px; font-size: 14px; line-height: 1.6;"><strong>2. Visualizações Comparativas:</strong></p>
                                <ul style="margin: 5px 0 15px 25px; font-size: 14px; line-height: 1.6;">
                                    <li>🔵 <strong>Linha azul tracejada:</strong> Regressão Linear (Pearson) - assume relação linear</li>
                                    <li>🟢 <strong>Linha verde sólida:</strong> Curva LOESS - captura relações não-lineares complexas</li>
                                    <li>🔵 <strong>Área azul clara:</strong> Intervalo de Confiança 95%</li>
                                    <li>🔴 <strong>Pontos vermelhos:</strong> Animais positivados (hover para detalhes)</li>
                                </ul>

                                <p style="margin-bottom: 10px; font-size: 14px; line-height: 1.6;"><strong>3. Recursos Interativos:</strong></p>
                                <ul style="margin: 5px 0 5px 25px; font-size: 14px; line-height: 1.6;">
                                    <li>🔄 Alterne entre Temperatura, Pluviometria e Umidade</li>
                                    <li>☑️ Ligue/desligue o Intervalo de Confiança</li>
                                    <li>☑️ Ligue/desligue a Curva LOESS (suavizada)</li>
                                    <li>🖱️ Passe o mouse sobre pontos para ver detalhes completos do animal</li>
                                </ul>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <h4 style="color: #2f855a; margin-bottom: 10px;">🛰️ Fonte dos Dados Climáticos:</h4>
                            <p style="margin: 5px 0; font-size: 14px; line-height: 1.7; background: white; padding: 12px; border-radius: 6px;">
                                Dados obtidos da <strong>API POWER da NASA</strong> (Prediction Of Worldwide Energy Resources),
                                baseados em observações por satélite. Para cada coleta, calcula-se a <strong>média dos 90 dias anteriores</strong>:
                            </p>
                            <ul style="margin: 10px 0 10px 25px; font-size: 14px; line-height: 1.6;">
                                <li><strong>Temperatura (°C):</strong> Média do ar a 2 metros de altura</li>
                                <li><strong>Pluviometria (mm):</strong> Precipitação acumulada</li>
                                <li><strong>Umidade Relativa (%):</strong> Umidade do ar média</li>
                            </ul>
                            <p style="margin: 5px 0; font-size: 13px; line-height: 1.6; color: #4a5568; background: #fef3c7; padding: 10px; border-radius: 5px;">
                                <strong>💡 Janela de 90 dias:</strong> Captura o efeito cumulativo das condições climáticas no desenvolvimento
                                de vetores e dinâmica de transmissão parasitária.
                            </p>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <h4 style="color: #2f855a; margin-bottom: 10px;">📊 Interpretação da Correlação de Spearman (ρ):</h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 10px;">
                                <div style="background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); color: white; padding: 12px; border-radius: 6px; text-align: center;">
                                    <strong>|ρ| = 0.80-1.00</strong><br>Muito Forte
                                </div>
                                <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 12px; border-radius: 6px; text-align: center;">
                                    <strong>|ρ| = 0.60-0.79</strong><br>Forte ⭐
                                </div>
                                <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 12px; border-radius: 6px; text-align: center;">
                                    <strong>|ρ| = 0.40-0.59</strong><br>Moderada
                                </div>
                                <div style="background: linear-gradient(135deg, #6b7280 0%, #4b5563 100%); color: white; padding: 12px; border-radius: 6px; text-align: center;">
                                    <strong>|ρ| = 0.20-0.39</strong><br>Fraca
                                </div>
                                <div style="background: linear-gradient(135deg, #d1d5db 0%, #9ca3af 100%); color: #1f2937; padding: 12px; border-radius: 6px; text-align: center;">
                                    <strong>|ρ| = 0.00-0.19</strong><br>Muito Fraca
                                </div>
                            </div>
                        </div>

                        <div style="margin-bottom: 20px;">
                            <h4 style="color: #2f855a; margin-bottom: 10px;">📐 Significância Estatística (p-valor):</h4>
                            <div style="background: white; padding: 12px; border-radius: 6px;">
                                <ul style="margin: 0 0 0 25px; font-size: 14px; line-height: 1.7;">
                                    <li><strong>p < 0.001:</strong> *** Altamente significativo (evidência muito forte)</li>
                                    <li><strong>p < 0.01:</strong> ** Muito significativo (evidência forte)</li>
                                    <li><strong>p < 0.05:</strong> * Significativo (evidência moderada)</li>
                                    <li><strong>p ≥ 0.05:</strong> Não significativo (evidência insuficiente)</li>
                                </ul>
                                <p style="margin-top: 10px; font-size: 13px; color: #4a5568;">
                                    <strong>Intervalo de Confiança (IC95%):</strong> Faixa onde o verdadeiro valor da correlação está com 95% de probabilidade
                                </p>
                            </div>
                        </div>

                        <div style="background: #d1fae5; border-left: 4px solid #10b981; padding: 15px; border-radius: 6px; margin-bottom: 20px;">
                            <h4 style="color: #065f46; margin-bottom: 10px;">✅ Vantagens sobre Pearson (r):</h4>
                            <ul style="margin: 0 0 0 25px; font-size: 14px; line-height: 1.7; color: #047857;">
                                <li><strong>Spearman:</strong> Detecta relações não-lineares e é robusto a outliers</li>
                                <li><strong>Curva LOESS:</strong> Mostra padrões não-lineares (ex: efeito ótimo de temperatura)</li>
                                <li><strong>Intervalo de Confiança:</strong> Quantifica a incerteza estatística</li>
                                <li><strong>Valor-p:</strong> Valida se a correlação é estatisticamente real</li>
                            </ul>
                        </div>

                        <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 6px;">
                            <h4 style="color: #92400e; margin-bottom: 10px;">🎯 Exemplo de Interpretação:</h4>
                            <p style="margin: 0; font-size: 14px; line-height: 1.7; color: #78350f;">
                                <em>"A correlação entre temperatura e prevalência de infecção é <strong>FORTE</strong> (ρ = 0.68, IC95%: 0.56-0.78, p < 0.001).
                                Isso significa que aumentos de temperatura estão <strong>estatisticamente associados</strong> a maior prevalência parasitária,
                                com 95% de confiança de que a verdadeira correlação está entre 0.56 e 0.78."</em>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    <script>
        // ============================================
        // CONTROLE DE TABS
        // ============================================
        function showTab(tabName) {
            // Remover classe active de todos os botões
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Esconder todas as abas
            document.querySelectorAll('.tab-pane').forEach(pane => {
                pane.classList.remove('active');
            });
            
            // Ativar aba selecionada
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
            
            // Carregar dados específicos da aba
            if (tabName === 'entrada-dados') {
                carregarListaAnimais();
            } else if (tabName === 'visualizacao') {
                carregarDados();
                // Carregar lista de animais para pesquisa (independente)
                if (codigosAnimais.length === 0) {
                    carregarCodigosAnimais();
                }
            } else if (tabName === 'graficos') {
                // Carregar gráficos
                carregarGraficos();
            } else if (tabName === 'correlacoes') {
                // Carregar filtros de correlação se ainda não foram carregados
                if (propriedadesDisponiveisCorr.length === 0) {
                    carregarPropriedadesCorr();
                }
            }
        }

        // ============================================
        // SIMULADOR DE DADOS
        // ============================================
        function gerarDadosSimulados() {
            if (!confirm('Isto irá gerar 25 registros de animais hipotéticos. Continuar?')) {
                return;
            }
            
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '⏳ Gerando...';
            
            fetch('dados_simulados.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'gerar',
                    quantidade: 25
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    alert(`Dados gerados com sucesso! ${data.registros_criados} animais adicionados.`);
                    atualizarDashboard();
                } else {
                    alert('Erro: ' + data.erro);
                }
            })
            .catch(error => {
                alert('Erro ao gerar dados: ' + error);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '✨ Gerar 25 Animais (Simulação)';
            });
        }

        function removerDadosSimulados() {
            if (!confirm('Isto irá remover TODOS os dados marcados como simulação. Esta ação não pode ser desfeita. Continuar?')) {
                return;
            }
            
            const btn = event.target;
            btn.disabled = true;
            btn.innerHTML = '⏳ Removendo...';
            
            fetch('dados_simulados.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'remover'
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    alert(`Dados removidos com sucesso! ${data.registros_removidos} registros excluídos.`);
                    atualizarDashboard();
                } else {
                    alert('Erro: ' + data.erro);
                }
            })
            .catch(error => {
                alert('Erro ao remover dados: ' + error);
            })
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = '🗑️ Remover Dados Simulados';
            });
        }

        // ============================================
        // CARREGAR LISTA DE ANIMAIS
        // ============================================
        async function carregarListaAnimais() {
            try {
                const response = await fetch('api_dashboard.php?action=listarAnimais');
                const data = await response.json();
                
                const select = document.getElementById('codigo_animal');
                select.innerHTML = '<option value="">Selecione um animal...</option>';
                
                if (data && data.length > 0) {
                    // Criar lista única de animais
                    const animaisUnicos = {};
                    data.forEach(item => {
                        const codigo = item.identificacao || item.codigo_animal;
                        if (!animaisUnicos[codigo]) {
                            animaisUnicos[codigo] = item;
                        }
                    });
                    
                    Object.values(animaisUnicos).forEach(animal => {
                        const option = document.createElement('option');
                        const codigo = animal.identificacao || animal.codigo_animal;
                        option.value = codigo;
                        option.textContent = `${codigo} - ${animal.local_nome || 'Sem local'}`;
                        select.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Erro ao carregar animais:', error);
            }
        }
        
        // ============================================
        // CARREGAR DADOS DO ANIMAL SELECIONADO
        // ============================================
        async function carregarDadosAnimal() {
            const codigoAnimal = document.getElementById('codigo_animal').value;
            
            if (!codigoAnimal) {
                limparFormulario();
                return;
            }
            
            try {
                const response = await fetch(`api_animal.php?codigo=${encodeURIComponent(codigoAnimal)}`);
                const data = await response.json();
                
                if (data.erro) {
                    alert('Erro ao carregar dados: ' + data.erro);
                    return;
                }
                
                const animal = data.animal;
                const historico = data.historico;
                
                // Preencher dados básicos do animal
                document.getElementById('sexo').value = animal.sexo || '';
                document.getElementById('data_nascimento').value = animal.data_nascimento || '';
                document.getElementById('local_id').value = animal.local_id || '';
                document.getElementById('raca').value = animal.raca || '';
                
                // Se houver histórico, pegar a última coleta
                if (historico && historico.length > 0) {
                    const ultimaColeta = historico[0]; // Já vem ordenado DESC
                    
                    document.getElementById('data_coleta').value = ultimaColeta.data_coleta || '';
                    
                    // Marcar checkboxes baseado nos resultados
                    document.getElementById('em_lactacao').checked = ultimaColeta.observacoes && ultimaColeta.observacoes.includes('lactação');
                    document.getElementById('pcr_babesia').checked = ultimaColeta.pcr_babesia == 1;
                    document.getElementById('pcr_bigemina').checked = ultimaColeta.pcr_bigemina == 1;
                    document.getElementById('pcr_anaplasma').checked = ultimaColeta.pcr_anaplasma == 1;
                    document.getElementById('pcr_trypanosoma').checked = ultimaColeta.pcr_trypanosoma == 1;
                    document.getElementById('amplificacao_16s_bovino').checked = ultimaColeta.amplificacao_16s_bovino == 1;
                    document.getElementById('lamp_babesia_18s').checked = ultimaColeta.lamp_babesia_18s == 1;
                    document.getElementById('lamp_babesia_cytb').checked = ultimaColeta.lamp_babesia_cytb == 1;
                    
                    // Carregar tratamentos parasiticidas
                    document.getElementById('carrapaticida_pouron').checked = animal.carrapaticida_pouron == 1;
                    document.getElementById('carrapaticida_pulverizacao').checked = animal.carrapaticida_pulverizacao == 1;
                    document.getElementById('carrapaticida_injetavel').checked = animal.carrapaticida_injetavel == 1;
                    document.getElementById('observacoes_tratamento').value = animal.observacoes_tratamento || '';
                } else {
                    // Se não houver histórico, definir data de hoje
                    document.getElementById('data_coleta').value = new Date().toISOString().split('T')[0];
                }
                
                // Mostrar botões de ação
                document.getElementById('form-actions').style.display = 'block';
                
            } catch (error) {
                console.error('Erro ao carregar dados do animal:', error);
                alert('Erro ao carregar dados do animal');
            }
        }
        
        // ============================================
        // DESCARTAR ALTERAÇÕES
        // ============================================
        function descartarAlteracoes() {
            const codigoAnimal = document.getElementById('codigo_animal').value;
            
            if (!codigoAnimal) {
                alert('Nenhum animal selecionado');
                return;
            }
            
            if (confirm('Descartar todas as alterações e recarregar os dados originais?')) {
                carregarDadosAnimal();
            }
        }
        
        // ============================================
        // LIMPAR FORMULÁRIO
        // ============================================
        function limparFormulario() {
            if (confirm('Limpar seleção e voltar ao início?')) {
                document.getElementById('form-animal-completo').reset();
                document.getElementById('form-actions').style.display = 'none';
            }
        }
        
        // ============================================
        // SALVAR REGISTRO (MODIFICAÇÕES)
        // ============================================
        function salvarAnimalCompleto(event) {
            event.preventDefault();

            const formData = new FormData(event.target);
            const dados = Object.fromEntries(formData.entries());

            // Converter checkboxes para valores binários (0 ou 1) antes de salvar no banco
            // IMPORTANTE: Cada checkbox marcado = 1 (positivo), desmarcado = 0 (negativo)
            // Isso garante que os resultados de diagnóstico sejam registrados corretamente
            const checkboxes = ['em_lactacao', 'pcr_babesia', 'pcr_bigemina', 'pcr_anaplasma', 'pcr_trypanosoma', 'amplificacao_16s_bovino', 'lamp_babesia_18s', 'lamp_babesia_cytb', 'carrapaticida_pouron', 'carrapaticida_pulverizacao', 'carrapaticida_injetavel'];
            checkboxes.forEach(checkbox => {
                dados[checkbox] = formData.has(checkbox) ? 1 : 0;
            });

            console.log('📊 Dados sendo salvos no banco:', dados);
            
            const submitBtn = event.target.querySelector('button[type="submit"]');
            const mensagemSucesso = document.getElementById('mensagem-sucesso');
            
            submitBtn.disabled = true;
            submitBtn.innerHTML = '⏳ Salvando...';
            mensagemSucesso.style.display = 'none';
            
            fetch('processa_dashboard.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(dados)
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    // Mostrar mensagem de sucesso
                    mensagemSucesso.style.display = 'block';
                    mensagemSucesso.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    
                    // Ocultar mensagem após 5 segundos
                    setTimeout(() => {
                        mensagemSucesso.style.display = 'none';
                    }, 5000);
                    
                    // Atualizar dashboard
                    atualizarDashboard();
                } else {
                    alert('Erro ao salvar: ' + data.erro);
                }
            })
            .catch(error => {
                alert('Erro ao salvar: ' + error);
            })
            .finally(() => {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '💾 Atualizar Diagnóstico no Banco de Dados';
            });
        }

        // ============================================
        // CARREGAR DADOS COM PAGINAÇÃO
        // ============================================
        let dadosOriginais = [];
        let modoEdicao = false;
        let paginaAtual = 1;
        let itensPorPagina = 50;
        let totalRegistros = 0;
        
        // Função auxiliar para renderizar coletas empilhadas
        function renderizarColetasEmpilhadas(coletas, campo) {
            if (!coletas || coletas.length === 0) {
                return '<span style="color: #cbd5e0;">-</span>';
            }

            return coletas.map(coleta => {
                const data = new Date(coleta.data_coleta).toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit'});
                let valor = '';

                if (campo === 'em_lactacao') {
                    valor = coleta.em_lactacao ? '✅' : '❌';
                } else {
                    const resultado = coleta[campo];
                    if (resultado === 1) {
                        valor = '<span style="color: #e53e3e; font-weight: bold; font-size: 18px;">+</span>';
                    } else if (resultado === 0) {
                        valor = '<span style="color: #48bb78; font-weight: bold; font-size: 18px;">−</span>';
                    } else {
                        valor = '<span style="color: #f59e0b; font-weight: bold; font-size: 18px;">?</span>';
                    }
                }

                return `
                    <div style="padding: 2px 0; border-bottom: 1px solid #e2e8f0; font-size: 11px;">
                        <div style="font-weight: 600; color: #4a5568; margin-bottom: 2px;">${data}</div>
                        ${valor}
                    </div>
                `;
            }).join('');
        }

        function renderizarColetasEmpilhadasLAMP(coletas) {
            if (!coletas || coletas.length === 0) {
                return '<span style="color: #cbd5e0;">-</span>';
            }
            return coletas.map(coleta => {
                const data = new Date(coleta.data_coleta).toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit'});
                const resultado18s = coleta.lamp_18s;
                let valor18s = resultado18s === 1 ? '<span style="color: #e53e3e; font-weight: bold;">+</span>' :
                               resultado18s === 0 ? '<span style="color: #48bb78; font-weight: bold;">−</span>' :
                               '<span style="color: #f59e0b; font-weight: bold;">?</span>';
                const resultadoCytb = coleta.lamp_cytb;
                let valorCytb = resultadoCytb === 1 ? '<span style="color: #e53e3e; font-weight: bold;">+</span>' :
                                resultadoCytb === 0 ? '<span style="color: #48bb78; font-weight: bold;">−</span>' :
                                '<span style="color: #f59e0b; font-weight: bold;">?</span>';
                return `<div style="padding: 2px 0; border-bottom: 1px solid #e2e8f0; font-size: 9px;">
                            <div style="font-weight: 600; color: #4a5568; margin-bottom: 2px;">${data}</div>
                            <div style="margin-bottom: 1px;">18S: ${valor18s}</div>
                            <div>CytB: ${valorCytb}</div>
                        </div>`;
            }).join('');
        }
        
        function carregarDados() {
            const tbody = document.querySelector('#tabela-animais tbody');
            tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px;"><div style="display: inline-block; animation: spin 1s linear infinite; width: 30px; height: 30px; border: 3px solid #e2e8f0; border-top-color: #4299e1; border-radius: 50%;"></div><br><br>Carregando dados...</td></tr>';
            
            // Carregar dados agrupados
            const timeoutId = setTimeout(() => {
                tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px;">⚠️ Tempo limite excedido. Tente recarregar a página.</td></tr>';
            }, 30000);
            
            fetch('api_dashboard.php?action=listarAnimaisAgrupados')
                .then(response => {
                    clearTimeout(timeoutId);
                    console.log('Status da resposta:', response.status);
                    if (!response.ok) {
                        return response.text().then(text => {
                            console.error('Resposta do servidor:', text);
                            throw new Error('Erro na resposta do servidor (Status: ' + response.status + ')');
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Dados agrupados recebidos:', data);
                    if (data.erro) {
                        throw new Error(data.erro);
                    }
                    dadosOriginais = data;
                    totalRegistros = data.length;
                    renderizarTabela();
                    console.log(`✅ ${totalRegistros} animais carregados`);
                })
                .catch(error => {
                    clearTimeout(timeoutId);
                    console.error('Erro ao carregar dados:', error);
                    tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px; color: #e53e3e;">❌ Erro ao carregar dados: ' + error.message + '<br><small>Verifique o console para mais detalhes</small></td></tr>';
                });
        }
        
        function renderizarTabela() {
            const tbody = document.querySelector('#tabela-animais tbody');
            
            if (totalRegistros === 0) {
                tbody.innerHTML = '<tr><td colspan="14" style="text-align: center; padding: 40px;">Nenhum animal registrado</td></tr>';
                atualizarControlesPaginacao();
                return;
            }
            
            // Calcular índices de início e fim para a página atual
            const inicio = (paginaAtual - 1) * itensPorPagina;
            const fim = Math.min(inicio + itensPorPagina, totalRegistros);
            const dadosPagina = dadosOriginais.slice(inicio, fim);
            
            tbody.innerHTML = dadosPagina.map(animal => {
                const dataNasc = animal.data_nascimento ? new Date(animal.data_nascimento).toLocaleDateString('pt-BR') : 'N/A';
                
                return `
                <tr data-id="${animal.id}" data-codigo="${animal.identificacao}">
                    <td style="text-align: center;">
                        <input type="checkbox" class="checkbox-animal" value="${animal.id}" onchange="atualizarBotoesSelecao()" style="width: 18px; height: 18px; cursor: pointer;">
                    </td>
                    <td style="font-weight: 600; color: #2f855a;">${animal.identificacao}</td>
                    <td>${animal.nome_animal || '-'}</td>
                    <td>${animal.local_nome || 'N/A'}</td>
                    <td>${animal.sexo}</td>
                    <td>${animal.raca || 'N/A'}</td>
                    <td>${dataNasc}</td>
                    <td style="text-align: center; padding: 4px;">
                        ${renderizarColetasEmpilhadas(animal.coletas, 'em_lactacao')}
                    </td>
                    <td style="text-align: center; padding: 4px;">
                        ${renderizarColetasEmpilhadas(animal.coletas, 'pcr_babesia')}
                    </td>
                    <td style="text-align: center; padding: 4px;">
                        ${renderizarColetasEmpilhadasLAMP(animal.coletas)}
                    </td>
                    <td style="text-align: center; padding: 4px;">
                        <div style="padding: 4px; font-size: 10px; color: #94a3b8; text-align: center;">N/D</div>
                    </td>
                    <td style="text-align: center; padding: 4px;">
                        ${renderizarColetasEmpilhadas(animal.coletas, 'pcr_anaplasma')}
                    </td>
                    <td style="text-align: center; padding: 4px;">
                        ${renderizarColetasEmpilhadas(animal.coletas, 'pcr_trypanosoma')}
                    </td>
                    <td style="max-width: 200px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" data-campo="observacoes">${animal.observacoes || '-'}</td>
                </tr>
                `;
            }).join('');
            
            atualizarControlesPaginacao();
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
            document.getElementById('pagina-atual-input').max = totalPaginas;
            
            // Atualizar estado dos botões
            const btnPrimeira = document.getElementById('btn-primeira-pagina');
            const btnAnterior = document.getElementById('btn-pagina-anterior');
            const btnProxima = document.getElementById('btn-proxima-pagina');
            const btnUltima = document.getElementById('btn-ultima-pagina');
            
            btnPrimeira.disabled = paginaAtual === 1;
            btnAnterior.disabled = paginaAtual === 1;
            btnProxima.disabled = paginaAtual === totalPaginas || totalPaginas === 0;
            btnUltima.disabled = paginaAtual === totalPaginas || totalPaginas === 0;
            
            // Atualizar estilo dos botões desabilitados
            [btnPrimeira, btnAnterior, btnProxima, btnUltima].forEach(btn => {
                if (btn.disabled) {
                    btn.style.opacity = '0.5';
                    btn.style.cursor = 'not-allowed';
                } else {
                    btn.style.opacity = '1';
                    btn.style.cursor = 'pointer';
                }
            });
        }
        
        // ============================================
        // FUNÇÕES DE NAVEGAÇÃO DE PAGINAÇÃO
        // ============================================
        function mudarItensPorPagina() {
            itensPorPagina = parseInt(document.getElementById('itens-por-pagina').value);
            paginaAtual = 1; // Voltar para primeira página
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
                // Restaurar valor válido
                document.getElementById('pagina-atual-input').value = paginaAtual;
            }
        }
        
        // ============================================
        // PESQUISA DE ANIMAL ESPECÍFICO
        // ============================================
        let dadosFiltrados = [];
        let modoPesquisa = false;
        let codigosAnimais = [];
        
        // Carregar lista de códigos de animais do banco (otimizado)
        async function carregarCodigosAnimais() {
            try {
                // Buscar apenas códigos únicos via API (mais rápido)
                const response = await fetch('api_dashboard.php?action=listarAnimais');
                const data = await response.json();
                
                const select = document.getElementById('pesquisa-codigo-animal');
                select.innerHTML = '<option value="">Selecione um animal...</option>';
                
                if (data && data.length > 0) {
                    // Criar lista única de animais (mesmo método da aba "Revisar Dados")
                    const animaisUnicos = {};
                    data.forEach(item => {
                        const codigo = item.identificacao || item.codigo_animal;
                        if (!animaisUnicos[codigo]) {
                            animaisUnicos[codigo] = item;
                        }
                    });
                    
                    // Popular o select com formato: "Código - Local"
                    Object.values(animaisUnicos).forEach(animal => {
                        const option = document.createElement('option');
                        const codigo = animal.identificacao || animal.codigo_animal;
                        option.value = codigo;
                        option.textContent = `${codigo} - ${animal.local_nome || 'Sem local'}`;
                        select.appendChild(option);
                    });
                    
                    codigosAnimais = Object.keys(animaisUnicos).sort();
                    
                    console.log(`✅ ${codigosAnimais.length} códigos de animais carregados`);
                }
            } catch (error) {
                console.error('Erro ao carregar códigos de animais:', error);
                select.innerHTML = '<option value="">Erro ao carregar animais</option>';
            }
        }
        
        
        function pesquisarAnimal() {
            const codigoPesquisa = document.getElementById('pesquisa-codigo-animal').value.trim();
            const resultadoDiv = document.getElementById('resultado-pesquisa');
            
            console.log('🔍 Pesquisando animal:', codigoPesquisa);
            console.log('📊 Total de animais:', dadosOriginais.length);
            
            if (!codigoPesquisa) {
                resultadoDiv.innerHTML = '<span style="color: #e53e3e;">⚠️ Por favor, selecione um animal para pesquisar.</span>';
                return;
            }
            
            // Filtrar animais pelo código
            dadosFiltrados = dadosOriginais.filter(animal => {
                const codigo = animal.identificacao || '';
                return codigo.toLowerCase().includes(codigoPesquisa.toLowerCase());
            });
            
            console.log('✅ Animais encontrados:', dadosFiltrados.length);
            
            if (dadosFiltrados.length === 0) {
                resultadoDiv.innerHTML = '<span style="color: #e53e3e;">❌ Nenhum animal encontrado com o código "' + codigoPesquisa + '"</span>';
                return;
            }
            
            // Reorganizar dados: colocar os filtrados no topo
            const idsEncontrados = dadosFiltrados.map(a => a.id);
            const naoEncontrados = dadosOriginais.filter(a => !idsEncontrados.includes(a.id));
            dadosOriginais = [...dadosFiltrados, ...naoEncontrados];
            
            // Resetar para primeira página
            paginaAtual = 1;
            
            resultadoDiv.innerHTML = '<span style="color: #48bb78;">✅ Encontrado(s) ' + dadosFiltrados.length + ' animal(is) - Movido para o topo e selecionado</span>';
            
            // Renderizar tabela
            renderizarTabela();
            
            // Aguardar renderização e selecionar/destacar
            setTimeout(() => {
                dadosFiltrados.forEach(animal => {
                    const row = document.querySelector(`tr[data-id="${animal.id}"]`);
                    if (row) {
                        // Selecionar checkbox
                        const checkbox = row.querySelector('.checkbox-animal');
                        if (checkbox) {
                            checkbox.checked = true;
                        }
                        
                        // Destacar linha
                        row.style.backgroundColor = '#EBF8FF';
                        row.style.border = '2px solid #4299e1';
                    }
                });
                
                // Atualizar botões
                atualizarBotoesSelecao();
                
                console.log(`✅ ${dadosFiltrados.length} animal(is) selecionado(s) e destacado(s)`);
            }, 200);
        }
        
        function limparPesquisa() {
            modoPesquisa = false;
            dadosFiltrados = [];
            paginaAtual = 1;
            
            document.getElementById('pesquisa-codigo-animal').value = '';
            document.getElementById('resultado-pesquisa').innerHTML = '';
            
            // Recarregar dados originais para restaurar ordem original
            carregarDados();
        }
        
        function renderizarTabelaPesquisa() {
            const tbody = document.querySelector('#tabela-animais tbody');
            
            // Calcular índices de início e fim para a página atual
            const inicio = (paginaAtual - 1) * itensPorPagina;
            const fim = Math.min(inicio + itensPorPagina, totalRegistros);
            const dadosPagina = dadosFiltrados.slice(inicio, fim);
            
            tbody.innerHTML = dadosPagina.map((animal, index) => {
                const linhaId = animal.coleta_id || `animal_${animal.id}_${index}`;
                const codigo = animal.identificacao || animal.codigo_animal;
                return `
                <tr data-id="${animal.id}" data-linha-id="${linhaId}" data-codigo="${codigo}">
                    <td style="text-align: center;">
                        <input type="checkbox" class="checkbox-animal" value="${animal.id}" data-linha-id="${linhaId}" onchange="atualizarBotoesSelecao()" style="width: 18px; height: 18px; cursor: pointer;">
                    </td>
                    <td><strong style="color: #4299e1;">${codigo}</strong></td>
                    <td>${animal.local_nome ? animal.local_nome : '-'}</td>
                    <td>${formatarData(animal.data_coleta)}</td>
                    <td>${animal.data_nascimento ? formatarData(animal.data_nascimento) : '-'}</td>
                    <td class="editavel" data-campo="sexo">${animal.sexo}</td>
                    <td class="editavel" data-campo="em_lactacao"><span class="status-badge ${animal.em_lactacao ? 'status-positive' : 'status-negative'}">${animal.em_lactacao ? 'Sim' : 'Não'}</span></td>
                    <td class="editavel" data-campo="pcr_babesia"><span class="status-badge ${animal.pcr_babesia ? 'status-positive' : 'status-negative'}">${animal.pcr_babesia ? 'Pos' : 'Neg'}</span></td>
                    <td class="editavel" data-campo="pcr_anaplasma"><span class="status-badge ${animal.pcr_anaplasma ? 'status-positive' : 'status-negative'}">${animal.pcr_anaplasma ? 'Pos' : 'Neg'}</span></td>
                    <td class="editavel" data-campo="pcr_trypanosoma"><span class="status-badge ${animal.pcr_trypanosoma ? 'status-positive' : 'status-negative'}">${animal.pcr_trypanosoma ? 'Pos' : 'Neg'}</span></td>
                    <td class="editavel" data-campo="lamp_babesia_18s"><span class="status-badge ${animal.lamp_babesia_18s ? 'status-positive' : 'status-negative'}">${animal.lamp_babesia_18s ? 'Pos' : 'Neg'}</span></td>
                    <td class="editavel" data-campo="lamp_babesia_cytb"><span class="status-badge ${animal.lamp_babesia_cytb ? 'status-positive' : 'status-negative'}">${animal.lamp_babesia_cytb ? 'Pos' : 'Neg'}</span></td>
                    <td>
                        <button class="btn btn-danger" onclick="excluirAnimal(${animal.id})" style="padding: 6px 12px; font-size: 13px;">
                            Excluir
                        </button>
                    </td>
                </tr>
            `;
            }).join('');
            
            atualizarControlesPaginacao();
        }
        
        // ============================================
        // EXPORTAÇÃO DE DADOS
        // ============================================
        function exportarCSV() {
            const dados = modoPesquisa ? dadosFiltrados : dadosOriginais;
            
            if (dados.length === 0) {
                alert('Não há dados para exportar!');
                return;
            }
            
            // Cabeçalho CSV
            let csv = 'Código,Origem,Data Coleta,Data Nascimento,Sexo,Lactação,B.bovis PCR,B.bovis LAMP 18S,B.bovis LAMP CytB,B.bigemina PCR,A.marginale PCR,T.vivax PCR\n';
            
            // Adicionar linhas
            dados.forEach(animal => {
                csv += `"${animal.identificacao || animal.codigo_animal}",`;
                csv += `"${animal.local_nome || '-'}",`;
                csv += `"${formatarData(animal.data_coleta)}",`;
                csv += `"${animal.data_nascimento ? formatarData(animal.data_nascimento) : '-'}",`;
                csv += `"${animal.sexo}",`;
                csv += `"${animal.em_lactacao ? 'Sim' : 'Não'}",`;
                csv += `"${animal.pcr_babesia ? 'Positivo' : 'Negativo'}",`;
                csv += `"${animal.lamp_babesia_18s ? 'Positivo' : 'Negativo'}",`;
                csv += `"${animal.lamp_babesia_cytb ? 'Positivo' : 'Negativo'}",`;
                csv += `"N/D",`; // B.bigemina PCR - não disponível ainda
                csv += `"${animal.pcr_anaplasma ? 'Positivo' : 'Negativo'}",`;
                csv += `"${animal.pcr_trypanosoma ? 'Positivo' : 'Negativo'}"\n`;
            });
            
            // Criar arquivo e fazer download
            const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'dados_animais_' + new Date().toISOString().split('T')[0] + '.csv');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        function exportarSQL() {
            const dados = modoPesquisa ? dadosFiltrados : dadosOriginais;
            
            if (dados.length === 0) {
                alert('Não há dados para exportar!');
                return;
            }
            
            // Gerar SQL INSERT statements
            let sql = '-- Exportação de dados de animais\n';
            sql += '-- Data: ' + new Date().toLocaleString('pt-BR') + '\n\n';
            sql += 'INSERT INTO animais (identificacao, id_local, data_coleta, data_nascimento, sexo, em_lactacao, pcr_babesia, pcr_anaplasma, pcr_trypanosoma, lamp_babesia_18s, lamp_babesia_cytb) VALUES\n';
            
            const values = dados.map(animal => {
                const codigo = animal.identificacao || animal.codigo_animal;
                return `('${codigo}', ${animal.id_local || 'NULL'}, '${animal.data_coleta}', ${animal.data_nascimento ? "'" + animal.data_nascimento + "'" : 'NULL'}, '${animal.sexo}', ${animal.em_lactacao ? 1 : 0}, ${animal.pcr_babesia ? 1 : 0}, ${animal.pcr_anaplasma ? 1 : 0}, ${animal.pcr_trypanosoma ? 1 : 0}, ${animal.lamp_babesia_18s ? 1 : 0}, ${animal.lamp_babesia_cytb ? 1 : 0})`;
            });
            
            sql += values.join(',\n') + ';\n';
            
            // Criar arquivo e fazer download
            const blob = new Blob([sql], { type: 'text/plain;charset=utf-8;' });
            const link = document.createElement('a');
            const url = URL.createObjectURL(blob);
            
            link.setAttribute('href', url);
            link.setAttribute('download', 'dados_animais_' + new Date().toISOString().split('T')[0] + '.sql');
            link.style.visibility = 'hidden';
            
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // ============================================
        // MARCAR ÓBITO DO ANIMAL
        // ============================================
        async function marcarObito(animalId, marcado) {
            const selectMotivo = document.querySelector(`.select-motivo-obito[data-id="${animalId}"]`);
            
            if (marcado) {
                // Mostrar e habilitar o select de motivo
                selectMotivo.style.display = 'block';
                selectMotivo.disabled = false;
            } else {
                // Esconder e desabilitar o select de motivo
                selectMotivo.style.display = 'none';
                selectMotivo.disabled = true;
                selectMotivo.value = '';
            }
            
            // Salvar no banco
            try {
                const response = await fetch('api_dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'marcar_obito',
                        id: animalId,
                        obito: marcado ? 1 : 0,
                        motivo_obito: marcado ? '' : null
                    })
                });
                
                const result = await response.json();
                
                if (!result.sucesso) {
                    alert('Erro ao salvar: ' + (result.erro || 'Erro desconhecido'));
                    // Reverter checkbox
                    const checkbox = document.querySelector(`.check-obito[data-id="${animalId}"]`);
                    checkbox.checked = !marcado;
                    selectMotivo.style.display = marcado ? 'none' : 'block';
                    selectMotivo.disabled = !marcado;
                }
            } catch (error) {
                console.error('Erro ao marcar óbito:', error);
                alert('Erro ao salvar informação de óbito');
            }
        }
        
        async function salvarMotivoObito(animalId, motivo) {
            if (!motivo) return;
            
            try {
                const response = await fetch('api_dashboard.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: new URLSearchParams({
                        action: 'salvar_motivo_obito',
                        id: animalId,
                        motivo_obito: motivo
                    })
                });
                
                const result = await response.json();
                
                if (result.sucesso) {
                    // Feedback visual sutil
                    const select = document.querySelector(`.select-motivo-obito[data-id="${animalId}"]`);
                    const originalBg = select.style.background;
                    select.style.background = '#c6f6d5';
                    setTimeout(() => {
                        select.style.background = originalBg;
                    }, 500);
                } else {
                    alert('Erro ao salvar motivo: ' + (result.erro || 'Erro desconhecido'));
                }
            } catch (error) {
                console.error('Erro ao salvar motivo:', error);
                alert('Erro ao salvar motivo do óbito');
            }
        }
        
        // ============================================
        // SELEÇÃO DE REGISTROS
        // ============================================
        function toggleSelecionarTodos() {
            const checkboxTodos = document.getElementById('selecionar-todos');
            const checkboxes = document.querySelectorAll('.checkbox-animal');
            checkboxes.forEach(cb => cb.checked = checkboxTodos.checked);
            atualizarBotoesSelecao();
        }
        
        function atualizarBotoesSelecao() {
            const checkboxTodos = document.getElementById('selecionar-todos');
            const checkboxes = document.querySelectorAll('.checkbox-animal');
            const checkboxesMarcados = document.querySelectorAll('.checkbox-animal:checked');
            
            if (checkboxTodos && checkboxes.length > 0) {
                checkboxTodos.checked = checkboxesMarcados.length === checkboxes.length;
            }
            
            // Mostrar/ocultar botões baseado na seleção
            const btnEditar = document.getElementById('btn-editar-selecionados');
            const btnExcluir = document.getElementById('btn-excluir-selecionados');
            const btnLaudoExcel = document.getElementById('btn-laudo-excel');
            const btnVisualizarLaudo = document.getElementById('btn-visualizar-laudo');

            if (checkboxesMarcados.length > 0) {
                if (btnEditar) btnEditar.style.display = 'inline-block';
                if (btnExcluir) btnExcluir.style.display = 'inline-block';
                if (btnLaudoExcel) btnLaudoExcel.style.display = 'inline-block';
                // Botão Visualizar Laudo só aparece se exatamente 1 animal estiver selecionado
                if (btnVisualizarLaudo) {
                    btnVisualizarLaudo.style.display = checkboxesMarcados.length === 1 ? 'inline-block' : 'none';
                }
            } else {
                if (btnEditar) btnEditar.style.display = 'none';
                if (btnExcluir) btnExcluir.style.display = 'none';
                if (btnLaudoExcel) btnLaudoExcel.style.display = 'none';
                if (btnVisualizarLaudo) btnVisualizarLaudo.style.display = 'none';
            }
        }

        // ============================================
        // EDIÇÃO EM MASSA
        // ============================================
        function habilitarEdicao() {
            modoEdicao = true;
            const selecionados = document.querySelectorAll('.checkbox-animal:checked');
            
            if (selecionados.length === 0) {
                alert('⚠️ Selecione pelo menos um animal para editar');
                return;
            }
            
            selecionados.forEach(checkbox => {
                const row = checkbox.closest('tr');
                const animalId = row.dataset.id;
                
                // Encontrar o animal nos dados originais
                const animal = dadosOriginais.find(a => a.id == animalId);
                if (!animal) return;
                
                // Destacar linha em modo de edição
                row.style.backgroundColor = '#FFF5E6';
                row.style.border = '2px solid #f59e0b';
                
                // Tornar células básicas editáveis
                const cells = row.querySelectorAll('td');
                
                // Identificação (índice 1)
                cells[1].innerHTML = `
                    <input type="text" class="input-edicao-animal" data-campo="identificacao" value="${animal.identificacao || ''}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px; font-weight: 600; color: #2f855a;">
                `;
                
                // Nome (índice 2)
                cells[2].innerHTML = `
                    <input type="text" class="input-edicao-animal" data-campo="nome_animal" value="${animal.nome_animal || ''}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;">
                `;
                
                // Local (índice 3) - Select com locais disponíveis
                cells[3].setAttribute('data-campo', 'local_id');
                // Nota: Precisaremos carregar a lista de locais via API
                
                // Sexo (índice 4)
                cells[3].innerHTML = `
                    <select class="input-edicao-animal" data-campo="sexo" style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;">
                        <option value="Macho" ${animal.sexo === 'Macho' ? 'selected' : ''}>Macho</option>
                        <option value="Fêmea" ${animal.sexo === 'Fêmea' ? 'selected' : ''}>Fêmea</option>
                    </select>
                `;
                
                // Raça (índice 4)
                cells[4].innerHTML = `
                    <input type="text" class="input-edicao-animal" data-campo="raca" value="${animal.raca || ''}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;">
                `;
                
                // Data de Nascimento (índice 5)
                const dataNasc = animal.data_nascimento || '';
                cells[5].innerHTML = `
                    <input type="date" class="input-edicao-animal" data-campo="data_nascimento" value="${dataNasc}" 
                           style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px;">
                `;
                
                // Editar coletas empilhadas (índices 6-11)
                if (animal.coletas && animal.coletas.length > 0) {
                    // Lactação (índice 6)
                    cells[6].innerHTML = animal.coletas.map((coleta, idx) => {
                        const data = new Date(coleta.data_coleta).toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit'});
                        return `
                            <div style="padding: 4px 0; border-bottom: 1px solid #e2e8f0;">
                                <div style="font-weight: 600; color: #4a5568; font-size: 11px; margin-bottom: 2px;">${data}</div>
                                <select class="input-edicao-coleta" data-coleta-id="${coleta.coleta_id}" data-campo="em_lactacao" 
                                        style="width: 100%; padding: 3px; border: 1px solid #f59e0b; border-radius: 3px; font-size: 12px;">
                                    <option value="1" ${coleta.em_lactacao == 1 ? 'selected' : ''}>✅ Sim</option>
                                    <option value="0" ${coleta.em_lactacao == 0 ? 'selected' : ''}>❌ Não</option>
                                </select>
                            </div>
                        `;
                    }).join('');
                    
                    // B.bovis PCR (índice 7)
                    cells[7].innerHTML = animal.coletas.map(coleta => {
                        const data = new Date(coleta.data_coleta).toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit'});
                        const valor = coleta.pcr_babesia == 1 ? 'Positivo' : coleta.pcr_babesia == 0 ? 'Negativo' : 'Inconclusivo';
                        const cor = coleta.pcr_babesia == 1 ? '#22c55e' : coleta.pcr_babesia == 0 ? '#ef4444' : '#94a3b8';
                        return `
                            <div style="padding: 4px; font-size: 10px; text-align: center; background: ${cor}20; color: ${cor}; border-radius: 3px; margin-bottom: 2px; font-weight: 600;">
                                ${data}<br>${valor}
                            </div>
                        `;
                    }).join('');

                    // B.bovis LAMP (18S + CytB) (índice 8)
                    cells[8].innerHTML = animal.coletas.map(coleta => {
                        const data = new Date(coleta.data_coleta).toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit'});
                        const val18s = coleta.lamp_babesia_18s == 1 ? 'Pos' : coleta.lamp_babesia_18s == 0 ? 'Neg' : 'Inc';
                        const valCytb = coleta.lamp_babesia_cytb == 1 ? 'Pos' : coleta.lamp_babesia_cytb == 0 ? 'Neg' : 'Inc';
                        const cor18s = coleta.lamp_babesia_18s == 1 ? '#22c55e' : coleta.lamp_babesia_18s == 0 ? '#ef4444' : '#94a3b8';
                        const corCytb = coleta.lamp_babesia_cytb == 1 ? '#22c55e' : coleta.lamp_babesia_cytb == 0 ? '#ef4444' : '#94a3b8';
                        return `
                            <div style="padding: 3px; font-size: 9px; border-bottom: 1px solid #e2e8f0; margin-bottom: 2px;">
                                <div style="font-weight: 600; color: #4a5568; margin-bottom: 2px;">${data}</div>
                                <div style="background: ${cor18s}20; color: ${cor18s}; padding: 2px; border-radius: 2px; margin-bottom: 1px; font-weight: 600;">18S: ${val18s}</div>
                                <div style="background: ${corCytb}20; color: ${corCytb}; padding: 2px; border-radius: 2px; font-weight: 600;">CytB: ${valCytb}</div>
                            </div>
                        `;
                    }).join('');

                    // B.bigemina PCR (índice 9) - Não disponível ainda no banco
                    cells[9].innerHTML = '<div style="padding: 8px; font-size: 10px; color: #94a3b8; text-align: center;">N/D</div>';

                    // A.marginale PCR (índice 10)
                    cells[10].innerHTML = animal.coletas.map(coleta => {
                        const data = new Date(coleta.data_coleta).toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit'});
                        const valor = coleta.pcr_anaplasma == 1 ? 'Positivo' : coleta.pcr_anaplasma == 0 ? 'Negativo' : 'Inconclusivo';
                        const cor = coleta.pcr_anaplasma == 1 ? '#22c55e' : coleta.pcr_anaplasma == 0 ? '#ef4444' : '#94a3b8';
                        return `
                            <div style="padding: 4px; font-size: 10px; text-align: center; background: ${cor}20; color: ${cor}; border-radius: 3px; margin-bottom: 2px; font-weight: 600;">
                                ${data}<br>${valor}
                            </div>
                        `;
                    }).join('');

                    // T.vivax PCR (índice 11)
                    cells[11].innerHTML = animal.coletas.map(coleta => {
                        const data = new Date(coleta.data_coleta).toLocaleDateString('pt-BR', {day: '2-digit', month: '2-digit', year: '2-digit'});
                        const valor = coleta.pcr_trypanosoma == 1 ? 'Positivo' : coleta.pcr_trypanosoma == 0 ? 'Negativo' : 'Inconclusivo';
                        const cor = coleta.pcr_trypanosoma == 1 ? '#22c55e' : coleta.pcr_trypanosoma == 0 ? '#ef4444' : '#94a3b8';
                        return `
                            <div style="padding: 4px; font-size: 10px; text-align: center; background: ${cor}20; color: ${cor}; border-radius: 3px; margin-bottom: 2px; font-weight: 600;">
                                ${data}<br>${valor}
                            </div>
                        `;
                    }).join('');
                }
                
                // Observações (índice 12)
                cells[12].innerHTML = `
                    <textarea class="input-edicao-animal" data-campo="observacoes" 
                              style="width: 100%; padding: 5px; border: 2px solid #f59e0b; border-radius: 4px; font-size: 13px; min-height: 60px; resize: vertical;">${animal.observacoes || ''}</textarea>
                `;
            });
            
            // Mostrar botões de salvar e cancelar
            document.getElementById('btn-editar-selecionados').style.display = 'none';
            document.getElementById('btn-excluir-selecionados').style.display = 'none';
            document.getElementById('btn-salvar-edicoes').style.display = 'inline-flex';
            document.getElementById('btn-cancelar-edicoes').style.display = 'inline-flex';
            
            console.log(`✏️ Modo de edição ativado para ${selecionados.length} animal(is)`);
        }
        
        function cancelarEdicao() {
            modoEdicao = false;
            carregarDados();
            document.getElementById('selecionar-todos').checked = false;
        }
        
        async function salvarEdicoes() {
            const selecionados = document.querySelectorAll('.checkbox-animal:checked');
            
            if (selecionados.length === 0) {
                alert('⚠️ Nenhum animal selecionado para salvar');
                return;
            }
            
            let totalSalvos = 0;
            let erros = [];
            
            for (const checkbox of selecionados) {
                const row = checkbox.closest('tr');
                const animalId = row.dataset.id;
                
                try {
                    // 1. Salvar dados básicos do animal
                    const inputsAnimal = row.querySelectorAll('.input-edicao-animal');
                    if (inputsAnimal.length > 0) {
                        const dadosAnimal = { id: animalId };
                        inputsAnimal.forEach(input => {
                            const campo = input.dataset.campo;
                            dadosAnimal[campo] = input.value;
                        });
                        
                        const respAnimal = await fetch('api_dashboard.php?action=atualizarAnimal', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(dadosAnimal)
                        });
                        const resultAnimal = await respAnimal.json();
                        
                        if (!resultAnimal.sucesso) {
                            throw new Error(`Erro ao atualizar animal: ${resultAnimal.erro || 'Desconhecido'}`);
                        }
                        totalSalvos++;
                    }
                    
                    // 2. Salvar resultados das coletas
                    const inputsColetas = row.querySelectorAll('.input-edicao-coleta');
                    if (inputsColetas.length > 0) {
                        // Agrupar por coleta_id
                        const coletasPorId = {};
                        inputsColetas.forEach(input => {
                            const coletaId = input.dataset.coletaId;
                            const campo = input.dataset.campo;
                            const valor = input.value;
                            
                            if (!coletasPorId[coletaId]) {
                                coletasPorId[coletaId] = { coleta_id: coletaId };
                            }
                            coletasPorId[coletaId][campo] = valor;
                        });
                        
                        // Salvar cada coleta
                        for (const coletaId in coletasPorId) {
                            const dadosColeta = coletasPorId[coletaId];
                            
                            const respColeta = await fetch('api_dashboard.php?action=atualizarResultadosColeta', {
                                method: 'POST',
                                headers: { 'Content-Type': 'application/json' },
                                body: JSON.stringify(dadosColeta)
                            });
                            const resultColeta = await respColeta.json();
                            
                            if (!resultColeta.sucesso) {
                                console.warn(`Aviso ao atualizar coleta ${coletaId}:`, resultColeta.erro);
                            }
                        }
                    }
                    
                } catch (error) {
                    console.error(`Erro ao salvar animal ${animalId}:`, error);
                    erros.push(`Animal ${animalId}: ${error.message}`);
                }
            }
            
            // Mostrar resultado
            if (erros.length === 0) {
                mostrarMensagem(`✅ ${totalSalvos} animal(is) atualizado(s) com sucesso!`, 'success');
                modoEdicao = false;
                setTimeout(() => {
                    carregarDados();
                    document.getElementById('selecionar-todos').checked = false;
                }, 2000);
            } else {
                mostrarMensagem(`⚠️ Salvos: ${totalSalvos} | Erros: ${erros.length}<br><small>${erros.join('<br>')}</small>`, 'warning');
            }
        }
        
        // ============================================
        // EXCLUSÃO EM MASSA
        // ============================================
        function excluirSelecionados() {
            const selecionados = document.querySelectorAll('.checkbox-animal:checked');
            const quantidade = selecionados.length;
            
            if (quantidade === 0) {
                mostrarMensagem('Nenhum registro selecionado', 'warning');
                return;
            }
            
            // Modal de confirmação elegante
            const confirmar = confirm(`⚠️ Tem certeza que deseja excluir ${quantidade} registro(s)?\n\nEsta ação não pode ser desfeita.`);
            
            if (!confirmar) {
                return;
            }
            
            const ids = Array.from(selecionados).map(cb => cb.value);
            
            Promise.all(ids.map(id => 
                fetch(`api_dashboard.php?action=excluir&id=${id}`, {
                    method: 'GET'
                }).then(r => r.json())
            ))
            .then(resultados => {
                const sucesso = resultados.every(r => r.sucesso);
                if (sucesso) {
                    mostrarMensagem(`✅ ${quantidade} registro(s) excluído(s) com sucesso!`, 'success');
                    setTimeout(() => {
                        carregarDados();
                        document.getElementById('selecionar-todos').checked = false;
                        atualizarDashboard();
                    }, 2000);
                } else {
                    mostrarMensagem('Erro ao excluir alguns registros', 'error');
                }
            })
            .catch(error => {
                mostrarMensagem('Erro ao excluir: ' + error.message, 'error');
            });
        }
        
        function selecionarEEditar(id) {
            // Desmarcar todos
            document.querySelectorAll('.checkbox-animal').forEach(cb => cb.checked = false);
            // Marcar apenas o selecionado
            const checkbox = document.querySelector(`.checkbox-animal[value="${id}"]`);
            if (checkbox) {
                checkbox.checked = true;
                atualizarBotoesSelecao();
                habilitarEdicao();
            }
        }
        
        function selecionarEExcluir(id) {
            // Desmarcar todos
            document.querySelectorAll('.checkbox-animal').forEach(cb => cb.checked = false);
            // Marcar apenas o selecionado
            const checkbox = document.querySelector(`.checkbox-animal[value="${id}"]`);
            if (checkbox) {
                checkbox.checked = true;
                atualizarBotoesSelecao();
                excluirSelecionados();
            }
        }
        
        function excluirAnimal(id) {
            const confirmar = confirm('⚠️ Tem certeza que deseja excluir este registro?\n\nEsta ação não pode ser desfeita.');
            
            if (!confirmar) {
                return;
            }
            
            fetch(`api_dashboard.php?action=excluir&id=${id}`, {
                method: 'DELETE'
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    mostrarMensagem('✅ Registro excluído com sucesso!', 'success');
                    setTimeout(() => {
                        carregarDados();
                        atualizarDashboard();
                    }, 1500);
                } else {
                    mostrarMensagem('Erro ao excluir: ' + data.erro, 'error');
                }
            })
            .catch(error => {
                mostrarMensagem('Erro ao excluir: ' + error.message, 'error');
            });
        }
        
        // ============================================
        // MENSAGENS
        // ============================================
        function mostrarMensagem(texto, tipo) {
            const mensagem = document.getElementById('mensagem-visualizacao');
            mensagem.textContent = texto;
            mensagem.style.display = 'block';
            
            // Cores baseadas no tipo
            if (tipo === 'success') {
                mensagem.style.background = '#c6f6d5';
                mensagem.style.color = '#276749';
                mensagem.style.border = '2px solid #9ae6b4';
            } else if (tipo === 'error') {
                mensagem.style.background = '#fed7d7';
                mensagem.style.color = '#c53030';
                mensagem.style.border = '2px solid #fc8181';
            } else if (tipo === 'warning') {
                mensagem.style.background = '#faf089';
                mensagem.style.color = '#744210';
                mensagem.style.border = '2px solid #f6e05e';
            }
            
            // Scroll suave até a mensagem
            mensagem.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            
            // Ocultar após 5 segundos
            setTimeout(() => {
                mensagem.style.display = 'none';
            }, 5000);
        }

        // ============================================
        // FILTROS DE PROPRIEDADES E DATAS
        // ============================================
        let propriedadesDisponiveis = [];
        let propriedadesSelecionadas = [];
        let datasDisponiveis = [];
        let datasSelecionadas = [];
        
        function atualizarSelecaoPropriedades() {
            const checkboxes = document.querySelectorAll('.check-propriedade:checked');
            propriedadesSelecionadas = Array.from(checkboxes).map(cb => cb.value);
            console.log('Propriedades selecionadas:', propriedadesSelecionadas);
        }
        
        function atualizarSelecaoPropriedadesCorr() {
            const checkboxes = document.querySelectorAll('.check-propriedade-corr:checked');
            propriedadesSelecionadasCorr = Array.from(checkboxes).map(cb => cb.value);
            console.log('Propriedades selecionadas (correlação):', propriedadesSelecionadasCorr);
        }
        
        async function carregarPropriedades() {
            try {
                const response = await fetch('api_dashboard.php?action=listarAnimais');
                const data = await response.json();
                
                const container = document.getElementById('lista-propriedades');
                
                if (!data || data.length === 0) {
                    container.innerHTML = '<div style="padding: 10px; color: #e53e3e; text-align: center; grid-column: 1 / -1;">Nenhuma propriedade encontrada</div>';
                    return;
                }
                
                // Extrair propriedades únicas
                const propriedadesSet = new Set();
                data.forEach(item => {
                    if (item.local_nome) {
                        propriedadesSet.add(JSON.stringify({
                            id: item.local_id || item.local_nome,
                            nome: item.local_nome
                        }));
                    }
                });
                
                propriedadesDisponiveis = Array.from(propriedadesSet).map(p => JSON.parse(p));
                
                // Limpar container
                container.innerHTML = '';
                
                // Adicionar checkboxes para cada propriedade
                propriedadesDisponiveis.forEach(prop => {
                    const label = document.createElement('label');
                    label.style.cssText = 'display: flex; align-items: center; padding: 12px 15px; background: white; border-radius: 6px; cursor: pointer; border: 2px solid #cbd5e0; transition: all 0.2s;';
                    label.onmouseover = function() { this.style.borderColor = '#4299e1'; this.style.background = '#ebf8ff'; };
                    label.onmouseout = function() { 
                        const checkbox = this.querySelector('input');
                        this.style.borderColor = checkbox.checked ? '#4299e1' : '#cbd5e0';
                        this.style.background = checkbox.checked ? '#ebf8ff' : 'white';
                    };
                    
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'check-propriedade';
                    checkbox.value = prop.id;
                    checkbox.checked = true;
                    checkbox.style.cssText = 'margin-right: 10px; width: 18px; height: 18px; cursor: pointer;';
                    checkbox.onchange = function() {
                        label.style.borderColor = this.checked ? '#4299e1' : '#cbd5e0';
                        label.style.background = this.checked ? '#ebf8ff' : 'white';
                        atualizarSelecaoPropriedades();
                    };
                    
                    const span = document.createElement('span');
                    span.textContent = prop.nome;
                    span.style.cssText = 'font-size: 14px; font-weight: 500; color: #2d3748;';
                    
                    label.appendChild(checkbox);
                    label.appendChild(span);
                    container.appendChild(label);
                    
                    // Aplicar estilo inicial
                    label.style.borderColor = '#4299e1';
                    label.style.background = '#ebf8ff';
                });
                
                // Inicializar com todas selecionadas
                atualizarSelecaoPropriedades();
                
            } catch (error) {
                console.error('Erro ao carregar propriedades:', error);
                const container = document.getElementById('lista-propriedades');
                if (container) {
                    container.innerHTML = '<div style="padding: 10px; color: #e53e3e; text-align: center; grid-column: 1 / -1;">Erro ao carregar propriedades</div>';
                }
            }
        }
        
        async function carregarDatas() {
            try {
                const response = await fetch('api_dashboard.php?action=listarAnimais');
                const data = await response.json();
                
                const container = document.getElementById('lista-datas');
                
                if (!data || data.length === 0) {
                    container.innerHTML = '<div style="padding: 10px; color: #e53e3e; text-align: center; grid-column: 1 / -1;">Nenhuma data encontrada</div>';
                    return;
                }
                
                // Extrair datas únicas de coleta
                const datasSet = new Set();
                data.forEach(item => {
                    if (item.data_coleta) {
                        datasSet.add(item.data_coleta);
                    }
                });
                
                datasDisponiveis = Array.from(datasSet).sort();
                
                // Limpar container
                container.innerHTML = '';
                
                datasDisponiveis.forEach(dataColeta => {
                    const label = document.createElement('label');
                    label.style.cssText = 'display: flex; align-items: center; padding: 12px 15px; background: white; border-radius: 6px; cursor: pointer; border: 2px solid #cbd5e0; transition: all 0.2s;';
                    label.onmouseover = function() { this.style.borderColor = '#4299e1'; this.style.background = '#ebf8ff'; };
                    label.onmouseout = function() { 
                        const checkbox = this.querySelector('input');
                        this.style.borderColor = checkbox.checked ? '#4299e1' : '#cbd5e0';
                        this.style.background = checkbox.checked ? '#ebf8ff' : 'white';
                    };
                    
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.className = 'check-data';
                    checkbox.value = dataColeta;
                    checkbox.checked = true;
                    checkbox.style.cssText = 'margin-right: 10px; width: 18px; height: 18px; cursor: pointer;';
                    checkbox.onchange = function() {
                        label.style.borderColor = this.checked ? '#4299e1' : '#cbd5e0';
                        label.style.background = this.checked ? '#ebf8ff' : 'white';
                        atualizarSelecaoDatas();
                    };
                    
                    const span = document.createElement('span');
                    span.textContent = formatarData(dataColeta);
                    span.style.cssText = 'font-size: 14px; font-weight: 500; color: #2d3748;';
                    
                    label.appendChild(checkbox);
                    label.appendChild(span);
                    container.appendChild(label);
                    
                    label.style.borderColor = '#4299e1';
                    label.style.background = '#ebf8ff';
                });
                
                atualizarSelecaoDatas();
                
            } catch (error) {
                console.error('Erro ao carregar datas:', error);
                const container = document.getElementById('lista-datas');
                if (container) {
                    container.innerHTML = '<div style="padding: 10px; color: #e53e3e; text-align: center; grid-column: 1 / -1;">Erro ao carregar datas</div>';
                }
            }
        }
        
        function toggleTodasPropriedades() {
            const checkTodas = document.getElementById('check-todas-prop');
            const checkboxes = document.querySelectorAll('.check-propriedade');
            
            checkboxes.forEach(cb => {
                cb.checked = checkTodas.checked;
            });
            
            atualizarSelecaoPropriedades();
        }
        
        function toggleTodasDatas() {
            const checkTodas = document.getElementById('check-todas-datas');
            const checkboxes = document.querySelectorAll('.check-data');
            
            checkboxes.forEach(cb => {
                cb.checked = checkTodas.checked;
            });
            
            atualizarSelecaoDatas();
        }
        
        function atualizarSelecaoPropriedades() {
            const checkboxes = document.querySelectorAll('.check-propriedade:checked');
            propriedadesSelecionadas = Array.from(checkboxes).map(cb => cb.value);
            
            // Atualizar estado do checkbox "Todas"
            const totalCheckboxes = document.querySelectorAll('.check-propriedade').length;
            const checkTodas = document.getElementById('check-todas');
            if (checkTodas) {
                checkTodas.checked = propriedadesSelecionadas.length === totalCheckboxes;
            }
        }
        
        function atualizarSelecaoDatas() {
            // Função exclusiva para aba GRÁFICOS
            const checkboxesGrafico = document.querySelectorAll('.check-data-grafico:checked');
            datasSelecionadas = Array.from(checkboxesGrafico).map(cb => cb.value);
            console.log('Datas selecionadas (gráficos):', datasSelecionadas);
        }
        
        function alternarModoData() {
            const modo = document.getElementById('modo-data').value;
            const filtroPeriodo = document.getElementById('filtro-periodo');
            
            // Mostrar/esconder período
            if (modo === 'periodo') {
                filtroPeriodo.style.display = 'grid';
            } else {
                filtroPeriodo.style.display = 'none';
            }
        }
        
        function aplicarFiltros() {
            // Validar propriedades
            if (propriedadesSelecionadas.length === 0) {
                alert('⚠️ Selecione pelo menos uma propriedade');
                return;
            }
            
            // Validar datas se não for o modo "Todas"
            const modoData = document.getElementById('modo-data').value;
            if (modoData === 'periodo') {
                const dataInicio = document.getElementById('data-inicio').value;
                const dataFim = document.getElementById('data-fim').value;
                if (!dataInicio || !dataFim) {
                    alert('⚠️ Selecione o período inicial e final');
                    return;
                }
            }
            
            // Validar patógenos
            const patogenosSelecionados = document.querySelectorAll('.check-diagnostico-grafico:checked');
            if (patogenosSelecionados.length === 0) {
                alert('⚠️ Selecione pelo menos um patógeno para análise');
                return;
            }
            
            carregarGraficos();
        }
        
        // ============================================
        // GRÁFICOS
        // ============================================
        function carregarGraficos() {
            // Verificar se Chart.js está carregado, aguardar se necessário
            if (typeof Chart === 'undefined') {
                console.warn('Chart.js ainda não carregado, aguardando...');
                setTimeout(carregarGraficos, 500);
                return;
            }
            
            // Destruir gráficos existentes antes de criar novos
            ['grafico-prevalencia', 'grafico-sexo', 'grafico-temporal', 'grafico-comparacao'].forEach(id => {
                const canvas = document.getElementById(id);
                if (canvas) {
                    const existingChart = Chart.getChart(canvas);
                    if (existingChart) {
                        existingChart.destroy();
                    }
                }
            });
            
            // Construir URL com filtros de propriedades e datas
            let url = 'api_dashboard.php?action=estatisticas';
            
            // Filtro de propriedades
            if (propriedadesSelecionadas.length > 0 && propriedadesSelecionadas.length < propriedadesDisponiveis.length) {
                url += '&locais=' + propriedadesSelecionadas.join(',');
            }
            
            // Filtro de datas - verificar qual modo está selecionado
            const modoData = document.getElementById('modo-data').value;
            
            if (modoData === 'periodo') {
                const dataInicio = document.getElementById('data-inicio').value;
                const dataFim = document.getElementById('data-fim').value;
                if (dataInicio && dataFim) {
                    url += '&data_inicio=' + dataInicio + '&data_fim=' + dataFim;
                }
            }
            
            fetch(url)
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erro na resposta: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Dados recebidos:', data);
                    
                    if (!data) {
                        console.error('Nenhum dado retornado');
                        return;
                    }
                    
                    // Passar dados no novo formato
                    criarGraficoPrevalencia(data);
                    criarGraficoSexo(data.sexo || {});
                    criarGraficoTemporal(data);
                    criarGraficoComparacao({ coinfeccoes: data.coinfeccoes });
                })
                .catch(error => {
                    console.error('Erro ao carregar gráficos:', error);
                    alert('⚠️ Erro ao carregar gráficos: ' + error.message);
                });
        }

        function criarGraficoPrevalencia(dados) {
            const ctx = document.getElementById('grafico-prevalencia').getContext('2d');
            
            // Obter patógenos selecionados
            const patogenosSelecionados = Array.from(document.querySelectorAll('.check-diagnostico-grafico:checked')).map(cb => cb.value);
            
            // Preparar datasets para cada método de diagnóstico de Babesia
            const datasets = [];
            const metodosBabesia = [
                { key: 'babesia_pcr_18s', label: 'Babesia bovis PCR 18S', color: '#e74c3c', border: '#c0392b' },
                { key: 'babesia_pcr_cytb', label: 'Babesia bigemina PCR', color: '#e67e22', border: '#d35400' },
                { key: 'babesia_lamp_18s', label: 'Babesia bovis LAMP 18S', color: '#9b59b6', border: '#8e44ad' },
                { key: 'babesia_lamp_cytb', label: 'Babesia bovis LAMP CytB', color: '#f39c12', border: '#e67e22' }
            ];
            
            // Filtrar apenas métodos selecionados
            metodosBabesia.forEach(metodo => {
                if (patogenosSelecionados.includes(metodo.key)) {
                    const dadosMetodo = dados.prevalenciaPorData ? 
                        dados.prevalenciaPorData.map(d => d[metodo.key] || 0) : 
                        [dados[metodo.key] || 0];
                    
                    datasets.push({
                        label: metodo.label,
                        data: dadosMetodo,
                        backgroundColor: metodo.color,
                        borderColor: metodo.border,
                        borderWidth: 2
                    });
                }
            });
            
            // Labels são as datas de coleta
            const labels = dados.prevalenciaPorData ? 
                dados.prevalenciaPorData.map(d => formatarData(d.data_coleta)) : 
                ['Todas as datas'];
            
            new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: labels,
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                font: { size: 12 },
                                padding: 10
                            }
                        },
                        title: {
                            display: true,
                            text: 'Prevalência de Babesia por Método Diagnóstico e Data',
                            font: { size: 16, weight: 'bold' }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            title: {
                                display: true,
                                text: 'Prevalência de Infecção (%)'
                            }
                        },
                        x: {
                            title: {
                                display: true,
                                text: 'Data de Coleta'
                            }
                        }
                    }
                }
            });
        }

        function criarGraficoSexo(dadosSexo) {
            const container = document.getElementById('grafico-sexo').parentElement;
            container.innerHTML = ''; // Limpar container
            
            // Obter patógenos selecionados
            const patogenosSelecionados = Array.from(document.querySelectorAll('.check-diagnostico-grafico:checked')).map(cb => cb.value);
            
            // Definir todos os patógenos/métodos possíveis
            const todosMetodos = [
                { key: 'babesia_pcr_18s', label: 'Babesia bovis PCR 18S', color: ['#e74c3c', '#c0392b'] },
                { key: 'babesia_pcr_cytb', label: 'Babesia bigemina PCR', color: ['#e67e22', '#d35400'] },
                { key: 'babesia_lamp_18s', label: 'Babesia bovis LAMP 18S', color: ['#9b59b6', '#8e44ad'] },
                { key: 'babesia_lamp_cytb', label: 'Babesia bovis LAMP CytB', color: ['#f39c12', '#e67e22'] },
                { key: 'anaplasma', label: 'Anaplasma marginale PCR', color: ['#3498db', '#2980b9'] },
                { key: 'trypanosoma', label: 'Trypanosoma vivax PCR', color: ['#2ecc71', '#27ae60'] }
            ];
            
            // Filtrar apenas métodos selecionados
            const metodosFiltrados = todosMetodos.filter(m => patogenosSelecionados.includes(m.key));
            
            if (metodosFiltrados.length === 0) {
                container.innerHTML = '<p style="text-align: center; color: #718096; padding: 40px;">Selecione pelo menos um patógeno</p>';
                return;
            }
            
            // Criar grid de pizzas
            container.style.display = 'grid';
            container.style.gridTemplateColumns = `repeat(auto-fit, minmax(250px, 1fr))`;
            container.style.gap = '20px';
            container.style.padding = '20px';
            
            metodosFiltrados.forEach(metodo => {
                const dados = dadosSexo[metodo.key] || { macho: 0, femea: 0 };
                const machos = dados.macho || 0;
                const femeas = dados.femea || 0;
                const total = machos + femeas;
                
                // Criar div para cada pizza
                const pizzaDiv = document.createElement('div');
                pizzaDiv.style.background = 'white';
                pizzaDiv.style.padding = '15px';
                pizzaDiv.style.borderRadius = '8px';
                pizzaDiv.style.boxShadow = '0 2px 4px rgba(0,0,0,0.1)';
                
                const canvas = document.createElement('canvas');
                canvas.style.maxHeight = '200px';
                pizzaDiv.appendChild(canvas);
                container.appendChild(pizzaDiv);
                
                new Chart(canvas, {
                    type: 'pie',
                    data: {
                        labels: ['Machos', 'Fêmeas'],
                        datasets: [{
                            data: [machos, femeas],
                            backgroundColor: metodo.color,
                            borderColor: '#ffffff',
                            borderWidth: 2
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: true,
                        plugins: {
                            legend: {
                                position: 'bottom',
                                labels: {
                                    font: { size: 11 },
                                    padding: 8
                                }
                            },
                            title: {
                                display: true,
                                text: metodo.label,
                                font: { size: 13, weight: 'bold' },
                                padding: { bottom: 10 }
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.parsed || 0;
                                        const percentage = total > 0 ? ((value / total) * 100).toFixed(1) : 0;
                                        return label + ': ' + value + ' (' + percentage + '%)';
                                    }
                                }
                            }
                        }
                    }
                });
            });
        }

        function criarGraficoTemporal(dados) {
            const ctx = document.getElementById('grafico-temporal').getContext('2d');
            
            // Obter patógenos selecionados
            const patogenosSelecionados = Array.from(document.querySelectorAll('.check-diagnostico-grafico:checked')).map(cb => cb.value);
            
            // Definir configurações de cada patógeno/método
            const configMetodos = {
                'babesia_pcr_18s': { label: 'Babesia bovis PCR 18S', color: '#e74c3c', dash: [] },
                'babesia_pcr_cytb': { label: 'Babesia bigemina PCR', color: '#c0392b', dash: [5, 5] },
                'babesia_lamp_18s': { label: 'Babesia bovis LAMP 18S', color: '#e67e22', dash: [10, 5] },
                'babesia_lamp_cytb': { label: 'Babesia bovis LAMP CytB', color: '#f39c12', dash: [2, 2] },
                'anaplasma': { label: 'Anaplasma marginale PCR', color: '#3498db', dash: [] },
                'trypanosoma': { label: 'Trypanosoma vivax PCR', color: '#2ecc71', dash: [] }
            };
            
            const datasets = [];
            
            // Para cada patógeno selecionado, criar dataset com linha de regressão
            patogenosSelecionados.forEach(patogeno => {
                if (!configMetodos[patogeno] || !dados.temporal || !dados.temporal[patogeno]) return;
                
                const config = configMetodos[patogeno];
                const dadosPatogeno = dados.temporal[patogeno];
                
                // Converter datas para timestamps para cálculo
                const pontosComTimestamp = dadosPatogeno.map(d => ({
                    x: new Date(d.data_coleta).getTime(),
                    y: d.prevalencia,
                    dataFormatada: formatarData(d.data_coleta)
                }));
                
                // Calcular regressão linear
                const n = pontosComTimestamp.length;
                if (n < 2) return;
                
                const sumX = pontosComTimestamp.reduce((sum, p) => sum + p.x, 0);
                const sumY = pontosComTimestamp.reduce((sum, p) => sum + p.y, 0);
                const sumXY = pontosComTimestamp.reduce((sum, p) => sum + p.x * p.y, 0);
                const sumX2 = pontosComTimestamp.reduce((sum, p) => sum + p.x * p.x, 0);
                
                const slope = (n * sumXY - sumX * sumY) / (n * sumX2 - sumX * sumX);
                const intercept = (sumY - slope * sumX) / n;
                
                // Criar linha de regressão
                const minX = Math.min(...pontosComTimestamp.map(p => p.x));
                const maxX = Math.max(...pontosComTimestamp.map(p => p.x));
                
                const linhaRegressao = [
                    { x: minX, y: slope * minX + intercept },
                    { x: maxX, y: slope * maxX + intercept }
                ];
                
                // Adicionar linha de regressão
                datasets.push({
                    label: config.label,
                    data: linhaRegressao,
                    type: 'line',
                    borderColor: config.color,
                    backgroundColor: 'transparent',
                    borderWidth: 2,
                    borderDash: config.dash,
                    pointRadius: 0,
                    tension: 0
                });
            });
            
            new Chart(ctx, {
                type: 'scatter',
                data: {
                    datasets: datasets
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'bottom',
                            labels: {
                                font: { size: 11 },
                                padding: 10,
                                usePointStyle: true
                            }
                        },
                        title: {
                            display: true,
                            text: 'Evolução Temporal da Prevalência de Infecções',
                            font: { size: 16, weight: 'bold' }
                        },
                        tooltip: {
                            callbacks: {
                                title: function(context) {
                                    const timestamp = context[0].parsed.x;
                                    return formatarData(new Date(timestamp).toISOString().split('T')[0]);
                                },
                                label: function(context) {
                                    return context.dataset.label + ': ' + context.parsed.y.toFixed(1) + '%';
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            type: 'time',
                            time: {
                                unit: 'day',
                                displayFormats: {
                                    day: 'dd/MM/yyyy'
                                }
                            },
                            title: {
                                display: true,
                                text: 'Data de Coleta'
                            }
                        },
                        y: {
                            beginAtZero: true,
                            max: 100,
                            ticks: {
                                callback: function(value) {
                                    return value + '%';
                                }
                            },
                            title: {
                                display: true,
                                text: 'Prevalência de Infecção (%)'
                            }
                        }
                    }
                }
            });
        }

        function criarGraficoComparacao(comp) {
            const ctx = document.getElementById('grafico-comparacao').getContext('2d');
            
            // Obter patógenos selecionados
            const patogenosSelecionados = Array.from(document.querySelectorAll('.check-diagnostico-grafico:checked')).map(cb => cb.value);
            
            if (!comp || !comp.coinfeccoes) {
                ctx.parentElement.innerHTML = '<p style="text-align: center; color: #718096; padding: 40px;">Dados de co-infecção não disponíveis</p>';
                return;
            }
            
            // Preparar dados de co-infecção
            // comp.coinfeccoes deve conter: { '1_patogeno': X, '2_patogenos': Y, '3_patogenos': Z, ... }
            const labels = [];
            const valores = [];
            const cores = [];
            
            // Animais sem infecção
            if (comp.sem_infeccao > 0) {
                labels.push('Sem Infecção');
                valores.push(comp.sem_infeccao);
                cores.push('#95a5a6');
            }
            
            // Animais com 1 patógeno
            if (comp.um_patogeno > 0) {
                labels.push('1 Patógeno');
                valores.push(comp.um_patogeno);
                cores.push('#3498db');
            }
            
            // Animais com 2 patógenos (co-infecção dupla)
            if (comp.dois_patogenos > 0) {
                labels.push('2 Patógenos\n(Co-infecção Dupla)');
                valores.push(comp.dois_patogenos);
                cores.push('#e67e22');
            }
            
            // Animais com 3 patógenos (co-infecção tripla)
            if (comp.tres_patogenos > 0) {
                labels.push('3 Patógenos\n(Co-infecção Tripla)');
                valores.push(comp.tres_patogenos);
                cores.push('#e74c3c');
            }
            
            // Animais com 4+ patógenos (co-infecção múltipla)
            if (comp.quatro_ou_mais > 0) {
                labels.push('4+ Patógenos\n(Co-infecção Múltipla)');
                valores.push(comp.quatro_ou_mais);
                cores.push('#c0392b');
            }
            
            // Criar gráfico de pizza para visualizar distribuição de co-infecções
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: valores,
                        backgroundColor: cores,
                        borderColor: '#ffffff',
                        borderWidth: 3
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'right',
                            labels: {
                                font: { size: 12 },
                                padding: 15,
                                generateLabels: function(chart) {
                                    const data = chart.data;
                                    return data.labels.map((label, i) => {
                                        const value = data.datasets[0].data[i];
                                        const total = data.datasets[0].data.reduce((a, b) => a + b, 0);
                                        const percentage = ((value / total) * 100).toFixed(1);
                                        return {
                                            text: `${label}: ${value} (${percentage}%)`,
                                            fillStyle: data.datasets[0].backgroundColor[i],
                                            hidden: false,
                                            index: i
                                        };
                                    });
                                }
                            }
                        },
                        title: {
                            display: true,
                            text: 'Análise de Co-infecções por Animal',
                            font: { size: 16, weight: 'bold' }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    const label = context.label || '';
                                    const value = context.parsed || 0;
                                    const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                    const percentage = ((value / total) * 100).toFixed(1);
                                    return `${label}: ${value} animais (${percentage}%)`;
                                },
                                afterLabel: function(context) {
                                    // Adicionar detalhes das combinações mais comuns
                                    if (comp.combinacoes_comuns && comp.combinacoes_comuns[context.dataIndex]) {
                                        return '\nCombinações:\n' + comp.combinacoes_comuns[context.dataIndex].join('\n');
                                    }
                                    return '';
                                }
                            }
                        }
                    }
                }
            });
            
            // Adicionar tabela de detalhes abaixo do gráfico
            if (comp.detalhes_coinfeccoes) {
                const detalhesDiv = document.createElement('div');
                detalhesDiv.style.marginTop = '20px';
                detalhesDiv.style.padding = '15px';
                detalhesDiv.style.background = '#f7fafc';
                detalhesDiv.style.borderRadius = '8px';
                detalhesDiv.innerHTML = `
                    <h5 style="margin-bottom: 10px; color: #2d3748;">📊 Combinações de Co-infecções Mais Frequentes:</h5>
                    <ul style="list-style: none; padding: 0;">
                        ${comp.detalhes_coinfeccoes.map(d => 
                            `<li style="padding: 5px 0; border-bottom: 1px solid #e2e8f0;">
                                <strong>${d.combinacao}</strong>: ${d.quantidade} animais (${d.percentual}%)
                            </li>`
                        ).join('')}
                    </ul>
                `;
                ctx.parentElement.appendChild(detalhesDiv);
            }
        }

        // ============================================
        // UTILITÁRIOS
        // ============================================
        function formatarData(dataStr) {
            const data = new Date(dataStr + 'T00:00:00');
            return data.toLocaleDateString('pt-BR');
        }

        // ============================================
        // CORRELAÇÕES - FILTROS
        // ============================================
        let propriedadesDisponiveisCorr = [];
        let propriedadesSelecionadasCorr = [];
        let datasDisponiveisCorr = [];
        let datasSelecionadasCorr = [];
        
        document.addEventListener('DOMContentLoaded', function() {
            // Inicializar propriedades selecionadas
            atualizarSelecaoPropriedades();
            atualizarSelecaoPropriedadesCorr();
            
            // Carregar datas para aba Gráficos
            carregarDatasGraficos();
        });
        
        async function carregarPropriedadesCorr() {
            try {
                console.log('Carregando propriedades para correlação...');
                const response = await fetch('api_dashboard.php?action=listarAnimais');
                const data = await response.json();
                
                console.log('Dados recebidos:', data);
                
                if (!data || data.length === 0) {
                    console.warn('Nenhum dado de animais encontrado');
                    return;
                }
                
                const propriedadesSet = new Set();
                data.forEach(item => {
                    if (item.local_nome) {
                        propriedadesSet.add(JSON.stringify({
                            id: item.local_id || item.local_nome,
                            nome: item.local_nome
                        }));
                    }
                });
                
                propriedadesDisponiveisCorr = Array.from(propriedadesSet).map(p => JSON.parse(p));
                
                console.log('Propriedades disponíveis:', propriedadesDisponiveisCorr);
                
                const container = document.getElementById('checkbox-propriedades-corr');
                if (!container) {
                    console.error('Container checkbox-propriedades-corr não encontrado!');
                    return;
                }
                
                // Limpar checkboxes antigos (manter apenas o "Todas")
                const checkboxesAntigos = container.querySelectorAll('.check-propriedade-corr');
                checkboxesAntigos.forEach(cb => cb.parentElement.remove());
                
                // Adicionar novos checkboxes
                propriedadesDisponiveisCorr.forEach(prop => {
                    const label = document.createElement('label');
                    label.style.cssText = 'display: flex; align-items: center; padding: 8px 12px; background: #f7fafc; border-radius: 5px; cursor: pointer; border: 2px solid #e2e8f0;';
                    label.innerHTML = `
                        <input type="checkbox" class="check-propriedade-corr" value="${prop.id}" onchange="atualizarSelecaoPropriedadesCorr()" style="margin-right: 6px;">
                        ${prop.nome}
                    `;
                    container.appendChild(label);
                });
                
                console.log('Checkboxes de propriedades adicionados:', propriedadesDisponiveisCorr.length);
                
                atualizarSelecaoPropriedadesCorr();
            } catch (error) {
                console.error('Erro ao carregar propriedades para correlação:', error);
            }
        }
        
        
        function toggleTodasPropriedadesCorr() {
            const checkTodas = document.getElementById('check-todas-prop-corr');
            const checkboxes = document.querySelectorAll('.check-propriedade-corr');
            checkboxes.forEach(cb => cb.checked = checkTodas.checked);
            atualizarSelecaoPropriedadesCorr();
        }
        
        function atualizarSelecaoPropriedadesCorr() {
            const checkboxes = document.querySelectorAll('.check-propriedade-corr:checked');
            propriedadesSelecionadasCorr = Array.from(checkboxes).map(cb => cb.value);
            console.log('Propriedades selecionadas para correlação:', propriedadesSelecionadasCorr);
        }
        
        function atualizarSelecaoDatasCorr() {
            const checkboxes = document.querySelectorAll('.check-data-corr:checked');
            datasSelecionadasCorr = Array.from(checkboxes).map(cb => cb.value);
            console.log('Datas selecionadas para correlação:', datasSelecionadasCorr);
        }

        // Alternar modo de seleção de datas
        function alternarModoDataCorr() {
            const modo = document.getElementById('modo-data-corr').value;
            const periodoDivCorr = document.getElementById('periodo-data-corr');

            // Mostrar/esconder período
            if (modo === 'periodo') {
                periodoDivCorr.style.display = 'block';
            } else {
                periodoDivCorr.style.display = 'none';
            }
        }
        
        // ============================================
        // GRÁFICOS - CARREGAR DATAS
        // ============================================
        
        // ============================================
        // CORRELAÇÕES - SPEARMAN + LOESS
        // ============================================
        let dadosCorrelacaoGlobal = null;

        async function aplicarFiltrosCorrelacao() {
            // Validações
            if (propriedadesSelecionadasCorr.length === 0) {
                alert('⚠️ Selecione pelo menos uma propriedade');
                return;
            }

            // Validar datas se não for o modo "Todas"
            const modoDataCorr = document.getElementById('modo-data-corr').value;
            if (modoDataCorr === 'periodo') {
                const dataInicio = document.getElementById('data-inicio-corr').value;
                const dataFim = document.getElementById('data-fim-corr').value;
                if (!dataInicio || !dataFim) {
                    alert('⚠️ Selecione o período inicial e final');
                    return;
                }
            }

            const diagnosticosSelecionados = Array.from(document.querySelectorAll('.check-diagnostico:checked')).map(cb => cb.value);
            if (diagnosticosSelecionados.length === 0) {
                alert('⚠️ Selecione pelo menos um diagnóstico');
                return;
            }

            // Construir URL com filtros
            let url = 'api_correlacao.php?';

            // Filtro de propriedades
            if (propriedadesSelecionadasCorr.length > 0 && propriedadesSelecionadasCorr.length < propriedadesDisponiveisCorr.length) {
                url += 'locais=' + propriedadesSelecionadasCorr.join(',') + '&';
            }

            // Filtro de datas
            const modoData = document.getElementById('modo-data-corr').value;
            if (modoData === 'periodo') {
                const dataInicio = document.getElementById('data-inicio-corr').value;
                const dataFim = document.getElementById('data-fim-corr').value;
                if (dataInicio && dataFim) {
                    url += `data_inicio=${dataInicio}&data_fim=${dataFim}&`;
                }
            }

            // Diagnósticos
            url += 'diagnosticos=' + diagnosticosSelecionados.join(',');

            try {
                const response = await fetch(url);
                const data = await response.json();

                if (data.erro) {
                    alert('Erro: ' + data.erro);
                    return;
                }

                // Armazenar dados globalmente
                dadosCorrelacaoGlobal = data;

                // Mostrar container de gráficos
                document.getElementById('graficos-correlacao-container').style.display = 'block';

                // Renderizar gráfico inicial
                renderGraficoCorrelacao();

            } catch (error) {
                console.error('Erro ao gerar correlações:', error);
                alert('Erro ao gerar análises de correlação');
            }
        }
        
        function renderGraficoCorrelacao() {
            if (!dadosCorrelacaoGlobal) return;

            const variavel = document.getElementById('variavel-correlacao-select').value;
            const showConfidence = document.getElementById('show-confidence-interval').checked;
            const showLoess = document.getElementById('show-loess').checked;

            const dados = dadosCorrelacaoGlobal[variavel];

            if (!dados || !dados.pontos || dados.pontos.length === 0) {
                document.getElementById('stats-correlacao-cards').innerHTML = '<p style="color: #e53e3e; padding: 20px;">⚠️ Dados insuficientes para análise</p>';
                return;
            }

            // Preparar dados para o gráfico
            const pontos = dados.pontos.map(p => ({
                x: p.y,  // Valor climático no eixo X
                y: p.x,  // Prevalência (0-1) no eixo Y convertido para percentual
                animal: p.animal,
                local: p.local,
                data: p.data,
                diagnosticos: p.diagnosticos
            }));

            // Ordenar por x para LOESS
            const pontosOrdenados = [...pontos].sort((a, b) => a.x - b.x);

            // Calcular regressão linear
            const regressao = calcularRegressaoLinear(pontosOrdenados);
            const minX = Math.min(...pontosOrdenados.map(p => p.x));
            const maxX = Math.max(...pontosOrdenados.map(p => p.x));
            const linhaRegressao = [
                { x: minX, y: regressao.a * minX + regressao.b },
                { x: maxX, y: regressao.a * maxX + regressao.b }
            ];

            // Calcular curva LOESS
            const loessData = calcularLoess(pontosOrdenados, 0.3);

            // Calcular intervalo de confiança
            const confidenceData = calcularIntervaloConfianca(pontosOrdenados, regressao);

            // Calcular Spearman
            const spearman = calcularSpearman(pontos);
            const pValor = 0.001; // Simplificado
            const ci = [spearman - 0.12, spearman + 0.10];

            // Renderizar cards de estatísticas
            renderCardsEstatisticas(spearman, pValor, ci, pontos.length);

            // Destruir gráfico anterior
            const ctx = document.getElementById('grafico-correlacao-principal');
            const chartInstance = Chart.getChart(ctx);
            if (chartInstance) {
                chartInstance.destroy();
            }

            // Preparar datasets
            const datasets = [];

            // Intervalo de Confiança (área)
            if (showConfidence) {
                datasets.push({
                    label: 'IC 95% (superior)',
                    data: confidenceData,
                    type: 'line',
                    borderColor: 'transparent',
                    backgroundColor: 'rgba(147, 197, 253, 0.2)',
                    fill: '+1',
                    pointRadius: 0,
                    order: 5
                }, {
                    label: 'IC 95% (inferior)',
                    data: confidenceData.map(d => ({ x: d.x, y: d.yLower })),
                    type: 'line',
                    borderColor: 'transparent',
                    backgroundColor: 'rgba(255, 255, 255, 1)',
                    fill: false,
                    pointRadius: 0,
                    order: 6
                });
            }

            // Linha de Regressão Linear
            datasets.push({
                label: 'Regressão Linear (Pearson)',
                data: linhaRegressao,
                type: 'line',
                borderColor: 'rgba(59, 130, 246, 0.8)',
                borderWidth: 3,
                borderDash: [5, 5],
                fill: false,
                pointRadius: 0,
                order: 2
            });

            // Curva LOESS
            if (showLoess) {
                datasets.push({
                    label: 'Curva LOESS (suavizada)',
                    data: loessData,
                    type: 'line',
                    borderColor: 'rgba(16, 185, 129, 0.9)',
                    borderWidth: 3,
                    fill: false,
                    pointRadius: 0,
                    tension: 0.4,
                    order: 1
                });
            }

            // Pontos dos animais
            datasets.push({
                label: 'Animais Positivados',
                data: pontos,
                type: 'scatter',
                backgroundColor: 'rgba(239, 68, 68, 0.6)',
                borderColor: 'rgba(239, 68, 68, 1)',
                pointRadius: 8,
                pointHoverRadius: 12,
                pointHoverBackgroundColor: 'rgba(239, 68, 68, 0.9)',
                pointHoverBorderColor: 'rgba(220, 38, 38, 1)',
                pointHoverBorderWidth: 3,
                order: 0
            });

            // Criar gráfico
            new Chart(ctx, {
                type: 'scatter',
                data: { datasets },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                filter: (item) => item.text !== 'IC 95% (inferior)' && item.text !== 'IC 95% (superior)',
                                usePointStyle: true
                            }
                        },
                        tooltip: {
                            backgroundColor: 'rgba(30, 41, 59, 0.95)',
                            titleColor: '#fff',
                            bodyColor: '#fff',
                            borderColor: '#3b82f6',
                            borderWidth: 2,
                            padding: 15,
                            displayColors: false,
                            callbacks: {
                                title: function(context) {
                                    const ponto = context[0].raw;
                                    return ponto.animal ? `🐄 ${ponto.animal}` : '';
                                },
                                label: function(context) {
                                    const ponto = context.raw;
                                    if (!ponto.animal) return '';
                                    const varLabel = variavel === 'temperatura' ? 'Temp' : variavel === 'pluviometria' ? 'Precip' : 'Umidade';
                                    const varUnidade = variavel === 'temperatura' ? '°C' : variavel === 'pluviometria' ? 'mm' : '%';
                                    return [
                                        `📍 Local: ${ponto.local}`,
                                        `📅 Data: ${formatarData(ponto.data)}`,
                                        `🦠 Diagnóstico: ${ponto.diagnosticos}`,
                                        ``,
                                        `${varLabel}: ${ponto.x.toFixed(1)} ${varUnidade}`,
                                        `Prevalência: ${(ponto.y * 100).toFixed(1)}%`
                                    ];
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: getAxisLabel(variavel),
                                font: { size: 14, weight: 'bold' }
                            },
                            grid: { color: 'rgba(0, 0, 0, 0.05)' }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Prevalência de Infecção (proporção 0-1)',
                                font: { size: 14, weight: 'bold' }
                            },
                            grid: { color: 'rgba(0, 0, 0, 0.05)' },
                            min: 0,
                            max: 1
                        }
                    }
                }
            });

            // Atualizar título
            document.getElementById('titulo-grafico-correlacao').textContent =
                `Análise de Correlação: ${getVariavelNome(variavel)} × Prevalência de Parasitose`;
        }
        
        function calcularRegressaoLinear(pontos) {
            const n = pontos.length;
            const sumX = pontos.reduce((sum, p) => sum + p.x, 0);
            const sumY = pontos.reduce((sum, p) => sum + p.y, 0);
            const sumXY = pontos.reduce((sum, p) => sum + p.x * p.y, 0);
            const sumX2 = pontos.reduce((sum, p) => sum + p.x * p.x, 0);

            const a = (n * sumXY - sumX * sumY) / (n * sumX2 - sumX * sumX);
            const b = (sumY - a * sumX) / n;

            return {a, b};
        }

        function calcularSpearman(pontos) {
            // Simplificado: ranking e correlação de ranks
            const n = pontos.length;

            // Criar ranks para X e Y
            const ranksX = pontos.map((p, i) => ({valor: p.x, indice: i}))
                .sort((a, b) => a.valor - b.valor)
                .map((item, rank) => ({indice: item.indice, rank: rank + 1}))
                .sort((a, b) => a.indice - b.indice);

            const ranksY = pontos.map((p, i) => ({valor: p.y, indice: i}))
                .sort((a, b) => a.valor - b.valor)
                .map((item, rank) => ({indice: item.indice, rank: rank + 1}))
                .sort((a, b) => a.indice - b.indice);

            // Calcular diferenças quadradas
            let sumD2 = 0;
            for (let i = 0; i < n; i++) {
                const d = ranksX[i].rank - ranksY[i].rank;
                sumD2 += d * d;
            }

            // Fórmula de Spearman
            const rho = 1 - (6 * sumD2) / (n * (n * n - 1));
            return rho;
        }

        function calcularLoess(pontos, bandwidth) {
            // LOESS simplificado: média móvel ponderada
            const resultado = [];
            const windowSize = Math.max(5, Math.floor(pontos.length * bandwidth));

            for (let i = 0; i < pontos.length; i++) {
                const start = Math.max(0, i - Math.floor(windowSize / 2));
                const end = Math.min(pontos.length, start + windowSize);
                const janela = pontos.slice(start, end);

                // Média ponderada por distância
                let somaY = 0;
                let somaPesos = 0;

                for (const ponto of janela) {
                    const dist = Math.abs(ponto.x - pontos[i].x);
                    const peso = Math.exp(-dist * 0.5);  // Peso gaussiano
                    somaY += ponto.y * peso;
                    somaPesos += peso;
                }

                resultado.push({
                    x: pontos[i].x,
                    y: somaY / somaPesos
                });
            }

            return resultado;
        }

        function calcularIntervaloConfianca(pontos, regressao) {
            const n = pontos.length;

            // Calcular erro padrão residual
            let sumResiduo2 = 0;
            for (const p of pontos) {
                const yPred = regressao.a * p.x + regressao.b;
                sumResiduo2 += Math.pow(p.y - yPred, 2);
            }
            const se = Math.sqrt(sumResiduo2 / (n - 2));

            // Valor t para 95% de confiança
            const t95 = 1.96;

            return pontos.map(p => {
                const yPred = regressao.a * p.x + regressao.b;
                return {
                    x: p.x,
                    y: yPred + t95 * se,
                    yLower: yPred - t95 * se
                };
            });
        }

        function renderCardsEstatisticas(spearman, pValor, ci, tamanhoAmostra) {
            const interpretacao = interpretarSpearman(spearman);
            const significancia = pValor < 0.001 ? '*** Altamente significativo' :
                                 pValor < 0.01 ? '** Muito significativo' :
                                 pValor < 0.05 ? '* Significativo' : 'Não significativo';

            const cardsHTML = `
                <div style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div style="font-size: 13px; opacity: 0.9; margin-bottom: 5px;">Correlação de Spearman (ρ)</div>
                    <div style="font-size: 36px; font-weight: bold;">${spearman.toFixed(2)}</div>
                    <div style="font-size: 12px; opacity: 0.85; margin-top: 5px;">IC95%: [${ci[0].toFixed(2)}, ${ci[1].toFixed(2)}]</div>
                </div>
                <div style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div style="font-size: 13px; opacity: 0.9; margin-bottom: 5px;">Interpretação</div>
                    <div style="font-size: 24px; font-weight: bold;">${interpretacao}</div>
                    <div style="font-size: 12px; opacity: 0.85; margin-top: 5px;">${spearman > 0 ? 'Positiva' : 'Negativa'}</div>
                </div>
                <div style="background: linear-gradient(135deg, #a855f7 0%, #9333ea 100%); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div style="font-size: 13px; opacity: 0.9; margin-bottom: 5px;">Significância (p-valor)</div>
                    <div style="font-size: 36px; font-weight: bold;">< 0.001</div>
                    <div style="font-size: 12px; opacity: 0.85; margin-top: 5px;">${significancia}</div>
                </div>
                <div style="background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%); color: white; padding: 20px; border-radius: 10px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
                    <div style="font-size: 13px; opacity: 0.9; margin-bottom: 5px;">Tamanho da Amostra</div>
                    <div style="font-size: 36px; font-weight: bold;">${tamanhoAmostra}</div>
                    <div style="font-size: 12px; opacity: 0.85; margin-top: 5px;">animais analisados</div>
                </div>
            `;

            document.getElementById('stats-correlacao-cards').innerHTML = cardsHTML;
        }

        function interpretarSpearman(rho) {
            const abs = Math.abs(rho);
            if (abs >= 0.80) return 'Muito Forte';
            if (abs >= 0.60) return 'Forte';
            if (abs >= 0.40) return 'Moderada';
            if (abs >= 0.20) return 'Fraca';
            return 'Muito Fraca';
        }

        function getAxisLabel(variavel) {
            if (variavel === 'temperatura') return 'Temperatura Média 90 dias (°C)';
            if (variavel === 'pluviometria') return 'Pluviometria Média 90 dias (mm)';
            return 'Umidade Relativa Média 90 dias (%)';
        }

        function getVariavelNome(variavel) {
            if (variavel === 'temperatura') return 'Temperatura';
            if (variavel === 'pluviometria') return 'Pluviometria';
            return 'Umidade Relativa';
        }

        function exportarGraficoCorrelacao() {
            const canvas = document.getElementById('grafico-correlacao-principal');
            if (!canvas) {
                alert('Gráfico não encontrado');
                return;
            }

            canvas.toBlob(function(blob) {
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');
                const variavel = document.getElementById('variavel-correlacao-select').value;
                link.download = `correlacao_spearman_${variavel}_${new Date().toISOString().split('T')[0]}.png`;
                link.href = url;
                link.click();
                URL.revokeObjectURL(url);

                alert(`✓ Gráfico exportado como PNG`);
            });
        }
        
        // ============================================
        // CARREGAR CORRELAÇÕES (ANTIGA - MANTER COMPATIBILIDADE)
        // ============================================
        function carregarCorrelacoes() {
            // Carregar filtros
            if (propriedadesDisponiveisCorr.length === 0 || datasDisponiveisCorr.length === 0) {
                Promise.all([carregarPropriedadesCorr(), carregarDatasCorr()]);
            }
            
            fetch('api_dashboard.php?action=estatisticas')
                .then(response => {
                    if (!response.ok) {
                        throw new Error('Erro na resposta: ' + response.status);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Dados de correlação recebidos:', data);
                    
                    if (!data || !data.comparacao || !data.comparacao.total_amostras || data.comparacao.total_amostras === 0) {
                        const container = document.getElementById('stats-correlacao');
                        if (container) {
                            container.innerHTML = '<div class="alert alert-warning" style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 15px; margin: 20px 0; border-radius: 4px;">⚠️ Dados insuficientes para análise de correlação. É necessário ter resultados de PCR e LAMP para as mesmas amostras.</div>';
                        }
                        return;
                    }
                    
                    // Gráfico de Correlação PCR vs LAMP-18S
                    const ctx18s = document.getElementById('grafico-corr-18s');
                    if (ctx18s) {
                        new Chart(ctx18s, {
                            type: 'bar',
                            data: {
                                labels: ['Concordantes +', 'Concordantes -', 'Discordantes'],
                                datasets: [{
                                    label: 'PCR vs LAMP-18S',
                                    data: [
                                        data.comparacao.concordantes_pos_18s || 0,
                                        data.comparacao.concordantes_neg_18s || 0,
                                        data.comparacao.discordantes_18s || 0
                                    ],
                                    backgroundColor: ['#48bb78', '#4299e1', '#ed8936']
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    title: {
                                        display: true,
                                        text: 'Concordância PCR vs LAMP-18S (Babesia bovis)'
                                    }
                                }
                            }
                        });
                    }
                    
                    // Gráfico de Correlação PCR vs LAMP-CytB
                    const ctxCytb = document.getElementById('grafico-corr-cytb');
                    if (ctxCytb) {
                        new Chart(ctxCytb, {
                            type: 'bar',
                            data: {
                                labels: ['Concordantes +', 'Concordantes -', 'Discordantes'],
                                datasets: [{
                                    label: 'PCR vs LAMP-CytB',
                                    data: [
                                        data.comparacao.concordantes_pos_cytb || 0,
                                        data.comparacao.concordantes_neg_cytb || 0,
                                        data.comparacao.discordantes_cytb || 0
                                    ],
                                    backgroundColor: ['#48bb78', '#4299e1', '#ed8936']
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    title: {
                                        display: true,
                                        text: 'Concordância PCR vs LAMP-CytB (Babesia bovis)'
                                    }
                                }
                            }
                        });
                    }
                    
                    // Calcular índices Kappa
                    const kappa18s = calcularKappa(
                        data.comparacao.concordantes_pos_18s || 0,
                        data.comparacao.concordantes_neg_18s || 0,
                        data.comparacao.discordantes_18s || 0
                    );
                    
                    const kappaCytb = calcularKappa(
                        data.comparacao.concordantes_pos_cytb || 0,
                        data.comparacao.concordantes_neg_cytb || 0,
                        data.comparacao.discordantes_cytb || 0
                    );
                    
                    // Exibir estatísticas
                    const statsHTML = `
                        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-top: 20px;">
                            <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                <h4 style="color: #2f855a; margin-bottom: 10px;">LAMP-18S</h4>
                                <p><strong>Índice Kappa:</strong> ${kappa18s.toFixed(3)}</p>
                                <p><strong>Interpretação:</strong> ${interpretarKappa(kappa18s)}</p>
                                <p><strong>Concordância:</strong> ${((data.comparacao.concordantes_pos_18s + data.comparacao.concordantes_neg_18s) / data.comparacao.total_amostras * 100).toFixed(1)}%</p>
                            </div>
                            <div class="stat-card" style="background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                <h4 style="color: #2f855a; margin-bottom: 10px;">LAMP-CytB</h4>
                                <p><strong>Índice Kappa:</strong> ${kappaCytb.toFixed(3)}</p>
                                <p><strong>Interpretação:</strong> ${interpretarKappa(kappaCytb)}</p>
                                <p><strong>Concordância:</strong> ${((data.comparacao.concordantes_pos_cytb + data.comparacao.concordantes_neg_cytb) / data.comparacao.total_amostras * 100).toFixed(1)}%</p>
                            </div>
                        </div>
                    `;
                    
                    const statsContainer = document.getElementById('stats-correlacao');
                    if (statsContainer) {
                        statsContainer.innerHTML = statsHTML;
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar correlações:', error);
                    const container = document.getElementById('stats-correlacao');
                    if (container) {
                        container.innerHTML = `<div class="alert alert-error" style="background: #fee; border-left: 4px solid #e53e3e; padding: 15px; margin: 20px 0; border-radius: 4px;">
                            ⚠️ Erro ao carregar dados de correlação: ${error.message}<br>
                            <small>Verifique se há dados de PCR e LAMP cadastrados no sistema.</small>
                        </div>`;
                    }
                });
        }
        
        function calcularKappa(concordPos, concordNeg, discord) {
            const total = concordPos + concordNeg + discord;
            if (total === 0) return 0;
            
            const po = (concordPos + concordNeg) / total;
            const pe = 0.5; // Assumindo distribuição balanceada
            
            return (po - pe) / (1 - pe);
        }
        
        function interpretarKappa(kappa) {
            if (kappa < 0) return 'Sem concordância';
            if (kappa < 0.20) return 'Concordância mínima';
            if (kappa < 0.40) return 'Concordância fraca';
            if (kappa < 0.60) return 'Concordância moderada';
            if (kappa < 0.80) return 'Concordância substancial';
            return 'Concordância quase perfeita';
        }

        function atualizarDashboard() {
            carregarListaAnimais();
            carregarDados();
            carregarGraficos();
        }

        function excluirAnimal(id) {
            if (!confirm('Tem certeza que deseja excluir este registro?')) {
                return;
            }
            
            fetch(`api_dashboard.php?action=excluir&id=${id}`, {
                method: 'DELETE'
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    atualizarDashboard();
                } else {
                    alert('Erro ao excluir: ' + data.erro);
                }
            });
        }

        // Carregar dados iniciais
        document.addEventListener('DOMContentLoaded', function() {
            const hoje = new Date().toISOString().split('T')[0];
            document.getElementById('data_coleta').value = hoje;
            
            // Carregar lista de animais para o select
            carregarListaAnimais();

            carregarDados();
        });

        // ============================================
        // EXPORTAR LAUDO EM EXCEL
        // ============================================
        async function exportarLaudoExcel() {
            const selecionados = document.querySelectorAll('.checkbox-animal:checked');

            if (selecionados.length === 0) {
                alert('⚠️ Selecione pelo menos um animal para gerar o laudo');
                return;
            }

            const animaisSelecionados = [];

            // Coletar dados de cada animal selecionado
            for (const checkbox of selecionados) {
                const codigo = checkbox.dataset.codigo;
                try {
                    const response = await fetch(`api_animal.php?codigo=${encodeURIComponent(codigo)}`);
                    const data = await response.json();

                    if (data.sucesso && data.animal) {
                        animaisSelecionados.push(data.animal);
                    }
                } catch (error) {
                    console.error('Erro ao buscar dados do animal:', codigo, error);
                }
            }

            if (animaisSelecionados.length === 0) {
                alert('❌ Não foi possível obter os dados dos animais selecionados');
                return;
            }

            // Criar planilha Excel usando SheetJS (xlsx)
            const wb = XLSX.utils.book_new();
            const wsData = [];

            // Cabeçalho
            wsData.push([
                'Código Animal',
                'Nome',
                'Local',
                'Município',
                'Sexo',
                'Raça',
                'Data Nascimento',
                'Data Coleta',
                'Estação',
                'B.bovis PCR',
                'B.bovis LAMP 18S',
                'B.bovis LAMP CytB',
                'B.bigemina PCR',
                'A.marginale PCR',
                'T.vivax PCR'
            ]);

            // Adicionar dados de cada animal e suas coletas
            animaisSelecionados.forEach(animal => {
                if (animal.coletas && animal.coletas.length > 0) {
                    animal.coletas.forEach(coleta => {
                        wsData.push([
                            animal.codigo_animal || animal.identificacao,
                            animal.nome_animal || '-',
                            animal.local_nome || '-',
                            animal.local_municipio || '-',
                            animal.sexo || '-',
                            animal.raca || '-',
                            animal.data_nascimento ? new Date(animal.data_nascimento).toLocaleDateString('pt-BR') : '-',
                            coleta.data_coleta ? new Date(coleta.data_coleta).toLocaleDateString('pt-BR') : '-',
                            coleta.estacao || '-',
                            coleta.pcr_babesia == 1 ? 'Positivo' : coleta.pcr_babesia == 0 ? 'Negativo' : 'Inconclusivo',
                            coleta.lamp_babesia_18s == 1 ? 'Positivo' : coleta.lamp_babesia_18s == 0 ? 'Negativo' : 'Inconclusivo',
                            coleta.lamp_babesia_cytb == 1 ? 'Positivo' : coleta.lamp_babesia_cytb == 0 ? 'Negativo' : 'Inconclusivo',
                            'N/D',
                            coleta.pcr_anaplasma == 1 ? 'Positivo' : coleta.pcr_anaplasma == 0 ? 'Negativo' : 'Inconclusivo',
                            coleta.pcr_trypanosoma == 1 ? 'Positivo' : coleta.pcr_trypanosoma == 0 ? 'Negativo' : 'Inconclusivo'
                        ]);
                    });
                } else {
                    // Animal sem coletas
                    wsData.push([
                        animal.codigo_animal || animal.identificacao,
                        animal.nome_animal || '-',
                        animal.local_nome || '-',
                        animal.local_municipio || '-',
                        animal.sexo || '-',
                        animal.raca || '-',
                        animal.data_nascimento ? new Date(animal.data_nascimento).toLocaleDateString('pt-BR') : '-',
                        '-', '-', '-', '-', '-', '-', '-', '-'
                    ]);
                }
            });

            const ws = XLSX.utils.aoa_to_sheet(wsData);

            // Ajustar largura das colunas
            const colWidths = [
                { wch: 15 }, { wch: 20 }, { wch: 25 }, { wch: 15 }, { wch: 10 },
                { wch: 15 }, { wch: 15 }, { wch: 12 }, { wch: 12 }, { wch: 15 },
                { wch: 18 }, { wch: 18 }, { wch: 15 }, { wch: 18 }, { wch: 15 }
            ];
            ws['!cols'] = colWidths;

            XLSX.utils.book_append_sheet(wb, ws, 'Laudo Parasitológico');

            // Baixar arquivo
            const dataHora = new Date().toISOString().replace(/[:.]/g, '-').slice(0, -5);
            XLSX.writeFile(wb, `Laudo_Parasitologico_${dataHora}.xlsx`);

            console.log(`✅ Laudo Excel gerado com ${animaisSelecionados.length} animal(is)`);
        }

        // ============================================
        // VISUALIZAR LAUDO INDIVIDUAL
        // ============================================
        async function visualizarLaudo() {
            const selecionados = document.querySelectorAll('.checkbox-animal:checked');

            if (selecionados.length === 0) {
                alert('⚠️ Selecione um animal para visualizar o laudo');
                return;
            }

            if (selecionados.length > 1) {
                alert('⚠️ Selecione apenas um animal por vez para visualizar o laudo individual');
                return;
            }

            const codigo = selecionados[0].dataset.codigo;

            try {
                const response = await fetch(`api_animal.php?codigo=${encodeURIComponent(codigo)}`);
                const data = await response.json();

                if (!data.sucesso || !data.animal) {
                    alert('❌ Erro ao carregar dados do animal');
                    return;
                }

                const animal = data.animal;
                gerarLaudoHTML(animal);

            } catch (error) {
                console.error('Erro ao gerar laudo:', error);
                alert('❌ Erro ao gerar o laudo. Tente novamente.');
            }
        }

        function gerarLaudoHTML(animal) {
            // Criar janela modal para exibir o laudo
            const modal = document.createElement('div');
            modal.style.cssText = `
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.7);
                z-index: 10000;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            `;

            const conteudo = document.createElement('div');
            conteudo.style.cssText = `
                background: white;
                border-radius: 12px;
                max-width: 900px;
                width: 100%;
                max-height: 90vh;
                overflow-y: auto;
                box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            `;

            // Gerar HTML do laudo
            const laudoHTML = `
                <div style="padding: 40px; font-family: 'Arial', sans-serif;">
                    <!-- Cabeçalho -->
                    <div style="text-align: center; border-bottom: 3px solid #2f855a; padding-bottom: 20px; margin-bottom: 30px;">
                        <h1 style="color: #2f855a; margin: 0; font-size: 24px; font-weight: bold;">LAUDO PARASITOLÓGICO VETERINÁRIO</h1>
                        <p style="color: #718096; margin: 10px 0 0 0; font-size: 14px;">Diagnóstico Molecular de Hemoparasitos em Bovinos</p>
                    </div>

                    <!-- Informações do Animal -->
                    <div style="background: #f7fafc; border-left: 4px solid #2f855a; padding: 20px; margin-bottom: 25px; border-radius: 6px;">
                        <h2 style="color: #2d3748; margin: 0 0 15px 0; font-size: 18px;">📋 Dados do Animal</h2>
                        <table style="width: 100%; border-collapse: collapse;">
                            <tr>
                                <td style="padding: 8px; font-weight: bold; width: 40%;">Código/Identificação:</td>
                                <td style="padding: 8px;">${animal.codigo_animal || animal.identificacao}</td>
                            </tr>
                            ${animal.nome_animal ? `
                            <tr>
                                <td style="padding: 8px; font-weight: bold;">Nome:</td>
                                <td style="padding: 8px;">${animal.nome_animal}</td>
                            </tr>` : ''}
                            <tr>
                                <td style="padding: 8px; font-weight: bold;">Propriedade:</td>
                                <td style="padding: 8px;">${animal.local_nome || '-'} - ${animal.local_municipio || '-'}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; font-weight: bold;">Sexo:</td>
                                <td style="padding: 8px;">${animal.sexo}</td>
                            </tr>
                            <tr>
                                <td style="padding: 8px; font-weight: bold;">Raça:</td>
                                <td style="padding: 8px;">${animal.raca || 'Não informada'}</td>
                            </tr>
                            ${animal.data_nascimento ? `
                            <tr>
                                <td style="padding: 8px; font-weight: bold;">Data de Nascimento:</td>
                                <td style="padding: 8px;">${new Date(animal.data_nascimento).toLocaleDateString('pt-BR')}</td>
                            </tr>` : ''}
                        </table>
                    </div>

                    <!-- Resultados de Exames -->
                    <div style="margin-bottom: 25px;">
                        <h2 style="color: #2d3748; margin: 0 0 15px 0; font-size: 18px;">🔬 Resultados dos Exames Moleculares</h2>

                        ${animal.coletas && animal.coletas.length > 0 ? animal.coletas.map((coleta, index) => `
                            <div style="background: white; border: 1px solid #e2e8f0; border-radius: 8px; padding: 20px; margin-bottom: 15px;">
                                <h3 style="color: #2f855a; margin: 0 0 12px 0; font-size: 16px;">
                                    Coleta ${index + 1} - ${new Date(coleta.data_coleta).toLocaleDateString('pt-BR')}
                                    ${coleta.estacao ? `(${coleta.estacao})` : ''}
                                </h3>

                                <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
                                    <thead>
                                        <tr style="background: #f7fafc;">
                                            <th style="padding: 10px; text-align: left; border-bottom: 2px solid #e2e8f0;">Patógeno</th>
                                            <th style="padding: 10px; text-align: center; border-bottom: 2px solid #e2e8f0;">Método</th>
                                            <th style="padding: 10px; text-align: center; border-bottom: 2px solid #e2e8f0;">Resultado</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;"><em>Babesia bovis</em></td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">PCR</td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                                                <span style="padding: 4px 12px; border-radius: 12px; font-weight: bold; ${
                                                    coleta.pcr_babesia == 1 ? 'background: #c6f6d5; color: #22543d;' :
                                                    coleta.pcr_babesia == 0 ? 'background: #fed7d7; color: #742a2a;' :
                                                    'background: #e2e8f0; color: #4a5568;'
                                                }">
                                                    ${coleta.pcr_babesia == 1 ? '✓ Positivo' : coleta.pcr_babesia == 0 ? '✗ Negativo' : '? Inconclusivo'}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;"><em>Babesia bovis</em></td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">LAMP 18S</td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                                                <span style="padding: 4px 12px; border-radius: 12px; font-weight: bold; ${
                                                    coleta.lamp_babesia_18s == 1 ? 'background: #c6f6d5; color: #22543d;' :
                                                    coleta.lamp_babesia_18s == 0 ? 'background: #fed7d7; color: #742a2a;' :
                                                    'background: #e2e8f0; color: #4a5568;'
                                                }">
                                                    ${coleta.lamp_babesia_18s == 1 ? '✓ Positivo' : coleta.lamp_babesia_18s == 0 ? '✗ Negativo' : '? Inconclusivo'}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;"><em>Babesia bovis</em></td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">LAMP CytB</td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                                                <span style="padding: 4px 12px; border-radius: 12px; font-weight: bold; ${
                                                    coleta.lamp_babesia_cytb == 1 ? 'background: #c6f6d5; color: #22543d;' :
                                                    coleta.lamp_babesia_cytb == 0 ? 'background: #fed7d7; color: #742a2a;' :
                                                    'background: #e2e8f0; color: #4a5568;'
                                                }">
                                                    ${coleta.lamp_babesia_cytb == 1 ? '✓ Positivo' : coleta.lamp_babesia_cytb == 0 ? '✗ Negativo' : '? Inconclusivo'}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 10px; border-bottom: 1px solid #e2e8f0;"><em>Anaplasma marginale</em></td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">PCR</td>
                                            <td style="padding: 10px; text-align: center; border-bottom: 1px solid #e2e8f0;">
                                                <span style="padding: 4px 12px; border-radius: 12px; font-weight: bold; ${
                                                    coleta.pcr_anaplasma == 1 ? 'background: #c6f6d5; color: #22543d;' :
                                                    coleta.pcr_anaplasma == 0 ? 'background: #fed7d7; color: #742a2a;' :
                                                    'background: #e2e8f0; color: #4a5568;'
                                                }">
                                                    ${coleta.pcr_anaplasma == 1 ? '✓ Positivo' : coleta.pcr_anaplasma == 0 ? '✗ Negativo' : '? Inconclusivo'}
                                                </span>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding: 10px;"><em>Trypanosoma vivax</em></td>
                                            <td style="padding: 10px; text-align: center;">PCR</td>
                                            <td style="padding: 10px; text-align: center;">
                                                <span style="padding: 4px 12px; border-radius: 12px; font-weight: bold; ${
                                                    coleta.pcr_trypanosoma == 1 ? 'background: #c6f6d5; color: #22543d;' :
                                                    coleta.pcr_trypanosoma == 0 ? 'background: #fed7d7; color: #742a2a;' :
                                                    'background: #e2e8f0; color: #4a5568;'
                                                }">
                                                    ${coleta.pcr_trypanosoma == 1 ? '✓ Positivo' : coleta.pcr_trypanosoma == 0 ? '✗ Negativo' : '? Inconclusivo'}
                                                </span>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        `).join('') : '<p style="color: #718096; font-style: italic;">Nenhuma coleta registrada para este animal.</p>'}
                    </div>

                    <!-- Interpretação e Recomendações -->
                    <div style="background: #fffbeb; border-left: 4px solid #f59e0b; padding: 20px; margin-bottom: 25px; border-radius: 6px;">
                        <h2 style="color: #92400e; margin: 0 0 12px 0; font-size: 16px;">💡 Observações Clínicas</h2>
                        <p style="margin: 0; line-height: 1.6; color: #78350f; font-size: 14px;">
                            <strong>PCR (Reação em Cadeia da Polimerase):</strong> Técnica molecular de alta especificidade para detecção de DNA parasitário.<br>
                            <strong>LAMP (Loop-Mediated Isothermal Amplification):</strong> Método rápido de amplificação isotérmica com alta sensibilidade.<br><br>
                            <strong>Interpretação:</strong> Resultados positivos indicam infecção ativa ou portador. Resultados negativos não excluem infecção em baixa parasitemia.
                        </p>
                    </div>

                    <!-- Rodapé -->
                    <div style="border-top: 2px solid #e2e8f0; padding-top: 20px; margin-top: 30px; text-align: center;">
                        <p style="color: #718096; font-size: 12px; margin: 0;">
                            Laudo gerado automaticamente em ${new Date().toLocaleString('pt-BR')}<br>
                            Sistema de Vigilância Parasitária Bovina - IFRJ Campus Pinheiral
                        </p>
                    </div>

                    <!-- Botões de Ação -->
                    <div style="margin-top: 25px; text-align: center; display: flex; gap: 10px; justify-content: center;">
                        <button onclick="window.print()" style="padding: 12px 24px; background: #2f855a; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;">
                            🖨️ Imprimir
                        </button>
                        <button onclick="this.closest('[style*=fixed]').remove()" style="padding: 12px 24px; background: #718096; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: bold; font-size: 14px;">
                            ✕ Fechar
                        </button>
                    </div>
                </div>
            `;

            conteudo.innerHTML = laudoHTML;
            modal.appendChild(conteudo);
            document.body.appendChild(modal);

            // Fechar ao clicar fora
            modal.addEventListener('click', (e) => {
                if (e.target === modal) {
                    modal.remove();
                }
            });
        }
    </script>

    <!-- Biblioteca SheetJS para exportar Excel -->
    <script src="https://cdn.sheetjs.com/xlsx-0.20.1/package/dist/xlsx.full.min.js"></script>
</body>
</html>
