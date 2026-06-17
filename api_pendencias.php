<?php
/**
 * api_pendencias.php
 * Retorna (JSON) todas as pendências de lançamento por coleta:
 *   - dados climáticos a gravar (médias de 90 dias)
 *   - PCR a lançar: categórico (Positivo/Negativo/Inconclusivo) e numérico (Tt) por patógeno
 *   - PCR-LAMP a lançar: categórico e numérico (Tt) por alvo (18S / CytB)
 *
 * Usado pela janela de avisos da index.php.
 */

require_once __DIR__ . '/config.php';
header('Content-Type: application/json; charset=utf-8');

function colExiste($pdo, $tabela, $coluna) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$tabela` LIKE " . $pdo->quote($coluna));
        return $stmt && $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}

try {
    $pdo = getDBConnection();

    // As colunas de Tt da PCR só existem após aplicar sql/08_pcr_tempo_deteccao.sql
    $ttPcrOk = colExiste($pdo, 'resultados_pcr', 'tempo_deteccao_babesia');
    $bigeminaOk = colExiste($pdo, 'resultados_pcr', 'babesia_bigemina');

    // Buscar todas as coletas com fazenda/animal
    $coletas = $pdo->query("
        SELECT c.id AS coleta_id, c.data_coleta,
               a.identificacao AS animal,
               COALESCE(l.nome, l2.nome, 'Sem fazenda') AS fazenda
        FROM coletas c
        LEFT JOIN animais a  ON a.id = c.animal_id
        LEFT JOIN locais  l  ON l.id = a.local_id
        LEFT JOIN locais  l2 ON l2.id = c.local_id
        ORDER BY c.data_coleta DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $itens = [];
    $cont = [
        'sem_data'   => 0,
        'clima'      => 0,
        'pcr_cat'    => 0,
        'pcr_tt'     => 0,
        'lamp_cat'   => 0,
        'lamp_tt'    => 0,
    ];

    // Patógenos PCR: coluna categórica + coluna Tt
    $patPcr = [
        ['nome' => 'B. bovis',     'cat' => 'babesia_bovis',       'tt' => 'tempo_deteccao_babesia'],
        ['nome' => 'B. bigemina',  'cat' => 'babesia_bigemina',    'tt' => 'tempo_deteccao_bigemina'],
        ['nome' => 'A. marginale', 'cat' => 'anaplasma_marginale', 'tt' => 'tempo_deteccao_anaplasma'],
        ['nome' => 'T. vivax',     'cat' => 'trypanosoma_vivax',   'tt' => 'tempo_deteccao_trypanosoma'],
    ];
    $alvoLamp = [
        ['nome' => 'LAMP 18S',  'cat' => 'babesia_18s_lamp',  'tt' => 'tempo_deteccao_18s_min'],
        ['nome' => 'LAMP CytB', 'cat' => 'babesia_cytb_lamp', 'tt' => 'tempo_deteccao_cytb_min'],
    ];

    foreach ($coletas as $c) {
        $cid = $c['coleta_id'];
        $pend = [];

        // Coleta sem data registrada
        $temData = !empty($c['data_coleta']) && $c['data_coleta'] !== '0000-00-00';
        if (!$temData) {
            $pend[] = 'Data da coleta não informada';
            $cont['sem_data']++;
        }

        // ---- Dados climáticos ----
        if ($temData) {
            $stmt = $pdo->prepare("SELECT temperatura_media FROM dados_climaticos WHERE coleta_id = ? AND temperatura_media IS NOT NULL LIMIT 1");
            $stmt->execute([$cid]);
            if (!$stmt->fetch()) {
                $pend[] = 'Dados climáticos (médias de 90 dias) a gravar';
                $cont['clima']++;
            }
        }

        // ---- PCR ----
        $stmt = $pdo->prepare("SELECT * FROM resultados_pcr WHERE coleta_id = ? LIMIT 1");
        $stmt->execute([$cid]);
        $pcr = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pcr) {
            $pend[] = 'PCR a lançar (nenhum patógeno registrado)';
            $cont['pcr_cat']++;
        } else {
            foreach ($patPcr as $p) {
                if ($p['cat'] === 'babesia_bigemina' && !$bigeminaOk) continue;
                if (!array_key_exists($p['cat'], $pcr)) continue;
                $val = $pcr[$p['cat']];
                if ($val === null || $val === '' || $val === 'Inconclusivo') {
                    $pend[] = 'PCR ' . $p['nome'] . ': resultado categórico não definido';
                    $cont['pcr_cat']++;
                } elseif ($val === 'Positivo' && $ttPcrOk && array_key_exists($p['tt'], $pcr) && ($pcr[$p['tt']] === null || $pcr[$p['tt']] === '')) {
                    $pend[] = 'PCR ' . $p['nome'] . ': positivo SEM Tt (tempo de detecção)';
                    $cont['pcr_tt']++;
                }
            }
        }

        // ---- LAMP ----
        $stmt = $pdo->prepare("SELECT * FROM resultados_lamp WHERE coleta_id = ? LIMIT 1");
        $stmt->execute([$cid]);
        $lamp = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$lamp) {
            $pend[] = 'PCR-LAMP a lançar (18S e CytB)';
            $cont['lamp_cat']++;
        } else {
            foreach ($alvoLamp as $p) {
                if (!array_key_exists($p['cat'], $lamp)) continue;
                $val = $lamp[$p['cat']];
                if ($val === null || $val === '' || $val === 'Inconclusivo') {
                    $pend[] = $p['nome'] . ': resultado categórico não definido';
                    $cont['lamp_cat']++;
                } elseif ($val === 'Positivo' && array_key_exists($p['tt'], $lamp) && ($lamp[$p['tt']] === null || $lamp[$p['tt']] === '')) {
                    $pend[] = $p['nome'] . ': positivo SEM Tt (tempo de detecção)';
                    $cont['lamp_tt']++;
                }
            }
        }

        if (!empty($pend)) {
            $itens[] = [
                'coleta_id'  => (int)$cid,
                'animal'     => $c['animal'] ?? '-',
                'fazenda'    => $c['fazenda'],
                'data'       => $temData ? date('d/m/Y', strtotime($c['data_coleta'])) : '(sem data)',
                'pendencias' => $pend,
            ];
        }
    }

    echo json_encode([
        'sucesso'         => true,
        'total_coletas'   => count($coletas),
        'coletas_pendentes' => count($itens),
        'contadores'      => $cont,
        'tt_pcr_habilitado' => $ttPcrOk,
        'itens'           => $itens,
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
