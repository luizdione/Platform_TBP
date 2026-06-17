<?php
// ============================================
// Sistema de Formulários - Vigilância Parasitária Bovina
// Arquivo: forms.php
// ============================================

require_once __DIR__ . '/../../templates/auth_central.php';
verificarAutenticacaoCentral();
bloquearSeSomenteLeitura(); // Bloquear acesso para usuários somente-leitura
require_once 'config.php';
setSecurityHeaders();

// Verificar se há mensagens de status
$mensagem = '';
$tipo_mensagem = '';
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'sucesso':
            if (isset($_GET['detalhe'])) {
                $mensagem = htmlspecialchars($_GET['detalhe']);
            } elseif (isset($_GET['total']) && $_GET['total'] > 1) {
                $mensagem = $_GET['total'] . ' coletas registradas com sucesso!';
            } else {
                $mensagem = 'Dados salvos com sucesso!';
            }
            $tipo_mensagem = 'success';
            break;
        case 'erro':
            if (isset($_GET['detalhe'])) {
                $mensagem = 'Erro: ' . htmlspecialchars($_GET['detalhe']);
            } else {
                $mensagem = 'Erro ao salvar dados. Tente novamente.';
            }
            $tipo_mensagem = 'error';
            break;
    }
}

// Buscar dados para os selects
try {
    $pdo = getDBConnection();
    
    // Buscar locais ativos (apenas com coordenadas definidas)
    $stmt = $pdo->query("SELECT id, nome, municipio FROM locais WHERE ativo = TRUE AND latitude IS NOT NULL AND longitude IS NOT NULL ORDER BY municipio, nome");
    $locais = $stmt->fetchAll();
    
    // Buscar animais por local (será carregado via AJAX)
    $animais_por_local = [];
    
} catch (Exception $e) {
    logErro("Erro ao carregar dados para formulários: " . $e->getMessage());
    $mensagem = "Erro ao carregar dados. Verifique a conexão com o banco.";
    $tipo_mensagem = "error";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sistema de Entrada de Dados - Vigilância Parasitária Bovina</title>
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
            min-height: 70vh;
            padding: 8px;
            position: relative;
        }
        
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(47, 133, 90, 0.85);
            z-index: -1;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            transform: scale(0.95);
            transform-origin: top center;
        }
        
        .header {
            background: linear-gradient(135deg, #2f855a 0%, #38a169 100%);
            border-radius: 12px;
            padding: 30px 40px;
            margin-bottom: 20px;
            box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            border-bottom: 4px solid #276749;
        }
        
        .header-content {
            flex: 1;
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header-icon {
            font-size: 3em;
            color: white;
            opacity: 0.9;
        }
        
        .header-text {
            flex: 1;
        }
        
        .header h1 {
            color: white;
            font-size: 2em;
            margin-bottom: 8px;
            font-weight: 700;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }
        
        .header p {
            color: rgba(255, 255, 255, 0.95);
            font-size: 1.1em;
            line-height: 1.5;
        }
        
        .header-buttons {
            display: flex;
            gap: 12px;
        }
        
        .header-btn {
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
        
        .header-btn:hover {
            background: white;
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.15);
        }
        
        .header-btn-icon {
            font-size: 1.2em;
        }
        
        .tabs {
            display: flex;
            background: white;
            border-radius: 15px 15px 0 0;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0,0,0,0.1);
        }
        
        .tab {
            flex: 1;
            padding: 6px;
            background: #f7fafc;
            border: none;
            cursor: pointer;
            font-size: 0.85em;
            font-weight: 600;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 4px;
        }
        
        .tab.active {
            background: #2f855a;
            color: white;
        }
        
        .tab:hover:not(.active) {
            background: #edf2f7;
        }
        
        .form-container {
            background: white;
            border-radius: 0 0 15px 15px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .form-section {
            display: none;
            padding: 6px;
        }
        
        .form-section.active {
            display: block;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 8px;
            margin-bottom: 8px;
        }
        
        .form-group {
            margin-bottom: 8px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #2d3748;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 8px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 8px;
            font-size: 14px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #2f855a;
        }
        
        .form-group.required label::after {
            content: " *";
            color: #e53e3e;
        }
        
        .radio-group {
            display: flex;
            gap: 20px;
            flex-wrap: wrap;
        }
        
        .radio-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .radio-item input[type="radio"] {
            width: auto;
        }
        
        .btn {
            padding: 10px 16px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: #2f855a;
            color: white;
        }
        
        .btn-primary:hover {
            background: #276749;
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: #718096;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #4a5568;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .alert.success {
            background: #c6f6d5;
            color: #276749;
            border: 1px solid #9ae6b4;
        }
        
        .alert.error {
            background: #fed7d7;
            color: #c53030;
            border: 1px solid #fbb6ce;
        }
        
        .section-title {
            font-size: 1.1em;
            color: #2d3748;
            margin-bottom: 12px;
            padding-bottom: 6px;
            border-bottom: 3px solid #2f855a;
        }
        
        @media (max-width: 768px) {
            .tabs {
                flex-direction: column;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
            
            .radio-group {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <div class="header-content">
                <img src="static/img/logo-ifrj.png" alt="IFRJ" class="header-logo" style="height:64px;width:auto;background:#fff;padding:4px 8px;border-radius:8px;box-shadow:0 2px 6px rgba(0,0,0,0.15);">
                <div class="header-icon">📋</div>
                <div class="header-text">
                    <h1>Sistema de Entrada de Dados</h1>
                    <p>Cadastro de animais, locais e coletas para vigilância parasitária bovina</p>
                </div>
            </div>
            <div class="header-buttons">
                <a href="index.php" class="header-btn">
                    <span class="header-btn-icon">🏠</span>
                    <span>Início</span>
                </a>
                <a href="dashboard.php" class="header-btn">
                    <span class="header-btn-icon">📊</span>
                    <span>Dashboard</span>
                </a>
            </div>
        </div>

        <!-- Mensagens de Status -->
        <?php if ($mensagem): ?>
            <div class="alert <?php echo $tipo_mensagem; ?>">
                <i class="fas <?php echo $tipo_mensagem === 'success' ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
                <?php echo htmlspecialchars($mensagem); ?>
            </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab active" onclick="showTab('locais')">
                📍 Locais & Animais
            </button>
            <button class="tab" onclick="showTab('coletas')">
                🧪 Amostras
            </button>
            <button class="tab" onclick="showTab('pcr-lamp')">
                🧬 Resultados PCR & PCR-LAMP
            </button>
            <button class="tab" onclick="showTab('clima')">
                🌤️ Dados Climáticos
            </button>
        </div>

        <div class="form-container">
            <!-- SEÇÃO: LOCAIS & ANIMAIS -->
            <div id="locais-section" class="form-section active">
                <h2 class="section-title">Novo Animal ou Novo Local</h2>
                
                <!-- Formulário de Animal -->
                <form id="form-animal" method="POST" action="processa_formulario.php">
                    <input type="hidden" name="tipo" value="animal">
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="local_animal">Local</label>
                            <select id="local_animal" name="local_id" required>
                                <option value="">Selecione o Local...</option>
                                <?php foreach ($locais as $local): ?>
                                    <option value="<?php echo $local['id']; ?>">
                                        <?php echo htmlspecialchars($local['nome'] . ' - ' . $local['municipio']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group required">
                            <label for="identificacao">Código de Identificação</label>
                            <input type="text" id="identificacao" name="identificacao" required placeholder="Ex: 0001, 0123, BOV001">
                        </div>
                        
                        <div class="form-group">
                            <label for="nome_animal">Nome do Animal (opcional)</label>
                            <input type="text" id="nome_animal" name="nome_animal" placeholder="Ex: Mimosa, Estrela">
                        </div>
                        
                        <div class="form-group">
                            <label for="raca">Raça</label>
                            <input type="text" id="raca" name="raca" placeholder="Ex: Nelore, Angus">
                        </div>
                        
                        <div class="form-group">
                            <label for="data_nascimento">Data de Nascimento</label>
                            <input type="date" id="data_nascimento" name="data_nascimento">
                        </div>
                        
                        <div class="form-group required">
                            <label>Sexo</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="macho" name="sexo" value="Macho" required>
                                    <label for="macho">Macho</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="femea" name="sexo" value="Fêmea" required>
                                    <label for="femea">Fêmea</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="peso">Peso (kg)</label>
                            <input type="number" id="peso" name="peso_kg" step="0.1" min="0">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="observacoes_animal">Observações</label>
                        <textarea id="observacoes_animal" name="observacoes" rows="3"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Animal
                    </button>
                </form>
                
                <hr style="margin: 40px 0; border: none; border-top: 2px solid #e2e8f0;">
                
                <!-- Formulário de Local -->
                <form id="form-local" method="POST" action="processa_formulario.php">
                    <input type="hidden" name="tipo" value="local">
                    
                    <div style="background: #e6fffa; border-left: 4px solid #2f855a; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                        <strong>ℹ️ Local Principal:</strong>
                        <button type="button" class="btn btn-secondary" onclick="preencherFazendaQuintasBarbosa()" style="margin-left: 10px;">
                            📍 Preencher Fazenda Quintas Barbosa
                        </button>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="nome_local">Nome do Local</label>
                            <input type="text" id="nome_local" name="nome" required list="lista_locais">
                                                        <datalist id="lista_locais">
                                                            <?php foreach ($locais as $l): ?>
                                                                <option value="<?php echo htmlspecialchars($l['nome']); ?>"></option>
                                                            <?php endforeach; ?>
                                                        </datalist>
                        </div>
                        
                        <div class="form-group required">
                            <label for="municipio">Município</label>
                            <select id="municipio" name="municipio" required>
                                <option value="">Selecione...</option>
                                <option value="Angra dos Reis">Angra dos Reis</option>
                                <option value="Aperibé">Aperibé</option>
                                <option value="Araruama">Araruama</option>
                                <option value="Areal">Areal</option>
                                <option value="Armação dos Búzios">Armação dos Búzios</option>
                                <option value="Arraial do Cabo">Arraial do Cabo</option>
                                <option value="Barra do Piraí">Barra do Piraí</option>
                                <option value="Barra Mansa">Barra Mansa</option>
                                <option value="Belford Roxo">Belford Roxo</option>
                                <option value="Bom Jardim">Bom Jardim</option>
                                <option value="Bom Jesus do Itabapoana">Bom Jesus do Itabapoana</option>
                                <option value="Cabo Frio">Cabo Frio</option>
                                <option value="Cachoeiras de Macacu">Cachoeiras de Macacu</option>
                                <option value="Cambuci">Cambuci</option>
                                <option value="Campos dos Goytacazes">Campos dos Goytacazes</option>
                                <option value="Cantagalo">Cantagalo</option>
                                <option value="Carapebus">Carapebus</option>
                                <option value="Cardoso Moreira">Cardoso Moreira</option>
                                <option value="Carmo">Carmo</option>
                                <option value="Casimiro de Abreu">Casimiro de Abreu</option>
                                <option value="Comendador Levy Gasparian">Comendador Levy Gasparian</option>
                                <option value="Conceição de Macabu">Conceição de Macabu</option>
                                <option value="Cordeiro">Cordeiro</option>
                                <option value="Duas Barras">Duas Barras</option>
                                <option value="Duque de Caxias">Duque de Caxias</option>
                                <option value="Engenheiro Paulo de Frontin">Engenheiro Paulo de Frontin</option>
                                <option value="Guapimirim">Guapimirim</option>
                                <option value="Iguaba Grande">Iguaba Grande</option>
                                <option value="Itaboraí">Itaboraí</option>
                                <option value="Itaguaí">Itaguaí</option>
                                <option value="Italva">Italva</option>
                                <option value="Itaocara">Itaocara</option>
                                <option value="Itaperuna">Itaperuna</option>
                                <option value="Itatiaia">Itatiaia</option>
                                <option value="Japeri">Japeri</option>
                                <option value="Laje do Muriaé">Laje do Muriaé</option>
                                <option value="Macaé">Macaé</option>
                                <option value="Macuco">Macuco</option>
                                <option value="Magé">Magé</option>
                                <option value="Mangaratiba">Mangaratiba</option>
                                <option value="Maricá">Maricá</option>
                                <option value="Mendes">Mendes</option>
                                <option value="Mesquita">Mesquita</option>
                                <option value="Miguel Pereira">Miguel Pereira</option>
                                <option value="Miracema">Miracema</option>
                                <option value="Natividade">Natividade</option>
                                <option value="Nilópolis">Nilópolis</option>
                                <option value="Niterói">Niterói</option>
                                <option value="Nova Friburgo">Nova Friburgo</option>
                                <option value="Nova Iguaçu">Nova Iguaçu</option>
                                <option value="Paracambi">Paracambi</option>
                                <option value="Paraíba do Sul">Paraíba do Sul</option>
                                <option value="Paraty">Paraty</option>
                                <option value="Paty do Alferes">Paty do Alferes</option>
                                <option value="Petrópolis">Petrópolis</option>
                                <option value="Pinheiral">Pinheiral</option>
                                <option value="Piraí">Piraí</option>
                                <option value="Porciúncula">Porciúncula</option>
                                <option value="Porto Real">Porto Real</option>
                                <option value="Quatis">Quatis</option>
                                <option value="Queimados">Queimados</option>
                                <option value="Quissamã">Quissamã</option>
                                <option value="Resende">Resende</option>
                                <option value="Rio Bonito">Rio Bonito</option>
                                <option value="Rio Claro">Rio Claro</option>
                                <option value="Rio das Flores">Rio das Flores</option>
                                <option value="Rio das Ostras">Rio das Ostras</option>
                                <option value="Rio de Janeiro">Rio de Janeiro</option>
                                <option value="Santa Maria Madalena">Santa Maria Madalena</option>
                                <option value="Santo Antônio de Pádua">Santo Antônio de Pádua</option>
                                <option value="São Fidélis">São Fidélis</option>
                                <option value="São Francisco de Itabapoana">São Francisco de Itabapoana</option>
                                <option value="São Gonçalo">São Gonçalo</option>
                                <option value="São João da Barra">São João da Barra</option>
                                <option value="São João de Meriti">São João de Meriti</option>
                                <option value="São José de Ubá">São José de Ubá</option>
                                <option value="São José do Vale do Rio Preto">São José do Vale do Rio Preto</option>
                                <option value="São Pedro da Aldeia">São Pedro da Aldeia</option>
                                <option value="São Sebastião do Alto">São Sebastião do Alto</option>
                                <option value="Sapucaia">Sapucaia</option>
                                <option value="Saquarema">Saquarema</option>
                                <option value="Seropédica">Seropédica</option>
                                <option value="Silva Jardim">Silva Jardim</option>
                                <option value="Sumidouro">Sumidouro</option>
                                <option value="Tanguá">Tanguá</option>
                                <option value="Teresópolis">Teresópolis</option>
                                <option value="Trajano de Moraes">Trajano de Moraes</option>
                                <option value="Três Rios">Três Rios</option>
                                <option value="Valença">Valença</option>
                                <option value="Varre-Sai">Varre-Sai</option>
                                <option value="Vassouras">Vassouras</option>
                                <option value="Volta Redonda">Volta Redonda</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="latitude">Latitude</label>
                            <input type="number" id="latitude" name="latitude" step="0.000001" placeholder="Ex: -22.123456">
                        </div>
                        
                        <div class="form-group">
                            <label for="longitude">Longitude</label>
                            <input type="number" id="longitude" name="longitude" step="0.000001" placeholder="Ex: -43.123456">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="propriedade">Nome da Propriedade</label>
                        <input type="text" id="propriedade" name="propriedade">
                    </div>
                    
                    <div class="form-group">
                        <label for="descricao_local">Descrição</label>
                        <textarea id="descricao_local" name="descricao" rows="3" placeholder="Características do local, observações..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Local
                    </button>
                </form>
            </div>

            <!-- SEÇÃO: AMOSTRAS (1 amostra = 1 animal × 1 data de coleta) -->
            <div id="coletas-section" class="form-section">
                <h2 class="section-title">Registro de Amostras</h2>
                <p style="color: #718096; margin-bottom: 20px;">
                    ℹ️ <b>Coleta</b> = a visita de campo (uma data). <b>Amostra</b> = um animal individual nessa coleta.
                    Em uma coleta com 30 animais → registre 30 amostras com a mesma data.
                    Você pode selecionar múltiplos animais para registrar amostras em lote (todos da mesma coleta).
                    Mantenha Ctrl pressionado (Windows) ou Cmd (Mac) para selecionar vários animais.
                </p>
                
                <form id="form-coleta" method="POST" action="processa_formulario.php">
                    <input type="hidden" name="tipo" value="coleta">
                    <input type="hidden" id="animais_selecionados" name="animais_ids" value="">
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="local_coleta">🏘️ Local</label>
                            <select id="local_coleta" name="local_id" required onchange="carregarAnimaisMultiplos()">
                                <option value="">Selecione o Local...</option>
                                <?php foreach ($locais as $local): ?>
                                    <option value="<?php echo $local['id']; ?>">
                                        <?php echo htmlspecialchars($local['nome'] . ' - ' . $local['municipio']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group required">
                            <label for="animal_coleta">🐄 Animais (Selecione um ou mais)</label>
                            <select id="animal_coleta" name="animal_id[]" required disabled multiple size="8" style="height: auto; min-height: 150px;" onchange="atualizarAnimaisSelecionados()">
                                <option value="">Primeiro selecione o local...</option>
                            </select>
                            <small style="color: #718096; display: block; margin-top: 5px;">
                                💡 Mantenha Ctrl (Windows) ou Cmd (Mac) pressionado para selecionar múltiplos animais
                            </small>
                            <div id="animais-selecionados-display" style="margin-top: 10px; padding: 10px; background: #f7fafc; border-radius: 5px; display: none;">
                                <strong style="color: #2d3748;">Animais Selecionados:</strong>
                                <div id="lista-animais-selecionados" style="margin-top: 5px; color: #4a5568;"></div>
                            </div>
                        </div>
                        
                        <div class="form-group required">
                            <label for="data_coleta">Data da Coleta <small style="color:#666;font-weight:normal">(data da visita ao campo)</small></label>
                            <input type="date" id="data_coleta" name="data_coleta" required>
                        </div>
                        
                        <div class="form-group required">
                            <label for="estacao">Estação</label>
                            <select id="estacao" name="estacao" required>
                                <option value="">Selecione...</option>
                                <option value="Verão">Verão</option>
                                <option value="Outono">Outono</option>
                                <option value="Inverno">Inverno</option>
                                <option value="Primavera">Primavera</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="tipo_amostra">Tipo de Amostra</label>
                            <select id="tipo_amostra" name="tipo_amostra">
                                <option value="Sangue">Sangue</option>
                                <option value="Soro">Soro</option>
                                <option value="Tecido">Tecido</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="volume_ml">Volume (mL)</label>
                            <input type="number" id="volume_ml" name="volume_ml" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="responsavel">Responsável</label>
                            <input type="text" id="responsavel" name="responsavel">
                        </div>
                        
                        <div class="form-group">
                            <label for="temperatura_animal">Temperatura do Animal (°C)</label>
                            <input type="number" id="temperatura_animal" name="temperatura_animal" step="0.1" min="35" max="45">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="condicoes_clinicas">Condições Clínicas</label>
                        <textarea id="condicoes_clinicas" name="condicoes_clinicas" rows="2" placeholder="Estado geral do animal, sintomas observados..."></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label for="observacoes_coleta">Observações</label>
                        <textarea id="observacoes_coleta" name="observacoes" rows="3"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Amostra(s)
                    </button>
                </form>
            </div>

            <!-- SEÇÃO: RESULTADOS PCR & PCR-LAMP -->
            <div id="pcr-lamp-section" class="form-section">
                <h2 class="section-title">Resultados PCR & PCR-LAMP</h2>

                <!-- SUBSEÇÃO: PCR Convencional -->
                <h3 style="color: #2d3748; margin: 25px 0 15px 0; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;">
                    🧬 PCR Convencional (Análise Qualitativa em Gel de Agarose)
                </h3>
                <p style="color: #718096; margin-bottom: 20px;">
                    ℹ️ PCR convencional com visualização de amplicons em gel de agarose - Resultado qualitativo (Positivo/Negativo/Inconclusivo)
                </p>
                
                <form id="form-pcr" method="POST" action="processa_formulario.php">
                    <input type="hidden" name="tipo" value="pcr">
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="local_pcr">🏘️ Propriedade</label>
                            <select id="local_pcr" name="local_id" required onchange="carregarAnimaisPCR()">
                                <option value="">Selecione a propriedade</option>
                                <?php foreach ($locais as $local): ?>
                                    <option value="<?= $local['id'] ?>"><?= htmlspecialchars($local['nome']) ?> - <?= htmlspecialchars($local['municipio']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group required">
                            <label for="animal_pcr">🐄 Animal</label>
                            <select id="animal_pcr" name="animal_id" required onchange="carregarColetasPCR()">
                                <option value="">Selecione a propriedade primeiro</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="coleta_pcr">📅 Coleta</label>
                            <select id="coleta_pcr" name="coleta_id" required>
                                <option value="">Selecione o animal primeiro</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="data_analise_pcr">Data da Análise PCR (opcional)</label>
                            <input type="date" id="data_analise_pcr" name="data_analise">
                        </div>
                    </div>
                    
                    <!-- Resultados da Qualidade DNA -->
                    <h3 style="color: #2d3748; margin: 20px 0 15px 0; font-size: 1.1em;">🧬 Resultados da Qualidade DNA</h3>
                    
                    <div class="form-group" style="background: #f7fafc; padding: 15px; border-radius: 8px; border-left: 4px solid #4299e1;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="checkbox" id="amplificacao_16s" name="amplificacao_16s_bovino" value="1" style="width: auto; cursor: pointer;">
                            <label for="amplificacao_16s" style="margin: 0; cursor: pointer; font-weight: 600; color: #2d3748;">
                                Amplificação PCR controle bovino 16S
                            </label>
                        </div>
                        <small style="color: #718096; display: block; margin-top: 8px; margin-left: 30px;">
                            ℹ️ Marque esta opção se houve amplificação do gene 16S ribossomal bovino (controle positivo de qualidade do DNA extraído)
                        </small>
                    </div>
                    
                    <!-- Resultados dos Parasitos -->
                    <h3 style="color: #2d3748; margin: 20px 0 15px 0; font-size: 1.1em;">🦠 Resultados dos Parasitos</h3>
                    
                    <div class="form-group">
                        <label>Babesia bovis (opcional - preencha quando tiver resultado)</label>
                        <div class="radio-group">
                            <div class="radio-item">
                                <input type="radio" id="babesia_pos" name="babesia_bovis" value="Positivo">
                                <label for="babesia_pos">✅ Positivo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="babesia_neg" name="babesia_bovis" value="Negativo">
                                <label for="babesia_neg">❌ Negativo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="babesia_inc" name="babesia_bovis" value="Inconclusivo">
                                <label for="babesia_inc">❓ Inconclusivo</label>
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <label for="tempo_deteccao_babesia">⏱️ Tempo de Detecção (min) <small style="color:#718096;">— Tt / Time to threshold (preencher se positivo)</small></label>
                            <input type="number" id="tempo_deteccao_babesia" name="tempo_deteccao_babesia" min="0" max="240" step="1" placeholder="ex.: 28">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Babesia bigemina (opcional - preencha quando tiver resultado)</label>
                        <div class="radio-group">
                            <div class="radio-item">
                                <input type="radio" id="bigemina_pos" name="babesia_bigemina" value="Positivo">
                                <label for="bigemina_pos">✅ Positivo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="bigemina_neg" name="babesia_bigemina" value="Negativo">
                                <label for="bigemina_neg">❌ Negativo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="bigemina_inc" name="babesia_bigemina" value="Inconclusivo">
                                <label for="bigemina_inc">❓ Inconclusivo</label>
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <label for="tempo_deteccao_bigemina">⏱️ Tempo de Detecção (min) <small style="color:#718096;">— Tt / Time to threshold (preencher se positivo)</small></label>
                            <input type="number" id="tempo_deteccao_bigemina" name="tempo_deteccao_bigemina" min="0" max="240" step="1" placeholder="ex.: 28">
                        </div>
                    </div>

                    <div class="form-group">
                        <label>Anaplasma marginale (opcional - preencha quando tiver resultado)</label>
                        <div class="radio-group">
                            <div class="radio-item">
                                <input type="radio" id="anaplasma_pos" name="anaplasma_marginale" value="Positivo">
                                <label for="anaplasma_pos">✅ Positivo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="anaplasma_neg" name="anaplasma_marginale" value="Negativo">
                                <label for="anaplasma_neg">❌ Negativo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="anaplasma_inc" name="anaplasma_marginale" value="Inconclusivo">
                                <label for="anaplasma_inc">❓ Inconclusivo</label>
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <label for="tempo_deteccao_anaplasma">⏱️ Tempo de Detecção (min) <small style="color:#718096;">— Tt / Time to threshold (preencher se positivo)</small></label>
                            <input type="number" id="tempo_deteccao_anaplasma" name="tempo_deteccao_anaplasma" min="0" max="240" step="1" placeholder="ex.: 28">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Trypanosoma vivax (opcional - preencha quando tiver resultado)</label>
                        <div class="radio-group">
                            <div class="radio-item">
                                <input type="radio" id="trypanosoma_pos" name="trypanosoma_vivax" value="Positivo">
                                <label for="trypanosoma_pos">✅ Positivo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="trypanosoma_neg" name="trypanosoma_vivax" value="Negativo">
                                <label for="trypanosoma_neg">❌ Negativo</label>
                            </div>
                            <div class="radio-item">
                                <input type="radio" id="trypanosoma_inc" name="trypanosoma_vivax" value="Inconclusivo">
                                <label for="trypanosoma_inc">❓ Inconclusivo</label>
                            </div>
                        </div>
                        <div style="margin-top: 10px;">
                            <label for="tempo_deteccao_trypanosoma">⏱️ Tempo de Detecção (min) <small style="color:#718096;">— Tt / Time to threshold (preencher se positivo)</small></label>
                            <input type="number" id="tempo_deteccao_trypanosoma" name="tempo_deteccao_trypanosoma" min="0" max="240" step="1" placeholder="ex.: 28">
                        </div>
                    </div>
                    
                    <!-- Detalhes Técnicos -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="qualidade_dna">Qualidade do DNA</label>
                            <select id="qualidade_dna" name="qualidade_dna">
                                <option value="Ótima">Ótima</option>
                                <option value="Boa" selected>Boa</option>
                                <option value="Regular">Regular</option>
                                <option value="Ruim">Ruim</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="concentracao_dna">Concentração DNA (ng/μL)</label>
                            <input type="number" id="concentracao_dna" name="concentracao_dna" step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="analista_pcr">Analista</label>
                            <input type="text" id="analista_pcr" name="analista">
                        </div>
                        
                        <div class="form-group">
                            <label for="equipamento">Equipamento</label>
                            <input type="text" id="equipamento" name="equipamento">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="observacoes_pcr">Observações</label>
                        <textarea id="observacoes_pcr" name="observacoes" rows="3"></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Resultado PCR
                    </button>
                </form>

                <!-- SUBSEÇÃO: PCR-LAMP -->
                <h3 style="color: #2d3748; margin: 35px 0 15px 0; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;">
                    🧪 PCR-LAMP (Babesia bovis - 2 Alvos)
                </h3>
                <p style="color: #718096; margin-bottom: 20px;">
                    ℹ️ LAMP detecta Babesia bovis usando 2 alvos genômicos independentes: 18S (gene ribossomal) e CytB (gene do citocromo B)
                </p>
                
                <form id="form-lamp" method="POST" action="processa_formulario.php">
                    <input type="hidden" name="tipo" value="lamp">
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="local_lamp">🏘️ Propriedade</label>
                            <select id="local_lamp" name="local_id" required onchange="carregarAnimaisLAMP()">
                                <option value="">Selecione a propriedade</option>
                                <?php foreach ($locais as $local): ?>
                                    <option value="<?= $local['id'] ?>"><?= htmlspecialchars($local['nome']) ?> - <?= htmlspecialchars($local['municipio']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group required">
                            <label for="animal_lamp">🐄 Animal</label>
                            <select id="animal_lamp" name="animal_id" required onchange="carregarColetasLAMP()">
                                <option value="">Selecione a propriedade primeiro</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="coleta_lamp">📅 Coleta</label>
                            <select id="coleta_lamp" name="coleta_id" required>
                                <option value="">Selecione o animal primeiro</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="data_analise_lamp">Data da Análise LAMP (opcional)</label>
                            <input type="date" id="data_analise_lamp" name="data_analise">
                        </div>
                    </div>
                    
                    <!-- ALVO 18S (Gene Ribossomal 18S) -->
                    <h3 style="color: #2d3748; margin: 25px 0 15px 0; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;">
                        🧬 Alvo 18S (Gene Ribossomal 18S)
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Resultado LAMP - 18S (opcional - preencha quando tiver resultado)</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="babesia_18s_pos" name="babesia_18s_lamp" value="Positivo">
                                    <label for="babesia_18s_pos">✅ Positivo</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="babesia_18s_neg" name="babesia_18s_lamp" value="Negativo">
                                    <label for="babesia_18s_neg">❌ Negativo</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="babesia_18s_inc" name="babesia_18s_lamp" value="Inconclusivo">
                                    <label for="babesia_18s_inc">❓ Inconclusivo</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="tempo_deteccao_18s">Tempo de Detecção 18S (min)</label>
                            <input type="number" id="tempo_deteccao_18s" name="tempo_deteccao_18s_min" min="0" max="120">
                        </div>
                        
                        <div class="form-group">
                            <label for="intensidade_sinal_18s">Intensidade do Sinal 18S</label>
                            <select id="intensidade_sinal_18s" name="intensidade_sinal_18s">
                                <option value="">Selecione...</option>
                                <option value="Forte">Forte</option>
                                <option value="Médio">Médio</option>
                                <option value="Fraco">Fraco</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- ALVO CytB (Gene do Citocromo B) -->
                    <h3 style="color: #2d3748; margin: 25px 0 15px 0; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;">
                        🧬 Alvo CytB (Gene do Citocromo B)
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label>Resultado LAMP - CytB (opcional - preencha quando tiver resultado)</label>
                            <div class="radio-group">
                                <div class="radio-item">
                                    <input type="radio" id="babesia_cytb_pos" name="babesia_cytb_lamp" value="Positivo">
                                    <label for="babesia_cytb_pos">✅ Positivo</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="babesia_cytb_neg" name="babesia_cytb_lamp" value="Negativo">
                                    <label for="babesia_cytb_neg">❌ Negativo</label>
                                </div>
                                <div class="radio-item">
                                    <input type="radio" id="babesia_cytb_inc" name="babesia_cytb_lamp" value="Inconclusivo">
                                    <label for="babesia_cytb_inc">❓ Inconclusivo</label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="tempo_deteccao_cytb">Tempo de Detecção CytB (min)</label>
                            <input type="number" id="tempo_deteccao_cytb" name="tempo_deteccao_cytb_min" min="0" max="120">
                        </div>
                        
                        <div class="form-group">
                            <label for="intensidade_sinal_cytb">Intensidade do Sinal CytB</label>
                            <select id="intensidade_sinal_cytb" name="intensidade_sinal_cytb">
                                <option value="">Selecione...</option>
                                <option value="Forte">Forte</option>
                                <option value="Médio">Médio</option>
                                <option value="Fraco">Fraco</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Dados Gerais do Teste -->
                    <h3 style="color: #2d3748; margin: 25px 0 15px 0; padding-bottom: 8px; border-bottom: 2px solid #e2e8f0;">
                        🧪 Dados Gerais do Teste
                    </h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="temperatura_reacao">Temperatura de Reação (°C)</label>
                            <input type="number" id="temperatura_reacao" name="temperatura_reacao" step="0.1" value="65.0">
                        </div>
                        
                        <div class="form-group">
                            <label for="custo_teste">Custo do Teste (R$)</label>
                            <input type="number" id="custo_teste" name="custo_teste" step="0.01" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="tempo_total">Tempo Total (min)</label>
                            <input type="number" id="tempo_total" name="tempo_total_min" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="analista_lamp">Analista</label>
                            <input type="text" id="analista_lamp" name="analista">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="observacoes_lamp">Observações</label>
                        <textarea id="observacoes_lamp" name="observacoes" rows="3" placeholder="Observações sobre o teste LAMP (ambos os alvos)..."></textarea>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">
                        💾 Salvar Resultados LAMP (2 Alvos)
                    </button>
                </form>
            </div>

            <!-- SEÇÃO: DADOS CLIMÁTICOS -->
            <div id="clima-section" class="form-section">
                <h2 class="section-title">Dados Climáticos</h2>
                
                <div style="background: #bee3f8; border-left: 4px solid #2b6cb0; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                    <strong>💡 Informação:</strong> Os dados climáticos são calculados automaticamente com base na <strong>data de coleta</strong> e consideram a <strong>média dos 90 dias anteriores</strong>. Você pode ajustar o período manualmente se necessário.
                </div>
                
                <form id="form-clima" method="POST" action="processa_formulario.php">
                    <input type="hidden" name="tipo" value="clima">
                    
                    <div class="form-grid">
                        <div class="form-group required">
                            <label for="local_clima">Local</label>
                            <select id="local_clima" name="local_id" required>
                                <option value="">Selecione o Local...</option>
                                <?php foreach ($locais as $local): ?>
                                    <option value="<?php echo $local['id']; ?>">
                                        <?php echo htmlspecialchars($local['nome'] . ' - ' . $local['municipio']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group required">
                            <label for="data_coleta_clima">Data da Coleta</label>
                            <input type="date" id="data_coleta_clima" name="data_coleta" required onchange="calcular90DiasAnteriores()">
                        </div>
                    </div>
                    
                    <!-- Período de Análise (90 dias) -->
                    <div style="background: #f7fafc; border: 2px solid #e2e8f0; padding: 15px; margin-bottom: 20px; border-radius: 8px;">
                        <h3 style="color: #2d3748; margin-bottom: 15px; font-size: 1.1em;">📅 Período de Análise Climática</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label for="data_inicio_clima">Data Inicial (90 dias antes)</label>
                                <input type="date" id="data_inicio_clima" name="data_inicio_periodo" readonly style="background: #edf2f7;">
                            </div>
                            <div class="form-group">
                                <label for="data_fim_clima">Data Final (data da coleta)</label>
                                <input type="date" id="data_fim_clima" name="data_fim_periodo" readonly style="background: #edf2f7;">
                            </div>
                            <div class="form-group">
                                <label>&nbsp;</label>
                                <button type="button" class="btn btn-secondary" onclick="habilitarEdicaoPeriodo()" style="width: 100%;">
                                    ✏️ Editar Período Manualmente
                                </button>
                            </div>
                        </div>
                        <p id="info-periodo" style="margin-top: 10px; color: #718096; font-size: 14px;"></p>
                    </div>

                    <!-- Seleção de Fonte de Dados -->
                    <div style="background: #e6fffa; border-left: 4px solid #2f855a; padding: 15px; margin-bottom: 20px; border-radius: 4px;">
                        <strong>💾 Fonte de Dados Climáticos:</strong>
                        <div style="display: grid; grid-template-columns: 1fr auto; gap: 10px; margin-top: 10px; align-items: end;">
                            <div class="form-group" style="margin-bottom: 0;">
                                <label for="fonte_dados_clima">Selecione a Fonte</label>
                                <select id="fonte_dados_clima" style="width: 100%; padding: 8px; border: 2px solid #e2e8f0; border-radius: 5px;">
                                    <option value="banco">Banco de Dados (Dados já cadastrados)</option>
                                    <option value="inmet">INMET (Brasil - Gratuito)</option>
                                    <option value="nasa">NASA POWER (Global - Gratuito)</option>
                                    <option value="simulado">Dados Simulados (Teste)</option>
                                </select>
                            </div>
                            <button type="button" class="btn btn-secondary" onclick="confirmarFonteEBuscarDados()" style="background: #4299e1; margin-bottom: 0;">
                                ✓ Confirmar e Buscar
                            </button>
                        </div>
                        <div id="status-fonte-clima" style="margin-top: 10px; padding: 10px; border-radius: 4px; display: none;"></div>
                    </div>
                    
                    
                    <!-- Dados de Temperatura -->
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="temp_media">Temperatura Média (°C)</label>
                            <input type="number" id="temp_media" name="temperatura_media" step="0.1">
                        </div>
                        
                        <div class="form-group">
                            <label for="temp_maxima">Temperatura Máxima (°C)</label>
                            <input type="number" id="temp_maxima" name="temperatura_maxima" step="0.1">
                        </div>
                        
                        <div class="form-group">
                            <label for="temp_minima">Temperatura Mínima (°C)</label>
                            <input type="number" id="temp_minima" name="temperatura_minima" step="0.1">
                        </div>
                        
                        <div class="form-group">
                            <label for="pluviometria">Precipitação (mm)</label>
                            <input type="number" id="pluviometria" name="pluviometria_mm" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="umidade">Umidade Relativa (%)</label>
                            <input type="number" id="umidade" name="umidade_relativa" step="0.1" min="0" max="100">
                        </div>
                        
                        <div class="form-group">
                            <label for="pressao">Pressão Atmosférica (hPa)</label>
                            <input type="number" id="pressao" name="pressao_atmosferica" step="0.1">
                        </div>
                        
                        <div class="form-group">
                            <label for="vento">Velocidade do Vento (km/h)</label>
                            <input type="number" id="vento" name="velocidade_vento" step="0.1" min="0">
                        </div>
                        
                        <div class="form-group">
                            <label for="fonte_dados">Fonte dos Dados</label>
                            <input type="text" id="fonte_dados" name="fonte_dados" placeholder="Ex: INMET, Estação Local">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="observacoes_clima">Observações</label>
                        <textarea id="observacoes_clima" name="observacoes" rows="3"></textarea>
                    </div>
                    
                    <!-- Botões de Ação -->
                    <div style="display: flex; gap: 10px; flex-wrap: wrap; margin-top: 20px;">
                        <button type="button" class="btn btn-secondary" onclick="calcularVariacoesClimaticas()" style="background: #4299e1;">
                            📊 Calcular Variações Climáticas
                        </button>
                        <button type="submit" class="btn btn-primary">
                            💾 Salvar Dados Climáticos
                        </button>
                    </div>
                    
                    <!-- Resultado do Cálculo -->
                    <div id="resultado-calculo-clima" style="margin-top: 20px;"></div>
                </form>

                <!-- Seção: Dados Climáticos Salvos -->
                <div style="margin-top: 40px; padding-top: 30px; border-top: 2px solid #e2e8f0;">
                    <h3 style="color: #2d3748; margin-bottom: 20px;">
                        📋 Dados Climáticos Já Salvos
                    </h3>

                    <div id="tabela-clima-salvo" style="overflow-x: auto;">
                        <p style="text-align: center; color: #718096; padding: 20px;">
                            Carregando dados...
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // ============================================
        // CONTROLE DE TABS
        // ============================================
        function showTab(tabName) {
            // Esconder todas as seções
            document.querySelectorAll('.form-section').forEach(section => {
                section.classList.remove('active');
            });
            
            // Remover classe active de todos os tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Mostrar seção selecionada
            document.getElementById(tabName + '-section').classList.add('active');
            
            // Adicionar classe active ao tab clicado
            event.target.classList.add('active');
            
            // Carregar dados específicos da seção
            if (tabName === 'pcr-lamp') {
                carregarColetas('pcr');
                carregarColetas('lamp');
            } else if (tabName === 'clima') {
                carregarDadosClimaticos();
            }
        }
        
        // ============================================
        // AUTO-FETCH CLIMA (INMET)
        // ============================================
        function preencherCamposClima(reg) {
            if (!reg) return;
            const m = (v)=> v !== null && v !== undefined ? Number(v).toFixed(2) : '';
            document.getElementById('temp_media').value = reg.temperatura_media ?? '';
            document.getElementById('temp_maxima').value = reg.temperatura_maxima ?? '';
            document.getElementById('temp_minima').value = reg.temperatura_minima ?? '';
            document.getElementById('pluviometria').value = reg.pluviometria_mm ?? '';
            document.getElementById('umidade').value = reg.umidade_relativa ?? '';
            document.getElementById('pressao').value = reg.pressao_atmosferica ?? '';
            document.getElementById('vento').value = reg.velocidade_vento ?? '';
            document.getElementById('fonte_dados').value = reg.fonte_dados || '';
        }

        async function buscarClimaDia() {
            const localId = document.getElementById('local_clima').value;
            const data = document.getElementById('data_registro').value;
            if (!localId || !data) return;
            try {
                const btn = document.querySelector('#form-clima .btn.btn-primary');
                const old = btn.innerHTML; btn.disabled = true; btn.innerHTML = '⏳ Carregando...';
                const resp = await fetch(`api_clima.php?action=diario&local_id=${encodeURIComponent(localId)}&data=${encodeURIComponent(data)}`);
                const json = await resp.json();
                if (json && json.dados && json.dados.length > 0) {
                    const reg = json.dados[0];
                    if (reg.sucesso) {
                        preencherCamposClima(reg);
                    }
                }
                btn.disabled = false; btn.innerHTML = old;
            } catch (e) { console.error('Erro clima (dia):', e); }
        }

        async function buscarClimaPeriodo() {
            const localId = document.getElementById('local_clima').value;
            const di = document.getElementById('data_inicio_clima').value;
            const df = document.getElementById('data_fim_clima').value;
            const base = document.getElementById('data_registro').value;
            const fonte = document.getElementById('fonte_dados').value;
            
            if (!localId) { alert('Selecione um local'); return; }
            const dataInicio = di || base; const dataFim = df || di || base;
            if (!dataInicio || !dataFim) { alert('Informe ao menos Data do Registro ou o período.'); return; }
            
            const cont = document.getElementById('clima-periodo-container');
            const tbody = document.querySelector('#tabela-clima-periodo tbody');
            const info = document.getElementById('clima-periodo-info');
            cont.style.display = 'block';
            
            const fonteNome = fonte === 'nasa' ? 'NASA POWER' : fonte === 'inmet' ? 'INMET' : 'Dados Simulados';
            tbody.innerHTML = `<tr><td colspan="7" style="padding:10px; text-align:center;">⏳ Carregando dados de ${fonteNome}...</td></tr>`;
            info.textContent = '';
            
            try {
                const url = `api_clima.php?action=diario&local_id=${encodeURIComponent(localId)}&data_inicio=${encodeURIComponent(dataInicio)}&data_fim=${encodeURIComponent(dataFim)}&fonte=${encodeURIComponent(fonte)}`;
                const resp = await fetch(url);
                const json = await resp.json();
                
                if (json.erro) throw new Error(json.erro);
                if (!json || !json.dados) throw new Error('Resposta inválida');
                
                const linhas = json.dados.map(d => {
                    const format = (v)=> (v===null||v===undefined)?'-':Number(v).toFixed(1);
                    const disabled = d.sucesso ? '' : 'disabled title="Sem dados para esta data"';
                    return `<tr>
                        <td style="padding:8px; border-bottom:1px solid #e2e8f0;">${d.data}</td>
                        <td style="padding:8px; border-bottom:1px solid #e2e8f0;">${format(d.temperatura_media)}</td>
                        <td style="padding:8px; border-bottom:1px solid #e2e8f0;">${format(d.temperatura_maxima)}</td>
                        <td style="padding:8px; border-bottom:1px solid #e2e8f0;">${format(d.temperatura_minima)}</td>
                        <td style="padding:8px; border-bottom:1px solid #e2e8f0;">${format(d.pluviometria_mm)}</td>
                        <td style="padding:8px; border-bottom:1px solid #e2e8f0;">${format(d.umidade_relativa)}</td>
                        <td style="padding:8px; border-bottom:1px solid #e2e8f0;"><button type="button" class="btn btn-secondary" ${disabled} onclick='aplicarClimaDia(${JSON.stringify(d).replace(/"/g,"&quot;")})'> Aplicar</button></td>
                    </tr>`;
                }).join('');
                tbody.innerHTML = linhas || '<tr><td colspan="7" style="padding:10px; text-align:center;">Sem dados para o período.</td></tr>';
                info.textContent = `Fonte: ${fonteNome} | ${json.dados.length} dia(s) carregado(s)`;
            } catch (e) {
                console.error('Erro clima (período):', e);
                tbody.innerHTML = `<tr><td colspan="7" style="padding:10px; text-align:center; color:#c53030;">Erro: ${e.message || 'Falha ao buscar dados'}</td></tr>`;
            }
        }

        function aplicarClimaDia(d) {
            if (!d) return;
            document.getElementById('data_registro').value = d.data;
            preencherCamposClima(d);
            // rolar até o botão salvar
            document.getElementById('form-clima').scrollIntoView({behavior:'smooth', block:'center'});
        }

        document.getElementById('local_clima').addEventListener('change', buscarClimaDia);
        document.getElementById('data_registro').addEventListener('change', buscarClimaDia);

        // ============================================
        // CARREGAR ANIMAIS POR LOCAL
        // ============================================
        function carregarAnimais(localId, selectId) {
            const select = document.getElementById(selectId);
            
            if (!localId) {
                select.innerHTML = '<option value="">Primeiro selecione o local...</option>';
                select.disabled = true;
                return;
            }
            
            select.innerHTML = '<option value="">Carregando animais...</option>';
            select.disabled = true;
            
            fetch(`processa_formulario.php?action=getAnimais&local_id=${localId}`)
                .then(response => response.json())
                .then(data => {
                    select.innerHTML = '<option value="">Selecione o animal...</option>';
                    
                    if (data.length > 0) {
                        data.forEach(animal => {
                            const option = document.createElement('option');
                            option.value = animal.id;
                            option.textContent = `${animal.identificacao} - ${animal.raca || 'Sem raça'} (${animal.sexo})`;
                            select.appendChild(option);
                        });
                        select.disabled = false;
                    } else {
                        select.innerHTML = '<option value="">Nenhum animal cadastrado neste local</option>';
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar animais:', error);
                    select.innerHTML = '<option value="">Erro ao carregar animais</option>';
                });
        }
        
        // ============================================
        // CARREGAR COLETAS PARA PCR/LAMP
        // ============================================
        function carregarColetas(tipo) {
            const select = document.getElementById(`coleta_${tipo}`);
            select.innerHTML = '<option value="">Carregando coletas...</option>';
            
            fetch('processa_formulario.php?action=getColetas')
                .then(response => response.json())
                .then(data => {
                    select.innerHTML = '<option value="">Selecione a coleta...</option>';
                    
                    if (data.length > 0) {
                        data.forEach(coleta => {
                            const option = document.createElement('option');
                            option.value = coleta.id;
                            option.textContent = `${coleta.data_coleta} - ${coleta.animal} (${coleta.local_nome})`;
                            select.appendChild(option);
                        });
                    } else {
                        select.innerHTML = '<option value="">Nenhuma coleta disponível</option>';
                    }
                })
                .catch(error => {
                    console.error('Erro ao carregar coletas:', error);
                    select.innerHTML = '<option value="">Erro ao carregar coletas</option>';
                });
        }
        
        // ============================================
        // AUTO-DETECTAR ESTAÇÃO PELA DATA
        // ============================================
        document.getElementById('data_coleta').addEventListener('change', function() {
            const data = new Date(this.value);
            const mes = data.getMonth() + 1;
            
            let estacao = '';
            if ([12, 1, 2].includes(mes)) estacao = 'Verão';
            else if ([3, 4, 5].includes(mes)) estacao = 'Outono';
            else if ([6, 7, 8].includes(mes)) estacao = 'Inverno';
            else if ([9, 10, 11].includes(mes)) estacao = 'Primavera';
            
            document.getElementById('estacao').value = estacao;
        });
        
        // ============================================
        // VALIDAÇÕES E MELHORIAS UX
        // ============================================
        
        // Definir data de hoje como padrão
        document.addEventListener('DOMContentLoaded', function() {
            const hoje = new Date().toISOString().split('T')[0];
            document.querySelectorAll('input[type="date"]').forEach(input => {
                if (!input.value) {
                    input.value = hoje;
                }
            });
        });
        
        // Validação de coordenadas
        document.getElementById('latitude').addEventListener('input', function() {
            const lat = parseFloat(this.value);
            if (lat < -90 || lat > 90) {
                this.setCustomValidity('Latitude deve estar entre -90 e 90');
            } else {
                this.setCustomValidity('');
            }
        });
        
        document.getElementById('longitude').addEventListener('input', function() {
            const lng = parseFloat(this.value);
            if (lng < -180 || lng > 180) {
                this.setCustomValidity('Longitude deve estar entre -180 e 180');
            } else {
                this.setCustomValidity('');
            }
        });
        
        // Submissão de formulários com loading
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const btn = this.querySelector('button[type="submit"]');
                btn.innerHTML = '⏳ Salvando...';
                btn.disabled = true;
                
                // Reabilitar botão após 5 segundos para evitar travamento
                setTimeout(() => {
                    btn.disabled = false;
                    btn.innerHTML = btn.innerHTML.replace('Salvando...', 'Salvar');
                }, 5000);
            });
        });
        
        // ============================================
        // PREENCHER FAZENDA QUINTAS BARBOSA
        // ============================================
        function preencherFazendaQuintasBarbosa() {
            document.getElementById('nome_local').value = 'Fazenda Quintas Barbosa';
            document.getElementById('municipio').value = 'Japeri';
            document.getElementById('latitude').value = '-22.654361';
            document.getElementById('longitude').value = '-43.556251';
            document.getElementById('propriedade').value = 'Fazenda Quintas Barbosa';
            document.getElementById('descricao_local').value = 'Local principal de coletas de amostras';
            
            // Scroll suave para o formulário
            document.getElementById('nome_local').scrollIntoView({ behavior: 'smooth', block: 'center' });
            
            // Destacar campos preenchidos
            ['nome_local', 'municipio', 'latitude', 'longitude'].forEach(id => {
                const el = document.getElementById(id);
                el.style.backgroundColor = '#c6f6d5';
                setTimeout(() => {
                    el.style.backgroundColor = '';
                }, 2000);
            });
            
            alert('✓ Dados da Fazenda Quintas Barbosa preenchidos!');
        }
        
        // ============================================
        // FUNÇÕES PARA PCR - CARREGAR ANIMAIS E COLETAS
        // ============================================
        async function carregarAnimaisPCR() {
            const localId = document.getElementById('local_pcr').value;
            const selectAnimal = document.getElementById('animal_pcr');
            const selectColeta = document.getElementById('coleta_pcr');
            
            selectAnimal.innerHTML = '<option value="">Carregando animais...</option>';
            selectColeta.innerHTML = '<option value="">Selecione o animal primeiro</option>';
            
            if (!localId) {
                selectAnimal.innerHTML = '<option value="">Selecione a propriedade primeiro</option>';
                return;
            }
            
            try {
                const response = await fetch(`api_dashboard.php?action=listarAnimais&local_id=${localId}`);
                const animais = await response.json();
                
                if (animais && animais.length > 0) {
                    selectAnimal.innerHTML = '<option value="">Selecione um animal</option>';
                    animais.forEach(animal => {
                        const option = document.createElement('option');
                        option.value = animal.animal_id;
                        // Exibir código de identificação
                        const display = animal.identificacao || 'N/A';
                        option.textContent = `ID: ${display}`;
                        selectAnimal.appendChild(option);
                    });
                } else {
                    selectAnimal.innerHTML = '<option value="">Nenhum animal encontrado</option>';
                }
            } catch (error) {
                console.error('Erro ao carregar animais:', error);
                selectAnimal.innerHTML = '<option value="">Erro ao carregar animais</option>';
            }
        }
        
        async function carregarColetasPCR() {
            const animalId = document.getElementById('animal_pcr').value;
            const selectColeta = document.getElementById('coleta_pcr');
            
            selectColeta.innerHTML = '<option value="">Carregando coletas...</option>';
            
            if (!animalId) {
                selectColeta.innerHTML = '<option value="">Selecione o animal primeiro</option>';
                return;
            }
            
            try {
                const response = await fetch(`api_dashboard.php?action=listarAnimais&animal_id=${animalId}`);
                const coletas = await response.json();
                
                if (coletas && coletas.length > 0) {
                    selectColeta.innerHTML = '<option value="">Selecione uma coleta</option>';
                    coletas.forEach(coleta => {
                        const option = document.createElement('option');
                        option.value = coleta.coleta_id;
                        option.textContent = `Coleta: ${formatarData(coleta.data_coleta)}`;
                        selectColeta.appendChild(option);
                    });
                } else {
                    selectColeta.innerHTML = '<option value="">Nenhuma coleta encontrada</option>';
                }
            } catch (error) {
                console.error('Erro ao carregar coletas:', error);
                selectColeta.innerHTML = '<option value="">Erro ao carregar coletas</option>';
            }
        }
        
        // ============================================
        // FUNÇÕES PARA LAMP - CARREGAR ANIMAIS E COLETAS
        // ============================================
        async function carregarAnimaisLAMP() {
            const localId = document.getElementById('local_lamp').value;
            const selectAnimal = document.getElementById('animal_lamp');
            const selectColeta = document.getElementById('coleta_lamp');
            
            selectAnimal.innerHTML = '<option value="">Carregando animais...</option>';
            selectColeta.innerHTML = '<option value="">Selecione o animal primeiro</option>';
            
            if (!localId) {
                selectAnimal.innerHTML = '<option value="">Selecione a propriedade primeiro</option>';
                return;
            }
            
            try {
                const response = await fetch(`api_dashboard.php?action=listarAnimais&local_id=${localId}`);
                const animais = await response.json();
                
                if (animais && animais.length > 0) {
                    selectAnimal.innerHTML = '<option value="">Selecione um animal</option>';
                    animais.forEach(animal => {
                        const option = document.createElement('option');
                        option.value = animal.animal_id;
                        // Exibir código de identificação
                        const display = animal.identificacao || 'N/A';
                        option.textContent = `ID: ${display}`;
                        selectAnimal.appendChild(option);
                    });
                } else {
                    selectAnimal.innerHTML = '<option value="">Nenhum animal encontrado</option>';
                }
            } catch (error) {
                console.error('Erro ao carregar animais:', error);
                selectAnimal.innerHTML = '<option value="">Erro ao carregar animais</option>';
            }
        }
        
        async function carregarColetasLAMP() {
            const animalId = document.getElementById('animal_lamp').value;
            const selectColeta = document.getElementById('coleta_lamp');
            
            selectColeta.innerHTML = '<option value="">Carregando coletas...</option>';
            
            if (!animalId) {
                selectColeta.innerHTML = '<option value="">Selecione o animal primeiro</option>';
                return;
            }
            
            try {
                const response = await fetch(`api_dashboard.php?action=listarAnimais&animal_id=${animalId}`);
                const coletas = await response.json();
                
                if (coletas && coletas.length > 0) {
                    selectColeta.innerHTML = '<option value="">Selecione uma coleta</option>';
                    coletas.forEach(coleta => {
                        const option = document.createElement('option');
                        option.value = coleta.coleta_id;
                        option.textContent = `Coleta: ${formatarData(coleta.data_coleta)}`;
                        selectColeta.appendChild(option);
                    });
                } else {
                    selectColeta.innerHTML = '<option value="">Nenhuma coleta encontrada</option>';
                }
            } catch (error) {
                console.error('Erro ao carregar coletas:', error);
                selectColeta.innerHTML = '<option value="">Erro ao carregar coletas</option>';
            }
        }
        
        // Função auxiliar para formatar data
        function formatarData(data) {
            if (!data) return '';
            const partes = data.split('-');
            if (partes.length === 3) {
                return `${partes[2]}/${partes[1]}/${partes[0]}`;
            }
            return data;
        }
        
        // ============================================
        // FUNÇÕES PARA COLETAS - SELEÇÃO MÚLTIPLA DE ANIMAIS
        // ============================================
        async function carregarAnimaisMultiplos() {
            const localId = document.getElementById('local_coleta').value;
            const selectAnimal = document.getElementById('animal_coleta');
            const displayDiv = document.getElementById('animais-selecionados-display');
            
            selectAnimal.innerHTML = '<option value="">Carregando animais...</option>';
            selectAnimal.disabled = true;
            displayDiv.style.display = 'none';
            
            if (!localId) {
                selectAnimal.innerHTML = '<option value="">Primeiro selecione o local...</option>';
                return;
            }
            
            try {
                const response = await fetch(`api_dashboard.php?action=listarAnimais&local_id=${localId}`);
                const animais = await response.json();
                
                if (animais && animais.length > 0) {
                    selectAnimal.innerHTML = '';
                    selectAnimal.disabled = false;
                    
                    animais.forEach(animal => {
                        const option = document.createElement('option');
                        option.value = animal.animal_id;
                        // Exibir código de identificação
                        const display = animal.identificacao || 'N/A';
                        option.textContent = `ID: ${display}`;
                        selectAnimal.appendChild(option);
                    });
                    
                    // Adicionar instrução no topo
                    const instrucao = document.createElement('option');
                    instrucao.value = '';
                    instrucao.textContent = '--- Selecione um ou mais animais (Ctrl/Cmd + Click) ---';
                    instrucao.disabled = true;
                    selectAnimal.insertBefore(instrucao, selectAnimal.firstChild);
                } else {
                    selectAnimal.innerHTML = '<option value="">Nenhum animal encontrado neste local</option>';
                    selectAnimal.disabled = true;
                }
            } catch (error) {
                console.error('Erro ao carregar animais:', error);
                selectAnimal.innerHTML = '<option value="">Erro ao carregar animais</option>';
                selectAnimal.disabled = true;
            }
        }
        
        function atualizarAnimaisSelecionados() {
            const selectAnimal = document.getElementById('animal_coleta');
            const displayDiv = document.getElementById('animais-selecionados-display');
            const listaDiv = document.getElementById('lista-animais-selecionados');
            const hiddenInput = document.getElementById('animais_selecionados');
            
            const selecionados = Array.from(selectAnimal.selectedOptions)
                .filter(opt => opt.value !== '');
            
            if (selecionados.length > 0) {
                displayDiv.style.display = 'block';
                
                const ids = selecionados.map(opt => opt.value);
                hiddenInput.value = ids.join(',');
                
                const nomes = selecionados.map((opt, index) => 
                    `<div style="padding: 5px 0; border-bottom: 1px solid #e2e8f0;">
                        <strong>${index + 1}.</strong> ${opt.textContent}
                    </div>`
                ).join('');
                
                listaDiv.innerHTML = nomes;
                
                // Atualizar contador no label
                const label = document.querySelector('label[for="animal_coleta"]');
                label.textContent = `🐄 Animais (${selecionados.length} selecionado${selecionados.length > 1 ? 's' : ''})`;
            } else {
                displayDiv.style.display = 'none';
                hiddenInput.value = '';
                
                const label = document.querySelector('label[for="animal_coleta"]');
                label.textContent = '🐄 Animais (Selecione um ou mais)';
            }
        }
        
        // ============================================
        // FUNÇÕES PARA DADOS CLIMÁTICOS
        // ============================================
        
        function calcular90DiasAnteriores() {
            const dataColetaInput = document.getElementById('data_coleta_clima');
            const dataInicioInput = document.getElementById('data_inicio_clima');
            const dataFimInput = document.getElementById('data_fim_clima');
            const infoPeriodo = document.getElementById('info-periodo');
            
            if (!dataColetaInput.value) {
                return;
            }
            
            // Data da coleta
            const dataColeta = new Date(dataColetaInput.value + 'T00:00:00');
            
            // Calcular 90 dias antes
            const dataInicio = new Date(dataColeta);
            dataInicio.setDate(dataInicio.getDate() - 90);
            
            // Formatar datas para input (YYYY-MM-DD)
            const dataInicioStr = dataInicio.toISOString().split('T')[0];
            const dataFimStr = dataColeta.toISOString().split('T')[0];
            
            // Preencher campos
            dataInicioInput.value = dataInicioStr;
            dataFimInput.value = dataFimStr;
            
            // Mostrar informação
            const dataInicioFormatada = dataInicio.toLocaleDateString('pt-BR');
            const dataColetaFormatada = dataColeta.toLocaleDateString('pt-BR');
            
            infoPeriodo.innerHTML = `
                <strong>📊 Período Calculado:</strong> ${dataInicioFormatada} até ${dataColetaFormatada} (90 dias)<br>
                <em>Este é o período padrão para análise climática. Você pode editá-lo se necessário.</em>
            `;
            infoPeriodo.style.color = '#2f855a';
        }
        
        function habilitarEdicaoPeriodo() {
            const dataInicioInput = document.getElementById('data_inicio_clima');
            const dataFimInput = document.getElementById('data_fim_clima');
            const infoPeriodo = document.getElementById('info-periodo');
            
            if (dataInicioInput.readOnly) {
                // Habilitar edição
                dataInicioInput.readOnly = false;
                dataFimInput.readOnly = false;
                dataInicioInput.style.background = 'white';
                dataFimInput.style.background = 'white';
                
                infoPeriodo.innerHTML = `
                    <strong>⚠️ Modo de Edição Manual Ativado</strong><br>
                    <em>Você pode agora modificar o período de análise. Lembre-se: o padrão é 90 dias antes da coleta.</em>
                `;
                infoPeriodo.style.color = '#c05621';
                
                event.target.textContent = '🔒 Bloquear Edição';
            } else {
                // Desabilitar edição e recalcular
                dataInicioInput.readOnly = true;
                dataFimInput.readOnly = true;
                dataInicioInput.style.background = '#edf2f7';
                dataFimInput.style.background = '#edf2f7';
                
                calcular90DiasAnteriores();
                
                event.target.textContent = '✏️ Editar Período Manualmente';
            }
        }
        
        // ============================================
        // CONFIRMAR FONTE E BUSCAR DADOS
        // ============================================
        async function confirmarFonteEBuscarDados() {
            const fonte = document.getElementById('fonte_dados_clima').value;
            const localId = document.getElementById('local_clima').value;
            const dataColeta = document.getElementById('data_coleta_clima').value;
            const statusDiv = document.getElementById('status-fonte-clima');
            
            // Validar campos
            if (!localId) {
                alert('⚠️ Por favor, selecione um local primeiro.');
                return;
            }
            
            if (!dataColeta) {
                alert('⚠️ Por favor, informe a data da coleta.');
                return;
            }
            
            // Mostrar status
            statusDiv.style.display = 'block';
            statusDiv.style.background = '#ebf8ff';
            statusDiv.style.border = '1px solid #4299e1';
            statusDiv.style.color = '#2c5282';
            
            const fonteNome = {
                'banco': 'Banco de Dados',
                'inmet': 'INMET',
                'nasa': 'NASA POWER',
                'simulado': 'Dados Simulados'
            }[fonte] || fonte;
            
            statusDiv.innerHTML = `✓ Fonte confirmada: <strong>${fonteNome}</strong><br><small>Clique em "Calcular Variações Climáticas" para buscar os dados.</small>`;
        }
        
        // ============================================
        // CALCULAR VARIAÇÕES CLIMÁTICAS
        // ============================================
        async function calcularVariacoesClimaticas() {
            console.log('🔍 Iniciando cálculo de variações climáticas...');
            
            const localId = document.getElementById('local_clima').value;
            const dataColeta = document.getElementById('data_coleta_clima').value;
            const fonte = document.getElementById('fonte_dados_clima').value;
            const resultadoDiv = document.getElementById('resultado-calculo-clima');
            
            // Validar campos
            if (!localId) {
                alert('⚠️ Por favor, selecione um local primeiro.');
                return;
            }
            
            if (!dataColeta) {
                alert('⚠️ Por favor, informe a data da coleta.');
                return;
            }
            
            // Mostrar loading
            resultadoDiv.innerHTML = '<div style="background: #f7fafc; padding: 20px; border-radius: 8px; text-align: center;"><div style="display: inline-block; width: 40px; height: 40px; border: 4px solid #e2e8f0; border-top-color: #4299e1; border-radius: 50%; animation: spin 1s linear infinite;"></div><p style="margin-top: 10px; color: #4a5568;">Calculando variações climáticas da fonte: ' + fonte + '...</p></div>';
            
            try {
                // Chamar API de cálculo com fonte selecionada
                const response = await fetch(`api_clima.php?action=calcular_variacoes&local_id=${localId}&data_coleta=${dataColeta}&fonte=${fonte}`);
                const data = await response.json();
                
                console.log('✅ Dados recebidos:', data);
                
                if (data.sucesso) {
                    // Preencher campos automaticamente
                    if (data.temperatura_media !== null) document.getElementById('temp_media').value = data.temperatura_media;
                    if (data.temperatura_max !== null) document.getElementById('temp_maxima').value = data.temperatura_max;
                    if (data.temperatura_min !== null) document.getElementById('temp_minima').value = data.temperatura_min;
                    if (data.pluviometria_media !== null) document.getElementById('pluviometria').value = data.pluviometria_media;
                    if (data.umidade_media !== null) document.getElementById('umidade').value = data.umidade_media;
                    
                    // Verificar se são dados simulados
                    const ehSimulado = data.fonte && data.fonte.includes('Simulados');
                    const corBorda = ehSimulado ? '#f59e0b' : '#48bb78';
                    const corFundo = ehSimulado ? '#fffbeb' : '#f0fff4';
                    const icone = ehSimulado ? '⚠️' : '✅';
                    const titulo = ehSimulado ? 'Cálculo Concluído (Dados Simulados)' : 'Cálculo Concluído';
                    
                    // Mostrar resultado
                    let html = '<div style="background: white; padding: 20px; border-radius: 8px; border: 2px solid ' + corBorda + ';">';
                    html += '<h4 style="color: #2f855a; margin-top: 0;">' + icone + ' ' + titulo + '</h4>';
                    
                    if (ehSimulado) {
                        html += '<div style="background: #fef3c7; padding: 12px; border-radius: 6px; margin-bottom: 15px; border-left: 4px solid #f59e0b;">';
                        html += '<p style="margin: 0; color: #92400e;"><strong>⚠️ Aviso:</strong> Não há dados climáticos reais cadastrados para este local e período. Os valores foram gerados com base em médias regionais e sazonais.</p>';
                        html += '</div>';
                    }
                    
                    html += '<div style="background: ' + corFundo + '; padding: 15px; border-radius: 6px; margin-bottom: 15px;">';
                    html += '<p style="margin: 5px 0;"><strong>📊 Período analisado:</strong> ' + data.dias_com_dados + ' dias com dados</p>';
                    html += '<p style="margin: 5px 0;"><strong>📅 Período:</strong> ' + data.periodo_inicio + ' até ' + data.periodo_fim + '</p>';
                    if (data.fonte) {
                        html += '<p style="margin: 5px 0;"><strong>🔍 Fonte:</strong> ' + data.fonte + '</p>';
                    }
                    html += '</div>';
                    
                    html += '<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">';
                    html += '<div style="background: #f7fafc; padding: 12px; border-radius: 6px;"><strong>🌡️ Temperatura Média:</strong><br>' + data.temperatura_media + ' °C</div>';
                    html += '<div style="background: #f7fafc; padding: 12px; border-radius: 6px;"><strong>🔺 Temp. Máxima:</strong><br>' + data.temperatura_max + ' °C</div>';
                    html += '<div style="background: #f7fafc; padding: 12px; border-radius: 6px;"><strong>🔻 Temp. Mínima:</strong><br>' + data.temperatura_min + ' °C</div>';
                    html += '<div style="background: #f7fafc; padding: 12px; border-radius: 6px;"><strong>🌧️ Pluviometria Média:</strong><br>' + data.pluviometria_media + ' mm</div>';
                    html += '<div style="background: #f7fafc; padding: 12px; border-radius: 6px;"><strong>💧 Umidade Média:</strong><br>' + data.umidade_media + ' %</div>';
                    html += '<div style="background: #f7fafc; padding: 12px; border-radius: 6px;"><strong>🌊 Pluviometria Total:</strong><br>' + data.pluviometria_total + ' mm</div>';
                    html += '</div>';
                    
                    html += '<p style="margin-top: 15px; color: #2f855a; font-weight: 600;">✅ Os campos foram preenchidos automaticamente. Você pode editá-los se necessário antes de salvar.</p>';
                    html += '</div>';
                    
                    resultadoDiv.innerHTML = html;
                } else {
                    resultadoDiv.innerHTML = '<div style="background: #fff5f5; padding: 20px; border-radius: 8px; border: 2px solid #e53e3e; color: #c53030;"><strong>❌ Erro:</strong> ' + (data.erro || 'Não foi possível calcular as variações climáticas.') + '</div>';
                }
            } catch (error) {
                console.error('❌ Erro:', error);
                resultadoDiv.innerHTML = '<div style="background: #fff5f5; padding: 20px; border-radius: 8px; border: 2px solid #e53e3e; color: #c53030;"><strong>❌ Erro:</strong> ' + error.message + '<br><small>Verifique se há dados climáticos cadastrados para este local e período.</small></div>';
            }
        }
        
        // Inicializar com data de hoje se vazio
        document.addEventListener('DOMContentLoaded', function() {
            const dataColetaClima = document.getElementById('data_coleta_clima');
            if (!dataColetaClima.value) {
                dataColetaClima.value = new Date().toISOString().split('T')[0];
                calcular90DiasAnteriores();
            }
            
            // Adicionar validação ao formulário de coletas
            const formColeta = document.getElementById('form-coleta');
            if (formColeta) {
                formColeta.addEventListener('submit', function(e) {
                    const animaisSelecionados = document.getElementById('animais_selecionados').value;
                    const selectAnimal = document.getElementById('animal_coleta');
                    
                    // Verificar se há animais selecionados
                    if (!animaisSelecionados || animaisSelecionados.trim() === '') {
                        e.preventDefault();
                        alert('⚠️ Por favor, selecione pelo menos um animal para registrar a coleta.');
                        selectAnimal.focus();
                        return false;
                    }
                    
                    // Confirmar se múltiplos animais foram selecionados
                    const totalAnimais = animaisSelecionados.split(',').length;
                    if (totalAnimais > 1) {
                        const confirmar = confirm(`Você está prestes a registrar coletas para ${totalAnimais} animais. Deseja continuar?`);
                        if (!confirmar) {
                            e.preventDefault();
                            return false;
                        }
                    }
                    
                    return true;
                });
            }
        });

        // ============================================
        // CARREGAR DADOS CLIMÁTICOS SALVOS
        // ============================================
        async function carregarDadosClimaticos() {
            const container = document.getElementById('tabela-clima-salvo');

            try {
                container.innerHTML = '<p style="text-align: center; color: #718096; padding: 20px;">Carregando dados...</p>';

                const response = await fetch('api_clima.php?action=listar_salvos');
                const data = await response.json();

                if (!data.sucesso) {
                    throw new Error(data.mensagem || 'Erro ao carregar dados');
                }

                if (!data.dados || data.dados.length === 0) {
                    container.innerHTML = `
                        <p style="text-align: center; color: #718096; padding: 20px;">
                            Nenhum dado climático salvo ainda.
                        </p>
                    `;
                    return;
                }

                // Construir tabela
                let html = `
                    <table style="width: 100%; border-collapse: collapse; background: white; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                        <thead>
                            <tr style="background: #f7fafc; border-bottom: 2px solid #e2e8f0;">
                                <th style="padding: 12px; text-align: left; font-weight: 600; color: #2d3748;">Local</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Data Coleta</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Temp Média</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Pluvio Total</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Umidade</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748;">Fonte</th>
                                <th style="padding: 12px; text-align: center; font-weight: 600; color: #2d3748; width: 80px;">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                `;

                data.dados.forEach(registro => {
                    html += `
                        <tr style="border-bottom: 1px solid #e2e8f0;">
                            <td style="padding: 12px;">${registro.nome_local || 'N/A'}</td>
                            <td style="padding: 12px; text-align: center;">${formatarData(registro.data_coleta)}</td>
                            <td style="padding: 12px; text-align: center;">${registro.temperatura_media}°C</td>
                            <td style="padding: 12px; text-align: center;">${registro.pluviometria_total} mm</td>
                            <td style="padding: 12px; text-align: center;">${registro.umidade_media}%</td>
                            <td style="padding: 12px; text-align: center; font-size: 11px;">${registro.fonte_dados || 'N/A'}</td>
                            <td style="padding: 12px; text-align: center;">
                                <button
                                    onclick="excluirDadoClimatico(${registro.id})"
                                    style="background: #e53e3e; color: white; border: none; padding: 6px 12px; border-radius: 4px; cursor: pointer; font-size: 12px;"
                                    onmouseover="this.style.background='#c53030'"
                                    onmouseout="this.style.background='#e53e3e'"
                                >
                                    🗑️ Excluir
                                </button>
                            </td>
                        </tr>
                    `;
                });

                html += `
                        </tbody>
                    </table>
                `;

                container.innerHTML = html;

            } catch (error) {
                console.error('Erro ao carregar dados climáticos:', error);
                container.innerHTML = `
                    <p style="text-align: center; color: #e53e3e; padding: 20px;">
                        ❌ Erro ao carregar dados: ${error.message}
                    </p>
                `;
            }
        }

        // ============================================
        // EXCLUIR DADO CLIMÁTICO
        // ============================================
        async function excluirDadoClimatico(id) {
            if (!confirm('Tem certeza que deseja excluir este registro climático?')) {
                return;
            }

            try {
                const response = await fetch(`api_clima.php?action=excluir&id=${id}`, {
                    method: 'DELETE'
                });
                const data = await response.json();

                if (data.sucesso) {
                    alert('✅ Registro excluído com sucesso!');
                    carregarDadosClimaticos(); // Recarregar tabela
                } else {
                    alert('❌ Erro ao excluir: ' + (data.mensagem || 'Erro desconhecido'));
                }
            } catch (error) {
                console.error('Erro ao excluir:', error);
                alert('❌ Erro ao excluir registro');
            }
        }

        // ============================================
        // FUNÇÃO AUXILIAR: FORMATAÇÃO DE DATA
        // ============================================
        function formatarData(data) {
            if (!data) return 'N/A';
            const d = new Date(data + 'T00:00:00');
            return d.toLocaleDateString('pt-BR');
        }
    </script>
</body>
</html>
