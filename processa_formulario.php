<?php
// ============================================
// Processamento de Formulários - Vigilância Parasitária Bovina
// Arquivo: processa_formulario.php
// ============================================

require_once 'config.php';
setSecurityHeaders();

// Verificar método
validarMetodo('POST');

// Verificar se o tipo foi especificado
if (!isset($_POST['tipo'])) {
    jsonResponse(['erro' => 'Tipo de formulário não especificado'], 400);
}

$tipo = sanitizeInput($_POST['tipo']);
$dados = sanitizeInput($_POST);

try {
    $pdo = getDBConnection();
    
    switch ($tipo) {
        case 'local':
            $resultado = processarLocal($pdo, $dados);
            break;
            
        case 'animal':
            $resultado = processarAnimal($pdo, $dados);
            break;
            
        case 'coleta':
            $resultado = processarColeta($pdo, $dados);
            break;
            
        case 'pcr':
            $resultado = processarResultadoPCR($pdo, $dados);
            break;
            
        case 'lamp':
            $resultado = processarResultadoLAMP($pdo, $dados);
            break;
            
        case 'clima':
            $resultado = processarDadosClimaticos($pdo, $dados);
            break;
            
        case 'editar_animal':
            $resultado = editarAnimal($pdo, $dados);
            jsonResponse($resultado);
            break;
            
        case 'excluir_animal':
            $resultado = excluirAnimal($pdo, $dados);
            jsonResponse($resultado);
            break;
            
        default:
            throw new Exception('Tipo de formulário inválido');
    }
    
    // Redirecionar com mensagem de sucesso
    if ($resultado['sucesso']) {
        $url = 'forms.php?msg=sucesso&tipo=' . $tipo;
        // Adicionar informações extras se disponíveis
        if (isset($resultado['total']) && $resultado['total'] > 1) {
            $url .= '&total=' . $resultado['total'];
        }
        if (isset($resultado['mensagem'])) {
            $url .= '&detalhe=' . urlencode($resultado['mensagem']);
        }
        header('Location: ' . $url);
    } else {
        header('Location: forms.php?msg=erro&detalhe=' . urlencode($resultado['erro']));
    }
    
} catch (Exception $e) {
    logErro("Erro no processamento do formulário: " . $e->getMessage());
    header('Location: forms.php?msg=erro&detalhe=' . urlencode($e->getMessage()));
}

exit;

// ============================================
// FUNÇÕES DE PROCESSAMENTO
// ============================================

function processarLocal($pdo, $dados) {
    // Validações obrigatórias
    if (empty($dados['nome']) || empty($dados['municipio'])) {
        throw new Exception('Nome e município são obrigatórios');
    }
    
    // Validar coordenadas se fornecidas
    $latitude = null;
    $longitude = null;
    
    if (!empty($dados['latitude'])) {
        $latitude = formatarCoordenada($dados['latitude'], 'lat');
        if ($latitude === null) {
            throw new Exception('Latitude inválida. Deve estar entre -90 e 90');
        }
    }
    
    if (!empty($dados['longitude'])) {
        $longitude = formatarCoordenada($dados['longitude'], 'lng');
        if ($longitude === null) {
            throw new Exception('Longitude inválida. Deve estar entre -180 e 180');
        }
    }
    
    // Inserir local
    $query = "INSERT INTO locais (nome, municipio, latitude, longitude, descricao, propriedade) 
              VALUES (:nome, :municipio, :latitude, :longitude, :descricao, :propriedade)";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'nome' => $dados['nome'],
        'municipio' => $dados['municipio'],
        'latitude' => $latitude,
        'longitude' => $longitude,
        'descricao' => $dados['descricao'] ?? null,
        'propriedade' => $dados['propriedade'] ?? null
    ]);
    
    $localId = $pdo->lastInsertId();
    
    // Se foram fornecidas coordenadas, importar dados climáticos históricos
    if ($latitude && $longitude) {
        importarDadosClimaticosHistoricos($pdo, $localId, $latitude, $longitude);
    }
    
    return ['sucesso' => true, 'id' => $localId];
}

function processarAnimal($pdo, $dados) {
    // Validações obrigatórias
    if (empty($dados['local_id']) || empty($dados['identificacao']) || empty($dados['sexo'])) {
        throw new Exception('Local, identificação e sexo são obrigatórios');
    }
    
    // Verificar se o local existe
    $stmt = $pdo->prepare("SELECT id FROM locais WHERE id = ? AND ativo = TRUE");
    $stmt->execute([$dados['local_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Local não encontrado ou inativo');
    }
    
    // Verificar se a identificação é única
    $stmt = $pdo->prepare("SELECT id FROM animais WHERE identificacao = ?");
    $stmt->execute([$dados['identificacao']]);
    if ($stmt->fetch()) {
        throw new Exception('Já existe um animal com esta identificação');
    }
    
    // Calcular idade em meses a partir da data de nascimento (se fornecida)
    $idadeMeses = null;
    if (!empty($dados['data_nascimento'])) {
        try {
            $dn = new DateTime($dados['data_nascimento']);
            $hoje = new DateTime();
            $diff = $hoje->diff($dn);
            $idadeMeses = ($diff->y * 12) + $diff->m; // ignorar dias para simplificar
        } catch (Exception $e) {
            $idadeMeses = null;
        }
    } elseif (!empty($dados['idade_meses'])) {
        $idadeMeses = (int)$dados['idade_meses'];
    }

    // Inserir animal (identificacao = código do brinco, nome_animal = nome opcional)
    $query = "INSERT INTO animais (local_id, identificacao, nome_animal, raca, idade_meses, sexo, peso_kg, observacoes) 
              VALUES (:local_id, :identificacao, :nome_animal, :raca, :idade_meses, :sexo, :peso_kg, :observacoes)";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'local_id' => $dados['local_id'],
        'identificacao' => $dados['identificacao'], // Código do brinco
        'nome_animal' => $dados['nome_animal'] ?? null, // Nome opcional
        'raca' => $dados['raca'] ?? null,
        'idade_meses' => $idadeMeses,
        'sexo' => $dados['sexo'],
        'peso_kg' => !empty($dados['peso_kg']) ? (float)$dados['peso_kg'] : null,
        'observacoes' => $dados['observacoes'] ?? null
    ]);
    
    return ['sucesso' => true, 'id' => $pdo->lastInsertId()];
}

function processarColeta($pdo, $dados) {
    // Validações obrigatórias
    if (empty($dados['data_coleta']) || empty($dados['estacao'])) {
        throw new Exception('Data da coleta e estação são obrigatórios');
    }
    
    // Verificar se há animais selecionados (pode vir como animais_ids ou animal_id)
    $animaisIds = [];
    if (!empty($dados['animais_ids'])) {
        // Múltiplos animais separados por vírgula
        $animaisIds = array_filter(array_map('trim', explode(',', $dados['animais_ids'])));
    } elseif (!empty($dados['animal_id'])) {
        // Um único animal
        if (is_array($dados['animal_id'])) {
            $animaisIds = array_filter($dados['animal_id']);
        } else {
            $animaisIds = [$dados['animal_id']];
        }
    }
    
    if (empty($animaisIds)) {
        throw new Exception('Selecione pelo menos um animal');
    }
    
    // Validar data
    if (!validarData($dados['data_coleta'])) {
        throw new Exception('Data de coleta inválida');
    }
    
    $coletasInseridas = [];
    $erros = [];
    
    // Processar cada animal selecionado
    foreach ($animaisIds as $animalId) {
        try {
            // Verificar se o animal existe
            $stmt = $pdo->prepare("SELECT a.id, l.id as local_id, l.latitude, l.longitude 
                                  FROM animais a 
                                  INNER JOIN locais l ON a.local_id = l.id 
                                  WHERE a.id = ?");
            $stmt->execute([$animalId]);
            $animal = $stmt->fetch();
            
            if (!$animal) {
                $erros[] = "Animal ID $animalId não encontrado";
                continue;
            }
            
            // Inserir coleta
            $query = "INSERT INTO coletas (animal_id, data_coleta, estacao, tipo_amostra, volume_ml, 
                      responsavel, condicoes_clinicas, temperatura_animal, observacoes) 
                      VALUES (:animal_id, :data_coleta, :estacao, :tipo_amostra, :volume_ml, 
                      :responsavel, :condicoes_clinicas, :temperatura_animal, :observacoes)";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                'animal_id' => $animalId,
                'data_coleta' => $dados['data_coleta'],
                'estacao' => $dados['estacao'],
                'tipo_amostra' => $dados['tipo_amostra'] ?? 'Sangue',
                'volume_ml' => !empty($dados['volume_ml']) ? (float)$dados['volume_ml'] : null,
                'responsavel' => $dados['responsavel'] ?? null,
                'condicoes_clinicas' => $dados['condicoes_clinicas'] ?? null,
                'temperatura_animal' => !empty($dados['temperatura_animal']) ? (float)$dados['temperatura_animal'] : null,
                'observacoes' => $dados['observacoes'] ?? null
            ]);
            
            $coletaId = $pdo->lastInsertId();
            $coletasInseridas[] = $coletaId;
            
            // Importar dados climáticos dos 3 meses anteriores à coleta se há coordenadas
            // Fazer apenas uma vez por local para evitar requisições duplicadas
            static $locaisProcessados = [];
            if ($animal['latitude'] && $animal['longitude'] && !in_array($animal['local_id'], $locaisProcessados)) {
                importarDadosClimaticos3MesesAnteriores($pdo, $animal['local_id'], $animal['latitude'], 
                                                       $animal['longitude'], $dados['data_coleta']);
                $locaisProcessados[] = $animal['local_id'];
            }
        } catch (Exception $e) {
            $erros[] = "Erro ao processar animal ID $animalId: " . $e->getMessage();
        }
    }
    
    // Verificar resultado
    if (empty($coletasInseridas)) {
        throw new Exception('Nenhuma coleta foi inserida. Erros: ' . implode('; ', $erros));
    }
    
    $mensagem = count($coletasInseridas) . ' coleta(s) registrada(s) com sucesso';
    if (!empty($erros)) {
        $mensagem .= '. Avisos: ' . implode('; ', $erros);
    }
    
    return [
        'sucesso' => true, 
        'id' => $coletasInseridas[0],
        'total' => count($coletasInseridas),
        'mensagem' => $mensagem
    ];
}

function processarResultadoPCR($pdo, $dados) {
    // Validações obrigatórias
    $campos_obrigatorios = ['coleta_id', 'data_analise', 'babesia_bovis', 'anaplasma_marginale', 'trypanosoma_vivax'];
    foreach ($campos_obrigatorios as $campo) {
        if (empty($dados[$campo])) {
            throw new Exception("Campo obrigatório não informado: $campo");
        }
    }
    
    // Validar data
    if (!validarData($dados['data_analise'])) {
        throw new Exception('Data de análise inválida');
    }
    
    // Verificar se a coleta existe
    $stmt = $pdo->prepare("SELECT id FROM coletas WHERE id = ?");
    $stmt->execute([$dados['coleta_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Coleta não encontrada');
    }
    
    // Verificar se já existe resultado para esta coleta
    $stmt = $pdo->prepare("SELECT id FROM resultados_pcr WHERE coleta_id = ?");
    $stmt->execute([$dados['coleta_id']]);
    if ($stmt->fetch()) {
        throw new Exception('Já existe resultado PCR para esta coleta');
    }
    
    // Processar checkbox de amplificação 16S (converte para boolean)
    $amplificacao_16s = isset($dados['amplificacao_16s_bovino']) && $dados['amplificacao_16s_bovino'] == '1' ? 1 : 0;

    // Verificar se a coluna babesia_bigemina existe na tabela
    $stmtCheck = $pdo->query("SHOW COLUMNS FROM resultados_pcr LIKE 'babesia_bigemina'");
    $colunaExiste = $stmtCheck->fetch() !== false;

    // Inserir resultado PCR (com ou sem babesia_bigemina dependendo da estrutura)
    if ($colunaExiste) {
        $query = "INSERT INTO resultados_pcr (coleta_id, data_analise, babesia_bovis, babesia_bigemina,
                  anaplasma_marginale, trypanosoma_vivax, ct_babesia, ct_bigemina, ct_anaplasma,
                  ct_trypanosoma, qualidade_dna, concentracao_dna, analista, equipamento,
                  amplificacao_16s_bovino, observacoes)
                  VALUES (:coleta_id, :data_analise, :babesia_bovis, :babesia_bigemina,
                  :anaplasma_marginale, :trypanosoma_vivax, :ct_babesia, :ct_bigemina, :ct_anaplasma,
                  :ct_trypanosoma, :qualidade_dna, :concentracao_dna, :analista, :equipamento,
                  :amplificacao_16s_bovino, :observacoes)";

        $stmt = $pdo->prepare($query);
        $stmt->execute([
            'coleta_id' => $dados['coleta_id'],
            'data_analise' => $dados['data_analise'],
            'babesia_bovis' => $dados['babesia_bovis'],
            'babesia_bigemina' => $dados['babesia_bigemina'] ?? 'Negativo',
            'anaplasma_marginale' => $dados['anaplasma_marginale'],
            'trypanosoma_vivax' => $dados['trypanosoma_vivax'],
            'ct_babesia' => !empty($dados['ct_babesia']) ? (float)$dados['ct_babesia'] : null,
            'ct_bigemina' => !empty($dados['ct_bigemina']) ? (float)$dados['ct_bigemina'] : null,
            'ct_anaplasma' => !empty($dados['ct_anaplasma']) ? (float)$dados['ct_anaplasma'] : null,
            'ct_trypanosoma' => !empty($dados['ct_trypanosoma']) ? (float)$dados['ct_trypanosoma'] : null,
            'qualidade_dna' => $dados['qualidade_dna'] ?? 'Boa',
            'concentracao_dna' => !empty($dados['concentracao_dna']) ? (float)$dados['concentracao_dna'] : null,
            'analista' => $dados['analista'] ?? null,
            'equipamento' => $dados['equipamento'] ?? null,
            'amplificacao_16s_bovino' => $amplificacao_16s,
            'observacoes' => $dados['observacoes'] ?? null
        ]);
    } else {
        // Query original sem babesia_bigemina (para bancos ainda não atualizados)
        $query = "INSERT INTO resultados_pcr (coleta_id, data_analise, babesia_bovis, anaplasma_marginale,
                  trypanosoma_vivax, ct_babesia, ct_anaplasma, ct_trypanosoma, qualidade_dna,
                  concentracao_dna, analista, equipamento, amplificacao_16s_bovino, observacoes)
                  VALUES (:coleta_id, :data_analise, :babesia_bovis, :anaplasma_marginale,
                  :trypanosoma_vivax, :ct_babesia, :ct_anaplasma, :ct_trypanosoma, :qualidade_dna,
                  :concentracao_dna, :analista, :equipamento, :amplificacao_16s_bovino, :observacoes)";

        $stmt = $pdo->prepare($query);
        $stmt->execute([
            'coleta_id' => $dados['coleta_id'],
            'data_analise' => $dados['data_analise'],
            'babesia_bovis' => $dados['babesia_bovis'],
            'anaplasma_marginale' => $dados['anaplasma_marginale'],
            'trypanosoma_vivax' => $dados['trypanosoma_vivax'],
            'ct_babesia' => !empty($dados['ct_babesia']) ? (float)$dados['ct_babesia'] : null,
            'ct_anaplasma' => !empty($dados['ct_anaplasma']) ? (float)$dados['ct_anaplasma'] : null,
            'ct_trypanosoma' => !empty($dados['ct_trypanosoma']) ? (float)$dados['ct_trypanosoma'] : null,
            'qualidade_dna' => $dados['qualidade_dna'] ?? 'Boa',
            'concentracao_dna' => !empty($dados['concentracao_dna']) ? (float)$dados['concentracao_dna'] : null,
            'analista' => $dados['analista'] ?? null,
            'equipamento' => $dados['equipamento'] ?? null,
            'amplificacao_16s_bovino' => $amplificacao_16s,
            'observacoes' => $dados['observacoes'] ?? null
        ]);
    }

    $novoId = $pdo->lastInsertId();

    // Tempo de Detecção (Tt / Time to threshold) por patógeno - PCR convencional.
    // Gravado de forma defensiva: só atualiza se as colunas existirem no banco
    // (aplicar sql/08_pcr_tempo_deteccao.sql). Sinônimo na plataforma: "Tempo de Detecção".
    try {
        $stmtChkTt = $pdo->query("SHOW COLUMNS FROM resultados_pcr LIKE 'tempo_deteccao_babesia'");
        if ($stmtChkTt && $stmtChkTt->fetch() !== false) {
            $mapaTt = [
                'tempo_deteccao_babesia'     => 'tempo_deteccao_babesia',
                'tempo_deteccao_bigemina'    => 'tempo_deteccao_bigemina',
                'tempo_deteccao_anaplasma'   => 'tempo_deteccao_anaplasma',
                'tempo_deteccao_trypanosoma' => 'tempo_deteccao_trypanosoma',
            ];
            $setsTt = [];
            $valsTt = [];
            foreach ($mapaTt as $campoForm => $coluna) {
                if (isset($dados[$campoForm]) && $dados[$campoForm] !== '') {
                    $setsTt[] = "$coluna = ?";
                    $valsTt[] = (int)$dados[$campoForm];
                }
            }
            if (!empty($setsTt)) {
                $valsTt[] = $novoId;
                $stmtTt = $pdo->prepare("UPDATE resultados_pcr SET " . implode(', ', $setsTt) . " WHERE id = ?");
                $stmtTt->execute($valsTt);
            }
        }
    } catch (Exception $e) {
        // Coluna ainda não existe ou erro: ignora o Tt sem quebrar o salvamento do PCR.
    }

    return ['sucesso' => true, 'id' => $novoId];
}

function processarResultadoLAMP($pdo, $dados) {
    // Validações obrigatórias - LAMP para Babesia bovis com 2 alvos
    if (empty($dados['coleta_id']) || empty($dados['data_analise'])) {
        throw new Exception('Coleta e data de análise são obrigatórios');
    }
    
    if (empty($dados['babesia_18s_lamp']) || empty($dados['babesia_cytb_lamp'])) {
        throw new Exception('Resultados dos 2 alvos LAMP (18S e CytB) são obrigatórios');
    }
    
    // Validar data
    if (!validarData($dados['data_analise'])) {
        throw new Exception('Data de análise inválida');
    }
    
    // Verificar se a coleta existe
    $stmt = $pdo->prepare("SELECT id FROM coletas WHERE id = ?");
    $stmt->execute([$dados['coleta_id']]);
    if (!$stmt->fetch()) {
        throw new Exception('Coleta não encontrada');
    }
    
    // Verificar se já existe resultado LAMP para esta coleta
    $stmt = $pdo->prepare("SELECT id FROM resultados_lamp WHERE coleta_id = ?");
    $stmt->execute([$dados['coleta_id']]);
    if ($stmt->fetch()) {
        throw new Exception('Já existe resultado LAMP para esta coleta');
    }
    
    // Inserir resultado LAMP com 2 alvos (18S e CytB)
    $query = "INSERT INTO resultados_lamp (
                coleta_id, data_analise, 
                babesia_18s_lamp, tempo_deteccao_18s_min, intensidade_sinal_18s,
                babesia_cytb_lamp, tempo_deteccao_cytb_min, intensidade_sinal_cytb,
                temperatura_reacao, custo_teste, tempo_total_min, 
                analista, observacoes
              ) VALUES (
                :coleta_id, :data_analise,
                :babesia_18s_lamp, :tempo_deteccao_18s_min, :intensidade_sinal_18s,
                :babesia_cytb_lamp, :tempo_deteccao_cytb_min, :intensidade_sinal_cytb,
                :temperatura_reacao, :custo_teste, :tempo_total_min,
                :analista, :observacoes
              )";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'coleta_id' => $dados['coleta_id'],
        'data_analise' => $dados['data_analise'],
        // Alvo 18S (gene ribossomal 18S)
        'babesia_18s_lamp' => $dados['babesia_18s_lamp'],
        'tempo_deteccao_18s_min' => !empty($dados['tempo_deteccao_18s_min']) ? (int)$dados['tempo_deteccao_18s_min'] : null,
        'intensidade_sinal_18s' => $dados['intensidade_sinal_18s'] ?? null,
        // Alvo CytB (gene do citocromo B)
        'babesia_cytb_lamp' => $dados['babesia_cytb_lamp'],
        'tempo_deteccao_cytb_min' => !empty($dados['tempo_deteccao_cytb_min']) ? (int)$dados['tempo_deteccao_cytb_min'] : null,
        'intensidade_sinal_cytb' => $dados['intensidade_sinal_cytb'] ?? null,
        // Dados gerais do teste
        'temperatura_reacao' => !empty($dados['temperatura_reacao']) ? (float)$dados['temperatura_reacao'] : 65.0,
        'custo_teste' => !empty($dados['custo_teste']) ? (float)$dados['custo_teste'] : null,
        'tempo_total_min' => !empty($dados['tempo_total_min']) ? (int)$dados['tempo_total_min'] : null,
        'analista' => $dados['analista'] ?? null,
        'observacoes' => $dados['observacoes'] ?? null
    ]);
    
    return ['sucesso' => true, 'id' => $pdo->lastInsertId()];
}

function processarDadosClimaticos($pdo, $dados) {
    // Compatibilidade: aceita tanto 'data_coleta' quanto 'data_registro'
    $dataColeta = $dados['data_coleta'] ?? $dados['data_registro'] ?? null;

    // Validações obrigatórias
    if (empty($dados['local_id']) || empty($dataColeta)) {
        throw new Exception('Local e data são obrigatórios');
    }

    // Validar data
    if (!validarData($dataColeta)) {
        throw new Exception('Data de coleta inválida');
    }

    // Verificar se o local existe
    $stmt = $pdo->prepare("SELECT id, latitude, longitude FROM locais WHERE id = ? AND ativo = TRUE");
    $stmt->execute([$dados['local_id']]);
    $local = $stmt->fetch();
    if (!$local) {
        throw new Exception('Local não encontrado ou inativo');
    }

    // Verificar se já existe coleta fictícia para este local/data
    $stmtColeta = $pdo->prepare("
        SELECT id FROM coletas
        WHERE local_id = ? AND data_coleta = ?
        AND observacoes LIKE '%DADOS CLIMÁTICOS%'
        LIMIT 1
    ");
    $stmtColeta->execute([$dados['local_id'], $dataColeta]);
    $coletaExistente = $stmtColeta->fetch();

    if (!$coletaExistente) {
        // Criar animal fictício se não existir
        $identificacaoUnica = 'CLIMA-MANUAL-' . $dados['local_id'];
        $stmtAnimal = $pdo->prepare("
            SELECT id FROM animais
            WHERE local_id = ? AND identificacao = ?
            LIMIT 1
        ");
        $stmtAnimal->execute([$dados['local_id'], $identificacaoUnica]);
        $animalExistente = $stmtAnimal->fetch();

        if (!$animalExistente) {
            $stmtAnimalInsert = $pdo->prepare("
                INSERT INTO animais (local_id, identificacao, raca, sexo, data_nascimento, observacoes)
                VALUES (?, ?, 'Sistema', 'Fêmea', '2000-01-01', 'Animal fictício para dados climáticos - Registro manual')
            ");
            $stmtAnimalInsert->execute([$dados['local_id'], $identificacaoUnica]);
            $animalId = $pdo->lastInsertId();
        } else {
            $animalId = $animalExistente['id'];
        }

        // Determinar estação
        $mes = (int)date('m', strtotime($dataColeta));
        if ($mes >= 1 && $mes <= 3) $estacao = 'Verão';
        elseif ($mes >= 4 && $mes <= 6) $estacao = 'Outono';
        elseif ($mes >= 7 && $mes <= 9) $estacao = 'Inverno';
        else $estacao = 'Primavera';

        // Criar coleta fictícia
        $stmtColetaInsert = $pdo->prepare("
            INSERT INTO coletas (animal_id, local_id, data_coleta, estacao, observacoes)
            VALUES (?, ?, ?, ?, 'DADOS CLIMÁTICOS - Registro manual via formulário')
        ");
        $stmtColetaInsert->execute([$animalId, $dados['local_id'], $dataColeta, $estacao]);
        $coletaId = $pdo->lastInsertId();
    } else {
        $coletaId = $coletaExistente['id'];
    }

    // Calcular período de 90 dias
    $dataInicio = $dados['data_inicio_periodo'] ?? date('Y-m-d', strtotime($dataColeta . ' -90 days'));
    $dataFim = $dados['data_fim_periodo'] ?? $dataColeta;

    // Inserir/atualizar dados climáticos com arredondamento para 1 casa decimal
    // NOTA: A tabela dados_climaticos não possui as colunas pressao_atmosferica, velocidade_vento e observacoes
    $query = "INSERT INTO dados_climaticos (
                coleta_id, local_id, data_coleta, latitude, longitude,
                data_inicio_periodo, data_fim_periodo,
                temperatura_media, temperatura_maxima, temperatura_minima,
                pluviometria_total, pluviometria_media,
                umidade_media, umidade_minima, umidade_maxima,
                fonte_dados, status
              ) VALUES (
                :coleta_id, :local_id, :data_coleta, :latitude, :longitude,
                :data_inicio_periodo, :data_fim_periodo,
                :temperatura_media, :temperatura_maxima, :temperatura_minima,
                :pluviometria_total, :pluviometria_media,
                :umidade_media, :umidade_minima, :umidade_maxima,
                :fonte_dados, 'processado'
              )
              ON DUPLICATE KEY UPDATE
                temperatura_media = VALUES(temperatura_media),
                temperatura_maxima = VALUES(temperatura_maxima),
                temperatura_minima = VALUES(temperatura_minima),
                pluviometria_total = VALUES(pluviometria_total),
                pluviometria_media = VALUES(pluviometria_media),
                umidade_media = VALUES(umidade_media),
                umidade_minima = VALUES(umidade_minima),
                umidade_maxima = VALUES(umidade_maxima),
                fonte_dados = VALUES(fonte_dados),
                status = 'processado'";

    $stmt = $pdo->prepare($query);
    $stmt->execute([
        'coleta_id' => $coletaId,
        'local_id' => $dados['local_id'],
        'data_coleta' => $dataColeta,
        'latitude' => $local['latitude'],
        'longitude' => $local['longitude'],
        'data_inicio_periodo' => $dataInicio,
        'data_fim_periodo' => $dataFim,
        'temperatura_media' => !empty($dados['temperatura_media']) ? round((float)$dados['temperatura_media'], 1) : null,
        'temperatura_maxima' => !empty($dados['temperatura_maxima']) ? round((float)$dados['temperatura_maxima'], 1) : null,
        'temperatura_minima' => !empty($dados['temperatura_minima']) ? round((float)$dados['temperatura_minima'], 1) : null,
        'pluviometria_total' => !empty($dados['pluviometria_mm']) ? round((float)$dados['pluviometria_mm'], 1) : null,
        'pluviometria_media' => !empty($dados['pluviometria_mm']) ? round((float)$dados['pluviometria_mm'] / 90, 1) : null,
        'umidade_media' => !empty($dados['umidade_relativa']) ? round((float)$dados['umidade_relativa'], 1) : null,
        'umidade_minima' => !empty($dados['umidade_relativa']) ? round((float)$dados['umidade_relativa'] - 10, 1) : null,
        'umidade_maxima' => !empty($dados['umidade_relativa']) ? round((float)$dados['umidade_relativa'] + 10, 1) : null,
        'fonte_dados' => $dados['fonte_dados'] ?? 'Manual'
    ]);

    return [
        'sucesso' => true,
        'id' => $pdo->lastInsertId(),
        'mensagem' => 'Dados climáticos salvos com sucesso!'
    ];
}

// ============================================
// IMPORTAÇÃO AUTOMÁTICA DE DADOS CLIMÁTICOS
// ============================================

function importarDadosClimaticosHistoricos($pdo, $localId, $latitude, $longitude) {
    // Importar dados dos últimos 12 meses
    $dataFim = new DateTime();
    $dataInicio = new DateTime();
    $dataInicio->sub(new DateInterval('P1Y'));
    
    importarDadosClimaticosOpenWeather($pdo, $localId, $latitude, $longitude, $dataInicio, $dataFim);
}

function importarDadosClimaticos3MesesAnteriores($pdo, $localId, $latitude, $longitude, $dataColeta) {
    $dataColeta = new DateTime($dataColeta);
    $dataInicio = clone $dataColeta;
    $dataInicio->sub(new DateInterval('P3M')); // 3 meses anteriores
    
    importarDadosClimaticosOpenWeather($pdo, $localId, $latitude, $longitude, $dataInicio, $dataColeta);
    
    // Calcular e salvar estatísticas de correlação
    calcularCorrelacaoClimatica($pdo, $localId, $dataColeta->format('Y-m-d'));
}

function importarDadosClimaticosOpenWeather($pdo, $localId, $latitude, $longitude, $dataInicio, $dataFim) {
    // Chave da API OpenWeather (você precisa se cadastrar em https://openweathermap.org/api)
    $apiKey = ''; // IMPORTANTE: Adicione sua chave da API aqui
    
    if (empty($apiKey)) {
        logErro("API Key do OpenWeather não configurada");
        return false;
    }
    
    // Usar Historical Weather API do OpenWeather
    $periodo = new DatePeriod($dataInicio, new DateInterval('P1D'), $dataFim);
    
    foreach ($periodo as $data) {
        $timestamp = $data->getTimestamp();
        $url = "https://api.openweathermap.org/data/3.0/onecall/timemachine?lat={$latitude}&lon={$longitude}&dt={$timestamp}&appid={$apiKey}&units=metric&lang=pt_br";
        
        try {
            $dadosClima = obterDadosClimaticosAPI($url);
            
            if ($dadosClima) {
                $query = "INSERT IGNORE INTO dados_climaticos 
                         (local_id, data_registro, temperatura_media, temperatura_maxima, 
                          temperatura_minima, pluviometria_mm, umidade_relativa, 
                          pressao_atmosferica, velocidade_vento, fonte_dados) 
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'OpenWeather API')";
                
                $stmt = $pdo->prepare($query);
                $stmt->execute([
                    $localId,
                    $data->format('Y-m-d'),
                    $dadosClima['temp_media'],
                    $dadosClima['temp_max'],
                    $dadosClima['temp_min'],
                    $dadosClima['chuva'],
                    $dadosClima['umidade'],
                    $dadosClima['pressao'],
                    $dadosClima['vento']
                ]);
            }
            
            // Delay para não sobrecarregar a API (limite: 1000 calls/month na versão gratuita)
            usleep(100000); // 0.1 segundo
            
        } catch (Exception $e) {
            logErro("Erro ao importar dados climáticos: " . $e->getMessage() . 
                    " - Data: " . $data->format('Y-m-d') . 
                    " - Lat: $latitude, Lng: $longitude");
        }
    }
}

function obterDadosClimaticosAPI($url) {
    $context = stream_context_create([
        'http' => [
            'timeout' => 10,
            'user_agent' => 'Sistema Vigilancia Parasitaria Bovina/1.0'
        ]
    ]);
    
    $response = @file_get_contents($url, false, $context);
    
    if ($response === false) {
        return false;
    }
    
    $data = json_decode($response, true);
    
    if (!$data || !isset($data['data'])) {
        return false;
    }
    
    $dadosDia = $data['data'][0];
    
    return [
        'temp_media' => $dadosDia['temp'] ?? null,
        'temp_max' => $dadosDia['temp'] ?? null, // API histórica não tem min/max separado
        'temp_min' => $dadosDia['temp'] ?? null,
        'chuva' => isset($dadosDia['rain']['1h']) ? $dadosDia['rain']['1h'] * 24 : 0, // Converter para mm/dia
        'umidade' => $dadosDia['humidity'] ?? null,
        'pressao' => $dadosDia['pressure'] ?? null,
        'vento' => $dadosDia['wind_speed'] ?? null
    ];
}

// ============================================
// CÁLCULO DE CORRELAÇÕES CLIMÁTICAS
// ============================================

function calcularCorrelacaoClimatica($pdo, $localId, $dataColeta) {
    // Buscar dados climáticos dos 3 meses anteriores
    $query = "SELECT 
                AVG(temperatura_media) as temp_media_3m,
                AVG(temperatura_maxima) as temp_max_3m,
                AVG(temperatura_minima) as temp_min_3m,
                SUM(pluviometria_mm) as chuva_total_3m,
                AVG(umidade_relativa) as umidade_media_3m,
                STDDEV(temperatura_media) as temp_variacao,
                STDDEV(pluviometria_mm) as chuva_variacao
              FROM dados_climaticos 
              WHERE local_id = ? 
              AND data_registro BETWEEN DATE_SUB(?, INTERVAL 3 MONTH) AND ?";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([$localId, $dataColeta, $dataColeta]);
    $climaStats = $stmt->fetch();
    
    if ($climaStats) {
        // Salvar estatísticas em tabela auxiliar
        $query = "INSERT INTO estatisticas_climaticas 
                 (local_id, data_referencia, temp_media_3m, temp_max_3m, temp_min_3m, 
                  chuva_total_3m, umidade_media_3m, temp_variacao, chuva_variacao, 
                  data_calculo) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                 ON DUPLICATE KEY UPDATE
                 temp_media_3m = VALUES(temp_media_3m),
                 chuva_total_3m = VALUES(chuva_total_3m),
                 data_calculo = NOW()";
        
        try {
            $stmt = $pdo->prepare($query);
            $stmt->execute([
                $localId,
                $dataColeta,
                $climaStats['temp_media_3m'],
                $climaStats['temp_max_3m'],
                $climaStats['temp_min_3m'],
                $climaStats['chuva_total_3m'],
                $climaStats['umidade_media_3m'],
                $climaStats['temp_variacao'],
                $climaStats['chuva_variacao']
            ]);
        } catch (PDOException $e) {
            // Tabela pode não existir ainda - criar se necessário
            criarTabelaEstatisticasClimaticas($pdo);
        }
    }
}

function criarTabelaEstatisticasClimaticas($pdo) {
    $query = "CREATE TABLE IF NOT EXISTS estatisticas_climaticas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        local_id INT NOT NULL,
        data_referencia DATE NOT NULL,
        temp_media_3m DECIMAL(5,2),
        temp_max_3m DECIMAL(5,2),
        temp_min_3m DECIMAL(5,2),
        chuva_total_3m DECIMAL(8,2),
        umidade_media_3m DECIMAL(5,2),
        temp_variacao DECIMAL(5,2),
        chuva_variacao DECIMAL(8,2),
        data_calculo TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (local_id) REFERENCES locais(id) ON DELETE CASCADE,
        UNIQUE KEY unique_local_data (local_id, data_referencia),
        INDEX idx_data_ref (data_referencia)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    
    $pdo->exec($query);
}

// ============================================
// FUNÇÃO PARA BUSCAR DADOS VIA AJAX
// ============================================

// Adicionar endpoint na API para buscar animais por local
if (isset($_GET['action']) && $_GET['action'] === 'getAnimais' && isset($_GET['local_id'])) {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT id, identificacao, raca, sexo FROM animais WHERE local_id = ? ORDER BY identificacao");
        $stmt->execute([$_GET['local_id']]);
        $animais = $stmt->fetchAll();
        
        jsonResponse($animais);
    } catch (Exception $e) {
        jsonResponse(['erro' => $e->getMessage()], 500);
    }
}

// Adicionar endpoint para buscar coletas
if (isset($_GET['action']) && $_GET['action'] === 'getColetas') {
    try {
        $pdo = getDBConnection();
        $stmt = $pdo->prepare("SELECT c.id, c.data_coleta, a.identificacao as animal, l.nome as local_nome
                               FROM coletas c
                               INNER JOIN animais a ON c.animal_id = a.id
                               INNER JOIN locais l ON a.local_id = l.id
                               ORDER BY c.data_coleta DESC");
        $stmt->execute();
        $coletas = $stmt->fetchAll();
        
        jsonResponse($coletas);
    } catch (Exception $e) {
        jsonResponse(['erro' => $e->getMessage()], 500);
    }
}

// ============================================
// EDITAR ANIMAL
// ============================================
function editarAnimal($pdo, $dados) {
    // Validações obrigatórias
    if (empty($dados['id']) || empty($dados['identificacao']) || empty($dados['sexo'])) {
        throw new Exception('ID, identificação e sexo são obrigatórios');
    }
    
    // Atualizar animal
    $query = "UPDATE animais SET 
              identificacao = :identificacao,
              local_id = :local_id,
              sexo = :sexo,
              raca = :raca,
              data_nascimento = :data_nascimento,
              observacoes = :observacoes
              WHERE id = :id";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute([
        ':id' => $dados['id'],
        ':identificacao' => $dados['identificacao'],
        ':local_id' => !empty($dados['local_id']) ? $dados['local_id'] : null,
        ':sexo' => $dados['sexo'],
        ':raca' => !empty($dados['raca']) ? $dados['raca'] : null,
        ':data_nascimento' => !empty($dados['data_nascimento']) ? $dados['data_nascimento'] : null,
        ':observacoes' => !empty($dados['observacoes']) ? $dados['observacoes'] : null
    ]);
    
    return [
        'sucesso' => true,
        'mensagem' => 'Animal atualizado com sucesso',
        'id' => $dados['id']
    ];
}

// ============================================
// EXCLUIR ANIMAL
// ============================================
function excluirAnimal($pdo, $dados) {
    // Validação obrigatória
    if (empty($dados['id'])) {
        throw new Exception('ID do animal é obrigatório');
    }
    
    // Verificar se o animal existe
    $stmt = $pdo->prepare("SELECT identificacao FROM animais WHERE id = ?");
    $stmt->execute([$dados['id']]);
    $animal = $stmt->fetch();
    
    if (!$animal) {
        throw new Exception('Animal não encontrado');
    }
    
    // Excluir animal (CASCADE irá excluir coletas e resultados automaticamente)
    $stmt = $pdo->prepare("DELETE FROM animais WHERE id = ?");
    $stmt->execute([$dados['id']]);
    
    return [
        'sucesso' => true,
        'mensagem' => 'Animal excluído com sucesso'
    ];
}
?>
