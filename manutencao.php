<?php
// ============================================
// SISTEMA COMPLETO DE MANUTENÇÃO
// Testes, Análises e Backups Integrados
// ============================================

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../../templates/auth_central.php';
verificarAutenticacaoCentral();
bloquearSeSomenteLeitura(); // Bloquear acesso para usuários somente-leitura
verificarAutenticacaoCentral();
require_once 'config.php';

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manutenção do Sistema</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { 
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: url('static/img/segundo plano.jpg') center center fixed;
            background-size: cover;
            padding: 0;
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
        .container { 
            max-width: 1400px; 
            margin: 0 auto 20px auto; 
            background: white; 
            padding: 30px; 
            border-radius: 12px; 
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        h1 { 
            color: #2f855a; 
            font-size: 2em; 
            margin-bottom: 10px;
        }
        .subtitle {
            color: #718096;
            margin-bottom: 20px;
            font-size: 1em;
        }
        .tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #e2e8f0;
            flex-wrap: wrap;
        }
        .tab {
            padding: 12px 24px;
            background: #f7fafc;
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 500;
            border-radius: 8px 8px 0 0;
            transition: all 0.3s;
        }
        .tab:hover { background: #e2e8f0; }
        .tab.active { 
            background: #2f855a; 
            color: white;
            transform: translateY(-2px);
        }
        .tab-content { display: none; }
        .tab-content.active { display: block; animation: fadeIn 0.3s; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        
        h2 { 
            color: #2d3748; 
            margin: 30px 0 15px 0; 
            padding-bottom: 10px;
            border-bottom: 3px solid #2f855a;
            font-size: 1.8em;
        }
        h3 {
            color: #4a5568;
            margin: 20px 0 10px 0;
            font-size: 1.3em;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin: 20px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-radius: 8px;
            overflow: hidden;
        }
        th, td { 
            padding: 14px; 
            text-align: left; 
            border-bottom: 1px solid #e2e8f0;
        }
        th { 
            background: linear-gradient(135deg, #2f855a 0%, #276749 100%);
            color: white; 
            font-weight: 600;
            text-transform: uppercase;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        tr:hover { background: #f7fafc; }
        
        .status { 
            padding: 6px 12px; 
            border-radius: 6px; 
            font-weight: 600;
            font-size: 13px;
            display: inline-block;
        }
        .status.ok { background: #c6f6d5; color: #276749; }
        .status.empty { background: #fed7d7; color: #c53030; }
        .status.warning { background: #feebc8; color: #c05621; }
        .status.info { background: #bee3f8; color: #2c5282; }
        
        .card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin: 15px 0;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-left: 4px solid #2f855a;
        }
        .card-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .stat-number {
            font-size: 3em;
            font-weight: bold;
            margin: 10px 0;
        }
        .stat-label {
            font-size: 1.1em;
            opacity: 0.9;
        }
        
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #2f855a;
            color: white;
            text-decoration: none;
            border-radius: 6px;
            margin: 10px 5px;
            font-weight: 500;
            transition: all 0.3s;
            border: none;
            cursor: pointer;
            font-size: 15px;
        }
        .btn:hover {
            background: #276749;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        .btn-secondary { background: #718096; }
        .btn-secondary:hover { background: #4a5568; }
        .btn-danger { background: #e53e3e; }
        .btn-danger:hover { background: #c53030; }
        .btn-success:hover { background: #276749; }
        
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
                padding: 15px;
                margin: 10px;
            }
            
            .tabs {
                flex-direction: column;
            }
            
            .tab {
                width: 100%;
            }
            
            table {
                font-size: 0.85em;
            }
            
            th, td {
                padding: 8px 4px;
            }
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid;
        }
        .alert.success { background: #c6f6d5; border-color: #276749; color: #276749; }
        .alert.error { background: #fed7d7; border-color: #c53030; color: #c53030; }
        .alert.warning { background: #feebc8; border-color: #c05621; color: #c05621; }
        .alert.info { background: #bee3f8; border-color: #2c5282; color: #2c5282; }
        
        .progress-bar {
            width: 100%;
            height: 30px;
            background: #e2e8f0;
            border-radius: 15px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #2f855a 0%, #48bb78 100%);
            transition: width 0.3s;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: bold;
        }
        .backup-section {
            background: #f7fafc;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
    <div class="page-header">
        <div class="page-header-content">
            <img src="static/img/logo-ifrj.png" alt="IFRJ" class="page-header-logo" style="height:64px;width:auto;background:#fff;padding:4px 8px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.15);">
            <div class="page-header-icon">🔧</div>
            <div class="page-header-text">
                <h1>Manutenção do Sistema</h1>
                <p>Gerenciamento de tabelas, diagnóstico de integridade e ferramentas de administração</p>
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

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('visao-geral')">📊 Visão Geral</button>
            <button class="tab" onclick="showTab('estrutura')">🗄️ Estrutura</button>
            <button class="tab" onclick="showTab('dados')">📈 Dados</button>
            <button class="tab" onclick="showTab('clima')">🌤️ Clima</button>
            <button class="tab" onclick="showTab('integridade')">✅ Integridade</button>
            <button class="tab" onclick="showTab('backup')">💾 Backup</button>
            <button class="tab" onclick="showTab('funcoes')">⚙️ Funções</button>
            <button class="tab" onclick="showTab('tabelas')">📋 Tabelas</button>
            <button class="tab" onclick="showTab('ajuda')">❓ Ajuda</button>
        </div>

        <?php
        try {
            $pdo = getDBConnection();
            
            // ============================================
            // TAB 1: VISÃO GERAL
            // ============================================
            echo "<div id='visao-geral' class='tab-content active'>";
            echo "<h2>📊 Visão Geral do Sistema</h2>";
            
            echo "<div class='alert success'>";
            echo "✅ <strong>Sistema Operacional</strong><br>";
            echo "Conexão: OK | Servidor: " . $pdo->getAttribute(PDO::ATTR_SERVER_INFO);
            echo "</div>";
            
            // Cards de estatísticas principais
            echo "<div class='card-grid'>";
            
            $stats = [
                ['query' => 'SELECT COUNT(*) FROM locais', 'label' => 'Locais', 'icon' => '🏘️', 'color' => '#667eea'],
                ['query' => 'SELECT COUNT(*) FROM animais', 'label' => 'Animais', 'icon' => '🐄', 'color' => '#764ba2'],
                ['query' => 'SELECT COUNT(*) FROM coletas', 'label' => 'Coletas', 'icon' => '💉', 'color' => '#f093fb'],
                ['query' => 'SELECT COUNT(*) FROM resultados_pcr', 'label' => 'PCR', 'icon' => '🧬', 'color' => '#4facfe'],
                ['query' => 'SELECT COUNT(*) FROM resultados_lamp', 'label' => 'LAMP', 'icon' => '🔬', 'color' => '#43e97b'],
                ['query' => 'SELECT COUNT(*) FROM dados_climaticos WHERE status="processado"', 'label' => 'Clima', 'icon' => '🌤️', 'color' => '#fa709a']
            ];
            
            foreach ($stats as $stat) {
                $stmt = $pdo->query($stat['query']);
                $count = $stmt->fetchColumn();
                
                echo "<div class='stat-card' style='background: linear-gradient(135deg, {$stat['color']} 0%, {$stat['color']}dd 100%);'>";
                echo "<div style='font-size: 2em;'>{$stat['icon']}</div>";
                echo "<div class='stat-number'>" . number_format($count, 0, ',', '.') . "</div>";
                echo "<div class='stat-label'>{$stat['label']}</div>";
                echo "</div>";
            }
            
            echo "</div>";
            
            // Resumo rápido
            echo "<div class='card'>";
            echo "<h3>📋 Resumo Rápido</h3>";
            
            $stmt = $pdo->query("SELECT MAX(data_coleta) as ultima FROM coletas");
            $ultima = $stmt->fetch();
            
            $stmt = $pdo->query("SELECT COUNT(DISTINCT local_id) as total FROM animais");
            $locaisAtivos = $stmt->fetch();
            
            echo "<p><strong>Última Coleta:</strong> " . ($ultima['ultima'] ? date('d/m/Y', strtotime($ultima['ultima'])) : 'Nenhuma') . "</p>";
            echo "<p><strong>Locais Ativos:</strong> {$locaisAtivos['total']}</p>";
            echo "<p><strong>Banco de Dados:</strong> " . $pdo->query("SELECT DATABASE()")->fetchColumn() . "</p>";
            echo "</div>";
            
            echo "</div>";
            
            // ============================================
            // TAB 2: ESTRUTURA
            // ============================================
            echo "<div id='estrutura' class='tab-content'>";
            echo "<h2>🗄️ Estrutura do Banco de Dados</h2>";
            
            $tabelas = [
                'locais' => 'Locais de coleta (fazendas)',
                'animais' => 'Animais cadastrados',
                'coletas' => 'Coletas de amostras',
                'resultados_pcr' => 'Resultados PCR',
                'resultados_lamp' => 'Resultados LAMP',
                'dados_climaticos' => 'Dados climáticos',
                'animais_completos' => 'View consolidada'
            ];
            
            echo "<table>";
            echo "<thead><tr><th>Tabela</th><th>Descrição</th><th>Status</th><th>Registros</th></tr></thead>";
            echo "<tbody>";
            
            foreach ($tabelas as $tabela => $descricao) {
                $stmt = $pdo->query("SHOW TABLES LIKE '$tabela'");
                $existe = $stmt->fetch();
                
                if ($existe) {
                    $stmt = $pdo->query("SELECT COUNT(*) as total FROM `$tabela`");
                    $count = $stmt->fetch();
                    $total = $count['total'];
                    
                    $statusClass = $total > 0 ? 'ok' : 'empty';
                    $statusText = $total > 0 ? 'OK' : 'Vazia';
                    
                    echo "<tr>";
                    echo "<td><strong>$tabela</strong></td>";
                    echo "<td>$descricao</td>";
                    echo "<td><span class='status $statusClass'>$statusText</span></td>";
                    echo "<td>" . number_format($total, 0, ',', '.') . "</td>";
                    echo "</tr>";
                } else {
                    echo "<tr>";
                    echo "<td><strong>$tabela</strong></td>";
                    echo "<td>$descricao</td>";
                    echo "<td><span class='status empty'>Não existe</span></td>";
                    echo "<td>-</td>";
                    echo "</tr>";
                }
            }
            
            echo "</tbody></table>";
            echo "</div>";
            
            // ============================================
            // TAB 3: DADOS
            // ============================================
            echo "<div id='dados' class='tab-content'>";
            echo "<h2>📈 Análise de Dados</h2>";
            
            // Prevalência de parasitas
            echo "<h3>🦠 Prevalência de Parasitas</h3>";
            $stmt = $pdo->query("
                SELECT 
                    SUM(CASE WHEN babesia_bovis = 'Positivo' THEN 1 ELSE 0 END) as babesia,
                    SUM(CASE WHEN anaplasma_marginale = 'Positivo' THEN 1 ELSE 0 END) as anaplasma,
                    SUM(CASE WHEN trypanosoma_vivax = 'Positivo' THEN 1 ELSE 0 END) as trypanosoma,
                    COUNT(*) as total
                FROM resultados_pcr
            ");
            $prevalencia = $stmt->fetch();
            
            if ($prevalencia['total'] > 0) {
                $prevBabesia = ($prevalencia['babesia'] / $prevalencia['total']) * 100;
                $prevAnaplasma = ($prevalencia['anaplasma'] / $prevalencia['total']) * 100;
                $prevTrypanosoma = ($prevalencia['trypanosoma'] / $prevalencia['total']) * 100;
                
                echo "<div class='card-grid'>";
                echo "<div class='card'>";
                echo "<h4 style='color: #e74c3c;'>Babesia bovis</h4>";
                echo "<div class='progress-bar'><div class='progress-fill' style='width: {$prevBabesia}%; background: #e74c3c;'>" . round($prevBabesia, 1) . "%</div></div>";
                echo "<p>{$prevalencia['babesia']} / {$prevalencia['total']} positivos</p>";
                echo "</div>";
                
                echo "<div class='card'>";
                echo "<h4 style='color: #3498db;'>Anaplasma marginale</h4>";
                echo "<div class='progress-bar'><div class='progress-fill' style='width: {$prevAnaplasma}%; background: #3498db;'>" . round($prevAnaplasma, 1) . "%</div></div>";
                echo "<p>{$prevalencia['anaplasma']} / {$prevalencia['total']} positivos</p>";
                echo "</div>";
                
                echo "<div class='card'>";
                echo "<h4 style='color: #2ecc71;'>Trypanosoma vivax</h4>";
                echo "<div class='progress-bar'><div class='progress-fill' style='width: {$prevTrypanosoma}%; background: #2ecc71;'>" . round($prevTrypanosoma, 1) . "%</div></div>";
                echo "<p>{$prevalencia['trypanosoma']} / {$prevalencia['total']} positivos</p>";
                echo "</div>";
                echo "</div>";
            }
            
            // Últimas coletas
            echo "<h3>📅 Últimas 10 Coletas</h3>";
            $stmt = $pdo->query("
                SELECT 
                    c.id,
                    c.data_coleta,
                    a.identificacao as animal,
                    l.nome as local
                FROM coletas c
                LEFT JOIN animais a ON c.animal_id = a.id
                LEFT JOIN locais l ON a.local_id = l.id
                ORDER BY c.data_coleta DESC
                LIMIT 10
            ");
            
            echo "<table>";
            echo "<thead><tr><th>ID</th><th>Data</th><th>Animal</th><th>Local</th></tr></thead>";
            echo "<tbody>";
            
            while ($row = $stmt->fetch()) {
                echo "<tr>";
                echo "<td>{$row['id']}</td>";
                echo "<td>" . date('d/m/Y', strtotime($row['data_coleta'])) . "</td>";
                echo "<td>{$row['animal']}</td>";
                echo "<td>{$row['local']}</td>";
                echo "</tr>";
            }
            
            echo "</tbody></table>";
            echo "</div>";
            
            // ============================================
            // TAB 4: CLIMA
            // ============================================
            echo "<div id='clima' class='tab-content'>";
            echo "<h2>🌤️ Dados Climáticos</h2>";

            // Verificar se a tabela existe
            $tabelaClimaExiste = false;
            try {
                $stmt = $pdo->query("SHOW TABLES LIKE 'dados_climaticos'");
                $tabelaClimaExiste = $stmt->fetch() !== false;
            } catch (Exception $e) {
                // Tabela não existe
            }

            if (!$tabelaClimaExiste) {
                echo "<div class='alert error'>";
                echo "<h3 style='margin-top: 0;'>⚠️ Tabela 'dados_climaticos' não existe!</h3>";
                echo "<p>A tabela precisa ser criada antes de usar os dados climáticos.</p>";
                echo "<a href='migrar_dados_climaticos.php' class='btn' style='margin-top: 10px;'>🔧 Criar Tabela</a>";
                echo "</div>";
            } else {
                // Estatísticas gerais
                $stmt = $pdo->query("
                    SELECT
                        COUNT(*) as total,
                        SUM(CASE WHEN status = 'processado' THEN 1 ELSE 0 END) as processados,
                        SUM(CASE WHEN status = 'pendente' THEN 1 ELSE 0 END) as pendentes,
                        SUM(CASE WHEN status = 'erro' THEN 1 ELSE 0 END) as erros
                    FROM dados_climaticos
                ");
                $climaStats = $stmt->fetch();

                if ($climaStats['total'] > 0) {
                    $percentual = ($climaStats['processados'] / $climaStats['total']) * 100;

                    echo "<div class='card'>";
                    echo "<h3>📊 Status do Processamento</h3>";
                    echo "<div class='progress-bar'>";
                    echo "<div class='progress-fill' style='width: {$percentual}%'>" . round($percentual, 1) . "%</div>";
                    echo "</div>";
                    echo "<p><strong>Total:</strong> {$climaStats['total']} registros</p>";
                    echo "<p><strong>Processados:</strong> {$climaStats['processados']}</p>";
                    echo "<p><strong>Pendentes:</strong> {$climaStats['pendentes']}</p>";
                    echo "<p><strong>Erros:</strong> {$climaStats['erros']}</p>";
                    echo "</div>";
                } else {
                    echo "<div class='alert warning'>";
                    echo "<h3 style='margin-top: 0;'>⚠️ Nenhum dado climático encontrado!</h3>";
                    echo "<p>Execute o pré-carregamento para popular a tabela com dados históricos.</p>";
                    echo "<a href='pre_carregar_dados_climaticos.php' class='btn' style='margin-top: 10px;'>🛰️ Pré-carregar Dados NASA</a>";
                    echo "</div>";
                }

                // Teste de integridade do fluxo
                echo "<div class='card' style='border-left-color: #4299e1; background: #f7fafc;'>";
                echo "<h3 style='color: #2c5282;'>🔬 Teste de Integridade do Fluxo Climático</h3>";
                echo "<p style='color: #4a5568; margin-bottom: 15px;'>Verifica se o sistema está configurado corretamente para registrar, calcular e exibir dados climáticos.</p>";
                echo "<button onclick='testarFluxoClimatico()' class='btn' style='background: #4299e1;'>▶️ Executar Teste Completo</button>";
                echo "<div id='resultado-teste-clima' style='margin-top: 20px;'></div>";
                echo "</div>";

                // Locais com dados climáticos
                echo "<div class='card'>";
                echo "<h3>📍 Locais com Dados Climáticos</h3>";
                $stmt = $pdo->query("
                    SELECT
                        l.id,
                        l.nome,
                        l.municipio,
                        l.latitude,
                        l.longitude,
                        COUNT(dc.id) as total_registros,
                        MIN(dc.data_coleta) as primeira_data,
                        MAX(dc.data_coleta) as ultima_data
                    FROM locais l
                    LEFT JOIN dados_climaticos dc ON l.id = dc.local_id
                    WHERE dc.id IS NOT NULL
                    GROUP BY l.id, l.nome, l.municipio, l.latitude, l.longitude
                    ORDER BY total_registros DESC
                ");
                $locaisComClima = $stmt->fetchAll();

                if (count($locaisComClima) > 0) {
                    echo "<table style='margin-top: 15px;'>";
                    echo "<thead><tr><th>Local</th><th>Município</th><th>Coordenadas</th><th>Registros</th><th>Período</th></tr></thead>";
                    echo "<tbody>";
                    foreach ($locaisComClima as $local) {
                        echo "<tr>";
                        echo "<td><strong>{$local['nome']}</strong></td>";
                        echo "<td>{$local['municipio']}</td>";
                        echo "<td><small>Lat: {$local['latitude']}<br>Lng: {$local['longitude']}</small></td>";
                        echo "<td><span class='status ok'>{$local['total_registros']}</span></td>";
                        echo "<td><small>" . date('d/m/Y', strtotime($local['primeira_data'])) . " até<br>" . date('d/m/Y', strtotime($local['ultima_data'])) . "</small></td>";
                        echo "</tr>";
                    }
                    echo "</tbody></table>";
                } else {
                    echo "<p style='color: #e53e3e; margin-top: 10px;'>❌ Nenhum local possui dados climáticos registrados.</p>";
                }
                echo "</div>";

                // Ações disponíveis
                echo "<div class='card'>";
                echo "<h3>⚙️ Ações Disponíveis</h3>";
                echo "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;'>";
                echo "<a href='pre_carregar_dados_climaticos.php' class='btn'>🛰️ Pré-carregar Dados NASA</a>";
                echo "<a href='forms.php#aba-clima' class='btn btn-secondary'>➕ Registrar Novo Dado Climático</a>";
                echo "<a href='dashboard.php#correlacoes' class='btn btn-secondary'>📊 Ver Correlações Climáticas</a>";
                echo "</div>";
                echo "</div>";
            }

            echo "</div>";
            
            // ============================================
            // TAB 5: INTEGRIDADE
            // ============================================
            echo "<div id='integridade' class='tab-content'>";
            echo "<h2>✅ Testes de Integridade</h2>";
            
            $testes = [];
            
            // Teste 1: Coletas sem resultados
            $stmt = $pdo->query("
                SELECT COUNT(*) as total
                FROM coletas c
                LEFT JOIN resultados_pcr rp ON c.id = rp.coleta_id
                LEFT JOIN resultados_lamp rl ON c.id = rl.coleta_id
                WHERE rp.id IS NULL AND rl.id IS NULL
            ");
            $coletasSemResultados = $stmt->fetch()['total'];
            $testes[] = [
                'nome' => 'Coletas sem resultados',
                'resultado' => $coletasSemResultados,
                'status' => $coletasSemResultados == 0 ? 'ok' : 'warning',
                'mensagem' => $coletasSemResultados == 0 ? 'Todas as coletas têm resultados' : "$coletasSemResultados coletas sem resultados"
            ];
            
            // Teste 2: Animais sem local
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM animais WHERE local_id IS NULL");
            $animaisSemLocal = $stmt->fetch()['total'];
            $testes[] = [
                'nome' => 'Animais sem local',
                'resultado' => $animaisSemLocal,
                'status' => $animaisSemLocal == 0 ? 'ok' : 'warning',
                'mensagem' => $animaisSemLocal == 0 ? 'Todos os animais têm local' : "$animaisSemLocal animais sem local"
            ];
            
            // Teste 3: Locais sem coordenadas
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM locais WHERE latitude IS NULL OR longitude IS NULL");
            $locaisSemCoordenadas = $stmt->fetch()['total'];
            $testes[] = [
                'nome' => 'Locais sem coordenadas',
                'resultado' => $locaisSemCoordenadas,
                'status' => $locaisSemCoordenadas == 0 ? 'ok' : 'warning',
                'mensagem' => $locaisSemCoordenadas == 0 ? 'Todos os locais têm coordenadas' : "$locaisSemCoordenadas locais sem coordenadas (necessário para dados climáticos)"
            ];
            
            echo "<table>";
            echo "<thead><tr><th>Teste</th><th>Resultado</th><th>Status</th><th>Mensagem</th></tr></thead>";
            echo "<tbody>";
            
            foreach ($testes as $teste) {
                echo "<tr>";
                echo "<td><strong>{$teste['nome']}</strong></td>";
                echo "<td>{$teste['resultado']}</td>";
                echo "<td><span class='status {$teste['status']}'>" . strtoupper($teste['status']) . "</span></td>";
                echo "<td>{$teste['mensagem']}</td>";
                echo "</tr>";
            }
            
            echo "</tbody></table>";
            echo "</div>";
            
            // ============================================
            // TAB 6: BACKUP
            // ============================================
            echo "<div id='backup' class='tab-content'>";
            echo "<h2>💾 Backup e Exportação</h2>";
            
            echo "<div class='backup-section'>";
            echo "<h3>📦 Backup Completo do Sistema</h3>";
            echo "<p>Exporta todas as tabelas e dados em formato SQL</p>";
            echo "<a href='export_backup.php?format=sql' class='btn' download>⬇️ Download Backup SQL</a>";
            echo "</div>";
            
            echo "<div class='backup-section'>";
            echo "<h3>📊 Exportar Tabelas Individuais (CSV)</h3>";
            echo "<p>Exporta dados de tabelas específicas em formato CSV para Excel</p>";
            echo "<div style='display: flex; flex-wrap: wrap; gap: 10px; margin-top: 15px;'>";
            
            foreach ($tabelas as $tabela => $descricao) {
                echo "<a href='export_backup.php?format=csv&table=$tabela' class='btn btn-secondary' download>📄 $tabela</a>";
            }
            
            echo "</div>";
            echo "</div>";
            
            echo "<div class='backup-section'>";
            echo "<h3>📖 Dicionário de Dados</h3>";
            echo "<p>Documentação completa da estrutura do banco de dados</p>";
            echo "<a href='export_backup.php?format=dictionary' class='btn' download>📚 Download Dicionário</a>";
            echo "</div>";
            
            echo "<div class='alert info'>";
            echo "<strong>💡 Recomendações:</strong><br>";
            echo "• Faça backups regulares (semanalmente)<br>";
            echo "• Armazene backups em local seguro<br>";
            echo "• Teste a restauração periodicamente<br>";
            echo "• Mantenha pelo menos 3 backups recentes";
            echo "</div>";

            // ============================================
            // BLOCO AVANÇADO: SYNC + GIT (PROTEGIDO POR SENHA)
            // Disponível apenas em ambiente DEV (Docker no PC do Luiz)
            // Senha vem de config_local.php, NUNCA hard-coded aqui.
            // ============================================
            $isDev = function_exists('isDevAmbiente') && isDevAmbiente();
            if ($isDev) {
                echo "<hr style='margin:30px 0;border:none;border-top:2px dashed #ffc107;'>";
                echo "<h2 style='color:#b8860b;'>🔒 Operações DEV avançadas</h2>";
                echo "<div class='alert warning' style='background:#fff3cd;padding:12px;border-left:4px solid #ffc107;'>";
                echo "<b>Atenção:</b> esta seção só aparece quando <code>TPB_AMBIENTE=desenvolvimento</code>. ";
                echo "Em produção (Hostinger) ela permanece oculta. As ações chamam scripts <code>.bat</code> ";
                echo "no host — funcionam apenas se o usuário do servidor PHP tem permissão de execução.";
                echo "</div>";

                // Senha em sessão
                $senhaConfigurada = function_exists('getSenhaManutencaoDev') ? getSenhaManutencaoDev() : null;
                $autenticado = isset($_SESSION['manut_dev_ok']) && $_SESSION['manut_dev_ok'] === true;

                // Tratamento POST do formulário de autenticação
                if (!$autenticado && isset($_POST['manut_dev_pwd']) && $senhaConfigurada) {
                    if (password_verify($_POST['manut_dev_pwd'], $senhaConfigurada)) {
                        $_SESSION['manut_dev_ok'] = true;
                        $autenticado = true;
                    } else {
                        echo "<div class='alert' style='background:#f8d7da;color:#721c24;padding:10px;border-radius:4px;'>Senha incorreta.</div>";
                    }
                }

                if (!$senhaConfigurada) {
                    echo "<div class='alert info'>";
                    echo "Senha de manutenção DEV não configurada. Defina <code>getSenhaManutencaoDev()</code> em <code>config_local.php</code> retornando <code>password_hash('SUA_SENHA', PASSWORD_DEFAULT)</code>.";
                    echo "</div>";
                } elseif (!$autenticado) {
                    echo "<form method='post' style='background:#f8f9fa;padding:20px;border-radius:8px;max-width:420px;'>";
                    echo "<label><b>Senha DEV para liberar operações</b></label><br>";
                    echo "<input type='password' name='manut_dev_pwd' style='padding:8px;width:100%;margin:10px 0;border:1px solid #ccc;border-radius:4px;' required autofocus>";
                    echo "<button type='submit' class='btn' style='background:#1F4E78;color:white;'>🔓 Desbloquear</button>";
                    echo "</form>";
                } else {
                    // ============= AÇÕES PROTEGIDAS =============
                    echo "<p style='color:#28a745;'><b>✓ Sessão DEV autenticada.</b>
                        <a href='?logout=manut_dev' style='font-size:.9em;'>(sair)</a></p>";

                    // Sync Hostinger
                    echo "<div class='backup-section' style='background:#e7f1fa;padding:15px;border-radius:8px;margin:15px 0;'>";
                    echo "<h3>☁️ Sincronização com Hostinger</h3>";
                    echo "<p>Duas formas de obter o backup SQL atualizado da produção:</p>";

                    echo "<div style='display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin:10px 0;'>";
                    echo "<a href='sync_endpoint.php?action=ping_hostinger' target='_blank' class='btn btn-secondary'>📡 Testar conexão</a>";
                    echo "<a href='sync_endpoint.php?action=download_sql' target='_blank' class='btn'>⬇️ Baixar backup SQL automatizado</a>";
                    echo "<a href='manutencao.php?dev_action=manual_help#backup' class='btn' style='background:#198754;color:white;'>📥 Baixar backup SQL manualmente</a>";
                    echo "<a href='sync_endpoint.php?action=list_backups' target='_blank' class='btn btn-secondary'>📋 Listar backups</a>";
                    echo "</div>";

                    echo "<p style='font-size:.9em;color:#666;margin-top:8px;'><b>Automatizado:</b> requer <code>sync_hostinger.env</code> preenchido e IP liberado no hPanel > Bancos > Hosts MySQL Remotos. <b>Manual:</b> sempre funciona, sem dependências.</p>";

                    // Mostrar instrucoes do modo manual quando solicitado
                    if (($_GET['dev_action'] ?? '') === 'manual_help') {
                        echo '<div id="manual_help" style="background:white;border:2px solid #198754;border-radius:8px;padding:18px;margin-top:14px;">';
                        echo '<h4 style="margin-top:0;color:#198754;">📥 Como baixar o backup SQL manualmente</h4>';
                        echo '<p>Use este modo quando a conexão automatizada não estiver disponível (IP não liberado, plano sem MySQL externo, etc).</p>';
                        echo '<ol style="line-height:1.9;font-size:1em;">';
                        echo '<li>Acesse <a href="https://auth-db887.hstgr.io" target="_blank"><b>https://auth-db887.hstgr.io</b></a> (phpMyAdmin do Hostinger).</li>';
                        echo '<li>Selecione o banco <code>u112348440_ifrj_crj_lgm</code>.</li>';
                        echo '<li>Aba <b>Exportar</b> → método <b>Rápido</b> → formato <b>SQL</b> → <b>Executar</b>.</li>';
                        echo '<li>Salve o arquivo na pasta do projeto, substituindo o atual.</li>';
                        echo '<li>Renomeie para <code>u112348440_ifrj_crj_lgm.sql</code> (sem timestamp).</li>';
                        echo '<li>No PowerShell, dentro da pasta do projeto, rode:';
                        echo '<pre style="background:#212529;color:#0f0;padding:14px;border-radius:6px;font-family:Consolas,monospace;font-size:.9em;margin:8px 0;">';
                        echo "docker compose down -v\n";
                        echo "docker compose up -d --build\n";
                        echo "aplicar_migracoes.bat";
                        echo '</pre>';
                        echo '</li>';
                        echo '</ol>';
                        echo '<p style="font-size:.85em;color:#555;margin-top:12px;"><b>Por que <code>down -v</code>?</b> Apaga o volume do banco local para forçar nova importação do dump fresco. Não afeta produção.</p>';
                        echo '<p style="margin-bottom:0;"><a href="manutencao.php#backup" style="color:#666;">← Voltar</a></p>';
                        echo '</div>';
                    }
                    echo "</div>";

                    // BLOCO ESPELHAMENTO PRODUCAO (cuidadoso)
                    echo "<div class='backup-section' style='background:#f8d7da;padding:15px;border-radius:8px;margin:15px 0;border:2px solid #dc3545;'>";
                    echo "<h3 style='color:#842029;'>🔄 Espelhar limpeza no Hostinger (PRODUÇÃO)</h3>";
                    echo "<p><b>Aplica as migrações sql/00 e sql/02 diretamente no banco de produção.</b> ";
                    echo "Apaga Fazenda Sorriso, padroniza raças para Girolando, cria a view científica. ";
                    echo "Faz <b>backup automático antes</b> em <code>backups_sql/preapply_*.sql</code>.</p>";
                    echo "<p style='color:#842029;font-size:.95em;'><b>⚠️ Procedimento de segurança em 2 passos:</b><br>";
                    echo "<b>1)</b> Clique em <b>PREVIEW</b> — abre uma aba mostrando o que será mudado e gera um <b>token único</b>.<br>";
                    echo "<b>2)</b> Copie o token gerado (16 caracteres hexadecimais), cole no campo abaixo e clique em <b>EXECUTAR MUDANÇAS</b>.<br>";
                    echo "O token só vale para a sua sessão atual e é descartado após uso (uso único).</p>";

                    echo "<div style='display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:12px;'>";
                    echo "<a href='sync_hostinger_apply.php?action=preview' target='_blank' class='btn' style='background:#6c757d;color:white;'>👁️ PREVIEW (revisar mudanças)</a>";

                    echo "<form method='get' action='sync_hostinger_apply.php' target='_blank' style='display:inline-flex;gap:8px;align-items:center;'>";
                    echo "<input type='hidden' name='action' value='apply'>";
                    echo "<input type='text' name='token' placeholder='cole aqui o token (ex: fd1fe9cc63582ee5)' "
                       . "pattern='[a-f0-9]{16}' maxlength='16' required "
                       . "style='padding:8px 10px;border:2px solid #dc3545;border-radius:4px;font-family:Consolas,monospace;width:280px;font-size:.9em;'>";
                    echo "<button type='submit' class='btn' style='background:#dc3545;color:white;'>⚡ EXECUTAR MUDANÇAS</button>";
                    echo "</form>";
                    echo "</div>";
                    echo "</div>";

                    // ===== CARGA CLIMA NASA POWER =====
                    echo "<div class='backup-section' style='background:#cfe2ff;padding:15px;border-radius:8px;margin:15px 0;border:2px solid #0d6efd;'>";
                    echo "<h3 style='color:#084298;'>🌦️ Carga de dados climáticos (NASA POWER)</h3>";
                    echo "<p>Para cada coleta sem dados climáticos, busca <b>média de 90 dias antes da data de coleta</b> na NASA POWER (T2M, PRECTOTCORR, RH2M) e grava em <code>dados_climaticos</code>. Pode levar alguns minutos (250ms throttle entre requests).</p>";
                    echo "<div style='display:flex;gap:10px;flex-wrap:wrap;'>";
                    echo "<a href='api_clima_carga.php?action=resumo' target='_blank' class='btn btn-secondary'>📊 Resumo cobertura</a>";
                    echo "<a href='api_clima_carga.php?action=preview' target='_blank' class='btn btn-secondary'>👁️ Preview pendentes</a>";
                    echo "<a href='api_clima_carga.php?action=carregar' target='_blank' class='btn' style='background:#0d6efd;color:white;'>⬇️ Carregar 90d clima na NASA POWER</a>";
                    echo "</div>";
                    echo "</div>";

                    echo "<div class='backup-section' style='background:#fff3cd;padding:15px;border-radius:8px;margin:15px 0;'>";
                    echo "<h3>⬆️ Upload de PHP para Hostinger</h3>";
                    echo "<p>O upload de PHP atualizados para o Hostinger é feito pelo PowerShell do seu Windows (não pelo container Linux). Use:</p>";
                    echo "<pre style='background:#f1f1f1;padding:10px;border-radius:4px;'>sincronizar_hostinger.bat upload</pre>";
                    echo "<p>Veja a aba <strong>❓ Ajuda → Sincronização com Hostinger</strong> para configurar credenciais SFTP.</p>";
                    echo "</div>";

                    // Sync GitHub — instrucoes (rodar no Windows)
                    echo "<div class='backup-section' style='background:#e7f1fa;padding:15px;border-radius:8px;margin:15px 0;'>";
                    echo "<h3>🐙 Sincronização com GitHub</h3>";
                    echo "<p>Push para <a href='https://github.com/luizdione/app_tpb' target='_blank'>luizdione/app_tpb</a> é feito do PowerShell do Windows (git roda no host, não no container Linux).</p>";
                    echo "<pre style='background:#f1f1f1;padding:10px;border-radius:4px;'>sincronizar_github.bat \"sua mensagem de commit\"</pre>";
                    echo "<p>O script copia arquivos sanitizados para <code>github/</code>, valida que não há credenciais residuais, e faz commit + push.</p>";
                    echo "</div>";
                }
            }

            echo "</div>";

            // Logout DEV (limpa sessao)
            if (isset($_GET['logout']) && $_GET['logout'] === 'manut_dev') {
                unset($_SESSION['manut_dev_ok']);
                header('Location: manutencao.php#backup');
                exit;
            }

            // ============================================
            // TAB 7: FUNÇÕES DO SISTEMA
            // ============================================
            echo "<div id='funcoes' class='tab-content'>";
            echo "<h2>⚙️ Funções do Sistema (config.php)</h2>";
            
            echo "<div class='alert info'>";
            echo "<strong>📚 Sobre:</strong> Esta seção lista todas as funções disponíveis no arquivo <code>config.php</code> que são utilizadas em todo o sistema.";
            echo "</div>";
            
            // Testar se as funções existem
            $funcoes = [
                'getDBConnection' => 'Estabelece conexão com o banco de dados MySQL usando PDO. Retorna objeto PDO configurado com modo de erro em exceção.',
                'setSecurityHeaders' => 'Define cabeçalhos de segurança HTTP para proteger contra XSS, clickjacking e outros ataques. Inclui Content-Security-Policy.',
                'validarMetodo' => 'Valida se a requisição HTTP usa o método esperado (GET, POST, etc). Lança exceção se o método for inválido.',
                'jsonResponse' => 'Envia resposta JSON padronizada para APIs. Define Content-Type correto e código HTTP. Usado em todas as APIs do sistema.',
                'logErro' => 'Registra erros no arquivo de log (logs/erros.log). Inclui timestamp, mensagem e contexto. Essencial para debug.',
                'sanitizeInput' => 'Remove tags HTML e caracteres especiais de entrada do usuário. Previne XSS e SQL injection.',
                'validarData' => 'Valida formato de data (YYYY-MM-DD). Verifica se a data é válida no calendário. Retorna boolean.',
                'determinarEstacao' => 'Determina a estação do ano (Verão, Outono, Inverno, Primavera) com base em uma data fornecida.',
                'formatarCoordenada' => 'Formata coordenadas geográficas (latitude/longitude) para formato decimal padronizado.',
                'isAjax' => 'Verifica se a requisição é AJAX através do cabeçalho X-Requested-With. Útil para diferenciar requisições.'
            ];
            
            echo "<table>";
            echo "<thead><tr><th style='width: 25%;'>Função</th><th>Descrição e Uso</th><th style='width: 15%;'>Status</th></tr></thead>";
            echo "<tbody>";
            
            foreach ($funcoes as $funcao => $descricao) {
                $existe = function_exists($funcao);
                $statusClass = $existe ? 'ok' : 'empty';
                $statusText = $existe ? '✅ Disponível' : '❌ Não encontrada';
                
                echo "<tr>";
                echo "<td><strong><code>$funcao()</code></strong></td>";
                echo "<td style='font-size: 13px; line-height: 1.6;'>$descricao</td>";
                echo "<td><span class='status $statusClass'>$statusText</span></td>";
                echo "</tr>";
            }
            
            echo "</tbody></table>";
            
            // Teste de conexão com banco
            echo "<div class='card'>";
            echo "<h3>🔌 Teste de Conexão com Banco</h3>";
            try {
                $testPdo = getDBConnection();
                echo "<div class='alert success'>";
                echo "✅ <strong>Conexão estabelecida com sucesso!</strong><br>";
                echo "Banco: <code>" . $testPdo->query("SELECT DATABASE()")->fetchColumn() . "</code><br>";
                echo "Servidor: " . $testPdo->getAttribute(PDO::ATTR_SERVER_INFO);
                echo "</div>";
                
                // Testar uma query simples
                $stmt = $testPdo->query("SELECT 1 as test");
                $result = $stmt->fetch();
                if ($result['test'] == 1) {
                    echo "<p style='color: #2f855a;'>✅ Query de teste executada com sucesso!</p>";
                }
            } catch (Exception $e) {
                echo "<div class='alert error'>";
                echo "❌ <strong>Erro ao conectar:</strong> " . htmlspecialchars($e->getMessage());
                echo "</div>";
            }
            echo "</div>";
            
            echo "</div>";
            
            // ============================================
            // TAB 8: ESTRUTURA DE TABELAS
            // ============================================
            echo "<div id='tabelas' class='tab-content'>";
            echo "<h2>📋 Estrutura Detalhada das Tabelas</h2>";
            
            // Botão de Autodiagnóstico no topo
            echo "<div style='background: #f7fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; border: 2px solid #4299e1;'>";
            echo "<h3 style='color: #2d3748; margin-top: 0;'>🔍 Autodiagnóstico do Sistema</h3>";
            echo "<p style='color: #4a5568; margin-bottom: 15px;'>Verifica erros de sintaxe, integridade de colunas, IDs duplicados e outros problemas nas tabelas do projeto.</p>";
            echo "<button onclick='executarAutodiagnostico()' style='padding: 12px 24px; background: #4299e1; color: white; border: none; border-radius: 6px; cursor: pointer; font-weight: 600; font-size: 15px; transition: all 0.3s;' onmouseover='this.style.background=\"#3182ce\"' onmouseout='this.style.background=\"#4299e1\"'>🔍 Executar Autodiagnóstico</button>";
            echo "<div id='resultado-diagnostico' style='margin-top: 20px;'></div>";
            echo "</div>";
            
            echo "<div class='alert info'>";
            echo "<strong>📚 Sobre:</strong> Esta seção mostra a estrutura completa de cada tabela do banco de dados, incluindo colunas, tipos de dados e chaves.";
            echo "</div>";
            
            // LEGENDA DOS CABEÇALHOS
            echo "<div class='card' style='background: #f7fafc; border-left-color: #4299e1;'>";
            echo "<h3 style='color: #2c5282; margin-top: 0;'>📖 Legenda dos Cabeçalhos das Tabelas</h3>";
            echo "<div style='display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px; margin-top: 15px;'>";
            
            echo "<div style='background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #4299e1;'>";
            echo "<strong style='color: #2c5282;'>📌 Coluna</strong>";
            echo "<p style='margin: 5px 0 0 0; font-size: 13px; color: #4a5568;'>Nome do campo na tabela. Identifica cada dado armazenado.</p>";
            echo "</div>";
            
            echo "<div style='background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #805ad5;'>";
            echo "<strong style='color: #553c9a;'>🔤 Tipo</strong>";
            echo "<p style='margin: 5px 0 0 0; font-size: 13px; color: #4a5568;'>Tipo de dado que a coluna aceita (texto, número, data, etc).</p>";
            echo "</div>";
            
            echo "<div style='background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #ed8936;'>";
            echo "<strong style='color: #c05621;'>❓ Nulo</strong>";
            echo "<p style='margin: 5px 0 0 0; font-size: 13px; color: #4a5568;'>Se a coluna aceita valores vazios (NULL). ✅ = Aceita | ❌ = Obrigatório.</p>";
            echo "</div>";
            
            echo "<div style='background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #48bb78;'>";
            echo "<strong style='color: #276749;'>🔑 Chave</strong>";
            echo "<p style='margin: 5px 0 0 0; font-size: 13px; color: #4a5568;'>Tipo de índice/chave da coluna (PRI, UNI, MUL).</p>";
            echo "</div>";
            
            echo "<div style='background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #667eea;'>";
            echo "<strong style='color: #5a67d8;'>⚙️ Padrão</strong>";
            echo "<p style='margin: 5px 0 0 0; font-size: 13px; color: #4a5568;'>Valor padrão inserido automaticamente se nenhum for fornecido.</p>";
            echo "</div>";
            
            echo "<div style='background: white; padding: 12px; border-radius: 6px; border-left: 3px solid #f56565;'>";
            echo "<strong style='color: #c53030;'>⚡ Extra</strong>";
            echo "<p style='margin: 5px 0 0 0; font-size: 13px; color: #4a5568;'>Propriedades especiais da coluna (auto_increment, etc).</p>";
            echo "</div>";
            
            echo "</div>";
            echo "</div>";
            
            // GLOSSÁRIO COMPLETO
            echo "<div class='card' style='background: #fffaf0; border-left-color: #ed8936;'>";
            echo "<h3 style='color: #c05621; margin-top: 0;'>📚 Glossário de Tipos de Dados e Definições</h3>";
            
            echo "<div style='margin-top: 15px;'>";
            echo "<h4 style='color: #2d3748; margin-bottom: 10px;'>🔤 Tipos de Dados Textuais</h4>";
            echo "<table style='font-size: 13px;'>";
            echo "<thead><tr><th style='width: 25%;'>Tipo</th><th>Descrição</th><th style='width: 30%;'>Exemplo de Uso</th></tr></thead>";
            echo "<tbody>";
            echo "<tr><td><code>VARCHAR(n)</code></td><td>Texto variável até <strong>n</strong> caracteres. Mais eficiente que CHAR.</td><td>VARCHAR(100) para nomes, VARCHAR(50) para códigos</td></tr>";
            echo "<tr><td><code>CHAR(n)</code></td><td>Texto fixo de <strong>n</strong> caracteres. Preenche com espaços.</td><td>CHAR(2) para siglas (UF, BR)</td></tr>";
            echo "<tr><td><code>TEXT</code></td><td>Texto longo até 65.535 caracteres. Para descrições.</td><td>Observações, comentários extensos</td></tr>";
            echo "<tr><td><code>ENUM('A','B')</code></td><td>Lista de valores permitidos. Aceita apenas os valores listados.</td><td>ENUM('Macho','Fêmea'), ENUM('Positivo','Negativo','Inconclusivo')</td></tr>";
            echo "</tbody></table>";
            echo "</div>";
            
            echo "<div style='margin-top: 20px;'>";
            echo "<h4 style='color: #2d3748; margin-bottom: 10px;'>🔢 Tipos de Dados Numéricos</h4>";
            echo "<table style='font-size: 13px;'>";
            echo "<thead><tr><th style='width: 25%;'>Tipo</th><th>Descrição</th><th style='width: 30%;'>Exemplo de Uso</th></tr></thead>";
            echo "<tbody>";
            echo "<tr><td><code>INT(n)</code></td><td>Número inteiro. <strong>n</strong> é apenas visual (display width).</td><td>INT(11) para IDs, contadores</td></tr>";
            echo "<tr><td><code>TINYINT(1)</code></td><td>Número pequeno (-128 a 127). Usado como boolean (0/1).</td><td>TINYINT(1) para flags: ativo/inativo, sim/não</td></tr>";
            echo "<tr><td><code>DECIMAL(m,d)</code></td><td>Número decimal preciso. <strong>m</strong>=total de dígitos, <strong>d</strong>=decimais.</td><td>DECIMAL(6,2) para valores monetários: 9999.99</td></tr>";
            echo "<tr><td><code>FLOAT/DOUBLE</code></td><td>Número decimal aproximado. Mais rápido mas menos preciso.</td><td>Coordenadas geográficas, medições científicas</td></tr>";
            echo "</tbody></table>";
            echo "</div>";
            
            echo "<div style='margin-top: 20px;'>";
            echo "<h4 style='color: #2d3748; margin-bottom: 10px;'>📅 Tipos de Dados Temporais</h4>";
            echo "<table style='font-size: 13px;'>";
            echo "<thead><tr><th style='width: 25%;'>Tipo</th><th>Descrição</th><th style='width: 30%;'>Exemplo de Uso</th></tr></thead>";
            echo "<tbody>";
            echo "<tr><td><code>DATE</code></td><td>Data no formato YYYY-MM-DD.</td><td>Data de nascimento, data de coleta</td></tr>";
            echo "<tr><td><code>DATETIME</code></td><td>Data e hora: YYYY-MM-DD HH:MM:SS.</td><td>Registro de quando algo foi criado/modificado</td></tr>";
            echo "<tr><td><code>TIMESTAMP</code></td><td>Como DATETIME mas atualiza automaticamente.</td><td>Rastreamento de modificações</td></tr>";
            echo "<tr><td><code>TIME</code></td><td>Apenas hora: HH:MM:SS.</td><td>Horário de eventos</td></tr>";
            echo "</tbody></table>";
            echo "</div>";
            
            echo "<div style='margin-top: 20px;'>";
            echo "<h4 style='color: #2d3748; margin-bottom: 10px;'>🔑 Tipos de Chaves (Keys)</h4>";
            echo "<table style='font-size: 13px;'>";
            echo "<thead><tr><th style='width: 25%;'>Chave</th><th>Descrição</th><th style='width: 30%;'>Quando Usar</th></tr></thead>";
            echo "<tbody>";
            echo "<tr><td><code>PRI</code> (Primary)</td><td><strong>Chave Primária.</strong> Identifica unicamente cada registro. Não pode ser NULL nem duplicado.</td><td>Campo 'id' em todas as tabelas principais</td></tr>";
            echo "<tr><td><code>UNI</code> (Unique)</td><td><strong>Chave Única.</strong> Não permite valores duplicados, mas aceita NULL.</td><td>Email, CPF, código de identificação único</td></tr>";
            echo "<tr><td><code>MUL</code> (Multiple)</td><td><strong>Índice Múltiplo.</strong> Permite duplicatas. Acelera buscas.</td><td>Chaves estrangeiras, campos frequentemente buscados</td></tr>";
            echo "</tbody></table>";
            echo "</div>";
            
            echo "<div style='margin-top: 20px;'>";
            echo "<h4 style='color: #2d3748; margin-bottom: 10px;'>⚙️ Valores Padrão (Default)</h4>";
            echo "<table style='font-size: 13px;'>";
            echo "<thead><tr><th style='width: 25%;'>Valor</th><th>Descrição</th><th style='width: 30%;'>Exemplo</th></tr></thead>";
            echo "<tbody>";
            echo "<tr><td><code>NULL</code></td><td>Sem valor padrão. Campo fica vazio se não preenchido.</td><td>Campos opcionais</td></tr>";
            echo "<tr><td><code>CURRENT_TIMESTAMP</code></td><td>Data/hora atual do servidor no momento da inserção.</td><td>Campos created_at, updated_at</td></tr>";
            echo "<tr><td>Valor específico</td><td>Valor fixo inserido automaticamente.</td><td>0, 'Pendente', 1</td></tr>";
            echo "</tbody></table>";
            echo "</div>";
            
            echo "<div style='margin-top: 20px;'>";
            echo "<h4 style='color: #2d3748; margin-bottom: 10px;'>⚡ Propriedades Extras</h4>";
            echo "<table style='font-size: 13px;'>";
            echo "<thead><tr><th style='width: 25%;'>Extra</th><th>Descrição</th><th style='width: 30%;'>Uso</th></tr></thead>";
            echo "<tbody>";
            echo "<tr><td><code>auto_increment</code></td><td>Incrementa automaticamente a cada novo registro (1, 2, 3...).</td><td>Campos ID (chave primária)</td></tr>";
            echo "<tr><td><code>on update CURRENT_TIMESTAMP</code></td><td>Atualiza automaticamente para data/hora atual quando o registro é modificado.</td><td>Campo updated_at</td></tr>";
            echo "</tbody></table>";
            echo "</div>";
            
            echo "<div style='margin-top: 20px;'>";
            echo "<h4 style='color: #2d3748; margin-bottom: 10px;'>🗂️ Tipos de Índices</h4>";
            echo "<table style='font-size: 13px;'>";
            echo "<thead><tr><th style='width: 25%;'>Tipo</th><th>Descrição</th><th style='width: 30%;'>Performance</th></tr></thead>";
            echo "<tbody>";
            echo "<tr><td><code>BTREE</code></td><td><strong>B-Tree Index.</strong> Padrão do MySQL. Ótimo para comparações (=, >, <, BETWEEN).</td><td>Mais comum e eficiente para a maioria dos casos</td></tr>";
            echo "<tr><td><code>HASH</code></td><td><strong>Hash Index.</strong> Muito rápido para igualdade exata (=), mas não funciona para ranges.</td><td>Apenas para buscas exatas, não para ordenação</td></tr>";
            echo "<tr><td><code>FULLTEXT</code></td><td><strong>Full-Text Index.</strong> Para buscas de texto completo (LIKE, MATCH).</td><td>Buscas em textos longos</td></tr>";
            echo "</tbody></table>";
            echo "</div>";
            
            echo "</div>";
            
            // Listar apenas tabelas do projeto diagnostico_tpb
            $tabelasProjeto = [
                'locais',
                'animais', 
                'coletas',
                'resultados_pcr',
                'resultados_lamp'
            ];
            
            foreach ($tabelasProjeto as $tabela) {
                // Verificar se a tabela existe
                $stmt = $pdo->query("SHOW TABLES LIKE '$tabela'");
                if (!$stmt->fetch()) {
                    echo "<div class='alert error'>";
                    echo "<strong>⚠️ Tabela '$tabela' não encontrada!</strong>";
                    echo "</div>";
                    continue;
                }
                echo "<div class='card'>";
                echo "<h3 style='color: #2f855a; margin-top: 0;'>📊 Tabela: <code>$tabela</code></h3>";
                
                // Contar registros
                $stmt = $pdo->query("SELECT COUNT(*) as total FROM `$tabela`");
                $total = $stmt->fetch()['total'];
                echo "<p><strong>Total de registros:</strong> " . number_format($total, 0, ',', '.') . "</p>";
                
                // Mostrar estrutura da tabela
                $stmt = $pdo->query("DESCRIBE `$tabela`");
                $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                echo "<table style='margin-top: 15px;'>";
                echo "<thead><tr>";
                echo "<th>Coluna</th>";
                echo "<th>Tipo</th>";
                echo "<th>Nulo</th>";
                echo "<th>Chave</th>";
                echo "<th>Padrão</th>";
                echo "<th>Extra</th>";
                echo "</tr></thead>";
                echo "<tbody>";
                
                foreach ($colunas as $coluna) {
                    echo "<tr>";
                    echo "<td><strong><code>{$coluna['Field']}</code></strong></td>";
                    echo "<td><code>{$coluna['Type']}</code></td>";
                    echo "<td>" . ($coluna['Null'] == 'YES' ? '✅' : '❌') . "</td>";
                    echo "<td>" . ($coluna['Key'] ? "<span class='status info'>{$coluna['Key']}</span>" : '-') . "</td>";
                    echo "<td>" . ($coluna['Default'] !== null ? "<code>{$coluna['Default']}</code>" : 'NULL') . "</td>";
                    echo "<td>" . ($coluna['Extra'] ? "<span class='status warning'>{$coluna['Extra']}</span>" : '-') . "</td>";
                    echo "</tr>";
                }
                
                echo "</tbody></table>";
                
                // Mostrar índices
                $stmt = $pdo->query("SHOW INDEX FROM `$tabela`");
                $indices = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                if (count($indices) > 0) {
                    echo "<h4 style='margin-top: 20px; color: #4a5568;'>🔑 Índices:</h4>";
                    echo "<table style='margin-top: 10px;'>";
                    echo "<thead><tr><th>Nome do Índice</th><th>Coluna</th><th>Único</th><th>Tipo</th></tr></thead>";
                    echo "<tbody>";
                    
                    $indicesProcessados = [];
                    foreach ($indices as $indice) {
                        $key = $indice['Key_name'] . '_' . $indice['Column_name'];
                        if (!in_array($key, $indicesProcessados)) {
                            echo "<tr>";
                            echo "<td><code>{$indice['Key_name']}</code></td>";
                            echo "<td><code>{$indice['Column_name']}</code></td>";
                            echo "<td>" . ($indice['Non_unique'] == 0 ? '✅ Sim' : '❌ Não') . "</td>";
                            echo "<td><span class='status info'>{$indice['Index_type']}</span></td>";
                            echo "</tr>";
                            $indicesProcessados[] = $key;
                        }
                    }
                    
                    echo "</tbody></table>";
                }
                
                echo "</div>";
            }

            echo "</div>";

            // ============================================
            // TAB 9: AJUDA E TUTORIAIS
            // ============================================
            echo "<div id='ajuda' class='tab-content'>";
            echo "<h2>❓ Ajuda e Tutoriais</h2>";

            $ajudaUnlocked = function_exists('ajudaLiberada') && ajudaLiberada();

            // Listagem dinamica dos arquivos .md em ajuda_tutoriais/
            $ajudaDir = __DIR__ . DIRECTORY_SEPARATOR . 'ajuda_tutoriais';
            $arquivosMd = [];
            if (is_dir($ajudaDir)) {
                $arquivosMd = array_values(array_filter(
                    scandir($ajudaDir),
                    fn($f) => preg_match('/\.md$/i', $f)
                ));
                sort($arquivosMd);
            }

            // ===== Bloco de senha (visivel ate liberar) =====
            echo "<div id='ajuda-lock' style='" . ($ajudaUnlocked ? 'display:none;' : '') . "background:linear-gradient(135deg,#fef3c7 0%,#fde68a 100%);border:2px solid #f59e0b;border-radius:12px;padding:30px;text-align:center;margin:20px 0;max-width:520px;margin-left:auto;margin-right:auto;'>";
            echo "<div style='font-size:48px;margin-bottom:10px;'>🔒</div>";
            echo "<h3 style='color:#78350f;margin:0 0 8px;'>Conteúdo restrito</h3>";
            echo "<p style='color:#92400e;margin:0 0 18px;font-size:14px;'>Esta seção contém informações sensíveis do projeto. Digite a senha de acesso para visualizar os tutoriais.</p>";
            echo "<form id='ajuda-form-senha' onsubmit='liberarAjuda(event)' style='display:flex;flex-direction:column;gap:10px;max-width:320px;margin:0 auto;'>";
            echo "<input type='password' id='ajuda-input-senha' placeholder='Senha de acesso' autocomplete='current-password' style='padding:11px 14px;border:2px solid #d97706;border-radius:8px;font-size:15px;outline:none;'>";
            echo "<button type='submit' style='background:linear-gradient(135deg,#2f855a 0%,#38a169 100%);color:#fff;border:none;padding:11px 20px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer;'>🔓 Desbloquear</button>";
            echo "</form>";
            echo "<div id='ajuda-msg-erro' style='margin-top:12px;color:#b91c1c;font-size:13px;min-height:18px;'></div>";
            echo "</div>";

            // ===== Bloco da Ajuda (visivel apos liberar) =====
            echo "<div id='ajuda-conteudo' style='" . ($ajudaUnlocked ? '' : 'display:none;') . "'>";

            echo "<div class='alert info' style='background:#e7f1fa;border-left:4px solid #0d6efd;padding:14px;border-radius:6px;display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;'>";
            echo "<div><strong>📚 Sobre:</strong> Selecione um tópico na lista à esquerda para visualizar o conteúdo.</div>";
            echo "<button onclick='bloquearAjuda()' style='background:#fff;border:1px solid #0d6efd;color:#0d6efd;padding:6px 12px;border-radius:6px;cursor:pointer;font-size:13px;'>🔒 Bloquear novamente</button>";
            echo "</div>";

            echo "<div class='ajuda-wrapper' style='display:grid;grid-template-columns:280px 1fr;gap:20px;margin-top:20px;'>";
            // Sidebar
            echo "<aside class='ajuda-sidebar' style='background:#f7fafc;border-radius:10px;padding:14px;height:fit-content;border:1px solid #e2e8f0;position:sticky;top:20px;'>";
            echo "<h3 style='font-size:15px;color:#2f855a;margin-bottom:10px;border-bottom:2px solid #38a169;padding-bottom:6px;'>📑 Tópicos</h3>";
            echo "<ul id='ajuda-lista' style='list-style:none;padding:0;margin:0;'>";

            if (count($arquivosMd) === 0) {
                echo "<li style='color:#718096;font-size:13px;'>Nenhum tutorial encontrado em <code>ajuda_tutoriais/</code>.</li>";
            } else {
                foreach ($arquivosMd as $i => $arq) {
                    $titulo = preg_replace('/^\d+[_\-]?/', '', $arq);
                    $titulo = preg_replace('/\.md$/i', '', $titulo);
                    $titulo = ucfirst(str_replace('_', ' ', $titulo));
                    $cls = $i === 0 ? 'ajuda-item active' : 'ajuda-item';
                    $arqEsc = htmlspecialchars($arq, ENT_QUOTES);
                    $titEsc = htmlspecialchars($titulo);
                    // proxy autenticado api_ajuda.php
                    echo "<li><a href='#' class='{$cls}' data-md='" . urlencode($arq) . "' onclick='carregarAjuda(event, this)' style='display:block;padding:8px 10px;margin:3px 0;border-radius:6px;color:#2d3748;text-decoration:none;font-size:13px;transition:all .15s;'>{$titEsc}</a></li>";
                }
            }
            echo "</ul>";
            echo "</aside>";

            // Conteudo
            echo "<section class='ajuda-content' style='background:#fff;border-radius:10px;padding:24px;border:1px solid #e2e8f0;min-height:400px;'>";
            echo "<div id='ajuda-viewer' style='line-height:1.7;color:#2d3748;'>";
            echo "<p style='color:#718096;text-align:center;padding:40px 0;'>⏳ Carregando documentação...</p>";
            echo "</div>";
            echo "</section>";
            echo "</div>";  // wrapper

            echo "</div>";  // ajuda-conteudo

            // Estilos específicos da aba Ajuda
            echo "<style>
                #ajuda .ajuda-item:hover{background:#e6fffa;color:#2c7a7b;}
                #ajuda .ajuda-item.active{background:linear-gradient(135deg,#2f855a 0%,#38a169 100%);color:#fff!important;font-weight:600;}
                #ajuda-viewer h1{color:#2f855a;border-bottom:2px solid #38a169;padding-bottom:8px;margin-bottom:14px;font-size:24px;}
                #ajuda-viewer h2{color:#2f855a;margin:20px 0 10px;font-size:20px;}
                #ajuda-viewer h3{color:#276749;margin:16px 0 8px;font-size:17px;}
                #ajuda-viewer h4{color:#2d3748;margin:14px 0 6px;font-size:15px;}
                #ajuda-viewer p{margin:8px 0;}
                #ajuda-viewer code{background:#f1f5f9;color:#c53030;padding:2px 5px;border-radius:4px;font-family:Consolas,Monaco,monospace;font-size:90%;}
                #ajuda-viewer pre{background:#1a202c;color:#e2e8f0;padding:14px 16px;border-radius:8px;overflow-x:auto;margin:10px 0;}
                #ajuda-viewer pre code{background:transparent;color:inherit;padding:0;}
                #ajuda-viewer table{border-collapse:collapse;width:100%;margin:12px 0;background:#fff;}
                #ajuda-viewer th{background:#2f855a;color:#fff;padding:8px 12px;text-align:left;}
                #ajuda-viewer td{padding:8px 12px;border-bottom:1px solid #e2e8f0;}
                #ajuda-viewer tr:nth-child(even) td{background:#f7fafc;}
                #ajuda-viewer ul,#ajuda-viewer ol{margin:8px 0 8px 24px;}
                #ajuda-viewer li{margin:4px 0;}
                #ajuda-viewer blockquote{border-left:4px solid #38a169;background:#f0fff4;margin:10px 0;padding:10px 16px;color:#276749;border-radius:0 6px 6px 0;}
                #ajuda-viewer a{color:#2f855a;font-weight:500;}
                @media(max-width:900px){.ajuda-wrapper{grid-template-columns:1fr!important;}}
            </style>";

            echo "</div>";

        } catch (Exception $e) {
            echo "<div class='alert error'>";
            echo "❌ <strong>Erro:</strong> " . htmlspecialchars($e->getMessage());
            echo "</div>";
        }
        ?>

        <!-- Botões de Navegação -->
        <div style="margin-top: 30px; text-align: center; padding: 20px; background: #f7fafc; border-radius: 8px;">
            <h3 style="margin-bottom: 15px;">🔗 Navegação</h3>
            <a href="dashboard.php" class="btn">📊 Dashboard</a>
            <a href="forms.php" class="btn">➕ Novo Registro</a>
            <a href="index.php" class="btn btn-secondary">🏠 Início</a>
        </div>
    </div>
    
    <!-- marked.js para renderizar Markdown na aba Ajuda -->
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script>
        // ============================================
        // AJUDA E TUTORIAIS - autenticacao + renderizador Markdown
        // ============================================

        // ----- Desbloqueio por senha -----
        async function liberarAjuda(ev) {
            if (ev) ev.preventDefault();
            const inp  = document.getElementById('ajuda-input-senha');
            const msg  = document.getElementById('ajuda-msg-erro');
            const senha = inp ? inp.value : '';
            if (!senha) { msg.textContent = 'Digite a senha.'; return; }
            msg.textContent = '';
            try {
                const fd = new FormData();
                fd.append('senha', senha);
                const r = await fetch('api_ajuda_auth.php', { method:'POST', body: fd, credentials:'same-origin' });
                const j = await r.json();
                if (j.ok && j.liberada) {
                    document.getElementById('ajuda-lock').style.display = 'none';
                    document.getElementById('ajuda-conteudo').style.display = '';
                    inp.value = '';
                    const primeiro = document.querySelector('#ajuda-lista .ajuda-item');
                    if (primeiro) carregarAjuda(null, primeiro);
                } else {
                    msg.textContent = (j.erro || 'Senha incorreta.') +
                        (j.tentativas ? ' (' + j.tentativas + '/' + (j.max_tentativas || 5) + ')' : '');
                }
            } catch (e) {
                msg.textContent = 'Falha de comunicacao: ' + e.message;
            }
        }

        async function bloquearAjuda() {
            try {
                await fetch('api_ajuda_auth.php?acao=logout', { credentials:'same-origin' });
            } catch (e) { /* ignora */ }
            document.getElementById('ajuda-lock').style.display = '';
            document.getElementById('ajuda-conteudo').style.display = 'none';
            const v = document.getElementById('ajuda-viewer');
            if (v) v.innerHTML = '<p style="color:#718096;text-align:center;padding:30px 0;">🔒 Bloqueado. Digite a senha acima para desbloquear.</p>';
        }

        // ----- Renderizador Markdown -----
        async function carregarAjuda(ev, el) {
            if (ev) ev.preventDefault();
            const viewer = document.getElementById('ajuda-viewer');
            const ativo  = document.querySelector('#ajuda-lista .ajuda-item.active');
            if (ativo) ativo.classList.remove('active');
            if (el) el.classList.add('active');
            const arq = el ? el.getAttribute('data-md') : null;
            if (!arq || !viewer) return;
            viewer.innerHTML = '<p style="color:#718096;text-align:center;padding:30px 0;">⏳ Carregando...</p>';
            try {
                // OBS: usa & para cache-buster (corrige bug do HTTP 400)
                const url = 'api_ajuda.php?file=' + encodeURIComponent(arq) + '&_=' + Date.now();
                const r = await fetch(url, { credentials:'same-origin' });
                if (r.status === 403) {
                    viewer.innerHTML = '<div style="background:#fff7ed;padding:14px;border-radius:8px;color:#9a3412;"><strong>🔒 Acesso bloqueado.</strong> Use o formulario de senha acima para liberar.</div>';
                    document.getElementById('ajuda-lock').style.display = '';
                    document.getElementById('ajuda-conteudo').style.display = 'none';
                    return;
                }
                if (!r.ok) throw new Error('HTTP ' + r.status);
                const md = await r.text();
                if (typeof marked === 'undefined') {
                    viewer.innerHTML = '<pre style="white-space:pre-wrap;color:#2d3748;font-family:inherit;">' + md.replace(/[<>&]/g, c => ({'<':'&lt;','>':'&gt;','&':'&amp;'}[c])) + '</pre>';
                } else {
                    viewer.innerHTML = marked.parse(md);
                }
                viewer.scrollIntoView({behavior:'smooth', block:'nearest'});
            } catch (e) {
                viewer.innerHTML = '<div style="background:#fff5f5;padding:14px;border-radius:8px;color:#c53030;"><strong>Erro:</strong> ' + e.message + '</div>';
            }
        }

        // Auto-carregar primeiro topico se a aba ja estiver liberada na carga da pagina
        document.addEventListener('DOMContentLoaded', function(){
            const conteudo = document.getElementById('ajuda-conteudo');
            if (conteudo && conteudo.style.display !== 'none') {
                const primeiro = document.querySelector('#ajuda-lista .ajuda-item');
                if (primeiro) carregarAjuda(null, primeiro);
            }
        });

        async function executarAutodiagnostico() {
            console.log('🔍 Iniciando autodiagnóstico...');
            const resultadoDiv = document.getElementById('resultado-diagnostico');
            
            if (!resultadoDiv) {
                console.error('❌ Elemento resultado-diagnostico não encontrado!');
                alert('Erro: Elemento de resultado não encontrado na página.');
                return;
            }
            
            resultadoDiv.innerHTML = '<div style="text-align: center; padding: 20px;"><div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #e2e8f0; border-top-color: #4299e1; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px; color: #4a5568;">Executando diagnóstico...</p></div>';
            
            try {
                console.log('📡 Fazendo requisição para api_autodiagnostico.php...');
                const response = await fetch('api_autodiagnostico.php');
                console.log('📥 Resposta recebida:', response.status, response.statusText);
                
                if (!response.ok) {
                    throw new Error('Erro HTTP: ' + response.status + ' - ' + response.statusText);
                }
                
                const data = await response.json();
                console.log('✅ Dados recebidos:', data);
                
                if (data.sucesso) {
                    let html = '<div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid #48bb78;">';
                    html += '<h4 style="color: #2f855a; margin-top: 0;">✅ Diagnóstico Concluído</h4>';
                    
                    // Resumo
                    html += '<div style="background: #f0fff4; padding: 15px; border-radius: 6px; margin-bottom: 20px;">';
                    html += '<p style="margin: 5px 0;"><strong>Total de problemas encontrados:</strong> ' + data.total_problemas + '</p>';
                    html += '<p style="margin: 5px 0;"><strong>Tabelas verificadas:</strong> ' + data.tabelas_verificadas + '</p>';
                    html += '<p style="margin: 5px 0;"><strong>Status:</strong> ' + (data.total_problemas === 0 ? '✅ Sistema saudável' : '⚠️ Atenção necessária') + '</p>';
                    html += '</div>';
                    
                    // Detalhes por tabela
                    if (data.detalhes && data.detalhes.length > 0) {
                        html += '<h5 style="color: #2d3748; margin-top: 20px;">📋 Detalhes por Tabela:</h5>';
                        data.detalhes.forEach(tabela => {
                            const cor = tabela.problemas === 0 ? '#48bb78' : (tabela.problemas > 5 ? '#e53e3e' : '#f59e0b');
                            html += '<div style="background: #f7fafc; padding: 12px; border-left: 4px solid ' + cor + '; margin: 10px 0; border-radius: 4px;">';
                            html += '<strong>' + tabela.tabela + ':</strong> ';
                            html += tabela.problemas === 0 ? '✅ Sem problemas' : '⚠️ ' + tabela.problemas + ' problema(s)';
                            if (tabela.mensagens && tabela.mensagens.length > 0) {
                                html += '<ul style="margin: 10px 0 0 20px; font-size: 13px;">';
                                tabela.mensagens.forEach(msg => {
                                    html += '<li>' + msg + '</li>';
                                });
                                html += '</ul>';
                            }
                            html += '</div>';
                        });
                    }
                    
                    html += '</div>';
                    resultadoDiv.innerHTML = html;
                } else {
                    console.error('❌ API retornou erro:', data.erro);
                    resultadoDiv.innerHTML = '<div style="background: #fff5f5; padding: 20px; border-radius: 8px; border: 2px solid #e53e3e; color: #c53030;"><strong>❌ Erro:</strong> ' + (data.erro || 'Erro desconhecido') + '</div>';
                }
            } catch (error) {
                console.error('❌ Erro na execução:', error);
                resultadoDiv.innerHTML = '<div style="background: #fff5f5; padding: 20px; border-radius: 8px; border: 2px solid #e53e3e; color: #c53030;"><strong>❌ Erro:</strong> ' + error.message + '<br><small>Verifique o console do navegador (F12) para mais detalhes.</small></div>';
            }
        }
        
        // Teste ao carregar a página
        document.addEventListener('DOMContentLoaded', function() {
            console.log('✅ Página carregada. Função executarAutodiagnostico disponível:', typeof executarAutodiagnostico);
        });

        // ============================================
        // TESTE DE FLUXO CLIMÁTICO
        // ============================================
        async function testarFluxoClimatico() {
            const resultadoDiv = document.getElementById('resultado-teste-clima');
            if (!resultadoDiv) {
                alert('Erro: Elemento de resultado não encontrado.');
                return;
            }

            resultadoDiv.innerHTML = '<div style="text-align: center; padding: 20px;"><div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #e2e8f0; border-top-color: #4299e1; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px; color: #4a5568;">Testando fluxo climático...</p></div>';

            try {
                const response = await fetch('api_teste_fluxo_clima.php');
                if (!response.ok) {
                    throw new Error('Erro HTTP: ' + response.status);
                }

                const data = await response.json();
                console.log('✅ Resultado do teste:', data);

                if (data.sucesso) {
                    let html = '<div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid #48bb78;">';
                    html += '<h4 style="color: #2f855a; margin-top: 0;">✅ Teste Concluído</h4>';

                    // Resumo
                    html += '<div style="background: #f0fff4; padding: 15px; border-radius: 6px; margin-bottom: 20px;">';
                    html += '<p style="margin: 5px 0;"><strong>Status Geral:</strong> ' + (data.aprovado ? '✅ APROVADO' : '⚠️ PROBLEMAS DETECTADOS') + '</p>';
                    html += '<p style="margin: 5px 0;"><strong>Testes Executados:</strong> ' + data.total_testes + '</p>';
                    html += '<p style="margin: 5px 0;"><strong>Aprovados:</strong> ' + data.testes_aprovados + '</p>';
                    html += '<p style="margin: 5px 0;"><strong>Falhas:</strong> ' + data.testes_falhados + '</p>';
                    html += '</div>';

                    // Detalhes dos testes
                    if (data.testes && data.testes.length > 0) {
                        html += '<h5 style="color: #2d3748; margin-top: 20px;">📋 Detalhes dos Testes:</h5>';
                        data.testes.forEach(teste => {
                            const cor = teste.passou ? '#48bb78' : '#e53e3e';
                            const icone = teste.passou ? '✅' : '❌';
                            html += '<div style="background: #f7fafc; padding: 12px; border-left: 4px solid ' + cor + '; margin: 10px 0; border-radius: 4px;">';
                            html += '<strong>' + icone + ' ' + teste.nome + '</strong><br>';
                            html += '<small style="color: #4a5568;">' + teste.mensagem + '</small>';
                            html += '</div>';
                        });
                    }

                    html += '</div>';
                    resultadoDiv.innerHTML = html;
                } else {
                    resultadoDiv.innerHTML = '<div style="background: #fff5f5; padding: 20px; border-radius: 8px; border: 2px solid #e53e3e; color: #c53030;"><strong>❌ Erro:</strong> ' + (data.erro || 'Erro desconhecido') + '</div>';
                }
            } catch (error) {
                console.error('❌ Erro:', error);
                resultadoDiv.innerHTML = '<div style="background: #fff5f5; padding: 20px; border-radius: 8px; border: 2px solid #e53e3e; color: #c53030;"><strong>❌ Erro:</strong> ' + error.message + '</div>';
            }
        }
        
        function showTab(tabName) {
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            document.getElementById(tabName).classList.add('active');
            event.target.classList.add('active');
        }
    </script>
</body>
</html>
