<?php
// ============================================
// API Climática - Dados públicos (INMET)
// Arquivo: api_clima.php
// ============================================

require_once __DIR__ . '/../../templates/auth_central.php';
verificarAutenticacaoCentral();
require_once 'config.php';
setSecurityHeaders();

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? '';
$fonte = $_GET['fonte'] ?? 'simulado'; // nasa, inmet, simulado

// Função para buscar dados da NASA POWER
function buscarNASA($lat, $lon, $dataInicio, $dataFim, $variavel) {
    $params = [
        'T2M' => 'temperatura', // Temperatura a 2m
        'PRECTOTCORR' => 'pluviometria', // Precipitação
        'RH2M' => 'umidade' // Umidade relativa
    ];
    
    $paramNASA = array_search($variavel, $params);
    if (!$paramNASA) $paramNASA = 'T2M';
    
    // Formato correto NASA: YYYYMMDD (ex: 20251009)
    $url = "https://power.larc.nasa.gov/api/temporal/daily/point?parameters={$paramNASA}&community=AG&longitude={$lon}&latitude={$lat}&start={$dataInicio}&end={$dataFim}&format=JSON";
    
    // Log para debug (remover em produção)
    error_log("NASA URL: " . $url);
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Aumentado para 30s
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Desabilitar verificação SSL
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); // Seguir redirects
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);
    
    // Log de erro
    if ($curlError) {
        error_log("NASA CURL Error: " . $curlError);
    }
    if ($httpCode !== 200) {
        error_log("NASA HTTP Code: " . $httpCode);
    }
    
    if ($httpCode !== 200 || !$response) {
        return null;
    }
    
    $data = json_decode($response, true);
    if (!isset($data['properties']['parameter'][$paramNASA])) {
        error_log("NASA Response: " . print_r($data, true));
        return null;
    }

    return $data['properties']['parameter'][$paramNASA];
}

// ============================================
// Busca NASA POWER completa (todas as variáveis de uma vez) para o período de 90 dias.
// Retorna já as médias/mín/máx/total agregados, ou null se a NASA falhar.
// Datas em formato YYYYMMDD.
// ============================================
function buscarNASACompleto($lat, $lon, $dataInicioYMD, $dataFimYMD) {
    $params = 'T2M,T2M_MIN,T2M_MAX,PRECTOTCORR,RH2M';
    $url = "https://power.larc.nasa.gov/api/temporal/daily/point?parameters={$params}&community=AG&longitude={$lon}&latitude={$lat}&start={$dataInicioYMD}&end={$dataFimYMD}&format=JSON";

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 45);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) error_log("NASA(completo) CURL Error: " . $curlError);
    if ($httpCode !== 200 || !$response) {
        error_log("NASA(completo) HTTP Code: " . $httpCode);
        return null;
    }

    $data = json_decode($response, true);
    if (!isset($data['properties']['parameter'])) return null;
    $p = $data['properties']['parameter'];

    // Remove valores de preenchimento da NASA (-999) e não numéricos
    $limpar = function ($k) use ($p) {
        if (!isset($p[$k]) || !is_array($p[$k])) return [];
        return array_filter(array_values($p[$k]), fn($v) => is_numeric($v) && $v > -990);
    };
    $t   = $limpar('T2M');
    $tmn = $limpar('T2M_MIN');
    $tmx = $limpar('T2M_MAX');
    $pr  = $limpar('PRECTOTCORR');
    $rh  = $limpar('RH2M');
    if (empty($t)) return null;

    return [
        'temperatura_media'  => round(array_sum($t) / count($t), 1),
        'temperatura_min'    => $tmn ? round(min($tmn), 1) : null,
        'temperatura_max'    => $tmx ? round(max($tmx), 1) : null,
        'pluviometria_total' => $pr ? round(array_sum($pr), 1) : 0.0,
        'pluviometria_media' => $pr ? round(array_sum($pr) / count($pr), 1) : 0.0,
        'pluviometria_min'   => $pr ? round(min($pr), 1) : 0.0,
        'pluviometria_max'   => $pr ? round(max($pr), 1) : 0.0,
        'umidade_media'      => $rh ? round(array_sum($rh) / count($rh), 1) : null,
        'umidade_min'        => $rh ? round(min($rh), 1) : null,
        'umidade_max'        => $rh ? round(max($rh), 1) : null,
        'dias_com_dados'     => count($t),
    ];
}

// ============================================
// Classificação sazonal (Hemisfério Sul) por mês
// ============================================
function estacaoDoMesSH($mes) {
    $mes = (int)$mes;
    if (in_array($mes, [12, 1, 2], true)) return 'Verão';
    if (in_array($mes, [3, 4, 5], true))  return 'Outono';
    if (in_array($mes, [6, 7, 8], true))  return 'Inverno';
    return 'Primavera'; // 9, 10, 11
}

// ============================================
// Estação predominante em um período: conta, dia a dia, a que estação
// (Hemisfério Sul) cada dia entre $dataInicio e $dataFim pertence e devolve
// a estação com mais dias + a distribuição completa.
// ============================================
function estacaoPredominante($dataInicio, $dataFim) {
    try {
        $ini = new DateTime($dataInicio);
        $fim = new DateTime($dataFim);
    } catch (Exception $e) {
        return null;
    }
    if ($fim < $ini) { $tmp = $ini; $ini = $fim; $fim = $tmp; }

    $dist = ['Verão' => 0, 'Outono' => 0, 'Inverno' => 0, 'Primavera' => 0];
    $cursor = clone $ini;
    $umDia = new DateInterval('P1D');
    while ($cursor <= $fim) {
        $dist[estacaoDoMesSH((int)$cursor->format('n'))]++;
        $cursor->add($umDia);
    }
    arsort($dist);
    $predominante = array_key_first($dist);
    return [
        'estacao'           => $predominante,
        'dias_predominante' => $dist[$predominante],
        'total_dias'        => array_sum($dist),
        'distribuicao'      => $dist,
    ];
}

// Função para calcular médias climáticas dos últimos 90 dias do banco de dados
function calcularMediasClimaticas90Dias($pdo, $localId, $dataColeta) {
    try {
        $dataInicio = date('Y-m-d', strtotime($dataColeta . " -90 days"));

        $stmt = $pdo->prepare("
            SELECT
                COUNT(*) as total_registros,
                AVG(temperatura_media) as temperatura_media,
                MAX(temperatura_maxima) as temperatura_max,
                MIN(temperatura_minima) as temperatura_min,
                AVG(pluviometria_media) as pluviometria_media,
                MIN(pluviometria_media) as pluviometria_min,
                MAX(pluviometria_media) as pluviometria_max,
                SUM(pluviometria_total) as pluviometria_total,
                AVG(umidade_media) as umidade_media,
                MIN(umidade_minima) as umidade_min,
                MAX(umidade_maxima) as umidade_max,
                MIN(data_coleta) as primeira_data,
                MAX(data_coleta) as ultima_data
            FROM dados_climaticos
            WHERE local_id = :local_id
              AND data_coleta BETWEEN :data_inicio AND :data_fim
              AND status = 'processado'
        ");

        $stmt->execute([
            'local_id' => $localId,
            'data_inicio' => $dataInicio,
            'data_fim' => $dataColeta
        ]);

        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);

        // Retornar null se não houver dados suficientes (menos de 30 dias)
        if (!$resultado || $resultado['total_registros'] < 30) {
            return null;
        }

        return [
            'temperatura_media' => round((float)$resultado['temperatura_media'], 1),
            'temperatura_max' => round((float)$resultado['temperatura_max'], 1),
            'temperatura_min' => round((float)$resultado['temperatura_min'], 1),
            'pluviometria_media' => round((float)$resultado['pluviometria_media'], 1),
            'pluviometria_min' => round((float)$resultado['pluviometria_min'], 1),
            'pluviometria_max' => round((float)$resultado['pluviometria_max'], 1),
            'pluviometria_total' => round((float)$resultado['pluviometria_total'], 1),
            'umidade_media' => round((float)$resultado['umidade_media'], 1),
            'umidade_min' => round((float)$resultado['umidade_min'], 1),
            'umidade_max' => round((float)$resultado['umidade_max'], 1),
            'dias_com_dados' => (int)$resultado['total_registros'],
            'periodo_inicio' => $resultado['primeira_data'],
            'periodo_fim' => $resultado['ultima_data'],
            'dias_periodo' => (int)((strtotime($resultado['ultima_data']) - strtotime($resultado['primeira_data'])) / 86400)
        ];

    } catch (Exception $e) {
        error_log("Erro em calcularMediasClimaticas90Dias: " . $e->getMessage());
        return null;
    }
}

try {
    $pdo = getDBConnection();

    switch ($action) {
        case 'media_periodo':
            $variavel = $_GET['variavel'] ?? 'temperatura';
            $dataInicio = $_GET['data_inicio'] ?? '';
            $dataFim = $_GET['data_fim'] ?? '';

            if (!$dataInicio || !$dataFim) {
                jsonResponse(['erro' => 'Parâmetros data_inicio e data_fim são obrigatórios'], 400);
            }

            $inicio = new DateTime($dataInicio);
            $fim = new DateTime($dataFim);
            if ($fim < $inicio) {
                jsonResponse(['erro' => 'data_fim deve ser >= data_inicio'], 400);
            }

            // Buscar locais ativos com coordenadas
            $stmt = $pdo->query("SELECT id, nome, municipio, latitude, longitude FROM locais WHERE ativo = TRUE AND latitude IS NOT NULL AND longitude IS NOT NULL");
            $locais = $stmt->fetchAll();

            $pontos = [];

            foreach ($locais as $local) {
                $valor = null;
                
                // Determinar coluna baseado na variável
                $coluna = 'temperatura_media';
                if ($variavel === 'pluviometria') {
                    $coluna = 'pluviometria_mm';
                } elseif ($variavel === 'umidade') {
                    $coluna = 'umidade_relativa';
                }
                
                // Primeiro, tentar buscar dados do banco
                $stmt = $pdo->prepare("
                    SELECT AVG($coluna) as valor_medio
                    FROM dados_climaticos
                    WHERE local_id = ? AND data_registro BETWEEN ? AND ?
                ");
                $stmt->execute([$local['id'], $dataInicio, $dataFim]);
                $resultado = $stmt->fetch();
                
                // Se tiver dados no banco, usar
                if ($resultado && $resultado['valor_medio'] !== null) {
                    $valor = (float)$resultado['valor_medio'];
                } elseif ($fonte === 'nasa') {
                    // Buscar dados da NASA POWER (com fallback para simulados)
                    $lat = (float)$local['latitude'];
                    $lon = (float)$local['longitude'];
                    $dataInicioNASA = str_replace('-', '', $dataInicio);
                    $dataFimNASA = str_replace('-', '', $dataFim);
                    
                    try {
                        $dadosNASA = buscarNASA($lat, $lon, $dataInicioNASA, $dataFimNASA, $variavel);
                        if ($dadosNASA && is_array($dadosNASA)) {
                            $valores = array_values($dadosNASA);
                            $valores = array_filter($valores, function($v) { return $v !== -999 && is_numeric($v); });
                            if (!empty($valores)) {
                                $valor = array_sum($valores) / count($valores);
                            }
                        }
                    } catch (Exception $e) {
                        // Fallback para dados simulados se NASA falhar
                        $dadosNASA = null;
                    }
                    
                    // Se NASA falhou, usar dados simulados
                    if ($valor === null) {
                        $mes = (int)date('m', strtotime($dataInicio));
                        if ($variavel === 'temperatura') {
                            $tempBase = 25;
                            $variacao = ($mes >= 12 || $mes <= 3) ? 5 : -3;
                            $valor = $tempBase + $variacao + (($lat + 23) * 0.5);
                        } elseif ($variavel === 'pluviometria') {
                            $chuvaBase = ($mes >= 11 || $mes <= 3) ? 150 : 50;
                            $valor = $chuvaBase + rand(-30, 30);
                        } elseif ($variavel === 'umidade') {
                            $valor = 75 + rand(-10, 10);
                        }
                    }
                } else {
                    // Caso contrário, gerar dados simulados realistas
                    $lat = (float)$local['latitude'];
                    $lon = (float)$local['longitude'];
                    
                    // Valores base para região do Rio de Janeiro
                    if ($variavel === 'temperatura') {
                        // Temperatura varia com latitude e época do ano
                        $mes = (int)date('m', strtotime($dataInicio));
                        $tempBase = 25; // Base para RJ
                        $variacao = ($mes >= 12 || $mes <= 3) ? 5 : -3; // Verão/Inverno
                        $valor = $tempBase + $variacao + (($lat + 23) * 0.5); // Ajuste por latitude
                    } elseif ($variavel === 'pluviometria') {
                        // Pluviometria varia com época do ano
                        $mes = (int)date('m', strtotime($dataInicio));
                        $chuvaBase = ($mes >= 11 || $mes <= 3) ? 150 : 50; // Verão chuvoso
                        $valor = $chuvaBase + rand(-30, 30);
                    } elseif ($variavel === 'umidade') {
                        // Umidade relativamente alta no RJ
                        $valor = 75 + rand(-10, 10);
                    }
                }
                
                if ($valor !== null) {
                    $pontos[] = [
                        'lat' => (float)$local['latitude'],
                        'lon' => (float)$local['longitude'],
                        'nome' => $local['nome'],
                        'municipio' => $local['municipio'],
                        'valor' => round($valor, 2),
                        'estacao' => [
                            'codigo' => 'SIM',
                            'nome' => 'Dados Simulados'
                        ]
                    ];
                }
            }

            jsonResponse($pontos);
            break;

        case 'diario':
            $localId = $_GET['local_id'] ?? '';
            $data = $_GET['data'] ?? '';
            $dataInicio = $_GET['data_inicio'] ?? '';
            $dataFim = $_GET['data_fim'] ?? '';

            if (empty($localId)) {
                jsonResponse(['erro' => 'Parâmetro local_id é obrigatório'], 400);
            }

            // Buscar local e coordenadas
            $stmt = $pdo->prepare("SELECT id, nome, municipio, latitude, longitude FROM locais WHERE id = ?");
            $stmt->execute([$localId]);
            $local = $stmt->fetch();
            if (!$local || $local['latitude'] === null || $local['longitude'] === null) {
                jsonResponse(['erro' => 'Local não encontrado ou sem coordenadas'], 404);
            }

            // Determinar intervalo
            if ($data) { $dataInicio = $dataFim = $data; }
            if (!$dataInicio) { $dataInicio = date('Y-m-d'); }
            if (!$dataFim) { $dataFim = $dataInicio; }
            $inicio = new DateTime($dataInicio);
            $fim = new DateTime($dataFim);
            if ($fim < $inicio) { jsonResponse(['erro' => 'data_fim deve ser >= data_inicio'], 400); }

            // Buscar dados do banco ou gerar simulados
            $periodo = new DatePeriod($inicio, new DateInterval('P1D'), (clone $fim)->modify('+1 day'));
            $result = [];
            
            foreach ($periodo as $dia) {
                $d = $dia->format('Y-m-d');
                
                // Tentar buscar do banco primeiro
                $stmt = $pdo->prepare("SELECT * FROM dados_climaticos WHERE local_id = ? AND data_registro = ?");
                $stmt->execute([$localId, $d]);
                $dadosBanco = $stmt->fetch();
                
                if ($dadosBanco) {
                    $result[] = [
                        'data' => $d,
                        'temperatura_media' => $dadosBanco['temperatura_media'],
                        'temperatura_maxima' => $dadosBanco['temperatura_maxima'],
                        'temperatura_minima' => $dadosBanco['temperatura_minima'],
                        'pluviometria_mm' => $dadosBanco['pluviometria_mm'],
                        'umidade_relativa' => $dadosBanco['umidade_relativa'],
                        'pressao_atmosferica' => $dadosBanco['pressao_atmosferica'],
                        'velocidade_vento' => $dadosBanco['velocidade_vento'],
                        'fonte_dados' => $dadosBanco['fonte_dados'] ?? 'Banco de Dados',
                        'sucesso' => true
                    ];
                } else {
                    // Gerar dados simulados
                    $mes = (int)date('m', strtotime($d));
                    $tempBase = 25;
                    $variacao = ($mes >= 12 || $mes <= 3) ? 5 : -3;
                    
                    $result[] = [
                        'data' => $d,
                        'temperatura_media' => round($tempBase + $variacao + rand(-2, 2), 1),
                        'temperatura_maxima' => round($tempBase + $variacao + 5 + rand(0, 3), 1),
                        'temperatura_minima' => round($tempBase + $variacao - 5 + rand(-3, 0), 1),
                        'pluviometria_mm' => round((($mes >= 11 || $mes <= 3) ? 150 : 50) + rand(-30, 30), 1),
                        'umidade_relativa' => round(75 + rand(-10, 10), 1),
                        'pressao_atmosferica' => round(1013 + rand(-5, 5), 1),
                        'velocidade_vento' => round(3 + rand(0, 5), 1),
                        'fonte_dados' => 'Dados Simulados',
                        'sucesso' => true
                    ];
                }
            }

            jsonResponse(['local' => $local, 'dados' => $result]);
            break;

        case 'calcular_variacoes':
            $localId = $_GET['local_id'] ?? '';
            $dataColeta = $_GET['data_coleta'] ?? '';
            $fonteEscolhida = $_GET['fonte'] ?? 'banco'; // banco, inmet, nasa, simulado
            
            if (empty($localId)) {
                jsonResponse(['sucesso' => false, 'erro' => 'Parâmetro local_id é obrigatório'], 400);
            }
            
            if (empty($dataColeta)) {
                jsonResponse(['sucesso' => false, 'erro' => 'Parâmetro data_coleta é obrigatório'], 400);
            }
            
            // Buscar informações do local
            $stmtLocal = $pdo->prepare("SELECT nome, municipio, latitude, longitude FROM locais WHERE id = ?");
            $stmtLocal->execute([$localId]);
            $local = $stmtLocal->fetch();
            
            if (!$local) {
                jsonResponse(['sucesso' => false, 'erro' => 'Local não encontrado'], 404);
            }
            
            $medias = null;
            
            // Calcular período
            $data_fim = $dataColeta;
            $data_inicio = date('Y-m-d', strtotime($dataColeta . " -90 days"));
            $mes = (int)date('m', strtotime($dataColeta));
            $lat = (float)($local['latitude'] ?? -22.5);
            $lon = (float)($local['longitude'] ?? -43.2);
            
            // Processar conforme fonte escolhida
            if ($fonteEscolhida === 'banco') {
                // Calcular médias dos 90 dias anteriores do banco de dados
                $medias = calcularMediasClimaticas90Dias($pdo, $localId, $dataColeta);
                
                if ($medias) {
                    $medias['fonte'] = 'Banco de Dados (dados já cadastrados)';
                } else {
                    // Fallback para simulado se não houver dados
                    $fonteEscolhida = 'simulado';
                }
            }
            
            // NASA POWER real: médias dos 90 dias anteriores à coleta (satélite)
            if ($fonteEscolhida === 'nasa') {
                $nasa = buscarNASACompleto(
                    $lat, $lon,
                    str_replace('-', '', $data_inicio),
                    str_replace('-', '', $data_fim)
                );
                if ($nasa) {
                    $medias = array_merge($nasa, [
                        'periodo_inicio' => $data_inicio,
                        'periodo_fim'    => $data_fim,
                        'dias_periodo'   => $nasa['dias_com_dados'],
                        'fonte'          => 'NASA POWER (satélite — 90 dias anteriores à coleta)',
                    ]);
                } else {
                    // NASA indisponível → cai para simulado (com aviso)
                    $fonteEscolhida = 'simulado';
                    $medias = null;
                }
            }

            // INMET ainda não implementado → simulado
            if ($fonteEscolhida === 'inmet') {
                $fonteEscolhida = 'simulado';
                $medias = null;
            }
            
            // Gerar dados simulados se necessário
            if ($medias === null || $fonteEscolhida === 'simulado') {
                // Valores base para região do Rio de Janeiro
                $tempBase = 25;
                $variacao = ($mes >= 12 || $mes <= 3) ? 5 : -3; // Verão/Inverno
                
                $medias = [
                    'temperatura_media' => round($tempBase + $variacao + (($lat + 23) * 0.5), 1),
                    'temperatura_min' => round($tempBase + $variacao - 5, 1),
                    'temperatura_max' => round($tempBase + $variacao + 5, 1),
                    'pluviometria_media' => round((($mes >= 11 || $mes <= 3) ? 150 : 50) + rand(-30, 30), 1),
                    'pluviometria_min' => round((($mes >= 11 || $mes <= 3) ? 50 : 10), 1),
                    'pluviometria_max' => round((($mes >= 11 || $mes <= 3) ? 250 : 100), 1),
                    'pluviometria_total' => round((($mes >= 11 || $mes <= 3) ? 150 : 50) * 90, 1),
                    'umidade_media' => round(75 + rand(-10, 10), 1),
                    'umidade_min' => 60.0,
                    'umidade_max' => 90.0,
                    'dias_com_dados' => 90,
                    'periodo_inicio' => $data_inicio,
                    'periodo_fim' => $data_fim,
                    'dias_periodo' => 90,
                    'fonte' => $fonteEscolhida === 'simulado' 
                        ? 'Dados Simulados (valores estimados para teste)' 
                        : 'Dados Simulados (API externa temporariamente indisponível)'
                ];
            }
            
            // Estação predominante nos 90 dias (independe da fonte dos números)
            $estInfo = estacaoPredominante($data_inicio, $data_fim);

            jsonResponse([
                'sucesso' => true,
                'temperatura_media' => $medias['temperatura_media'],
                'temperatura_max' => $medias['temperatura_max'],
                'temperatura_min' => $medias['temperatura_min'],
                'pluviometria_media' => $medias['pluviometria_media'],
                'pluviometria_min' => $medias['pluviometria_min'],
                'pluviometria_max' => $medias['pluviometria_max'],
                'pluviometria_total' => $medias['pluviometria_total'],
                'umidade_media' => $medias['umidade_media'],
                'umidade_min' => $medias['umidade_min'],
                'umidade_max' => $medias['umidade_max'],
                'dias_com_dados' => $medias['dias_com_dados'],
                'periodo_inicio' => $medias['periodo_inicio'],
                'periodo_fim' => $medias['periodo_fim'],
                'dias_periodo' => $medias['dias_periodo'],
                'fonte' => $medias['fonte'] ?? 'Não especificado',
                'estacao_predominante'  => $estInfo['estacao'] ?? null,
                'estacao_dias'          => $estInfo['dias_predominante'] ?? null,
                'estacao_total_dias'    => $estInfo['total_dias'] ?? null,
                'estacao_distribuicao'  => $estInfo['distribuicao'] ?? null,
            ]);
            break;

        case 'listar_salvos':
            // Listar todos os dados climáticos salvos
            $stmt = $pdo->query("
                SELECT
                    dc.id,
                    dc.local_id,
                    dc.data_coleta,
                    dc.temperatura_media,
                    dc.pluviometria_total,
                    dc.umidade_media,
                    dc.fonte_dados,
                    l.nome as nome_local
                FROM dados_climaticos dc
                LEFT JOIN locais l ON dc.local_id = l.id
                ORDER BY dc.data_coleta DESC, dc.id DESC
            ");

            $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

            jsonResponse([
                'sucesso' => true,
                'dados' => $dados
            ]);
            break;

        case 'excluir':
            // Excluir um registro climático
            $id = $_GET['id'] ?? null;

            if (!$id) {
                jsonResponse(['sucesso' => false, 'mensagem' => 'ID não informado'], 400);
                break;
            }

            $stmt = $pdo->prepare("DELETE FROM dados_climaticos WHERE id = :id");
            $stmt->execute(['id' => $id]);

            if ($stmt->rowCount() > 0) {
                jsonResponse(['sucesso' => true, 'mensagem' => 'Registro excluído com sucesso']);
            } else {
                jsonResponse(['sucesso' => false, 'mensagem' => 'Registro não encontrado'], 404);
            }
            break;

        default:
            jsonResponse(['erro' => 'Ação não reconhecida'], 400);
    }
} catch (Exception $e) {
    logErro('Erro API Clima: ' . $e->getMessage());
    jsonResponse(['erro' => $e->getMessage()], 500);
}
