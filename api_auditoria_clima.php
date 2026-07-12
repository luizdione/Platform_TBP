<?php
/**
 * api_auditoria_clima.php   (SOMENTE LEITURA - nao altera nada)
 *
 * Varredura de auditoria dos dados climaticos: o que existe, onde esta,
 * o que falta e o que esta gravado contra a fazenda errada.
 *
 * Abra no navegador:
 *   .../api_auditoria_clima.php            -> JSON com o relatorio completo
 *
 * Cobre: locais x coordenadas, coletas x vinculo de local,
 * dados_climaticos (clima por coleta, 90d), clima_semanal_cache (heatmap)
 * e a view cientifica v_dados_cientificos usada pelo relatorio.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../../templates/auth_central.php';
exigirArea('diagnostico');   // auditoria (somente leitura) restrita a usuarios do diagnostico
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
ini_set('display_errors', '0');
ini_set('max_execution_time', 60);

try {
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Helpers tolerantes a erro: nunca derrubam a auditoria inteira.
    $one = function ($sql, $params = []) use ($pdo) {
        try { $st = $pdo->prepare($sql); $st->execute($params); $r = $st->fetch(PDO::FETCH_ASSOC); return $r === false ? [] : $r; }
        catch (Throwable $e) { return ['_erro' => $e->getMessage()]; }
    };
    $all = function ($sql, $params = []) use ($pdo) {
        try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(PDO::FETCH_ASSOC); }
        catch (Throwable $e) { return [['_erro' => $e->getMessage()]]; }
    };
    $temTabela = function ($nome) use ($pdo) {
        try { return (bool)$pdo->query("SHOW TABLES LIKE " . $pdo->quote($nome))->fetch(); }
        catch (Throwable $e) { return false; }
    };

    $R = [];
    $R['gerado_em'] = date('Y-m-d H:i:s');

    // 1) Tabelas / views presentes + contagem de linhas
    $alvos = ['locais', 'animais', 'coletas', 'dados_climaticos',
              'clima_semanal_cache', 'clima_pixel_diario', 'clima_pixel_cobertura',
              'v_dados_cientificos'];
    $R['tabelas'] = [];
    foreach ($alvos as $t) {
        $ex = $temTabela($t);
        $linhas = null;
        if ($ex) { $c = $one("SELECT COUNT(*) AS n FROM `$t`"); $linhas = $c['n'] ?? ($c['_erro'] ?? null); }
        $R['tabelas'][$t] = ['existe' => $ex, 'linhas' => $linhas];
    }

    // 2) locais x coordenadas (sem lat/lng = nunca recebem clima)
    $R['locais'] = [
        'resumo' => $one("SELECT COUNT(*) AS total,
                                 SUM(latitude IS NOT NULL AND longitude IS NOT NULL) AS com_coordenadas,
                                 SUM(ativo=1) AS ativos
                          FROM locais"),
        'sem_coordenadas' => $all("SELECT id, nome, municipio, ativo
                                   FROM locais
                                   WHERE latitude IS NULL OR longitude IS NULL
                                   ORDER BY nome"),
        'todos' => $all("SELECT id, nome, municipio, latitude, longitude, ativo
                         FROM locais ORDER BY nome"),
    ];

    // 3) coletas x vinculo de local (animal->local vs coleta.local_id)
    $R['coletas'] = [
        'total' => $one("SELECT COUNT(*) AS n FROM coletas"),
        'sem_data' => $one("SELECT COUNT(*) AS n FROM coletas WHERE data_coleta IS NULL OR data_coleta='0000-00-00'"),
        'sem_local_resolvivel' => $one("
            SELECT COUNT(*) AS n FROM coletas c
            LEFT JOIN animais a  ON a.id = c.animal_id
            LEFT JOIN locais  la ON la.id = a.local_id
            LEFT JOIN locais  lc ON lc.id = c.local_id
            WHERE la.id IS NULL AND lc.id IS NULL"),
        'animal_local_difere_da_coleta' => $one("
            SELECT COUNT(*) AS n FROM coletas c
            JOIN animais a ON a.id = c.animal_id
            WHERE c.local_id IS NOT NULL AND a.local_id IS NOT NULL AND c.local_id <> a.local_id"),
    ];

    // 4) dados_climaticos = clima por coleta (90 dias). Base das correlacoes.
    if ($temTabela('dados_climaticos')) {
        $R['dados_climaticos'] = [
            'linhas' => $one("SELECT COUNT(*) AS n FROM dados_climaticos"),
            'coletas_com_clima' => $one("SELECT COUNT(DISTINCT coleta_id) AS n FROM dados_climaticos WHERE temperatura_media IS NOT NULL"),
            'linhas_sem_coordenada' => $one("SELECT COUNT(*) AS n FROM dados_climaticos WHERE latitude IS NULL OR longitude IS NULL"),
            'clima_gravado_em_local_errado' => $one("
                SELECT COUNT(*) AS n FROM dados_climaticos dc
                JOIN coletas c ON c.id = dc.coleta_id
                JOIN animais a ON a.id = c.animal_id
                WHERE dc.local_id IS NOT NULL AND a.local_id IS NOT NULL AND dc.local_id <> a.local_id"),
            'cobertura_por_fazenda' => $all("
                SELECT COALESCE(l.nome,'(sem fazenda)') AS fazenda,
                       COUNT(DISTINCT c.id) AS coletas,
                       COUNT(DISTINCT CASE WHEN dc.temperatura_media IS NOT NULL THEN c.id END) AS com_clima
                FROM coletas c
                LEFT JOIN animais a ON a.id = c.animal_id
                LEFT JOIN locais  l ON l.id = a.local_id
                LEFT JOIN dados_climaticos dc ON dc.coleta_id = c.id
                GROUP BY COALESCE(l.nome,'(sem fazenda)')
                ORDER BY fazenda"),
            'colunas' => $all("SHOW COLUMNS FROM dados_climaticos"),
        ];
    } else {
        $R['dados_climaticos'] = ['existe' => false];
    }

    // 5) clima_semanal_cache = heatmap semanal por fazenda
    if ($temTabela('clima_semanal_cache')) {
        $R['clima_semanal_cache'] = [
            'linhas' => $one("SELECT COUNT(*) AS n FROM clima_semanal_cache"),
            'por_fazenda' => $all("
                SELECT l.nome AS fazenda, COUNT(*) AS semanas,
                       MIN(s.semana_inicio) AS de, MAX(s.semana_fim) AS ate
                FROM clima_semanal_cache s
                JOIN locais l ON l.id = s.local_id
                GROUP BY l.nome ORDER BY l.nome"),
        ];
    } else {
        $R['clima_semanal_cache'] = ['existe' => false, 'nota' => 'ausente — o api_clima_semanal.php cria automaticamente'];
    }

    // 6) view cientifica usada pelo relatorio
    if ($temTabela('v_dados_cientificos')) {
        $R['v_dados_cientificos'] = [
            'linhas' => $one("SELECT COUNT(*) AS n FROM v_dados_cientificos"),
            'colunas_clima' => $all("SHOW COLUMNS FROM v_dados_cientificos LIKE '%temperatura%'"),
        ];
    } else {
        $R['v_dados_cientificos'] = ['existe' => false, 'nota' => 'view ausente — relatorio usa fallback animais_completos'];
    }

    // 7) Diagnostico resumido (acionavel)
    $problemas = [];
    $semCoord = $R['locais']['resumo'];
    if (isset($semCoord['total'], $semCoord['com_coordenadas']) && $semCoord['com_coordenadas'] < $semCoord['total']) {
        $problemas[] = ($semCoord['total'] - $semCoord['com_coordenadas']) . ' fazenda(s) SEM latitude/longitude — nao recebem clima.';
    }
    if (!empty($R['coletas']['sem_local_resolvivel']['n'])) {
        $problemas[] = $R['coletas']['sem_local_resolvivel']['n'] . ' coleta(s) sem fazenda vinculada.';
    }
    if (isset($R['dados_climaticos']['coletas_com_clima']['n'], $R['coletas']['total']['n'])) {
        $faltam = (int)$R['coletas']['total']['n'] - (int)$R['dados_climaticos']['coletas_com_clima']['n'];
        if ($faltam > 0) $problemas[] = "$faltam coleta(s) SEM clima por coleta (dados_climaticos) — rodar carga 90d.";
    }
    if (!empty($R['dados_climaticos']['clima_gravado_em_local_errado']['n'])) {
        $problemas[] = $R['dados_climaticos']['clima_gravado_em_local_errado']['n'] . ' linha(s) de clima gravadas na fazenda ERRADA.';
    }
    if (empty($problemas)) $problemas[] = 'Sem problemas detectados nas verificacoes principais.';
    $R['diagnostico'] = $problemas;

    echo json_encode(['sucesso' => true, 'auditoria' => $R], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(200);
    echo json_encode(['sucesso' => false, 'erro' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
