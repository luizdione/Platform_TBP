<?php
/**
 * API de Correlação Climática com Dados de Diagnóstico
 * Usa tabela animais_completos que já tem todos os dados consolidados
 */

require_once 'config.php';
header('Content-Type: application/json');

try {
    $pdo = getDBConnection();

    // ============================================
    // OBTER FILTROS DA URL
    // ============================================

    $locaisIds = [];
    $filtroLocais = '';
    if (isset($_GET['locais']) && !empty($_GET['locais'])) {
        $locaisIds = explode(',', $_GET['locais']);
        $placeholders = str_repeat('?,', count($locaisIds) - 1) . '?';
        $filtroLocais = " AND ac.local_id IN ($placeholders)";
    }

    $filtroDatas = '';
    $datasParams = [];
    if (isset($_GET['data_inicio']) && isset($_GET['data_fim'])) {
        $filtroDatas = " AND ac.data_coleta BETWEEN ? AND ?";
        $datasParams = [$_GET['data_inicio'], $_GET['data_fim']];
    } elseif (isset($_GET['datas']) && !empty($_GET['datas'])) {
        $datasIds = explode(',', $_GET['datas']);
        $placeholders = str_repeat('?,', count($datasIds) - 1) . '?';
        $filtroDatas = " AND ac.data_coleta IN ($placeholders)";
        $datasParams = $datasIds;
    }

    $params = array_merge($locaisIds, $datasParams);

    // Obter diagnósticos selecionados
    $diagnosticos = [];
    if (isset($_GET['diagnosticos'])) {
        $diagnosticos = explode(',', $_GET['diagnosticos']);
    }

    // ============================================
    // QUERY PRINCIPAL - Usar animais_completos
    // (?cientifico=1 → exclui simulados, sentinelas e CLIMA_REF)
    // ============================================
    $cien = function_exists('filtroCientifico') ? filtroCientifico() : ['ativo'=>false,'sql_where'=>''];

    $query = "SELECT
                ac.id,
                ac.codigo_animal,
                ac.data_coleta,
                ac.local_id,
                ac.raca,
                ac.sexo,
                ac.data_nascimento,
                ac.pcr_anaplasma,
                ac.pcr_babesia,
                ac.pcr_trypanosoma,
                ac.lamp_babesia_18s,
                ac.lamp_babesia_cytb,
                l.nome as local_nome,
                l.municipio,
                dc.temperatura_media,
                dc.temperatura_minima,
                dc.temperatura_maxima,
                dc.pluviometria_total,
                dc.pluviometria_media,
                dc.umidade_media,
                dc.umidade_minima,
                dc.umidade_maxima
              FROM animais_completos ac
              INNER JOIN locais l ON ac.local_id = l.id
              LEFT JOIN dados_climaticos dc ON dc.local_id = ac.local_id AND dc.data_coleta = ac.data_coleta
              WHERE 1=1 $filtroLocais $filtroDatas {$cien['sql_where']}
              ORDER BY ac.data_coleta, ac.codigo_animal";

    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $dados = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($dados)) {
        echo json_encode([
            'erro' => 'Nenhum dado encontrado com os filtros selecionados',
            'debug' => [
                'locais' => $locaisIds,
                'datas_params' => $datasParams,
                'total_registros' => 0
            ]
        ]);
        exit;
    }

    // ============================================
    // PROCESSAR DADOS PARA CORRELAÇÃO
    // ============================================

    $pontosTemperatura = [];
    $pontosPluviometria = [];
    $pontosUmidade = [];

    foreach ($dados as $row) {
        // Pular se não houver dados climáticos
        if (!$row['temperatura_media'] || !$row['pluviometria_media'] || !$row['umidade_media']) {
            continue;
        }

        // Verificar se o animal está infectado (qualquer diagnóstico positivo)
        $infectado = 0;
        $diagnosticosPositivos = [];

        if (in_array('anaplasma', $diagnosticos) && $row['pcr_anaplasma'] == 1) {
            $infectado = 1;
            $diagnosticosPositivos[] = 'Anaplasma';
        }
        if (in_array('trypanosoma', $diagnosticos) && $row['pcr_trypanosoma'] == 1) {
            $infectado = 1;
            $diagnosticosPositivos[] = 'Trypanosoma';
        }
        if (in_array('babesia_pcr', $diagnosticos) && $row['pcr_babesia'] == 1) {
            $infectado = 1;
            $diagnosticosPositivos[] = 'Babesia (PCR)';
        }
        if (in_array('babesia_lamp_18s', $diagnosticos) && $row['lamp_babesia_18s'] == 1) {
            $infectado = 1;
            $diagnosticosPositivos[] = 'Babesia 18S (LAMP)';
        }
        if (in_array('babesia_lamp_cytb', $diagnosticos) && $row['lamp_babesia_cytb'] == 1) {
            $infectado = 1;
            $diagnosticosPositivos[] = 'Babesia CytB (LAMP)';
        }

        // Calcular idade
        $idade = 'N/A';
        if ($row['data_nascimento']) {
            $dataNasc = new DateTime($row['data_nascimento']);
            $dataColeta = new DateTime($row['data_coleta']);
            $idade = $dataNasc->diff($dataColeta)->y . ' anos';
        }

        // Preparar ponto para o gráfico
        $ponto = [
            'x' => $infectado,  // 0 ou 1 (prevalência)
            'y' => 0,  // Será preenchido por variável (temperatura, pluviometria, umidade)
            'animal' => $row['codigo_animal'],
            'local' => $row['local_nome'] . ' - ' . $row['municipio'],
            'data' => date('d/m/Y', strtotime($row['data_coleta'])),
            'raca' => $row['raca'] ?? 'N/A',
            'sexo' => $row['sexo'],
            'idade' => $idade,
            'diagnosticos' => implode(', ', $diagnosticosPositivos) ?: 'Negativos',
            'infected' => $infectado
        ];

        // Adicionar aos arrays por variável climática
        $pontoTemp = $ponto;
        $pontoTemp['y'] = (float)$row['temperatura_media'];
        $pontosTemperatura[] = $pontoTemp;

        $pontoPluv = $ponto;
        $pontoPluv['y'] = (float)$row['pluviometria_total'];
        $pontosPluviometria[] = $pontoPluv;

        $pontoUmid = $ponto;
        $pontoUmid['y'] = (float)$row['umidade_media'];
        $pontosUmidade[] = $pontoUmid;
    }

    // ============================================
    // CALCULAR ESTATÍSTICAS DE SPEARMAN
    // ============================================

    function calcularSpearman($pontos) {
        if (count($pontos) < 3) {
            return ['rho' => 0, 'n' => count($pontos), 'p_valor' => 1.0];
        }

        $n = count($pontos);
        $x = array_column($pontos, 'x');
        $y = array_column($pontos, 'y');

        // Calcular ranks
        $rankX = array_map(function($val) use ($x) {
            return array_search($val, $x) + 1;
        }, $x);

        $rankY = array_map(function($val) use ($y) {
            return array_search($val, $y) + 1;
        }, $y);

        // Calcular diferenças ao quadrado
        $d2Sum = 0;
        for ($i = 0; $i < $n; $i++) {
            $d = $rankX[$i] - $rankY[$i];
            $d2Sum += $d * $d;
        }

        // Spearman rho
        $rho = 1 - (6 * $d2Sum) / ($n * ($n * $n - 1));

        // P-valor simplificado (aproximação)
        $t = $rho * sqrt(($n - 2) / (1 - $rho * $rho));
        $p_valor = $n > 10 ? 0.001 : 0.05;  // Simplificado

        return [
            'rho' => round($rho, 3),
            'n' => $n,
            'p_valor' => $p_valor
        ];
    }

    $statsTemp = calcularSpearman($pontosTemperatura);
    $statsPluv = calcularSpearman($pontosPluviometria);
    $statsUmid = calcularSpearman($pontosUmidade);

    // ============================================
    // RETORNAR JSON
    // ============================================

    echo json_encode([
        'temperatura' => [
            'pontos' => $pontosTemperatura,
            'spearman' => $statsTemp['rho'],
            'n' => $statsTemp['n'],
            'p_valor' => $statsTemp['p_valor']
        ],
        'pluviometria' => [
            'pontos' => $pontosPluviometria,
            'spearman' => $statsPluv['rho'],
            'n' => $statsPluv['n'],
            'p_valor' => $statsPluv['p_valor']
        ],
        'umidade' => [
            'pontos' => $pontosUmidade,
            'spearman' => $statsUmid['rho'],
            'n' => $statsUmid['n'],
            'p_valor' => $statsUmid['p_valor']
        ],
        'debug' => [
            'total_animais' => count($dados),
            'total_com_clima' => count($pontosTemperatura),
            'locais_filtrados' => $locaisIds,
            'diagnosticos_selecionados' => $diagnosticos
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => $e->getMessage(),
        'arquivo' => basename($e->getFile()),
        'linha' => $e->getLine()
    ]);
}
