<?php
/**
 * qc_tt.php  —  Controle de Qualidade do "Tempo de Detecção" (Tt)
 *
 * Verifica, de forma NÃO destrutiva (tudo dentro de transação com ROLLBACK), se:
 *   1) as colunas de Tt existem nas tabelas resultados_pcr e resultados_lamp;
 *   2) é possível GRAVAR e LER o Tt de volta (round-trip) nas colunas corretas;
 *   3) quantos registros reais já possuem Tt preenchido.
 *
 * Abra no navegador: .../diagnostico_tpb/qc_tt.php
 */

require_once __DIR__ . '/config.php';
header('Content-Type: text/html; charset=utf-8');

function colExiste($pdo, $tabela, $coluna) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$tabela` LIKE " . $pdo->quote($coluna));
        return $stmt && $stmt->fetch() !== false;
    } catch (Exception $e) { return false; }
}

$resultados = [];
function reg(&$r, $nome, $ok, $detalhe = '') { $r[] = ['nome' => $nome, 'ok' => $ok, 'detalhe' => $detalhe]; }

$colsPcr  = ['tempo_deteccao_babesia','tempo_deteccao_bigemina','tempo_deteccao_anaplasma','tempo_deteccao_trypanosoma'];
$colsLamp = ['tempo_deteccao_18s_min','tempo_deteccao_cytb_min'];

try {
    $pdo = getDBConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1) Existência das colunas
    foreach ($colsPcr as $c) {
        reg($resultados, "Coluna resultados_pcr.$c existe", colExiste($pdo, 'resultados_pcr', $c),
            colExiste($pdo, 'resultados_pcr', $c) ? '' : 'Rode sql/08_pcr_tempo_deteccao.sql');
    }
    foreach ($colsLamp as $c) {
        reg($resultados, "Coluna resultados_lamp.$c existe", colExiste($pdo, 'resultados_lamp', $c));
    }

    // 2) Round-trip PCR (transação + ROLLBACK)
    if (colExiste($pdo, 'resultados_pcr', 'tempo_deteccao_babesia')) {
        try {
            $pdo->beginTransaction();
            $row = $pdo->query("SELECT id FROM resultados_pcr ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $id = $row['id'];
                $sentinela = 777;
                $upd = $pdo->prepare("UPDATE resultados_pcr SET tempo_deteccao_babesia = ?, tempo_deteccao_bigemina = ?, tempo_deteccao_anaplasma = ?, tempo_deteccao_trypanosoma = ? WHERE id = ?");
                $upd->execute([$sentinela, $sentinela+1, $sentinela+2, $sentinela+3, $id]);
                $chk = $pdo->prepare("SELECT tempo_deteccao_babesia, tempo_deteccao_bigemina, tempo_deteccao_anaplasma, tempo_deteccao_trypanosoma FROM resultados_pcr WHERE id = ?");
                $chk->execute([$id]);
                $v = $chk->fetch(PDO::FETCH_ASSOC);
                $ok = ((int)$v['tempo_deteccao_babesia'] === $sentinela
                    && (int)$v['tempo_deteccao_bigemina'] === $sentinela+1
                    && (int)$v['tempo_deteccao_anaplasma'] === $sentinela+2
                    && (int)$v['tempo_deteccao_trypanosoma'] === $sentinela+3);
                reg($resultados, "Round-trip PCR (gravar+ler Tt nas 4 colunas)", $ok,
                    $ok ? "id de teste $id (revertido)" : "Lido: " . json_encode($v));
            } else {
                reg($resultados, "Round-trip PCR", false, "Nenhuma linha em resultados_pcr para testar");
            }
            $pdo->rollBack();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            reg($resultados, "Round-trip PCR", false, $e->getMessage());
        }
    }

    // 3) Round-trip LAMP (transação + ROLLBACK)
    if (colExiste($pdo, 'resultados_lamp', 'tempo_deteccao_18s_min')) {
        try {
            $pdo->beginTransaction();
            $row = $pdo->query("SELECT id FROM resultados_lamp ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $id = $row['id'];
                $upd = $pdo->prepare("UPDATE resultados_lamp SET tempo_deteccao_18s_min = ?, tempo_deteccao_cytb_min = ? WHERE id = ?");
                $upd->execute([55, 66, $id]);
                $chk = $pdo->prepare("SELECT tempo_deteccao_18s_min, tempo_deteccao_cytb_min FROM resultados_lamp WHERE id = ?");
                $chk->execute([$id]);
                $v = $chk->fetch(PDO::FETCH_ASSOC);
                $ok = ((int)$v['tempo_deteccao_18s_min'] === 55 && (int)$v['tempo_deteccao_cytb_min'] === 66);
                reg($resultados, "Round-trip LAMP (gravar+ler Tt 18S/CytB)", $ok,
                    $ok ? "id de teste $id (revertido)" : "Lido: " . json_encode($v));
            } else {
                reg($resultados, "Round-trip LAMP", false, "Nenhuma linha em resultados_lamp para testar");
            }
            $pdo->rollBack();
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            reg($resultados, "Round-trip LAMP", false, $e->getMessage());
        }
    }

    // 4) Contagem de Tt reais já preenchidos
    $contagens = [];
    foreach ($colsPcr as $c) {
        if (colExiste($pdo, 'resultados_pcr', $c)) {
            $n = (int)$pdo->query("SELECT COUNT(*) FROM resultados_pcr WHERE `$c` IS NOT NULL")->fetchColumn();
            $contagens["resultados_pcr.$c"] = $n;
        }
    }
    foreach ($colsLamp as $c) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM resultados_lamp WHERE `$c` IS NOT NULL")->fetchColumn();
        $contagens["resultados_lamp.$c"] = $n;
    }

} catch (Exception $e) {
    reg($resultados, "Conexão / execução", false, $e->getMessage());
    $contagens = [];
}

$tudoOk = !empty($resultados);
foreach ($resultados as $r) { if (!$r['ok']) $tudoOk = false; }
?>
<!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><title>QC — Tempo de Detecção (Tt)</title>
<style>
 body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f7fafc;color:#2d3748;padding:24px;max-width:900px;margin:auto;}
 h1{font-size:1.4em;} .ok{color:#2f855a;font-weight:bold;} .fail{color:#c53030;font-weight:bold;}
 table{width:100%;border-collapse:collapse;margin:14px 0;background:#fff;}
 th,td{border:1px solid #e2e8f0;padding:8px 10px;text-align:left;font-size:14px;}
 th{background:#2d3748;color:#fff;}
 .banner{padding:14px 18px;border-radius:8px;font-weight:bold;font-size:1.1em;margin-bottom:10px;}
 .banner.ok{background:#f0fff4;border:2px solid #38a169;}
 .banner.fail{background:#fff5f5;border:2px solid #e53e3e;}
</style></head><body>
<h1>🔬 Controle de Qualidade — Tempo de Detecção (Tt)</h1>
<div class="banner <?php echo $tudoOk ? 'ok' : 'fail'; ?>">
    <?php echo $tudoOk ? '✅ APROVADO: o Tt grava e lê corretamente nas colunas do banco.' : '❌ ATENÇÃO: há verificações com falha (ver abaixo).'; ?>
</div>
<table>
    <tr><th>Verificação</th><th>Status</th><th>Detalhe</th></tr>
    <?php foreach ($resultados as $r): ?>
    <tr>
        <td><?php echo htmlspecialchars($r['nome']); ?></td>
        <td class="<?php echo $r['ok'] ? 'ok' : 'fail'; ?>"><?php echo $r['ok'] ? 'PASSOU' : 'FALHOU'; ?></td>
        <td><?php echo htmlspecialchars($r['detalhe']); ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<h2 style="font-size:1.1em;">Registros reais com Tt preenchido</h2>
<table>
    <tr><th>Coluna</th><th>Linhas com Tt (não nulo)</th></tr>
    <?php foreach ($contagens as $col => $n): ?>
    <tr><td><?php echo htmlspecialchars($col); ?></td><td><?php echo (int)$n; ?></td></tr>
    <?php endforeach; ?>
</table>
<p style="font-size:12px;color:#718096;">Os testes de gravação rodam dentro de uma transação revertida (ROLLBACK): nenhum dado real é alterado.</p>
</body></html>
