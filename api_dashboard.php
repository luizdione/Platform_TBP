<?php
// ============================================
// API Dashboard - Vigilância Parasitária Bovina
// Arquivo: api_dashboard.php
// ============================================

require_once 'config.php';
setSecurityHeaders();

// Determinar action de GET ou POST
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $pdo = getDBConnection();

    switch ($action) {
        case 'listar':
            jsonResponse(listarAnimais($pdo));
            break;

        case 'listarAnimais':
            jsonResponse(listarAnimaisUnicos($pdo));
            break;

        case 'listarAnimaisAgrupados':
            jsonResponse(listarAnimaisAgrupados($pdo));
            break;

        case 'estatisticas':
            jsonResponse(obterEstatisticas($pdo));
            break;

        case 'excluir':
            $id = $_GET['id'] ?? '';
            if (empty($id)) {
                throw new Exception('ID não fornecido');
            }
            jsonResponse(excluirAnimal($pdo, $id));
            break;

        case 'marcar_obito':
            $id = $_POST['id'] ?? '';
            $obito = $_POST['obito'] ?? 0;
            $motivo = $_POST['motivo_obito'] ?? null;
            
            if (empty($id)) {
                throw new Exception('ID não fornecido');
            }
            jsonResponse(marcarObito($pdo, $id, $obito, $motivo));
            break;

        case 'salvar_motivo_obito':
            $id = $_POST['id'] ?? '';
            $motivo = $_POST['motivo_obito'] ?? '';
            
            if (empty($id)) {
                throw new Exception('ID não fornecido');
            }
            jsonResponse(salvarMotivoObito($pdo, $id, $motivo));
            break;

        case 'atualizarAnimal':
            $dados = json_decode(file_get_contents('php://input'), true);
            if (empty($dados['id'])) {
                throw new Exception('ID do animal não fornecido');
            }
            jsonResponse(atualizarAnimal($pdo, $dados));
            break;

        case 'atualizarResultadosColeta':
            $dados = json_decode(file_get_contents('php://input'), true);
            if (empty($dados['coleta_id'])) {
                throw new Exception('ID da coleta não fornecido');
            }
            jsonResponse(atualizarResultadosColeta($pdo, $dados));
            break;

        default:
            jsonResponse([
                'erro' => 'Ação não reconhecida',
                'mensagem' => 'Use um dos seguintes parâmetros: ?action=listar, ?action=listarAnimais, ?action=estatisticas, ?action=excluir&id=X',
                'acoes_disponiveis' => [
                    'listar' => 'Lista todos os animais com dados completos',
                    'listarAnimais' => 'Lista animais únicos para seleção',
                    'estatisticas' => 'Retorna estatísticas gerais do sistema',
                    'excluir' => 'Exclui um animal (requer parâmetro id)',
                    'marcar_obito' => 'Marca/desmarca óbito de um animal (POST)',
                    'salvar_motivo_obito' => 'Salva motivo do óbito (POST)'
                ]
            ], 400);
    }

} catch (Exception $e) {
    logErro("Erro na API dashboard: " . $e->getMessage());
    // Retornar erro detalhado para debug
    jsonResponse([
        'erro' => $e->getMessage(),
        'arquivo' => $e->getFile(),
        'linha' => $e->getLine(),
        'trace' => $e->getTraceAsString()
    ], 500);
}

function listarAnimais($pdo) {
    try {
        // Primeiro, testar se a tabela coletas existe e tem dados
        $testQuery = "SELECT COUNT(*) as total FROM coletas";
        $testStmt = $pdo->query($testQuery);
        $totalColetas = $testStmt->fetch(PDO::FETCH_ASSOC)['total'];
        
        if ($totalColetas == 0) {
            // Se não há coletas, retornar apenas animais
            $query = "SELECT 
                        a.id,
                        a.identificacao,
                        a.identificacao as codigo_animal,
                        a.nome_animal,
                        l.nome as local_nome,
                        l.municipio,
                        NULL as data_coleta,
                        a.data_nascimento,
                        a.sexo,
                        a.raca,
                        0 as obito,
                        NULL as motivo_obito,
                        NULL as estacao,
                        0 as em_lactacao,
                        0 as carrapaticida_pouron,
                        0 as carrapaticida_pulverizacao,
                        0 as carrapaticida_injetavel,
                        NULL as observacoes_tratamento,
                        NULL as coleta_id,
                        0 as pcr_babesia,
                        0 as pcr_anaplasma,
                        0 as pcr_trypanosoma,
                        0 as lamp_babesia_18s,
                        0 as lamp_babesia_cytb
                      FROM animais a
                      LEFT JOIN locais l ON a.local_id = l.id
                      ORDER BY a.identificacao";
            
            $stmt = $pdo->prepare($query);
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        // Se há coletas, buscar dados completos (incluir animais sem coletas também)
        $query = "SELECT 
                    a.id,
                    a.identificacao,
                    a.identificacao as codigo_animal,
                    a.nome_animal,
                    l.nome as local_nome,
                    l.municipio,
                    c.data_coleta,
                    a.data_nascimento,
                    a.sexo,
                    a.raca,
                    c.estacao,
                    CASE WHEN c.observacoes LIKE '%lactação%' OR c.observacoes LIKE '%lactacao%' THEN 1 ELSE 0 END as em_lactacao,
                    c.id as coleta_id,
                    0 as obito,
                    NULL as motivo_obito,
                    0 as carrapaticida_pouron,
                    0 as carrapaticida_pulverizacao,
                    0 as carrapaticida_injetavel,
                    NULL as observacoes_tratamento
                  FROM animais a
                  LEFT JOIN locais l ON a.local_id = l.id
                  LEFT JOIN coletas c ON c.animal_id = a.id
                  ORDER BY a.identificacao, c.data_coleta DESC";

        $stmt = $pdo->prepare($query);
        $stmt->execute();
        $resultados = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Buscar resultados PCR e LAMP para cada coleta
        foreach ($resultados as &$row) {
            $row['pcr_babesia'] = 0;
            $row['pcr_anaplasma'] = 0;
            $row['pcr_trypanosoma'] = 0;
            $row['lamp_babesia_18s'] = 0;
            $row['lamp_babesia_cytb'] = 0;
            
            try {
                // Buscar PCR
                $stmtPcr = $pdo->prepare("SELECT * FROM resultados_pcr WHERE coleta_id = ?");
                $stmtPcr->execute([$row['coleta_id']]);
                $pcr = $stmtPcr->fetch(PDO::FETCH_ASSOC);
                
                if ($pcr) {
                    $row['pcr_babesia'] = (isset($pcr['babesia_bovis']) && $pcr['babesia_bovis'] === 'Positivo') ? 1 : 0;
                    $row['pcr_anaplasma'] = (isset($pcr['anaplasma_marginale']) && $pcr['anaplasma_marginale'] === 'Positivo') ? 1 : 0;
                    $row['pcr_trypanosoma'] = (isset($pcr['trypanosoma_vivax']) && $pcr['trypanosoma_vivax'] === 'Positivo') ? 1 : 0;
                }
            } catch (Exception $e) {
                // Ignorar erro
            }
            
            try {
                // Buscar LAMP
                $stmtLamp = $pdo->prepare("SELECT * FROM resultados_lamp WHERE coleta_id = ?");
                $stmtLamp->execute([$row['coleta_id']]);
                $lamp = $stmtLamp->fetch(PDO::FETCH_ASSOC);
                
                if ($lamp) {
                    $row['lamp_babesia_18s'] = (isset($lamp['babesia_18s_lamp']) && $lamp['babesia_18s_lamp'] === 'Positivo') ? 1 : 0;
                    $row['lamp_babesia_cytb'] = (isset($lamp['babesia_cytb_lamp']) && $lamp['babesia_cytb_lamp'] === 'Positivo') ? 1 : 0;
                }
            } catch (Exception $e) {
                // Ignorar erro
            }
        }
        
        return $resultados;
        
    } catch (Exception $e) {
        // Log do erro real
        error_log("Erro em listarAnimais: " . $e->getMessage());
        throw new Exception("Erro ao listar animais: " . $e->getMessage());
    }
}

function listarAnimaisUnicos($pdo) {
    // Verificar se há filtros
    $local_id = $_GET['local_id'] ?? null;
    $animal_id = $_GET['animal_id'] ?? null;
    
    // Construir query base
    $where = [];
    $params = [];
    
    // Se filtrar por local_id, retornar animais daquele local
    if ($local_id) {
        $query = "SELECT 
                    a.id as animal_id,
                    a.identificacao,
                    a.nome_animal,
                    a.sexo,
                    a.raca,
                    a.local_id,
                    l.nome as local_nome,
                    l.municipio
                  FROM animais a
                  LEFT JOIN locais l ON a.local_id = l.id
                  WHERE a.local_id = ?
                  ORDER BY a.identificacao";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$local_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Se filtrar por animal_id, retornar coletas daquele animal
    if ($animal_id) {
        $query = "SELECT 
                    c.id as coleta_id,
                    c.data_coleta,
                    c.estacao,
                    c.tipo_amostra,
                    a.identificacao,
                    a.id as animal_id
                  FROM coletas c
                  INNER JOIN animais a ON c.animal_id = a.id
                  WHERE c.animal_id = ?
                  ORDER BY c.data_coleta DESC";
        
        $stmt = $pdo->prepare($query);
        $stmt->execute([$animal_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    // Sem filtros, retornar todos os animais únicos
    $query = "SELECT 
                a.id as animal_id,
                a.identificacao as codigo_animal,
                a.sexo,
                a.local_id,
                l.nome as local_nome,
                l.municipio,
                c.data_coleta
              FROM animais a
              LEFT JOIN locais l ON a.local_id = l.id
              LEFT JOIN coletas c ON a.id = c.animal_id
              ORDER BY a.identificacao, c.data_coleta";

    $stmt = $pdo->prepare($query);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function obterEstatisticas($pdo) {
    // Verificar se há filtro de locais
    $filtroLocais = '';
    $locaisIds = [];
    if (isset($_GET['locais']) && !empty($_GET['locais'])) {
        $locaisIds = explode(',', $_GET['locais']);
        $placeholders = str_repeat('?,', count($locaisIds) - 1) . '?';
        $filtroLocais = " AND a.local_id IN ($placeholders)";
    }
    
    // Verificar se há filtro de datas
    $filtroDatas = '';
    $datasParams = [];
    
    // Modo 1: Período (data_inicio e data_fim)
    if (isset($_GET['data_inicio']) && isset($_GET['data_fim'])) {
        $filtroDatas = " AND c.data_coleta BETWEEN ? AND ?";
        $datasParams = [$_GET['data_inicio'], $_GET['data_fim']];
    }
    // Modo 2: Datas específicas
    elseif (isset($_GET['datas']) && !empty($_GET['datas'])) {
        $datasIds = explode(',', $_GET['datas']);
        $placeholders = str_repeat('?,', count($datasIds) - 1) . '?';
        $filtroDatas = " AND c.data_coleta IN ($placeholders)";
        $datasParams = $datasIds;
    }
    
    // Combinar parâmetros
    $params = array_merge($locaisIds, $datasParams);
    
    // ============================================
    // 1. PREVALÊNCIA POR DATA E MÉTODO (para gráfico de barras agrupadas)
    // ============================================
    $query = "SELECT 
                c.data_coleta,
                -- Babesia bovis PCR 18S (usando campo babesia_bovis do PCR)
                ROUND(SUM(CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN pcr.babesia_bovis IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as babesia_pcr_18s,
                -- Babesia bigemina PCR (assumindo mesmo campo por enquanto - ajustar se houver campo específico)
                ROUND(SUM(CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN pcr.babesia_bovis IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as babesia_pcr_cytb,
                -- Babesia bovis LAMP 18S
                ROUND(SUM(CASE WHEN lamp.babesia_18s_lamp = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN lamp.babesia_18s_lamp IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as babesia_lamp_18s,
                -- Babesia bovis LAMP CytB
                ROUND(SUM(CASE WHEN lamp.babesia_cytb_lamp = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN lamp.babesia_cytb_lamp IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as babesia_lamp_cytb
              FROM coletas c
              INNER JOIN animais a ON c.animal_id = a.id
              LEFT JOIN resultados_pcr pcr ON c.id = pcr.coleta_id
              LEFT JOIN resultados_lamp lamp ON c.id = lamp.coleta_id
              WHERE 1=1 $filtroLocais $filtroDatas
              GROUP BY c.data_coleta 
              ORDER BY c.data_coleta";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $prevalenciaPorData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // ============================================
    // 2. DISTRIBUIÇÃO POR SEXO - POR PATÓGENO/MÉTODO
    // ============================================
    $query = "SELECT 
                a.sexo,
                -- Babesia bovis PCR 18S
                SUM(CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END) as babesia_pcr_18s,
                -- Babesia bigemina PCR
                SUM(CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END) as babesia_pcr_cytb,
                -- Babesia bovis LAMP 18S
                SUM(CASE WHEN lamp.babesia_18s_lamp = 'Positivo' THEN 1 ELSE 0 END) as babesia_lamp_18s,
                -- Babesia bovis LAMP CytB
                SUM(CASE WHEN lamp.babesia_cytb_lamp = 'Positivo' THEN 1 ELSE 0 END) as babesia_lamp_cytb,
                -- Anaplasma
                SUM(CASE WHEN pcr.anaplasma_marginale = 'Positivo' THEN 1 ELSE 0 END) as anaplasma,
                -- Trypanosoma
                SUM(CASE WHEN pcr.trypanosoma_vivax = 'Positivo' THEN 1 ELSE 0 END) as trypanosoma
              FROM animais a
              INNER JOIN coletas c ON a.id = c.animal_id
              LEFT JOIN resultados_pcr pcr ON c.id = pcr.coleta_id
              LEFT JOIN resultados_lamp lamp ON c.id = lamp.coleta_id
              WHERE 1=1 $filtroLocais $filtroDatas
              GROUP BY a.sexo";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $sexoData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Organizar dados por patógeno
    $sexoPorPatogeno = [
        'babesia_pcr_18s' => ['macho' => 0, 'femea' => 0],
        'babesia_pcr_cytb' => ['macho' => 0, 'femea' => 0],
        'babesia_lamp_18s' => ['macho' => 0, 'femea' => 0],
        'babesia_lamp_cytb' => ['macho' => 0, 'femea' => 0],
        'anaplasma' => ['macho' => 0, 'femea' => 0],
        'trypanosoma' => ['macho' => 0, 'femea' => 0]
    ];
    
    foreach ($sexoData as $row) {
        $sexo = strtolower($row['sexo']);
        if ($sexo === 'f' || $sexo === 'fêmea' || $sexo === 'femea') {
            $sexo = 'femea';
        } else {
            $sexo = 'macho';
        }
        
        $sexoPorPatogeno['babesia_pcr_18s'][$sexo] = (int)$row['babesia_pcr_18s'];
        $sexoPorPatogeno['babesia_pcr_cytb'][$sexo] = (int)$row['babesia_pcr_cytb'];
        $sexoPorPatogeno['babesia_lamp_18s'][$sexo] = (int)$row['babesia_lamp_18s'];
        $sexoPorPatogeno['babesia_lamp_cytb'][$sexo] = (int)$row['babesia_lamp_cytb'];
        $sexoPorPatogeno['anaplasma'][$sexo] = (int)$row['anaplasma'];
        $sexoPorPatogeno['trypanosoma'][$sexo] = (int)$row['trypanosoma'];
    }

    // ============================================
    // 3. DADOS TEMPORAIS - PREVALÊNCIA AO LONGO DO TEMPO
    // ============================================
    $query = "SELECT 
                c.data_coleta,
                -- Babesia bovis PCR 18S
                ROUND(SUM(CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN pcr.babesia_bovis IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as prevalencia_babesia_pcr_18s,
                -- Babesia bigemina PCR
                ROUND(SUM(CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN pcr.babesia_bovis IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as prevalencia_babesia_pcr_cytb,
                -- Babesia bovis LAMP 18S
                ROUND(SUM(CASE WHEN lamp.babesia_18s_lamp = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN lamp.babesia_18s_lamp IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as prevalencia_babesia_lamp_18s,
                -- Babesia bovis LAMP CytB
                ROUND(SUM(CASE WHEN lamp.babesia_cytb_lamp = 'Positivo' THEN 1 ELSE 0 END) * 100.0 /
                      NULLIF(COUNT(CASE WHEN lamp.babesia_cytb_lamp IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as prevalencia_babesia_lamp_cytb,
                -- Anaplasma
                ROUND(SUM(CASE WHEN pcr.anaplasma_marginale = 'Positivo' THEN 1 ELSE 0 END) * 100.0 / 
                      NULLIF(COUNT(CASE WHEN pcr.anaplasma_marginale IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as prevalencia_anaplasma,
                -- Trypanosoma
                ROUND(SUM(CASE WHEN pcr.trypanosoma_vivax = 'Positivo' THEN 1 ELSE 0 END) * 100.0 / 
                      NULLIF(COUNT(CASE WHEN pcr.trypanosoma_vivax IN ('Positivo', 'Negativo') THEN 1 END), 0), 1) as prevalencia_trypanosoma
              FROM coletas c
              INNER JOIN animais a ON c.animal_id = a.id
              LEFT JOIN resultados_pcr pcr ON c.id = pcr.coleta_id
              LEFT JOIN resultados_lamp lamp ON c.id = lamp.coleta_id
              WHERE 1=1 $filtroLocais $filtroDatas
              GROUP BY c.data_coleta 
              ORDER BY c.data_coleta";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $temporalData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organizar por patógeno
    $temporal = [
        'babesia_pcr_18s' => [],
        'babesia_pcr_cytb' => [],
        'babesia_lamp_18s' => [],
        'babesia_lamp_cytb' => [],
        'anaplasma' => [],
        'trypanosoma' => []
    ];
    
    foreach ($temporalData as $row) {
        $temporal['babesia_pcr_18s'][] = [
            'data_coleta' => $row['data_coleta'],
            'prevalencia' => (float)($row['prevalencia_babesia_pcr_18s'] ?? 0)
        ];
        $temporal['babesia_pcr_cytb'][] = [
            'data_coleta' => $row['data_coleta'],
            'prevalencia' => (float)($row['prevalencia_babesia_pcr_cytb'] ?? 0)
        ];
        $temporal['babesia_lamp_18s'][] = [
            'data_coleta' => $row['data_coleta'],
            'prevalencia' => (float)($row['prevalencia_babesia_lamp_18s'] ?? 0)
        ];
        $temporal['babesia_lamp_cytb'][] = [
            'data_coleta' => $row['data_coleta'],
            'prevalencia' => (float)($row['prevalencia_babesia_lamp_cytb'] ?? 0)
        ];
        $temporal['anaplasma'][] = [
            'data_coleta' => $row['data_coleta'],
            'prevalencia' => (float)($row['prevalencia_anaplasma'] ?? 0)
        ];
        $temporal['trypanosoma'][] = [
            'data_coleta' => $row['data_coleta'],
            'prevalencia' => (float)($row['prevalencia_trypanosoma'] ?? 0)
        ];
    }

    // ============================================
    // 4. ANÁLISE DE CO-INFECÇÕES
    // ============================================
    $query = "SELECT 
                a.id as animal_id,
                a.identificacao,
                -- Contar quantos patógenos positivos cada animal tem
                (CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 1 ELSE 0 END +
                 CASE WHEN lamp.babesia_18s_lamp = 'Positivo' THEN 1 ELSE 0 END +
                 CASE WHEN lamp.babesia_cytb_lamp = 'Positivo' THEN 1 ELSE 0 END +
                 CASE WHEN pcr.anaplasma_marginale = 'Positivo' THEN 1 ELSE 0 END +
                 CASE WHEN pcr.trypanosoma_vivax = 'Positivo' THEN 1 ELSE 0 END) as num_patogenos,
                -- Identificar quais patógenos
                CASE WHEN pcr.babesia_bovis = 'Positivo' THEN 'Babesia bovis PCR' ELSE '' END as babesia_pcr,
                CASE WHEN lamp.babesia_18s_lamp = 'Positivo' THEN 'Babesia bovis LAMP 18S' ELSE '' END as babesia_lamp_18s,
                CASE WHEN lamp.babesia_cytb_lamp = 'Positivo' THEN 'Babesia bovis LAMP CytB' ELSE '' END as babesia_lamp_cytb,
                CASE WHEN pcr.anaplasma_marginale = 'Positivo' THEN 'Anaplasma' ELSE '' END as anaplasma,
                CASE WHEN pcr.trypanosoma_vivax = 'Positivo' THEN 'Trypanosoma' ELSE '' END as trypanosoma
              FROM animais a
              INNER JOIN coletas c ON a.id = c.animal_id
              LEFT JOIN resultados_pcr pcr ON c.id = pcr.coleta_id
              LEFT JOIN resultados_lamp lamp ON c.id = lamp.coleta_id
              WHERE 1=1 $filtroLocais $filtroDatas";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $coinfeccoesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Contar por número de patógenos
    $distribuicao = [0 => 0, 1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
    $combinacoes = [];
    
    foreach ($coinfeccoesData as $row) {
        $num = (int)$row['num_patogenos'];
        $distribuicao[$num]++;
        
        // Registrar combinação
        if ($num > 1) {
            $patogenos = array_filter([
                $row['babesia_pcr'],
                $row['babesia_lamp_18s'],
                $row['babesia_lamp_cytb'],
                $row['anaplasma'],
                $row['trypanosoma']
            ]);
            $combinacao = implode(' + ', $patogenos);
            if (!isset($combinacoes[$combinacao])) {
                $combinacoes[$combinacao] = 0;
            }
            $combinacoes[$combinacao]++;
        }
    }
    
    // Ordenar combinações por frequência
    arsort($combinacoes);
    $top5Combinacoes = array_slice($combinacoes, 0, 5, true);
    
    $detalhesCoinfeccoes = [];
    $totalAnimais = count($coinfeccoesData);
    foreach ($top5Combinacoes as $comb => $qtd) {
        $detalhesCoinfeccoes[] = [
            'combinacao' => $comb,
            'quantidade' => $qtd,
            'percentual' => round(($qtd / $totalAnimais) * 100, 1)
        ];
    }
    
    $coinfeccoes = [
        'sem_infeccao' => $distribuicao[0],
        'um_patogeno' => $distribuicao[1],
        'dois_patogenos' => $distribuicao[2],
        'tres_patogenos' => $distribuicao[3],
        'quatro_ou_mais' => $distribuicao[4] + $distribuicao[5],
        'detalhes_coinfeccoes' => $detalhesCoinfeccoes
    ];

    return [
        'prevalenciaPorData' => $prevalenciaPorData,
        'sexo' => $sexoPorPatogeno,
        'temporal' => $temporal,
        'coinfeccoes' => $coinfeccoes
    ];
}

function excluirAnimal($pdo, $id) {
    // Excluir animal e suas coletas/resultados (CASCADE deve fazer isso automaticamente)
    $stmt = $pdo->prepare("DELETE FROM animais WHERE id = ?");
    $stmt->execute([$id]);

    if ($stmt->rowCount() > 0) {
        return ['sucesso' => true];
    } else {
        throw new Exception('Registro não encontrado');
    }
}

function marcarObito($pdo, $id, $obito, $motivo) {
    // Atualizar status de óbito do animal
    $stmt = $pdo->prepare("UPDATE animais SET obito = ?, motivo_obito = ? WHERE id = ?");
    $stmt->execute([$obito, $motivo, $id]);

    if ($stmt->rowCount() > 0 || $pdo->query("SELECT COUNT(*) FROM animais WHERE id = $id")->fetchColumn() > 0) {
        return ['sucesso' => true];
    } else {
        throw new Exception('Animal não encontrado');
    }
}

function salvarMotivoObito($pdo, $id, $motivo) {
    // Atualizar apenas o motivo do óbito
    $stmt = $pdo->prepare("UPDATE animais SET motivo_obito = ? WHERE id = ?");
    $stmt->execute([$motivo, $id]);

    if ($stmt->rowCount() > 0 || $pdo->query("SELECT COUNT(*) FROM animais WHERE id = $id")->fetchColumn() > 0) {
        return ['sucesso' => true];
    } else {
        throw new Exception('Animal não encontrado');
    }
}

function listarAnimaisAgrupados($pdo) {
    // Buscar todos os animais
    $query = "SELECT 
                a.id,
                a.identificacao,
                a.nome_animal,
                a.local_id,
                l.nome as local_nome,
                l.municipio,
                a.sexo,
                a.raca,
                a.data_nascimento,
                a.observacoes
              FROM animais a
              LEFT JOIN locais l ON a.local_id = l.id
              ORDER BY a.identificacao";
    
    $stmt = $pdo->prepare($query);
    $stmt->execute();
    $animais = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Para cada animal, buscar suas coletas com resultados
    foreach ($animais as &$animal) {
        $animal['coletas'] = [];
        
        // Buscar coletas do animal
        $stmtColetas = $pdo->prepare("SELECT 
                                        c.id as coleta_id,
                                        c.data_coleta,
                                        CASE WHEN c.observacoes LIKE '%lactação%' OR c.observacoes LIKE '%lactacao%' THEN 1 ELSE 0 END as em_lactacao
                                      FROM coletas c
                                      WHERE c.animal_id = ?
                                      ORDER BY c.data_coleta DESC");
        $stmtColetas->execute([$animal['id']]);
        $coletas = $stmtColetas->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($coletas as $coleta) {
            $coletaData = [
                'coleta_id' => $coleta['coleta_id'],
                'data_coleta' => $coleta['data_coleta'],
                'em_lactacao' => $coleta['em_lactacao'],
                'pcr_babesia' => 2,  // 2 = não diagnosticado
                'pcr_bigemina' => 2,
                'pcr_anaplasma' => 2,
                'pcr_trypanosoma' => 2,
                'lamp_18s' => 2,
                'lamp_cytb' => 2,
                // Tempo de Detecção (Tt) por análise - null quando não informado
                'tt_babesia' => null,
                'tt_bigemina' => null,
                'tt_anaplasma' => null,
                'tt_trypanosoma' => null,
                'tt_18s' => null,
                'tt_cytb' => null
            ];

            // Buscar resultados PCR
            try {
                $stmtPcr = $pdo->prepare("SELECT * FROM resultados_pcr WHERE coleta_id = ?");
                $stmtPcr->execute([$coleta['coleta_id']]);
                $pcr = $stmtPcr->fetch(PDO::FETCH_ASSOC);

                if ($pcr) {
                    $coletaData['pcr_babesia'] = ($pcr['babesia_bovis'] === 'Positivo') ? 1 : (($pcr['babesia_bovis'] === 'Negativo') ? 0 : 2);
                    if (array_key_exists('babesia_bigemina', $pcr)) {
                        $coletaData['pcr_bigemina'] = ($pcr['babesia_bigemina'] === 'Positivo') ? 1 : (($pcr['babesia_bigemina'] === 'Negativo') ? 0 : 2);
                    }
                    $coletaData['pcr_anaplasma'] = ($pcr['anaplasma_marginale'] === 'Positivo') ? 1 : (($pcr['anaplasma_marginale'] === 'Negativo') ? 0 : 2);
                    $coletaData['pcr_trypanosoma'] = ($pcr['trypanosoma_vivax'] === 'Positivo') ? 1 : (($pcr['trypanosoma_vivax'] === 'Negativo') ? 0 : 2);
                    // Tt (Tempo de Detecção) - só presente se a migração de colunas foi aplicada
                    if (array_key_exists('tempo_deteccao_babesia', $pcr))     $coletaData['tt_babesia']     = $pcr['tempo_deteccao_babesia'];
                    if (array_key_exists('tempo_deteccao_bigemina', $pcr))    $coletaData['tt_bigemina']    = $pcr['tempo_deteccao_bigemina'];
                    if (array_key_exists('tempo_deteccao_anaplasma', $pcr))   $coletaData['tt_anaplasma']   = $pcr['tempo_deteccao_anaplasma'];
                    if (array_key_exists('tempo_deteccao_trypanosoma', $pcr)) $coletaData['tt_trypanosoma'] = $pcr['tempo_deteccao_trypanosoma'];
                }
            } catch (Exception $e) {
                // Manter valores padrão (não diagnosticado)
            }

            // Buscar resultados LAMP
            try {
                $stmtLamp = $pdo->prepare("SELECT * FROM resultados_lamp WHERE coleta_id = ?");
                $stmtLamp->execute([$coleta['coleta_id']]);
                $lamp = $stmtLamp->fetch(PDO::FETCH_ASSOC);

                if ($lamp) {
                    $coletaData['lamp_18s'] = ($lamp['babesia_18s_lamp'] === 'Positivo') ? 1 : (($lamp['babesia_18s_lamp'] === 'Negativo') ? 0 : 2);
                    $coletaData['lamp_cytb'] = ($lamp['babesia_cytb_lamp'] === 'Positivo') ? 1 : (($lamp['babesia_cytb_lamp'] === 'Negativo') ? 0 : 2);
                    if (array_key_exists('tempo_deteccao_18s_min', $lamp))  $coletaData['tt_18s']  = $lamp['tempo_deteccao_18s_min'];
                    if (array_key_exists('tempo_deteccao_cytb_min', $lamp)) $coletaData['tt_cytb'] = $lamp['tempo_deteccao_cytb_min'];
                }
            } catch (Exception $e) {
                // Manter valores padrão (não diagnosticado)
            }
            
            $animal['coletas'][] = $coletaData;
        }
    }
    
    return $animais;
}

function atualizarAnimal($pdo, $dados) {
    $id = $dados['id'];
    $campos = [];
    $valores = [];
    
    // Campos permitidos para atualização
    $camposPermitidos = ['identificacao', 'nome_animal', 'sexo', 'raca', 'data_nascimento', 'observacoes'];
    
    foreach ($camposPermitidos as $campo) {
        if (isset($dados[$campo])) {
            $campos[] = "$campo = ?";
            $valores[] = $dados[$campo];
        }
    }
    
    if (empty($campos)) {
        return ['sucesso' => true, 'mensagem' => 'Nenhum campo para atualizar'];
    }
    
    $valores[] = $id;
    $sql = "UPDATE animais SET " . implode(', ', $campos) . " WHERE id = ?";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($valores);
    
    return ['sucesso' => true, 'mensagem' => 'Animal atualizado com sucesso'];
}

function atualizarResultadosColeta($pdo, $dados) {
    $coletaId = $dados['coleta_id'];
    $sucesso = true;
    $mensagens = [];

    // data_analise é NOT NULL em resultados_pcr/resultados_lamp. Para INSERTs de
    // novas linhas (coleta ainda sem resultado), usa a data da coleta como referência.
    $dataAnalise = date('Y-m-d');
    try {
        $stmtData = $pdo->prepare("SELECT data_coleta FROM coletas WHERE id = ?");
        $stmtData->execute([$coletaId]);
        $rowData = $stmtData->fetch(PDO::FETCH_ASSOC);
        if ($rowData && !empty($rowData['data_coleta'])) {
            $dataAnalise = $rowData['data_coleta'];
        }
    } catch (Exception $e) { /* mantém data atual */ }

    // Atualizar lactação na tabela coletas
    if (isset($dados['em_lactacao'])) {
        try {
            // Atualizar observações da coleta para incluir/remover lactação
            $stmt = $pdo->prepare("SELECT observacoes FROM coletas WHERE id = ?");
            $stmt->execute([$coletaId]);
            $coleta = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($coleta) {
                $obs = $coleta['observacoes'] ?? '';
                $emLactacao = ($dados['em_lactacao'] == 1);
                
                // Remover menções anteriores de lactação
                $obs = preg_replace('/(em )?lacta[çc][aã]o/i', '', $obs);
                $obs = trim($obs);
                
                // Adicionar se necessário
                if ($emLactacao) {
                    $obs = trim($obs . ' em lactação');
                }
                
                $stmtUpdate = $pdo->prepare("UPDATE coletas SET observacoes = ? WHERE id = ?");
                $stmtUpdate->execute([$obs, $coletaId]);
                $mensagens[] = 'Lactação atualizada';
            }
        } catch (Exception $e) {
            $mensagens[] = 'Erro ao atualizar lactação: ' . $e->getMessage();
            $sucesso = false;
        }
    }
    
    // Atualizar resultados PCR
    $camposPCR = ['pcr_babesia' => 'babesia_bovis', 'pcr_bigemina' => 'babesia_bigemina', 'pcr_anaplasma' => 'anaplasma_marginale', 'pcr_trypanosoma' => 'trypanosoma_vivax'];
    $atualizarPCR = false;
    $dadosPCR = [];
    
    foreach ($camposPCR as $campoInput => $campoTabela) {
        if (isset($dados[$campoInput])) {
            $atualizarPCR = true;
            $valor = $dados[$campoInput];
            // Converter: 1=Positivo, 0=Negativo, 2=Inconclusivo
            $dadosPCR[$campoTabela] = ($valor == 1) ? 'Positivo' : (($valor == 0) ? 'Negativo' : 'Inconclusivo');
        }
    }

    // Tempo de Detecção (Tt) por patógeno - só inclui se as colunas existirem no banco.
    $ttPCR = ['tt_babesia' => 'tempo_deteccao_babesia', 'tt_bigemina' => 'tempo_deteccao_bigemina', 'tt_anaplasma' => 'tempo_deteccao_anaplasma', 'tt_trypanosoma' => 'tempo_deteccao_trypanosoma'];
    $temColunasTtPCR = false;
    try {
        $temColunasTtPCR = ($pdo->query("SHOW COLUMNS FROM resultados_pcr LIKE 'tempo_deteccao_babesia'")->fetch() !== false);
    } catch (Exception $e) { $temColunasTtPCR = false; }
    if ($temColunasTtPCR) {
        foreach ($ttPCR as $campoInput => $campoTabela) {
            if (array_key_exists($campoInput, $dados)) {
                $atualizarPCR = true;
                $dadosPCR[$campoTabela] = ($dados[$campoInput] === '' || $dados[$campoInput] === null) ? null : (int)$dados[$campoInput];
            }
        }
    }
    
    if ($atualizarPCR) {
        try {
            // Verificar se já existe registro PCR
            $stmt = $pdo->prepare("SELECT id FROM resultados_pcr WHERE coleta_id = ?");
            $stmt->execute([$coletaId]);
            $pcrExiste = $stmt->fetch();
            
            if ($pcrExiste) {
                // UPDATE
                $campos = [];
                $valores = [];
                foreach ($dadosPCR as $campo => $valor) {
                    $campos[] = "$campo = ?";
                    $valores[] = $valor;
                }
                $valores[] = $coletaId;
                
                $sql = "UPDATE resultados_pcr SET " . implode(', ', $campos) . " WHERE coleta_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valores);
                $mensagens[] = 'PCR atualizado';
            } else {
                // INSERT - garante campos NOT NULL obrigatórios
                $dadosPCRins = $dadosPCR;
                if (!isset($dadosPCRins['data_analise']))      $dadosPCRins['data_analise'] = $dataAnalise;
                if (!isset($dadosPCRins['babesia_bovis']))      $dadosPCRins['babesia_bovis'] = 'Negativo';
                if (!isset($dadosPCRins['anaplasma_marginale'])) $dadosPCRins['anaplasma_marginale'] = 'Negativo';
                if (!isset($dadosPCRins['trypanosoma_vivax']))  $dadosPCRins['trypanosoma_vivax'] = 'Negativo';
                $campos = array_keys($dadosPCRins);
                $placeholders = array_fill(0, count($campos), '?');
                $valores = array_values($dadosPCRins);
                $valores[] = $coletaId;

                $sql = "INSERT INTO resultados_pcr (" . implode(', ', $campos) . ", coleta_id) VALUES (" . implode(', ', $placeholders) . ", ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valores);
                $mensagens[] = 'PCR criado';
            }
        } catch (Exception $e) {
            $mensagens[] = 'Erro ao atualizar PCR: ' . $e->getMessage();
            $sucesso = false;
        }
    }
    
    // Atualizar resultados LAMP
    $camposLAMP = ['lamp_18s' => 'babesia_18s_lamp', 'lamp_cytb' => 'babesia_cytb_lamp'];
    $atualizarLAMP = false;
    $dadosLAMP = [];
    
    foreach ($camposLAMP as $campoInput => $campoTabela) {
        if (isset($dados[$campoInput])) {
            $atualizarLAMP = true;
            $valor = $dados[$campoInput];
            $dadosLAMP[$campoTabela] = ($valor == 1) ? 'Positivo' : (($valor == 0) ? 'Negativo' : 'Inconclusivo');
        }
    }

    // Tempo de Detecção (Tt) LAMP - colunas já existem em resultados_lamp.
    $ttLAMP = ['tt_18s' => 'tempo_deteccao_18s_min', 'tt_cytb' => 'tempo_deteccao_cytb_min'];
    foreach ($ttLAMP as $campoInput => $campoTabela) {
        if (array_key_exists($campoInput, $dados)) {
            $atualizarLAMP = true;
            $dadosLAMP[$campoTabela] = ($dados[$campoInput] === '' || $dados[$campoInput] === null) ? null : (int)$dados[$campoInput];
        }
    }
    
    if ($atualizarLAMP) {
        try {
            // Verificar se já existe registro LAMP
            $stmt = $pdo->prepare("SELECT id FROM resultados_lamp WHERE coleta_id = ?");
            $stmt->execute([$coletaId]);
            $lampExiste = $stmt->fetch();
            
            if ($lampExiste) {
                // UPDATE
                $campos = [];
                $valores = [];
                foreach ($dadosLAMP as $campo => $valor) {
                    $campos[] = "$campo = ?";
                    $valores[] = $valor;
                }
                $valores[] = $coletaId;
                
                $sql = "UPDATE resultados_lamp SET " . implode(', ', $campos) . " WHERE coleta_id = ?";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valores);
                $mensagens[] = 'LAMP atualizado';
            } else {
                // INSERT - garante campos NOT NULL obrigatórios
                $dadosLAMPins = $dadosLAMP;
                if (!isset($dadosLAMPins['data_analise']))      $dadosLAMPins['data_analise'] = $dataAnalise;
                if (!isset($dadosLAMPins['babesia_bovis_lamp'])) $dadosLAMPins['babesia_bovis_lamp'] = 'Negativo';
                $campos = array_keys($dadosLAMPins);
                $placeholders = array_fill(0, count($campos), '?');
                $valores = array_values($dadosLAMPins);
                $valores[] = $coletaId;

                $sql = "INSERT INTO resultados_lamp (" . implode(', ', $campos) . ", coleta_id) VALUES (" . implode(', ', $placeholders) . ", ?)";
                $stmt = $pdo->prepare($sql);
                $stmt->execute($valores);
                $mensagens[] = 'LAMP criado';
            }
        } catch (Exception $e) {
            $mensagens[] = 'Erro ao atualizar LAMP: ' . $e->getMessage();
            $sucesso = false;
        }
    }
    
    return [
        'sucesso' => $sucesso,
        'mensagem' => implode(', ', $mensagens)
    ];
}
?>
