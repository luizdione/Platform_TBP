<?php
// ============================================
// API de Autodiagnóstico do Sistema
// Verifica integridade das tabelas do projeto
// ============================================

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 0);

require_once __DIR__ . '/../../templates/auth_central.php';
exigirArea('diagnostico');
require_once 'config.php';

try {
    $pdo = getDBConnection();
    
    // Tabelas do projeto diagnostico_tpb
    $tabelasProjeto = [
        'locais',
        'animais',
        'coletas',
        'resultados_pcr',
        'resultados_lamp'
    ];
    
    $totalProblemas = 0;
    $detalhes = [];
    
    foreach ($tabelasProjeto as $tabela) {
        $problemas = 0;
        $mensagens = [];
        
        // Verificar se a tabela existe
        $stmt = $pdo->query("SHOW TABLES LIKE '$tabela'");
        if (!$stmt->fetch()) {
            $mensagens[] = "❌ Tabela não existe no banco de dados";
            $problemas++;
            $detalhes[] = [
                'tabela' => $tabela,
                'problemas' => $problemas,
                'mensagens' => $mensagens
            ];
            $totalProblemas += $problemas;
            continue;
        }
        
        // 1. Verificar IDs duplicados
        $stmt = $pdo->query("SELECT id, COUNT(*) as count FROM `$tabela` GROUP BY id HAVING count > 1");
        $duplicados = $stmt->fetchAll();
        if (count($duplicados) > 0) {
            $mensagens[] = "⚠️ " . count($duplicados) . " ID(s) duplicado(s) encontrado(s)";
            $problemas += count($duplicados);
        }
        
        // 2. Verificar IDs nulos
        $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$tabela` WHERE id IS NULL");
        $nulos = $stmt->fetch()['count'];
        if ($nulos > 0) {
            $mensagens[] = "⚠️ $nulos registro(s) com ID nulo";
            $problemas += $nulos;
        }
        
        // 3. Verificar estrutura da tabela
        $stmt = $pdo->query("DESCRIBE `$tabela`");
        $colunas = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Verificar se tem coluna ID
        $temId = false;
        $temPrimaryKey = false;
        foreach ($colunas as $coluna) {
            if (strtolower($coluna['Field']) === 'id') {
                $temId = true;
            }
            if ($coluna['Key'] === 'PRI') {
                $temPrimaryKey = true;
            }
        }
        
        if (!$temId) {
            $mensagens[] = "❌ Tabela não possui coluna 'id'";
            $problemas++;
        }
        
        if (!$temPrimaryKey) {
            $mensagens[] = "❌ Tabela não possui chave primária (PRIMARY KEY)";
            $problemas++;
        }
        
        // 4. Verificar integridade referencial (chaves estrangeiras)
        if ($tabela === 'animais') {
            // Verificar se todos os local_id existem em locais
            $stmt = $pdo->query("
                SELECT COUNT(*) as count 
                FROM animais a 
                LEFT JOIN locais l ON a.local_id = l.id 
                WHERE a.local_id IS NOT NULL AND l.id IS NULL
            ");
            $refsInvalidas = $stmt->fetch()['count'];
            if ($refsInvalidas > 0) {
                $mensagens[] = "⚠️ $refsInvalidas animal(is) com local_id inválido (local não existe)";
                $problemas += $refsInvalidas;
            }
        }
        
        if ($tabela === 'coletas') {
            // Verificar se todos os animal_id existem em animais
            $stmt = $pdo->query("
                SELECT COUNT(*) as count 
                FROM coletas c 
                LEFT JOIN animais a ON c.animal_id = a.id 
                WHERE c.animal_id IS NOT NULL AND a.id IS NULL
            ");
            $refsInvalidas = $stmt->fetch()['count'];
            if ($refsInvalidas > 0) {
                $mensagens[] = "⚠️ $refsInvalidas coleta(s) com animal_id inválido (animal não existe)";
                $problemas += $refsInvalidas;
            }
        }
        
        if ($tabela === 'resultados_pcr') {
            // Verificar se todos os coleta_id existem em coletas
            $stmt = $pdo->query("
                SELECT COUNT(*) as count 
                FROM resultados_pcr r 
                LEFT JOIN coletas c ON r.coleta_id = c.id 
                WHERE r.coleta_id IS NOT NULL AND c.id IS NULL
            ");
            $refsInvalidas = $stmt->fetch()['count'];
            if ($refsInvalidas > 0) {
                $mensagens[] = "⚠️ $refsInvalidas resultado(s) PCR com coleta_id inválido (coleta não existe)";
                $problemas += $refsInvalidas;
            }
        }
        
        if ($tabela === 'resultados_lamp') {
            // Verificar se todos os coleta_id existem em coletas
            $stmt = $pdo->query("
                SELECT COUNT(*) as count 
                FROM resultados_lamp r 
                LEFT JOIN coletas c ON r.coleta_id = c.id 
                WHERE r.coleta_id IS NOT NULL AND c.id IS NULL
            ");
            $refsInvalidas = $stmt->fetch()['count'];
            if ($refsInvalidas > 0) {
                $mensagens[] = "⚠️ $refsInvalidas resultado(s) LAMP com coleta_id inválido (coleta não existe)";
                $problemas += $refsInvalidas;
            }
        }
        
        // 5. Verificar campos obrigatórios vazios
        foreach ($colunas as $coluna) {
            if ($coluna['Null'] === 'NO' && $coluna['Default'] === null && $coluna['Extra'] !== 'auto_increment') {
                $campo = $coluna['Field'];
                
                // Pular campos que podem estar vazios legitimamente
                $camposOpcionais = ['data_analise', 'observacoes', 'analista', 'equipamento'];
                
                if (!in_array($campo, $camposOpcionais)) {
                    // Verificação normal para campos obrigatórios
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$tabela` WHERE `$campo` IS NULL OR `$campo` = ''");
                    $vazios = $stmt->fetch()['count'];
                    if ($vazios > 0) {
                        $mensagens[] = "⚠️ $vazios registro(s) com campo obrigatório '$campo' vazio";
                        $problemas += $vazios;
                    }
                }
            }
        }
        
        // 6. Verificar datas inválidas (exceto data_analise em tabelas de resultados)
        foreach ($colunas as $coluna) {
            if (strpos($coluna['Type'], 'date') !== false) {
                $campo = $coluna['Field'];
                
                // Pular data_analise em tabelas de resultados (amostras aguardando análise)
                if (($tabela === 'resultados_pcr' || $tabela === 'resultados_lamp') && $campo === 'data_analise') {
                    // Contar registros pendentes apenas para informação
                    $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$tabela` WHERE `$campo` IS NULL OR `$campo` = '0000-00-00' OR `$campo` = '0000-00-00 00:00:00'");
                    $pendentes = $stmt->fetch()['count'];
                    if ($pendentes > 0) {
                        $mensagens[] = "ℹ️ $pendentes amostra(s) aguardando análise (data_analise pendente) - OK";
                    }
                    continue; // Não contar como problema
                }
                
                // Verificação de datas inválidas para outros campos
                $stmt = $pdo->query("SELECT COUNT(*) as count FROM `$tabela` WHERE `$campo` = '0000-00-00' OR `$campo` = '0000-00-00 00:00:00'");
                $datasInvalidas = $stmt->fetch()['count'];
                if ($datasInvalidas > 0) {
                    $mensagens[] = "⚠️ $datasInvalidas registro(s) com data inválida no campo '$campo'";
                    $problemas += $datasInvalidas;
                }
            }
        }
        
        $detalhes[] = [
            'tabela' => $tabela,
            'problemas' => $problemas,
            'mensagens' => $mensagens
        ];
        
        $totalProblemas += $problemas;
    }
    
    echo json_encode([
        'sucesso' => true,
        'total_problemas' => $totalProblemas,
        'tabelas_verificadas' => count($tabelasProjeto),
        'detalhes' => $detalhes,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode([
        'sucesso' => false,
        'erro' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
