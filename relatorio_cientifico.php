<?php
/**
 * relatorio_cientifico.php
 * Tabela 1 + Prevalencia (IC95% Wilson) + 3 graficos Chart.js.
 *
 * Resiliente: se v_dados_cientificos nao existe, monta fallback usando
 * animais_completos + filtro inline.
 */

// =================================================================
// ACESSO LIVRE PARA COLABORADORES (sem senha)
// A tela de login por senha foi removida a pedido do responsavel.
// =================================================================
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/config.php';

if (function_exists('setSecurityHeaders')) setSecurityHeaders();

$pdo = getDBConnection();

// =================================================================
// AJAX para EMISSAO DE LAUDOS (TASK 1)
// =================================================================
function _laudoTemView($pdo) {
    try { $pdo->query("SELECT 1 FROM v_dados_cientificos LIMIT 1"); return true; }
    catch (Throwable $e) { return false; }
}

if (isset($_POST['action'])) {
    $temViewLaudo = _laudoTemView($pdo);
    $whereExtraLaudo = $temViewLaudo ? '' : " AND COALESCE(ac.simulacao,0)=0 AND ac.codigo_animal NOT LIKE 'CLIMA%' ";

    if ($_POST['action'] === 'listar_fazendas_laudo') {
        header('Content-Type: application/json; charset=utf-8');
        if ($temViewLaudo) {
            $rows = $pdo->query("SELECT DISTINCT fazenda FROM v_dados_cientificos WHERE fazenda IS NOT NULL ORDER BY fazenda")->fetchAll();
            echo json_encode(['fazendas' => array_column($rows, 'fazenda')]);
        } else {
            $rows = $pdo->query("SELECT DISTINCT l.nome AS fazenda FROM locais l WHERE l.ativo=1 ORDER BY l.nome")->fetchAll();
            echo json_encode(['fazendas' => array_column($rows, 'fazenda')]);
        }
        exit;
    }

    if ($_POST['action'] === 'listar_animais_laudo') {
        header('Content-Type: application/json; charset=utf-8');
        $fazenda = trim($_POST['fazenda'] ?? '');
        $inicio  = $_POST['inicio'] ?? '2025-01-01';
        $fim     = $_POST['fim']    ?? date('Y-m-d');

        // Para 1 animal: 1 amostra = 1 data. COUNT(DISTINCT data_coleta) = n_amostras (=n_coletas neste contexto)
        // DEDUP defensivo contra duplicacao do JOIN com dados_climaticos.
        if ($temViewLaudo) {
            $sql = "SELECT codigo_animal,
                           MAX(sexo) AS sexo,
                           MAX(fazenda) AS fazenda,
                           COUNT(DISTINCT data_coleta) AS n_amostras,
                           COUNT(DISTINCT data_coleta) AS n_coletas,
                           MIN(data_coleta) AS primeira,
                           MAX(data_coleta) AS ultima,
                           MAX(pcr_babesia)     AS pcr_bb,
                           MAX(pcr_anaplasma)   AS pcr_am,
                           MAX(pcr_trypanosoma) AS pcr_tv,
                           MAX(lamp_babesia_18s)  AS lamp_18s,
                           MAX(lamp_babesia_cytb) AS lamp_cytb
                    FROM v_dados_cientificos
                    WHERE data_coleta BETWEEN ? AND ?";
            $params = [$inicio, $fim];
            if ($fazenda !== '') { $sql .= " AND fazenda = ?"; $params[] = $fazenda; }
            $sql .= " GROUP BY codigo_animal ORDER BY codigo_animal";
        } else {
            $sql = "SELECT ac.codigo_animal,
                           MAX(ac.sexo) AS sexo,
                           MAX(l.nome) AS fazenda,
                           COUNT(*) AS n_amostras,
                           COUNT(DISTINCT ac.data_coleta) AS n_coletas,
                           MIN(ac.data_coleta) AS primeira,
                           MAX(ac.data_coleta) AS ultima,
                           MAX(ac.pcr_babesia)     AS pcr_bb,
                           MAX(ac.pcr_anaplasma)   AS pcr_am,
                           MAX(ac.pcr_trypanosoma) AS pcr_tv,
                           MAX(ac.lamp_babesia_18s)  AS lamp_18s,
                           MAX(ac.lamp_babesia_cytb) AS lamp_cytb
                    FROM animais_completos ac
                    LEFT JOIN locais l ON l.id = ac.local_id
                    WHERE l.ativo = 1 $whereExtraLaudo
                      AND ac.data_coleta BETWEEN ? AND ?";
            $params = [$inicio, $fim];
            if ($fazenda !== '') { $sql .= " AND l.nome = ?"; $params[] = $fazenda; }
            $sql .= " GROUP BY ac.codigo_animal ORDER BY ac.codigo_animal";
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        echo json_encode(['animais' => $stmt->fetchAll()]);
        exit;
    }

    if ($_POST['action'] === 'gerar_laudo_html') {
        header('Content-Type: text/html; charset=utf-8');
        $fazenda = trim($_POST['fazenda'] ?? '');
        $inicio  = $_POST['inicio'] ?? '2025-01-01';
        $fim     = $_POST['fim']    ?? date('Y-m-d');
        $codigos = json_decode($_POST['codigos'] ?? '[]', true);
        if (!is_array($codigos) || empty($codigos)) {
            http_response_code(400);
            echo '<p style="color:red">Nenhum animal selecionado.</p>';
            exit;
        }

        // Query agregada
        $placeholders = implode(',', array_fill(0, count($codigos), '?'));
        if ($temViewLaudo) {
            // DEDUP por (animal, data) - 1 amostra = 1 animal x 1 data
            $sql = "SELECT codigo_animal,
                           MAX(sexo) AS sexo,
                           MAX(fazenda) AS fazenda,
                           MAX(municipio) AS municipio,
                           COUNT(DISTINCT data_coleta) AS n_amostras,
                           COUNT(DISTINCT data_coleta) AS n_coletas,
                           MIN(data_coleta) AS primeira,
                           MAX(data_coleta) AS ultima,
                           MAX(pcr_babesia)     AS pcr_bb,
                           MAX(pcr_anaplasma)   AS pcr_am,
                           MAX(pcr_trypanosoma) AS pcr_tv,
                           MAX(lamp_babesia_18s)  AS lamp_18s,
                           MAX(lamp_babesia_cytb) AS lamp_cytb
                    FROM v_dados_cientificos
                    WHERE data_coleta BETWEEN ? AND ?
                      AND codigo_animal IN ($placeholders)
                    GROUP BY codigo_animal
                    ORDER BY codigo_animal";
            $params = array_merge([$inicio, $fim], $codigos);
        } else {
            $sql = "SELECT ac.codigo_animal,
                           MAX(ac.sexo) AS sexo,
                           MAX(l.nome) AS fazenda,
                           MAX(l.municipio) AS municipio,
                           COUNT(*) AS n_amostras,
                           COUNT(DISTINCT ac.data_coleta) AS n_coletas,
                           MIN(ac.data_coleta) AS primeira,
                           MAX(ac.data_coleta) AS ultima,
                           MAX(ac.pcr_babesia)     AS pcr_bb,
                           MAX(ac.pcr_anaplasma)   AS pcr_am,
                           MAX(ac.pcr_trypanosoma) AS pcr_tv,
                           MAX(ac.lamp_babesia_18s)  AS lamp_18s,
                           MAX(ac.lamp_babesia_cytb) AS lamp_cytb
                    FROM animais_completos ac
                    LEFT JOIN locais l ON l.id = ac.local_id
                    WHERE l.ativo = 1 $whereExtraLaudo
                      AND ac.data_coleta BETWEEN ? AND ?
                      AND ac.codigo_animal IN ($placeholders)
                    GROUP BY ac.codigo_animal
                    ORDER BY ac.codigo_animal";
            $params = array_merge([$inicio, $fim], $codigos);
        }
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $animais = $stmt->fetchAll();

        // Estatisticas para conclusao
        $totalAnim = count($animais);
        $nPos_bb = 0; $nPos_am = 0; $nPos_tv = 0; $nPos_18s = 0; $nPos_cytb = 0;
        $nPosTotal = 0; $nMulti = 0;
        foreach ($animais as $a) {
            $bb = ((int)$a['pcr_bb']) || ((int)$a['lamp_18s']) || ((int)$a['lamp_cytb']);
            if ($bb) $nPos_bb++;
            if ((int)$a['pcr_am']) $nPos_am++;
            if ((int)$a['pcr_tv']) $nPos_tv++;
            if ((int)$a['lamp_18s']) $nPos_18s++;
            if ((int)$a['lamp_cytb']) $nPos_cytb++;
            $somap = ($bb?1:0) + ((int)$a['pcr_am']) + ((int)$a['pcr_tv']);
            if ($somap >= 1) $nPosTotal++;
            if ($somap >= 2) $nMulti++;
        }

        // Numero do laudo
        $numLaudo = date('Y') . '-' . str_pad((string)mt_rand(1,999), 3, '0', STR_PAD_LEFT) . '/LGM';
        $dataEmissao = date('d/m/Y');
        // Data por extenso
        $meses = ['','janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];
        $dataExtenso = date('d') . ' de ' . $meses[(int)date('n')] . ' de ' . date('Y');
        $fazendaTitulo = $fazenda !== '' ? htmlspecialchars($fazenda) : 'múltiplas fazendas';
        $municipios = array_unique(array_filter(array_column($animais, 'municipio')));
        $municipioTxt = !empty($municipios) ? implode(', ', $municipios) : '-';

        $fmtData = function($d) { return $d ? date('d/m/Y', strtotime($d)) : '-'; };
        $statusBadge = function($v) {
            $v = (int)$v;
            return $v
                ? '<span class="lau-pos">POSITIVO</span>'
                : '<span class="lau-neg">Negativo</span>';
        };

        ?>
        <div class="laudo-doc" id="laudo-doc-print">
          <div class="laudo-cabecalho">
            <h1>LAUDO DE ANÁLISE MOLECULAR</h1>
            <div class="laudo-instituicao">
                <strong>Instituto Federal do Rio de Janeiro – Campus Rio de Janeiro</strong><br>
                Laboratório de Genética Molecular (LGM)<br>
                <small>Diagnóstico de Hemoparasitas Bovinos – Tristeza Parasitária Bovina</small>
            </div>
          </div>

          <div class="laudo-meta">
            <div><b>Laudo Nº:</b> <?= htmlspecialchars($numLaudo) ?></div>
            <div><b>Data de emissão:</b> <?= htmlspecialchars($dataEmissao) ?></div>
            <div><b>Fazenda(s):</b> <?= $fazendaTitulo ?></div>
            <div><b>Município(s):</b> <?= htmlspecialchars($municipioTxt) ?></div>
            <div><b>Período de coleta:</b> <?= $fmtData($inicio) ?> a <?= $fmtData($fim) ?></div>
            <div><b>Total de animais analisados:</b> <?= $totalAnim ?></div>
          </div>

          <div class="laudo-introducao">
            <p>Aos <?= htmlspecialchars($dataExtenso) ?>, atesta-se que os <b><?= $totalAnim ?> (<?= numero_extenso($totalAnim) ?>)</b> bovinos abaixo relacionados, oriundos da propriedade <b><?= $fazendaTitulo ?></b><?= $municipioTxt !== '-' ? ', município(s) de ' . htmlspecialchars($municipioTxt) : '' ?>, foram submetidos a análises moleculares para detecção de hemoparasitas do complexo Tristeza Parasitária Bovina (TPB), conforme protocolos padronizados pelo Laboratório de Genética Molecular do IFRJ-CRJ.</p>
            <p>Foram realizadas reações de <b>PCR convencional</b> para detecção de <i>Babesia bovis</i>, <i>Anaplasma marginale</i> e <i>Trypanosoma vivax</i>, complementadas por <b>PCR-LAMP isotérmica</b> dirigida aos alvos 18S rRNA e citocromo B (CytB) específicos para <i>B. bovis</i>. As amostras foram obtidas no intervalo entre <b><?= $fmtData($inicio) ?> e <?= $fmtData($fim) ?></b>, em coletas seriais conforme delineamento longitudinal trimestral do estudo (cada amostra refere-se a 1 animal em 1 data de visita ao campo).</p>
          </div>

          <table class="laudo-tabela">
            <thead><tr>
                <th>Cód. Animal</th>
                <th>Sexo</th>
                <th>Amostras<br><small>(datas distintas)</small></th>
                <th>Última coleta</th>
                <th>PCR<br><i>B. bovis</i></th>
                <th>PCR<br><i>A. marginale</i></th>
                <th>PCR<br><i>T. vivax</i></th>
                <th>LAMP<br>18S</th>
                <th>LAMP<br>CytB</th>
                <th>Conclusão por animal</th>
            </tr></thead>
            <tbody>
            <?php foreach ($animais as $a):
                $bb_qualquer = ((int)$a['pcr_bb']) || ((int)$a['lamp_18s']) || ((int)$a['lamp_cytb']);
                $am = (int)$a['pcr_am']; $tv = (int)$a['pcr_tv'];
                $pos = [];
                if ($bb_qualquer) $pos[] = '<i>B. bovis</i>';
                if ($am)          $pos[] = '<i>A. marginale</i>';
                if ($tv)          $pos[] = '<i>T. vivax</i>';
                $conclusao = empty($pos)
                    ? '<span class="lau-conc-neg">Negativo para os hemoparasitas testados</span>'
                    : '<span class="lau-conc-pos">Positivo para: ' . implode(', ', $pos) . '</span>';
            ?>
              <tr>
                <td><b><?= htmlspecialchars($a['codigo_animal']) ?></b></td>
                <td><?= htmlspecialchars($a['sexo']) ?></td>
                <td class="num"><?= (int)$a['n_amostras'] ?></td>
                <td><?= $fmtData($a['ultima']) ?></td>
                <td><?= $statusBadge($a['pcr_bb']) ?></td>
                <td><?= $statusBadge($a['pcr_am']) ?></td>
                <td><?= $statusBadge($a['pcr_tv']) ?></td>
                <td><?= $statusBadge($a['lamp_18s']) ?></td>
                <td><?= $statusBadge($a['lamp_cytb']) ?></td>
                <td><?= $conclusao ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>

          <div class="laudo-conclusao">
            <h3>Conclusão analítica</h3>
            <p>Dos <b><?= $totalAnim ?></b> animais analisados no período, <b><?= $nPosTotal ?></b> (<?= $totalAnim>0 ? round(100*$nPosTotal/$totalAnim,1) : 0 ?>%) apresentaram positividade para ao menos um hemoparasita; <b><?= $nMulti ?></b> animais apresentaram coinfecção (≥2 patógenos simultâneos). Estratificação por agente etiológico:</p>
            <ul>
              <li><i>Babesia bovis</i> (qualquer alvo positivo: PCR, LAMP 18S ou LAMP CytB): <b><?= $nPos_bb ?>/<?= $totalAnim ?></b> animais.</li>
              <li><i>Anaplasma marginale</i> (PCR): <b><?= $nPos_am ?>/<?= $totalAnim ?></b> animais.</li>
              <li><i>Trypanosoma vivax</i> (PCR): <b><?= $nPos_tv ?>/<?= $totalAnim ?></b> animais.</li>
            </ul>

            <h3>Recomendações terapêuticas</h3>
            <p>Com base nos resultados obtidos, sugerem-se as seguintes condutas (sempre sob prescrição e supervisão de Médico Veterinário responsável):</p>
            <ol>
              <?php if ($nPos_bb > 0): ?>
                <li><b>Animais positivos para <i>Babesia bovis</i></b>: avaliar sinais clínicos de babesiose (febre, anemia, hemoglobinúria, icterícia). Fármacos disponíveis no mercado brasileiro:
                  <ul style="margin:.3em 0 .3em 1em;font-size:.94em;">
                    <li><b>Dipropionato de imidocarb 12%</b> (Imizol® — MSD; Imidox® / Imicarb®). Dose usual: 1,2 mg/kg IM/SC, dose única, repetida em 14 dias se necessário. <i>Efeito babesicida + anaplasmicida parcial.</i></li>
                    <li><b>Diaceturato de diminazeno + fenazona/antipirina</b> (Pirosan® — Calbos; Tristezina® — UCB; Ganavet® — Ouro Fino; Beroseg® — Bayer). Dose usual: 3,5 mg/kg IM, dose única. <i>Babesicida e tripanocida potente; também usado em coinfecção B. bovis + T. vivax.</i></li>
                    <li><b>Diaceturato de 4,4-diazoaminodibenzimidina + vitamina B12 (cianocobalamina)</b> (Izoot® — Hertape). Babesicida com suporte hematopoético da B12 — útil quando há anemia importante.</li>
                  </ul>
                  Controle vetorial: tratamento estratégico do <i>Rhipicephalus microplus</i> (pour-on, banho carrapaticida).
                </li>
              <?php endif; ?>
              <?php if ($nPos_am > 0): ?>
                <li><b>Animais positivos para <i>Anaplasma marginale</i></b>: monitorar icterícia (sem hemoglobinúria, importante para diferencial de babesiose), perda de condição corporal, palidez de mucosas. <b style="color:#a00;">ATENÇÃO:</b> os babesicidas listados acima (diminazeno, imidocarb em monoterapia, diaceturato) <b>NÃO são tratamento de primeira linha para anaplasmose</b> — <i>Anaplasma</i> é uma rickettsia (bactéria intracelular obrigatória), exigindo antibioticoterapia:
                  <ul style="margin:.3em 0 .3em 1em;font-size:.94em;">
                    <li><b>Oxitetraciclina de longa ação 200 mg/mL</b> (Terramicina LA® — Zoetis; Oxitrat LA®; Oxi-Tetra 200®). Dose: 20 mg/kg IM profunda, dose única, podendo repetir em 72h. <i>Tratamento de eleição segundo OIE e literatura brasileira.</i></li>
                    <li><b>Imidocarb</b> em <i>doses elevadas</i> (3 mg/kg, 2 aplicações com intervalo de 14 dias) tem ação anaplasmicida — opção quando há coinfecção B. bovis + A. marginale comprovada.</li>
                    <li><b>Suporte clínico</b>: hemotransfusão se PCV &lt; 12%, hidratação parenteral, hematínicos (ferro dextrano + B12).</li>
                  </ul>
                  Em rebanhos endemicamente estáveis (alta prevalência crônica), tratar apenas animais sintomáticos.
                </li>
              <?php endif; ?>
              <?php if ($nPos_tv > 0): ?>
                <li><b>Animais positivos para <i>Trypanosoma vivax</i></b>: investigar anemia progressiva, perda de peso, edemas submandibulares. Fármacos tripanocidas brasileiros:
                  <ul style="margin:.3em 0 .3em 1em;font-size:.94em;">
                    <li><b>Diaceturato de diminazeno + fenazona</b> (Pirosan®, Tristezina®, Ganavet®, Beroseg®). Dose: 3,5–7 mg/kg IM, dose única. <i>Tripanocida de eleição em pequenos surtos.</i></li>
                    <li><b>Cloreto de isometamídio</b> (Trypamidium Samorin® — importado). 0,5–1 mg/kg IM. <i>Quimioprofilaxia (efeito residual ~3-6 meses).</i></li>
                    <li><b>Aceturato de diminazeno + cianocobalamina</b> (Izoot®). Tripanocida com suporte hematopoético.</li>
                  </ul>
                  Controle de mosca-dos-estábulos (<i>Stomoxys calcitrans</i>) e tabanídeos é essencial — sem controle vetorial, reinfecção é certa.
                </li>
              <?php endif; ?>
              <?php if ($nMulti > 0): ?>
                <li><b>Animais com coinfecção (≥ 2 hemoparasitas)</b>: risco clínico elevado. Para combinação <i>B. bovis + T. vivax</i>, diminazeno isolado cobre ambos (3,5–7 mg/kg IM). Para <i>B. bovis + A. marginale</i>, considerar <b>imidocarb em dose alta</b> (3 mg/kg ×2, intervalo 14 dias) que cobre os dois, ou associar diminazeno + oxitetraciclina. Para infecção tripla, abordagem terapêutica sequencial: tratar primeiro o agente clinicamente dominante, reavaliar em 7 dias.</li>
              <?php endif; ?>
              <?php if ($nPosTotal === 0): ?>
                <li>Nenhuma positividade detectada no período. Recomenda-se manutenção do programa de vigilância sanitária e nova coleta seriada conforme o calendário trimestral do estudo.</li>
              <?php else: ?>
                <li><b>Acompanhamento longitudinal</b>: prosseguir com a coleta seriada para avaliar dinâmica da infecção (persistência, soroconversão, aclaramento). Reavaliação recomendada no próximo trimestre. Em animais tratados, realizar PCR de controle 30 dias após o término do tratamento para confirmar negativação.</li>
              <?php endif; ?>
              <li><b>Manejo geral</b>: controle estratégico de ectoparasitas (carrapaticidas no manejo seasonal), suplementação mineral adequada (Cu, Co, Se, vit. A), observação de animais recém-introduzidos (quarentena + sorologia), biossegurança no manejo de fluidos corpóreos (descarte adequado de agulhas) e desinfecção de instrumentos cirúrgicos.</li>
            </ol>

            <p style="font-size:.86em;color:#555;background:#f8f9fa;padding:.6em;border-left:3px solid #1F4E78;border-radius:3px;margin-top:1em;">
              <b>Informação técnica detalhada</b>: para descrição completa dos princípios ativos (Diaceturato de diminazeno, Dipropionato de imidocarb, Aceturato de diminazeno + B12, Oxitetraciclina), mecanismos de ação, posologia, contraindicações e indicações comparativas, consulte o documento <code>medicamentos_tpb.md</code> no diretório do projeto.
            </p>
          </div>

          <div class="laudo-rodape">
            <div class="laudo-assinatura">
              <div class="laudo-linha-ass"></div>
              <div><b>Responsável Técnico</b></div>
              <div>Laboratório de Genética Molecular – IFRJ/CRJ</div>
              <div><small>Emitido eletronicamente em <?= $dataEmissao ?> &middot; Laudo nº <?= htmlspecialchars($numLaudo) ?></small></div>
            </div>
            <div class="laudo-obs">
              <small><b>Observação:</b> este laudo refere-se exclusivamente às amostras processadas no período indicado e aos métodos moleculares aplicados. Não substitui avaliação clínica veterinária individual. Os resultados refletem a presença de DNA do patógeno na amostra; correlação com sinais clínicos deve ser feita pelo médico veterinário responsável.</small>
            </div>
          </div>
        </div>
        <?php
        exit;
    }
}

// Numero por extenso simples (1..999)
function numero_extenso($n) {
    $unidades = ['', 'um','dois','três','quatro','cinco','seis','sete','oito','nove','dez',
                 'onze','doze','treze','quatorze','quinze','dezesseis','dezessete','dezoito','dezenove'];
    $dezenas  = ['','','vinte','trinta','quarenta','cinquenta','sessenta','setenta','oitenta','noventa'];
    $centenas = ['','cento','duzentos','trezentos','quatrocentos','quinhentos','seiscentos','setecentos','oitocentos','novecentos'];
    if ($n === 0) return 'zero';
    if ($n === 100) return 'cem';
    if ($n < 20) return $unidades[$n];
    if ($n < 100) {
        $d = intdiv($n, 10); $u = $n % 10;
        return $dezenas[$d] . ($u ? ' e ' . $unidades[$u] : '');
    }
    $c = intdiv($n, 100); $r = $n % 100;
    return $centenas[$c] . ($r ? ' e ' . numero_extenso($r) : '');
}

function wilson95($k, $n) {
    if ($n <= 0) return ['p'=>0.0, 'lower'=>0.0, 'upper'=>0.0];
    $p = $k / $n;
    $z = 1.959963984540054;
    $denom  = 1 + ($z*$z)/$n;
    $center = $p + ($z*$z)/(2*$n);
    $margin = $z * sqrt(($p*(1-$p) + ($z*$z)/(4*$n))/$n);
    return [
        'p'     => $p,
        'lower' => max(0.0, ($center-$margin)/$denom),
        'upper' => min(1.0, ($center+$margin)/$denom),
    ];
}
function fmt_pct($p) { return number_format($p*100, 1, ',', '.') . '%'; }
function fmt_ci($w)  { return '[' . fmt_pct($w['lower']) . ' - ' . fmt_pct($w['upper']) . ']'; }

// =================================================================
// BIBLIOTECA ESTATISTICA (PHP puro — sem extensoes alem de PDO/math)
// Funcoes especiais adaptadas de Numerical Recipes (gammln/gser/gcf/betacf).
// Validada contra valores de referencia (normal, qui-quadrado, t de Student,
// regressao logistica vs OR analitico 2x2, McNemar exato/assintotico, kappa).
// =================================================================
function _gammln($xx) {
    $cof = [76.18009172947146, -86.50532032941677, 24.01409824083091,
            -1.231739572450155, 0.1208650973866179e-2, -0.5395239384953e-5];
    $x = $xx; $y = $xx; $tmp = $x + 5.5; $tmp -= ($x + 0.5) * log($tmp);
    $ser = 1.000000000190015;
    for ($j = 0; $j < 6; $j++) { $y += 1.0; $ser += $cof[$j] / $y; }
    return -$tmp + log(2.5066282746310005 * $ser / $x);
}
function _gser($a, $x) {
    if ($x <= 0) return 0.0;
    $ap = $a; $sum = 1.0 / $a; $del = $sum;
    for ($n = 1; $n <= 500; $n++) { $ap += 1.0; $del *= $x / $ap; $sum += $del;
        if (abs($del) < abs($sum) * 1e-14) break; }
    return $sum * exp(-$x + $a * log($x) - _gammln($a));
}
function _gcf($a, $x) {
    $FPMIN = 1e-300; $b = $x + 1.0 - $a; $c = 1.0 / $FPMIN; $d = 1.0 / $b; $h = $d;
    for ($i = 1; $i <= 500; $i++) {
        $an = -$i * ($i - $a); $b += 2.0; $d = $an * $d + $b; if (abs($d) < $FPMIN) $d = $FPMIN;
        $c = $b + $an / $c; if (abs($c) < $FPMIN) $c = $FPMIN; $d = 1.0 / $d; $del = $d * $c; $h *= $del;
        if (abs($del - 1.0) < 1e-14) break; }
    return exp(-$x + $a * log($x) - _gammln($a)) * $h;
}
function gammp($a, $x) { if ($x < 0 || $a <= 0) return 0.0; return ($x < $a + 1.0) ? _gser($a, $x) : 1.0 - _gcf($a, $x); }
function gammq($a, $x) { return 1.0 - gammp($a, $x); }
function _betacf($a, $b, $x) {
    $FPMIN = 1e-300; $qab = $a + $b; $qap = $a + 1.0; $qam = $a - 1.0;
    $c = 1.0; $d = 1.0 - $qab * $x / $qap; if (abs($d) < $FPMIN) $d = $FPMIN; $d = 1.0 / $d; $h = $d;
    for ($m = 1; $m <= 300; $m++) {
        $m2 = 2 * $m;
        $aa = $m * ($b - $m) * $x / (($qam + $m2) * ($a + $m2));
        $d = 1.0 + $aa * $d; if (abs($d) < $FPMIN) $d = $FPMIN;
        $c = 1.0 + $aa / $c; if (abs($c) < $FPMIN) $c = $FPMIN; $d = 1.0 / $d; $h *= $d * $c;
        $aa = -($a + $m) * ($qab + $m) * $x / (($a + $m2) * ($qap + $m2));
        $d = 1.0 + $aa * $d; if (abs($d) < $FPMIN) $d = $FPMIN;
        $c = 1.0 + $aa / $c; if (abs($c) < $FPMIN) $c = $FPMIN; $d = 1.0 / $d; $del = $d * $c; $h *= $del;
        if (abs($del - 1.0) < 1e-14) break; }
    return $h;
}
function betai($a, $b, $x) {
    if ($x <= 0.0) return 0.0; if ($x >= 1.0) return 1.0;
    $bt = exp(_gammln($a + $b) - _gammln($a) - _gammln($b) + $a * log($x) + $b * log(1.0 - $x));
    if ($x < ($a + 1.0) / ($a + $b + 2.0)) return $bt * _betacf($a, $b, $x) / $a;
    return 1.0 - $bt * _betacf($b, $a, 1.0 - $x) / $b;
}
function normCdf($z) {
    if ($z >= 0) return 0.5 * (1.0 + gammp(0.5, $z * $z / 2.0));
    return 0.5 * (1.0 - gammp(0.5, $z * $z / 2.0));
}
function normSf($z) { return 1.0 - normCdf($z); }
function chiSqSf($x, $df) { if ($x <= 0) return 1.0; return gammq($df / 2.0, $x / 2.0); }
function studentTwoSided($t, $df) { if ($df <= 0) return 1.0; $t = abs($t); return betai($df / 2.0, 0.5, $df / ($df + $t * $t)); }

// Inferencia para o rho de Spearman: p-valor (aprox. t) + IC95% (Fisher z)
function spearmanInfer($rho, $n) {
    if ($rho === null || $n < 4) return ['p' => null, 'lo' => null, 'hi' => null];
    $rho = max(-0.999999, min(0.999999, $rho));
    $t = $rho * sqrt(($n - 2) / (1 - $rho * $rho));
    $p = studentTwoSided($t, $n - 2);
    $lo = null; $hi = null;
    if ($n > 3) {
        $zr = 0.5 * log((1 + $rho) / (1 - $rho)); $se = 1.0 / sqrt($n - 3);
        $lo = tanh($zr - 1.959963984540054 * $se); $hi = tanh($zr + 1.959963984540054 * $se);
    }
    return ['p' => $p, 'lo' => $lo, 'hi' => $hi];
}
// Qui-quadrado de independencia para tabela RxC; retorna chi2, df, p, menor esperado
function chiSquareTest($table) {
    $R = count($table); if ($R < 2) return null;
    $C = count($table[0]); if ($C < 2) return null;
    $rowSum = array_fill(0, $R, 0.0); $colSum = array_fill(0, $C, 0.0); $tot = 0.0;
    for ($i = 0; $i < $R; $i++) for ($j = 0; $j < $C; $j++) { $v = (float)$table[$i][$j]; $rowSum[$i] += $v; $colSum[$j] += $v; $tot += $v; }
    if ($tot <= 0) return null;
    $chi = 0.0; $minExp = INF;
    for ($i = 0; $i < $R; $i++) for ($j = 0; $j < $C; $j++) {
        $e = $rowSum[$i] * $colSum[$j] / $tot; if ($e <= 0) continue;
        $minExp = min($minExp, $e); $chi += pow($table[$i][$j] - $e, 2) / $e; }
    $df = ($R - 1) * ($C - 1);
    return ['chi2' => $chi, 'df' => $df, 'p' => chiSqSf($chi, $df), 'min_expected' => $minExp];
}
// McNemar pareado a partir dos discordantes b (ref+/teste-) e c (ref-/teste+)
function mcnemarTest($b, $c) {
    $nd = $b + $c;
    if ($nd === 0) return ['chi2' => 0.0, 'df' => 1, 'p' => 1.0, 'b' => $b, 'c' => $c, 'discordantes' => 0, 'metodo' => '—'];
    if ($nd >= 25) {
        // Correcao de continuidade de Yates, travada em zero (|b-c|<=1 => chi=0 => p=1)
        $diff = max(0.0, abs($b - $c) - 1.0);
        $chi = $diff * $diff / $nd;
        return ['chi2' => $chi, 'df' => 1, 'p' => chiSqSf($chi, 1), 'b' => $b, 'c' => $c, 'discordantes' => $nd, 'metodo' => 'qui-quadrado (corr. continuidade)'];
    }
    $k = min($b, $c); $p = 0.0;
    for ($i = 0; $i <= $k; $i++) $p += exp(_gammln($nd + 1) - _gammln($i + 1) - _gammln($nd - $i + 1)) * pow(0.5, $nd);
    $p = min(1.0, 2.0 * $p);
    return ['chi2' => null, 'df' => 1, 'p' => $p, 'b' => $b, 'c' => $c, 'discordantes' => $nd, 'metodo' => 'binomial exato'];
}
// IC95% assintotico do kappa de Cohen a partir do 2x2 (a,b,c,d)
function kappaCI($a, $b, $c, $d) {
    $n = $a + $b + $c + $d; if ($n == 0) return ['kappa' => null, 'lo' => null, 'hi' => null, 'se' => null];
    $po = ($a + $d) / $n; $p1 = ($a + $b) / $n; $p2 = ($a + $c) / $n; $q1 = ($c + $d) / $n; $q2 = ($b + $d) / $n;
    $pe = $p1 * $p2 + $q1 * $q2; if (abs(1 - $pe) < 1e-12) return ['kappa' => null, 'lo' => null, 'hi' => null, 'se' => null];
    $k = ($po - $pe) / (1 - $pe);
    $se = sqrt(($po * (1 - $po)) / ($n * pow(1 - $pe, 2)));
    return ['kappa' => $k, 'se' => $se, 'lo' => $k - 1.959963984540054 * $se, 'hi' => $k + 1.959963984540054 * $se];
}
// Inversa de matriz por Gauss-Jordan com pivotamento parcial; null se singular
function matInverse($A) {
    $n = count($A); $M = [];
    for ($i = 0; $i < $n; $i++) { $M[$i] = array_values($A[$i]); for ($j = 0; $j < $n; $j++) $M[$i][$n + $j] = ($i === $j) ? 1.0 : 0.0; }
    for ($col = 0; $col < $n; $col++) {
        $piv = $col; $best = abs($M[$col][$col]);
        for ($r = $col + 1; $r < $n; $r++) if (abs($M[$r][$col]) > $best) { $best = abs($M[$r][$col]); $piv = $r; }
        if ($best < 1e-12) return null;
        if ($piv !== $col) { $tmp = $M[$piv]; $M[$piv] = $M[$col]; $M[$col] = $tmp; }
        $d = $M[$col][$col]; for ($j = 0; $j < 2 * $n; $j++) $M[$col][$j] /= $d;
        for ($r = 0; $r < $n; $r++) { if ($r === $col) continue; $f = $M[$r][$col]; if ($f == 0.0) continue;
            for ($j = 0; $j < 2 * $n; $j++) $M[$r][$j] -= $f * $M[$col][$j]; }
    }
    $inv = []; for ($i = 0; $i < $n; $i++) $inv[$i] = array_slice($M[$i], $n, $n);
    return $inv;
}
/**
 * Regressao logistica multivariavel via IRLS (Newton-Raphson).
 * $X: linhas de preditores (SEM intercepto, adicionado automaticamente); $y: 0/1.
 * Preditores padronizados internamente -> OR reportado por +1 desvio-padrao (DP).
 * Retorna null se n insuficiente / nao-convergiu / singular / preditor constante.
 */
function logisticFit($X, $y, $maxIter = 50, $tol = 1e-8) {
    $n = count($X); if ($n < 1) return null;
    $p = count($X[0]); if ($p < 1) return null;
    if ($n < 5 * ($p + 1)) return null;
    $mean = array_fill(0, $p, 0.0); $sd = array_fill(0, $p, 0.0);
    for ($j = 0; $j < $p; $j++) {
        for ($i = 0; $i < $n; $i++) $mean[$j] += $X[$i][$j]; $mean[$j] /= $n;
        for ($i = 0; $i < $n; $i++) $sd[$j] += pow($X[$i][$j] - $mean[$j], 2);
        $sd[$j] = sqrt($sd[$j] / max(1, $n - 1)); if ($sd[$j] < 1e-12) return null;
    }
    $Z = [];
    for ($i = 0; $i < $n; $i++) { $row = [1.0]; for ($j = 0; $j < $p; $j++) $row[] = ($X[$i][$j] - $mean[$j]) / $sd[$j]; $Z[$i] = $row; }
    $k = $p + 1; $beta = array_fill(0, $k, 0.0); $sumY = array_sum($y);
    if ($sumY <= 0 || $sumY >= $n) return null;
    $beta[0] = log($sumY / ($n - $sumY)); $cov = null; $converged = false;
    for ($iter = 0; $iter < $maxIter; $iter++) {
        $g = array_fill(0, $k, 0.0); $H = array_fill(0, $k, array_fill(0, $k, 0.0));
        for ($i = 0; $i < $n; $i++) {
            $eta = 0.0; for ($a = 0; $a < $k; $a++) $eta += $beta[$a] * $Z[$i][$a];
            $mu = 1.0 / (1.0 + exp(-$eta)); $w = $mu * (1.0 - $mu); if ($w < 1e-10) $w = 1e-10;
            $resid = $y[$i] - $mu;
            for ($a = 0; $a < $k; $a++) { $g[$a] += $Z[$i][$a] * $resid;
                for ($bb = $a; $bb < $k; $bb++) $H[$a][$bb] += $Z[$i][$a] * $Z[$i][$bb] * $w; }
        }
        for ($a = 0; $a < $k; $a++) for ($bb = 0; $bb < $a; $bb++) $H[$a][$bb] = $H[$bb][$a];
        $Hinv = matInverse($H); if ($Hinv === null) return null;
        $maxStep = 0.0;
        for ($a = 0; $a < $k; $a++) { $step = 0.0; for ($bb = 0; $bb < $k; $bb++) $step += $Hinv[$a][$bb] * $g[$bb];
            $beta[$a] += $step; $maxStep = max($maxStep, abs($step)); }
        $cov = $Hinv; if ($maxStep < $tol) { $converged = true; break; }
    }
    if ($cov === null || !$converged) return null;
    // Guarda contra (quase-)separacao: betas divergentes/nao-finitos invalidam o modelo
    // (evita OR=INF, que quebraria o json_encode do forest plot e abortaria o <script>)
    foreach ($beta as $bv) { if (!is_finite($bv) || abs($bv) > 25) return null; }
    $out = [];
    for ($j = 0; $j < $p; $j++) {
        $a = $j + 1; $b = $beta[$a]; $se = sqrt(max(0.0, $cov[$a][$a])); $z = $se > 0 ? $b / $se : 0.0;
        $or = exp($b); $or_lo = exp($b - 1.959963984540054 * $se); $or_hi = exp($b + 1.959963984540054 * $se);
        if (!is_finite($or) || !is_finite($or_lo) || !is_finite($or_hi) || !is_finite($se)) return null;
        $out[] = ['beta' => $b, 'se' => $se, 'z' => $z, 'p' => 2.0 * normSf(abs($z)),
            'or' => $or, 'or_lo' => $or_lo, 'or_hi' => $or_hi,
            'sd' => $sd[$j], 'mean' => $mean[$j]];
    }
    return ['n' => $n, 'eventos' => (int)$sumY, 'intercept' => $beta[0], 'coef' => $out];
}
// Regressao linear ponderada (para linha de tendencia nos bins): slope, intercept, r2
function olsWeighted($points) {
    $sw=0;$swx=0;$swy=0;$swxx=0;$swxy=0;$swyy=0;
    foreach ($points as $pt) { $w = $pt['w'] ?? 1.0; $x = $pt['x']; $y = $pt['y'];
        $sw+=$w;$swx+=$w*$x;$swy+=$w*$y;$swxx+=$w*$x*$x;$swxy+=$w*$x*$y;$swyy+=$w*$y*$y; }
    if ($sw <= 0) return null;
    $denom = $sw*$swxx - $swx*$swx; if (abs($denom) < 1e-12) return null;
    $slope = ($sw*$swxy - $swx*$swy)/$denom; $intercept = ($swy - $slope*$swx)/$sw;
    $num = $sw*$swxy - $swx*$swy; $den = sqrt(($sw*$swxx - $swx*$swx)*($sw*$swyy - $swy*$swy));
    $r = $den > 1e-12 ? $num/$den : 0.0;
    return ['slope'=>$slope,'intercept'=>$intercept,'r2'=>$r*$r];
}
// Maximo "redondo" para eixos: pega o teto agradavel acima do maior valor (+ folga), com limite
function niceMax($vals, $cap = 100.0, $headroom = 0.15, $floor = 5.0) {
    $vals = array_filter((array)$vals, fn($v) => $v !== null && is_numeric($v));
    if (empty($vals)) return $cap;
    $mx = max($vals) * (1 + $headroom);
    if ($mx <= 0) return $floor;
    $mx = min($mx, $cap);
    $pot = pow(10, floor(log10(max($mx, 1e-9))));
    $passos = [1, 2, 2.5, 5, 10];
    foreach ($passos as $s) { $cand = $s * $pot; if ($cand >= $mx) { return min($cap, max($floor, $cand)); } }
    return min($cap, max($floor, 10 * $pot));
}
// Formatacao pt-BR para p-valor e OR
// fmt_p: valor "cru" HTML-safe para celulas de tabela ("&lt; 0,001" ou "0,034")
function fmt_p($p) {
    if ($p === null) return '—';
    if ($p < 0.001) return '&lt; 0,001';
    return number_format($p, 3, ',', '.');
}
// fmt_pclause: clausula para prosa ("p &lt; 0,001" ou "p = 0,034")
function fmt_pclause($p) {
    if ($p === null) return 'p = —';
    if ($p < 0.001) return 'p &lt; 0,001';
    return 'p = ' . number_format($p, 3, ',', '.');
}
function fmt_num($v, $dec = 2) { return $v === null ? '—' : number_format($v, $dec, ',', '.'); }
// Nota inferencial de correlacao (rho + IC95% + p + R2 da tendencia)
function notaCorr($rho, $infer, $trend) {
    if (!isset($rho['rho']) || $rho['rho'] === null) return '<span style="color:#999">amostra insuficiente (n='.($rho['n']??0).')</span>';
    $sig = ($infer['p'] !== null && $infer['p'] < 0.05);
    $ci  = ($infer['lo'] !== null) ? ' · IC95% ['.fmt_num($infer['lo'],2).'; '.fmt_num($infer['hi'],2).']' : '';
    $r2  = ($trend && isset($trend['r2'])) ? ' · R²='.fmt_num($trend['r2'],2) : '';
    $cor = $sig ? '#157347' : '#555';
    $star = $sig ? ' <b style="color:#157347" title="p &lt; 0,05">*</b>' : '';
    return '<span style="color:'.$cor.'">ρ = '.fmt_num($rho['rho'],2).$ci.' · '.fmt_pclause($infer['p']).$star.' (n='.$rho['n'].')'.$r2.'</span>';
}

// =============== Detectar fonte de dados ===============
$temView = false;
try {
    $pdo->query("SELECT 1 FROM v_dados_cientificos LIMIT 1");
    $temView = true;
} catch (Throwable $e) {}

$temAnimaisCompletos = false;
try {
    $pdo->query("SELECT 1 FROM animais_completos LIMIT 1");
    $temAnimaisCompletos = true;
} catch (Throwable $e) {}

// Se nao tem nenhum, mostrar aviso e parar
if (!$temView && !$temAnimaisCompletos) {
    ?>
    <!doctype html><meta charset="utf-8"><title>Relatorio Cientifico</title>
    <style>body{font-family:Arial,sans-serif;margin:2em;color:#333}
    .alerta{background:#fff3cd;border-left:6px solid #ffc107;padding:1em;border-radius:4px;max-width:700px}</style>
    <h1>Relatorio Cientifico - banco vazio</h1>
    <div class="alerta"><b>Nenhuma das tabelas/views necessarias foi encontrada</b>
    (<code>v_dados_cientificos</code> nem <code>animais_completos</code>).<br><br>
    Possiveis causas: banco recem-criado sem dump importado, ou container ainda subindo.<br>
    Tente: <pre>docker compose down -v
docker compose up -d --build</pre></div>
    <?php
    exit;
}

// =============== Fonte: view OU fallback ===============
if ($temView) {
    $fonte = 'v_dados_cientificos';
    $whereExtra = '';
    $banner = "<div style='background:#d1e7dd;color:#0f5132;padding:8px 14px;border-radius:4px;margin-bottom:16px;'><b>Fonte:</b> <code>v_dados_cientificos</code> (view filtrada — migracoes aplicadas).</div>";
} else {
    // Fallback: usar animais_completos com filtro inline
    $fonte = 'animais_completos (fallback)';
    $whereExtra = " AND COALESCE(simulacao,0)=0
                    AND codigo_animal NOT LIKE 'CLIMA%' ";
    $banner = "<div style='background:#fff3cd;color:#664d03;padding:8px 14px;border-radius:4px;margin-bottom:16px;'>"
            . "<b>Modo fallback:</b> view <code>v_dados_cientificos</code> nao existe. "
            . "Para resultados oficiais com filtro completo, rode <code>aplicar_migracoes.bat</code>. "
            . "Mostrando dados de <code>animais_completos</code> com filtro basico."
            . "</div>";
}

// =============== Queries ===============
function colunaExiste($pdo, $tabela, $coluna) {
    try {
        $pdo->query("SELECT $coluna FROM $tabela LIMIT 1");
        return true;
    } catch (Throwable $e) { return false; }
}

if ($fonte === 'v_dados_cientificos') {
    $sqlBase = "FROM v_dados_cientificos WHERE 1=1";
} else {
    // fallback: junta animais_completos com locais
    $sqlBase = "FROM animais_completos ac
                LEFT JOIN locais l ON l.id = ac.local_id
                WHERE l.ativo = 1 $whereExtra";
}

// Glossario:
//   $totalAmostras = numero de (codigo_animal, data_coleta) distintos = amostras reais
//   $totalColetas  = COUNT(DISTINCT data_coleta)    (datas distintas; visitas ao campo)
//   $nAnimais      = COUNT(DISTINCT codigo_animal)  (animais unicos)
//
// IMPORTANTE: usar COUNT(DISTINCT animal, data) - v_dados_cientificos pode duplicar
// linhas via JOIN com dados_climaticos.
if ($fonte === 'v_dados_cientificos') {
    $colAnimal = 'codigo_animal';
    $colData   = 'data_coleta';
    $colFaz    = 'fazenda';
} else {
    $colAnimal = 'ac.codigo_animal';
    $colData   = 'ac.data_coleta';
    $colFaz    = 'l.nome';
}
$totalAmostras = (int)$pdo->query(
    "SELECT COUNT(DISTINCT $colAnimal, $colData) $sqlBase"
)->fetchColumn();
$total = $totalAmostras; // alias legado para compatibilidade
$totalColetas = (int)$pdo->query("SELECT COUNT(DISTINCT $colData) $sqlBase")->fetchColumn();
$nAnimais     = (int)$pdo->query("SELECT COUNT(DISTINCT $colAnimal) $sqlBase")->fetchColumn();
$nFazendas    = (int)$pdo->query("SELECT COUNT(DISTINCT $colFaz) $sqlBase")->fetchColumn();

if ($fonte === 'v_dados_cientificos') {
    $rangeData = $pdo->query("SELECT MIN(data_coleta) AS mn, MAX(data_coleta) AS mx FROM v_dados_cientificos")->fetch();
} else {
    $rangeData = $pdo->query("SELECT MIN(ac.data_coleta) AS mn, MAX(ac.data_coleta) AS mx FROM animais_completos ac LEFT JOIN locais l ON l.id=ac.local_id WHERE l.ativo=1 $whereExtra")->fetch();
}

// =============== Tabela 1 ===============
// SEMANTICA:
//   n_amostras = COUNT linhas distintas (codigo_animal, data_coleta)   - 1 amostra por animal x data
//   n_coletas  = COUNT(DISTINCT data_coleta)                            - visitas ao campo
//   n_animais  = COUNT(DISTINCT codigo_animal)                          - animais unicos
//
// DEFENSIVA: usa subquery dedup porque v_dados_cientificos faz LEFT JOIN
// com dados_climaticos que pode multiplicar linhas (varias leituras por data).
if ($fonte === 'v_dados_cientificos') {
    $tab1 = $pdo->query("
        SELECT fazenda, municipio,
            COUNT(*)                                                   AS n_amostras,
            COUNT(DISTINCT data_coleta)                                AS n_coletas,
            COUNT(DISTINCT codigo_animal)                              AS n_animais,
            ROUND(100.0 * SUM(sexo='Femea' OR sexo='Fêmea')/COUNT(*),1) AS pct_femea,
            ROUND(100.0 * SUM(em_lactacao=1)/NULLIF(COUNT(*),0),1)     AS pct_lactacao,
            ROUND(AVG(idade_meses_na_coleta),1)                        AS idade_media_meses,
            SUM(temperatura_media IS NOT NULL)                         AS n_com_clima
        FROM (
            SELECT codigo_animal, data_coleta,
                   MAX(fazenda) AS fazenda, MAX(municipio) AS municipio,
                   MAX(sexo) AS sexo, MAX(em_lactacao) AS em_lactacao,
                   MAX(idade_meses_na_coleta) AS idade_meses_na_coleta,
                   AVG(temperatura_media) AS temperatura_media
            FROM v_dados_cientificos
            GROUP BY codigo_animal, data_coleta
        ) v_dedup
        GROUP BY fazenda, municipio
        ORDER BY fazenda
    ")->fetchAll();
} else {
    $tab1 = $pdo->query("
        SELECT l.nome AS fazenda, l.municipio,
            COUNT(*) AS n_amostras,
            COUNT(DISTINCT ac.data_coleta) AS n_coletas,
            COUNT(DISTINCT ac.codigo_animal) AS n_animais,
            ROUND(100.0 * SUM(ac.sexo='Femea' OR ac.sexo='Fêmea')/COUNT(*),1) AS pct_femea,
            ROUND(100.0 * SUM(ac.em_lactacao=1)/NULLIF(COUNT(*),0),1) AS pct_lactacao,
            NULL AS idade_media_meses,
            0 AS n_com_clima
        FROM animais_completos ac
        LEFT JOIN locais l ON l.id = ac.local_id
        WHERE l.ativo = 1 $whereExtra
        GROUP BY l.nome, l.municipio
        ORDER BY l.nome
    ")->fetchAll();
}

// =============== Tabela 2 (dedup por (animal, data) ===============
if ($fonte === 'v_dados_cientificos') {
    $prev = $pdo->query("
        SELECT fazenda, COUNT(*) AS n,
            SUM(pcr_babesia=1)        AS pos_pcr_babesia,
            SUM(pcr_anaplasma=1)      AS pos_pcr_anaplasma,
            SUM(pcr_trypanosoma=1)    AS pos_pcr_trypanosoma,
            SUM(lamp_babesia_18s=1)   AS pos_lamp_18s,
            SUM(lamp_babesia_cytb=1)  AS pos_lamp_cytb
        FROM (
            SELECT codigo_animal, data_coleta,
                   MAX(fazenda) AS fazenda,
                   MAX(pcr_babesia) AS pcr_babesia,
                   MAX(pcr_anaplasma) AS pcr_anaplasma,
                   MAX(pcr_trypanosoma) AS pcr_trypanosoma,
                   MAX(lamp_babesia_18s) AS lamp_babesia_18s,
                   MAX(lamp_babesia_cytb) AS lamp_babesia_cytb
            FROM v_dados_cientificos
            GROUP BY codigo_animal, data_coleta
        ) v_dedup
        GROUP BY fazenda ORDER BY fazenda
    ")->fetchAll();
} else {
    $prev = $pdo->query("
        SELECT l.nome AS fazenda, COUNT(*) AS n,
            SUM(ac.pcr_babesia=1)        AS pos_pcr_babesia,
            SUM(ac.pcr_anaplasma=1)      AS pos_pcr_anaplasma,
            SUM(ac.pcr_trypanosoma=1)    AS pos_pcr_trypanosoma,
            SUM(ac.lamp_babesia_18s=1)   AS pos_lamp_18s,
            SUM(ac.lamp_babesia_cytb=1)  AS pos_lamp_cytb
        FROM animais_completos ac
        LEFT JOIN locais l ON l.id = ac.local_id
        WHERE l.ativo = 1 $whereExtra
        GROUP BY l.nome ORDER BY l.nome
    ")->fetchAll();
}

// =============== Tabela 3 (estacao) - dedup por (animal, data) ===============
if ($fonte === 'v_dados_cientificos') {
    $prevEst = $pdo->query("
        SELECT estacao, COUNT(*) AS n,
            SUM(pcr_babesia=1) AS pos_pcr_babesia,
            SUM(pcr_anaplasma=1) AS pos_pcr_anaplasma,
            SUM(pcr_trypanosoma=1) AS pos_pcr_trypanosoma,
            SUM(lamp_babesia_18s=1) AS pos_lamp_18s,
            SUM(lamp_babesia_cytb=1) AS pos_lamp_cytb
        FROM (
            SELECT codigo_animal, data_coleta,
                   MAX(estacao) AS estacao,
                   MAX(pcr_babesia) AS pcr_babesia,
                   MAX(pcr_anaplasma) AS pcr_anaplasma,
                   MAX(pcr_trypanosoma) AS pcr_trypanosoma,
                   MAX(lamp_babesia_18s) AS lamp_babesia_18s,
                   MAX(lamp_babesia_cytb) AS lamp_babesia_cytb
            FROM v_dados_cientificos
            GROUP BY codigo_animal, data_coleta
        ) v_dedup
        GROUP BY estacao
        ORDER BY FIELD(estacao,'Verão','Outono','Inverno','Primavera')
    ")->fetchAll();
} else {
    $prevEst = $pdo->query("
        SELECT
            CASE
                WHEN MONTH(ac.data_coleta) IN (12,1,2)  THEN 'Verão'
                WHEN MONTH(ac.data_coleta) IN (3,4,5)   THEN 'Outono'
                WHEN MONTH(ac.data_coleta) IN (6,7,8)   THEN 'Inverno'
                WHEN MONTH(ac.data_coleta) IN (9,10,11) THEN 'Primavera'
            END AS estacao,
            COUNT(*) AS n,
            SUM(ac.pcr_babesia=1) AS pos_pcr_babesia,
            SUM(ac.pcr_anaplasma=1) AS pos_pcr_anaplasma,
            SUM(ac.pcr_trypanosoma=1) AS pos_pcr_trypanosoma,
            SUM(ac.lamp_babesia_18s=1) AS pos_lamp_18s,
            SUM(ac.lamp_babesia_cytb=1) AS pos_lamp_cytb
        FROM animais_completos ac
        LEFT JOIN locais l ON l.id = ac.local_id
        WHERE l.ativo = 1 $whereExtra
        GROUP BY estacao
        ORDER BY FIELD(estacao,'Verão','Outono','Inverno','Primavera')
    ")->fetchAll();
}

// =============== Datasets para os graficos ===============
$parasitas = [
    'pos_pcr_babesia'     => 'PCR B. bovis',
    'pos_pcr_anaplasma'   => 'PCR A. marginale',
    'pos_pcr_trypanosoma' => 'PCR T. vivax',
    'pos_lamp_18s'        => 'LAMP 18S',
    'pos_lamp_cytb'       => 'LAMP CytB',
];
// Paleta Okabe-Ito (CVD-safe, Wong B. Nat Methods 2011) — distinguivel em
// protanopia/deuteranopia/tritanopia. Item 1 do roadmap (AUDITORIA sec.5.2).
$OKABE_ITO = ['#E69F00','#56B4E9','#009E73','#F0E442','#0072B2','#D55E00','#CC79A7'];
$cores = $OKABE_ITO; // alias: consumidores existentes (g1, g2) indexam $cores[$ci%5]

// Borda mais escura para cores claras (amarelo Okabe-Ito #F0E442) — garante
// contorno visivel da barra/linha sobre fundo branco. Demais cores: usa a propria.
function corBorda($hex) {
    return strtoupper($hex) === '#F0E442' ? '#B59E00' : $hex;
}
$g1_labels = array_column($prev, 'fazenda');
$g1_datasets = []; $ci=0; $g1_allHi = [];
foreach ($parasitas as $col => $nome) {
    $values=[]; $errs=[];
    foreach ($prev as $r) {
        $n=(int)$r['n']; $k=(int)$r[$col]; $w=wilson95($k,$n);
        $values[] = round($w['p']*100,2);
        $hi = round($w['upper']*100,2);
        $errs[]   = ['lo'=>round($w['lower']*100,2),'hi'=>$hi];
        $g1_allHi[] = $hi;
    }
    $g1_datasets[] = ['label'=>$nome,'data'=>$values,'errs'=>$errs,
        'backgroundColor'=>$cores[$ci%5].'cc','borderColor'=>corBorda($cores[$ci%5]),'borderWidth'=>1];
    $ci++;
}
// Eixo Y dinamico: teto agradavel acima do maior IC superior (evita barras minusculas)
$g1_ymax = niceMax($g1_allHi, 100.0);
$g2_labels = array_column($prevEst,'estacao');
$g2_datasets = []; $ci=0; $g2_allVals = [];
foreach ($parasitas as $col => $nome) {
    $values=[];
    foreach ($prevEst as $r) {
        $n=(int)$r['n']; $k=(int)$r[$col];
        $v = $n>0 ? round(100.0*$k/$n,2) : 0;
        $values[] = $v; $g2_allVals[] = $v;
    }
    $g2_datasets[] = ['label'=>$nome,'data'=>$values,'borderColor'=>corBorda($cores[$ci%5]),
        'backgroundColor'=>corBorda($cores[$ci%5]).'33','tension'=>0.25,'fill'=>false,
        'pointRadius'=>4,'pointHoverRadius'=>6,'borderWidth'=>2.2];
    $ci++;
}
$g2_ymax = niceMax($g2_allVals, 100.0);
$g3_labels = array_column($tab1,'fazenda');
$g3_data = array_map('intval', array_column($tab1,'n_animais'));

// =========== Dataset de correlacao clima x prevalencia ===========
// Cada ponto = uma coleta com clima vinculado.
// X = variavel climatica, Y = infectado em qualquer parasita (0 ou 1).
// Para visual util, agregamos em bins de clima e mostramos prevalencia agregada.
function buildCorrelacao($pdo, $varClima) {
    try {
        $sql = "
            SELECT $varClima AS x,
                   GREATEST(pcr_babesia, pcr_anaplasma, pcr_trypanosoma,
                            lamp_babesia_18s, lamp_babesia_cytb) AS infectado,
                   fazenda
            FROM v_dados_cientificos
            WHERE $varClima IS NOT NULL
        ";
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
function spearmanRho($pontos) {
    $n = count($pontos);
    if ($n < 4) return ['rho'=>null,'n'=>$n];
    $x = array_column($pontos,'x');
    $y = array_column($pontos,'y');
    // Ranks com tratamento de empate (rank medio)
    $rank = function($arr) {
        $sorted = $arr; asort($sorted);
        $r = []; $i = 1; $prevVal = null; $startIdx = 0; $values = array_values($sorted);
        $keys = array_keys($sorted);
        foreach ($values as $idx => $v) {
            if ($v === $prevVal) continue;
            // atribuir rank medio para empate anterior
            for ($j=$startIdx;$j<$idx;$j++) {
                $r[$keys[$j]] = ($startIdx + $idx - 1)/2 + 1;
            }
            $startIdx = $idx; $prevVal = $v;
        }
        for ($j=$startIdx;$j<count($values);$j++) {
            $r[$keys[$j]] = ($startIdx + count($values) - 1)/2 + 1;
        }
        ksort($r);
        return array_values($r);
    };
    $rx = $rank($x); $ry = $rank($y);
    $sumD2 = 0;
    for ($i=0;$i<$n;$i++) { $sumD2 += pow($rx[$i]-$ry[$i],2); }
    $rho = 1 - (6*$sumD2)/($n*($n*$n-1));
    return ['rho'=>round($rho,3),'n'=>$n];
}

$dadosTemp = buildCorrelacao($pdo, 'temperatura_media');
$dadosPluv = buildCorrelacao($pdo, 'pluviometria_total');
$dadosUmid = buildCorrelacao($pdo, 'umidade_media');

// Pontos para scatter: x=clima, y=prevalencia agregada (proporção infectados em bins)
function aggBins($dados, $nBins=8) {
    if (empty($dados)) return ['labels'=>[],'data'=>[],'errs'=>[]];
    $xs = array_column($dados,'x');
    $min = min($xs); $max = max($xs);
    if ($min == $max) { $max = $min + 1; }
    $w = ($max - $min) / $nBins;
    $bins = array_fill(0, $nBins, ['n'=>0,'k'=>0,'soma'=>0]);
    foreach ($dados as $r) {
        $idx = (int) min($nBins - 1, max(0, floor(($r['x'] - $min) / $w)));
        $bins[$idx]['n']++;
        $bins[$idx]['k'] += (int)$r['infectado'];
        $bins[$idx]['soma'] += (float)$r['x'];
    }
    $labels = []; $data = []; $errs = [];
    foreach ($bins as $b) {
        if ($b['n'] == 0) { continue; }
        $cx = round($b['soma']/$b['n'], 1);
        $w95 = wilson95($b['k'], $b['n']);
        $labels[] = (string)$cx;
        $data[] = ['x'=>$cx, 'y'=>round($w95['p']*100,2), 'n'=>$b['n']];
        $errs[] = ['lo'=>round($w95['lower']*100,2),'hi'=>round($w95['upper']*100,2)];
    }
    return ['labels'=>$labels,'data'=>$data,'errs'=>$errs];
}

// Para cada variavel, transformar dados em pontos (x, y=infectado) para spearman
$pontosT = array_map(fn($r)=>['x'=>(float)$r['x'],'y'=>(float)$r['infectado']], $dadosTemp);
$pontosP = array_map(fn($r)=>['x'=>(float)$r['x'],'y'=>(float)$r['infectado']], $dadosPluv);
$pontosU = array_map(fn($r)=>['x'=>(float)$r['x'],'y'=>(float)$r['infectado']], $dadosUmid);
$rT = spearmanRho($pontosT);
$rP = spearmanRho($pontosP);
$rU = spearmanRho($pontosU);

$binT = aggBins($dadosTemp);
$binP = aggBins($dadosPluv);
$binU = aggBins($dadosUmid);

// =========================================================
// INTEGRACAO CLIMA x EPIDEMIOLOGIA (estatistica inferencial)
// =========================================================
// Inferencia para o rho de Spearman ja calculado: p-valor (aprox. t) + IC95% (Fisher z)
$siT = spearmanInfer($rT['rho'], $rT['n']);
$siP = spearmanInfer($rP['rho'], $rP['n']);
$siU = spearmanInfer($rU['rho'], $rU['n']);

// Linha de tendencia (OLS ponderado pelo n de cada bin) sobre os pontos clima x prevalencia
function trendDosBins($bin) {
    if (empty($bin['data'])) return null;
    $pts = array_map(fn($d) => ['x' => $d['x'], 'y' => $d['y'], 'w' => $d['n']], $bin['data']);
    return olsWeighted($pts);
}
$trendT = trendDosBins($binT);
$trendP = trendDosBins($binP);
$trendU = trendDosBins($binU);

// Dataset por observacao (dedup animal x data) com as 3 variaveis climaticas + desfechos.
// So existe quando a view tem colunas climaticas populadas.
function buildClimaDataset($pdo) {
    try {
        $sql = "SELECT
                    AVG(temperatura_media)  AS temp,
                    AVG(pluviometria_total) AS pluv,
                    AVG(umidade_media)      AS umid,
                    MAX(GREATEST(COALESCE(pcr_babesia,0), COALESCE(lamp_babesia_18s,0), COALESCE(lamp_babesia_cytb,0))) AS bb,
                    MAX(COALESCE(pcr_anaplasma,0))   AS am,
                    MAX(COALESCE(pcr_trypanosoma,0)) AS tv
                FROM v_dados_cientificos
                WHERE temperatura_media IS NOT NULL
                  AND pluviometria_total IS NOT NULL
                  AND umidade_media IS NOT NULL
                GROUP BY codigo_animal, data_coleta";
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) { return []; }
}
$climaDS = buildClimaDataset($pdo);
$nClimaDS = count($climaDS);

// Regressao logistica multivariavel: infeccao (qualquer patogeno) ~ temp + pluv + umid
// (OR por +1 desvio-padrao de cada variavel climatica, com IC95% Wald)
$logit = null;
if ($nClimaDS >= 30) {
    $X = []; $yL = [];
    foreach ($climaDS as $r) {
        $X[]  = [(float)$r['temp'], (float)$r['pluv'], (float)$r['umid']];
        $yL[] = (((int)$r['bb']) || ((int)$r['am']) || ((int)$r['tv'])) ? 1 : 0;
    }
    $fit = logisticFit($X, $yL);
    if ($fit !== null) {
        $rotPred = ['Temperatura média (°C)', 'Pluviometria (mm/90d)', 'Umidade média (%)'];
        foreach ($fit['coef'] as $i => $cc) { $fit['coef'][$i]['rotulo'] = $rotPred[$i] ?? ('X' . $i); }
        $logit = $fit;
    }
}

// Associacao univariada por patogeno x variavel climatica (Spearman rho + p-valor)
$climVars = [
    'temp' => 'Temperatura (°C)',
    'pluv' => 'Pluviometria (mm)',
    'umid' => 'Umidade (%)',
];
$patAlvos = ['bb' => 'B. bovis', 'am' => 'A. marginale', 'tv' => 'T. vivax'];
$climPat = []; // [patogeno][var] = ['rho'=>, 'p'=>, 'n'=>]
foreach ($patAlvos as $pk => $pnome) {
    foreach ($climVars as $ck => $crot) {
        $pts = [];
        foreach ($climaDS as $r) {
            if ($r[$ck] === null) continue;
            $pts[] = ['x' => (float)$r[$ck], 'y' => (float)$r[$pk]];
        }
        $sr  = spearmanRho($pts);
        $inf = spearmanInfer($sr['rho'], $sr['n']);
        $climPat[$pk][$ck] = ['rho' => $sr['rho'], 'p' => $inf['p'], 'n' => $sr['n']];
    }
}

// =========== Sazonalidade: qui-quadrado estacao x infeccao (qualquer patogeno) ===========
function buildSeasonInfect($pdo, $fonte, $whereExtra) {
    try {
        if ($fonte === 'v_dados_cientificos') {
            $sql = "SELECT estacao, SUM(infect) AS pos, COUNT(*) AS n FROM (
                        SELECT MAX(estacao) AS estacao,
                            MAX(GREATEST(COALESCE(pcr_babesia,0),COALESCE(pcr_anaplasma,0),COALESCE(pcr_trypanosoma,0),
                                         COALESCE(lamp_babesia_18s,0),COALESCE(lamp_babesia_cytb,0))) AS infect
                        FROM v_dados_cientificos
                        GROUP BY codigo_animal, data_coleta
                    ) t WHERE estacao IS NOT NULL GROUP BY estacao";
        } else {
            $sql = "SELECT estacao, SUM(infect) AS pos, COUNT(*) AS n FROM (
                        SELECT codigo_animal, data_coleta,
                            MAX(CASE WHEN MONTH(ac.data_coleta) IN (12,1,2) THEN 'Verão'
                                     WHEN MONTH(ac.data_coleta) IN (3,4,5)  THEN 'Outono'
                                     WHEN MONTH(ac.data_coleta) IN (6,7,8)  THEN 'Inverno'
                                     ELSE 'Primavera' END) AS estacao,
                            MAX(GREATEST(COALESCE(ac.pcr_babesia,0),COALESCE(ac.pcr_anaplasma,0),COALESCE(ac.pcr_trypanosoma,0),
                                         COALESCE(ac.lamp_babesia_18s,0),COALESCE(ac.lamp_babesia_cytb,0))) AS infect
                        FROM animais_completos ac LEFT JOIN locais l ON l.id=ac.local_id
                        WHERE l.ativo=1 $whereExtra
                        GROUP BY codigo_animal, data_coleta
                    ) t WHERE estacao IS NOT NULL GROUP BY estacao";
        }
        return $pdo->query($sql)->fetchAll();
    } catch (Throwable $e) { return []; }
}
$seasonRows = buildSeasonInfect($pdo, $fonte, $whereExtra);
$tabEstacao = []; $estLabels = []; $estPrev = [];
foreach ($seasonRows as $r) {
    $n = (int)$r['n']; $pos = (int)$r['pos'];
    if ($n <= 0 || $r['estacao'] === null) continue;
    $tabEstacao[] = [$pos, $n - $pos];
    $estLabels[]  = $r['estacao'];
    $estPrev[$r['estacao']] = ['pos' => $pos, 'n' => $n, 'prev' => 100.0 * $pos / $n];
}
$chiEstacao = count($tabEstacao) >= 2 ? chiSquareTest($tabEstacao) : null;

// =============== TAREFA 2: Analise comparativa Babesia bovis ===============
// PCR convencional (pcr_babesia) vs PCR-LAMP (lamp_babesia_18s e lamp_babesia_cytb)
// DEDUP por (codigo_animal, data_coleta) para nao contar amostras duplicadas pela view
if ($fonte === 'v_dados_cientificos') {
    $sqlComp = "SELECT MAX(pcr_babesia) AS pcr_babesia,
                       MAX(lamp_babesia_18s) AS lamp_babesia_18s,
                       MAX(lamp_babesia_cytb) AS lamp_babesia_cytb
                FROM v_dados_cientificos
                GROUP BY codigo_animal, data_coleta";
} else {
    $sqlComp = "SELECT ac.pcr_babesia, ac.lamp_babesia_18s, ac.lamp_babesia_cytb
                FROM animais_completos ac
                LEFT JOIN locais l ON l.id = ac.local_id
                WHERE l.ativo = 1 $whereExtra";
}
$rowsComp = $pdo->query($sqlComp)->fetchAll();

// Categorias mutuamente exclusivas (Diagrama de Venn 3 metodos)
$cat = [
    'neg_todos'=>0, 'so_pcr'=>0, 'so_18s'=>0, 'so_cytb'=>0,
    'pcr_18s'=>0, 'pcr_cytb'=>0, '18s_cytb'=>0, 'todos'=>0,
];
$nPCR=0; $nLAMP18S=0; $nLAMPCytB=0; $nTotalComp=0;
// Tabelas 2x2 para cada par
$pp_18=0; $pn_18=0; $np_18=0; $nn_18=0; // PCR x LAMP18S
$pp_ct=0; $pn_ct=0; $np_ct=0; $nn_ct=0; // PCR x LAMPCytB
$pp_lc=0; $pn_lc=0; $np_lc=0; $nn_lc=0; // LAMP18S x LAMPCytB

foreach ($rowsComp as $r) {
    $pcr = (int)($r['pcr_babesia'] ?? 0);
    $l18 = (int)($r['lamp_babesia_18s'] ?? 0);
    $lct = (int)($r['lamp_babesia_cytb'] ?? 0);
    $nTotalComp++;
    if ($pcr) $nPCR++;
    if ($l18) $nLAMP18S++;
    if ($lct) $nLAMPCytB++;

    $key = "$pcr$l18$lct";
    switch ($key) {
        case '000': $cat['neg_todos']++; break;
        case '100': $cat['so_pcr']++; break;
        case '010': $cat['so_18s']++; break;
        case '001': $cat['so_cytb']++; break;
        case '110': $cat['pcr_18s']++; break;
        case '101': $cat['pcr_cytb']++; break;
        case '011': $cat['18s_cytb']++; break;
        case '111': $cat['todos']++; break;
    }
    // Pares
    if     ($pcr && $l18) $pp_18++;
    elseif ($pcr)         $pn_18++;
    elseif ($l18)         $np_18++;
    else                  $nn_18++;
    if     ($pcr && $lct) $pp_ct++;
    elseif ($pcr)         $pn_ct++;
    elseif ($lct)         $np_ct++;
    else                  $nn_ct++;
    if     ($l18 && $lct) $pp_lc++;
    elseif ($l18)         $pn_lc++;
    elseif ($lct)         $np_lc++;
    else                  $nn_lc++;
}

// Cohen's Kappa
function cohenKappa($a, $b, $c, $d) {
    $n = $a + $b + $c + $d;
    if ($n == 0) return null;
    $po = ($a + $d) / $n;
    $pe = ((($a+$b)/$n) * (($a+$c)/$n)) + ((($c+$d)/$n) * (($b+$d)/$n));
    if (abs(1 - $pe) < 1e-12) return null;
    return ($po - $pe) / (1 - $pe);
}
function interpretKappa($k) {
    if ($k === null) return '-';
    if ($k < 0)    return 'Pior que acaso';
    if ($k < 0.20) return 'Pobre';
    if ($k < 0.40) return 'Fraca';
    if ($k < 0.60) return 'Moderada';
    if ($k < 0.80) return 'Substancial';
    return 'Quase perfeita';
}
function safeDiv($a, $b) { return $b > 0 ? $a / $b : 0; }

// PCR convencional como referencia (gold-standard pratico)
$sens_18 = safeDiv($pp_18, $pp_18 + $pn_18);
$spec_18 = safeDiv($nn_18, $nn_18 + $np_18);
$ppv_18  = safeDiv($pp_18, $pp_18 + $np_18);
$npv_18  = safeDiv($nn_18, $nn_18 + $pn_18);
$acc_18  = safeDiv($pp_18 + $nn_18, $nTotalComp);
$kap_18  = cohenKappa($pp_18, $pn_18, $np_18, $nn_18);

$sens_ct = safeDiv($pp_ct, $pp_ct + $pn_ct);
$spec_ct = safeDiv($nn_ct, $nn_ct + $np_ct);
$ppv_ct  = safeDiv($pp_ct, $pp_ct + $np_ct);
$npv_ct  = safeDiv($nn_ct, $nn_ct + $pn_ct);
$acc_ct  = safeDiv($pp_ct + $nn_ct, $nTotalComp);
$kap_ct  = cohenKappa($pp_ct, $pn_ct, $np_ct, $nn_ct);

$kap_lc  = cohenKappa($pp_lc, $pn_lc, $np_lc, $nn_lc);
$acc_lc  = safeDiv($pp_lc + $nn_lc, $nTotalComp);

// Testes pareados (McNemar) + IC95% do kappa para a comparacao metodologica
// b = discordantes PCR+/teste- (pn) ; c = discordantes PCR-/teste+ (np)
$mcn_18 = mcnemarTest($pn_18, $np_18);
$mcn_ct = mcnemarTest($pn_ct, $np_ct);
$mcn_lc = mcnemarTest($pn_lc, $np_lc);
$kci_18 = kappaCI($pp_18, $pn_18, $np_18, $nn_18);
$kci_ct = kappaCI($pp_ct, $pn_ct, $np_ct, $nn_ct);
$kci_lc = kappaCI($pp_lc, $pn_lc, $np_lc, $nn_lc);

// Wilson IC95% para prevalencia geral por metodo
$wPCR     = wilson95($nPCR,     $nTotalComp);
$wLAMP18S = wilson95($nLAMP18S, $nTotalComp);
$wLAMPCyt = wilson95($nLAMPCytB,$nTotalComp);

// =========================================================
// ITEM 1 - COINFECCAO entre os 3 patogenos
// B. bovis (qualquer positivo dos 3 alvos), A. marginale, T. vivax
// DEDUP por (codigo_animal, data_coleta)
// =========================================================
if ($fonte === 'v_dados_cientificos') {
    $sqlCo = "SELECT codigo_animal, data_coleta,
                     MAX(fazenda) AS fazenda,
                     MAX(estacao) AS estacao,
                     MAX(pcr_babesia) AS pcr_babesia,
                     MAX(pcr_anaplasma) AS pcr_anaplasma,
                     MAX(pcr_trypanosoma) AS pcr_trypanosoma,
                     MAX(lamp_babesia_18s)  AS lamp_babesia_18s,
                     MAX(lamp_babesia_cytb) AS lamp_babesia_cytb
              FROM v_dados_cientificos
              GROUP BY codigo_animal, data_coleta
              ORDER BY codigo_animal, data_coleta";
} else {
    $sqlCo = "SELECT ac.pcr_babesia, ac.pcr_anaplasma, ac.pcr_trypanosoma,
                     ac.lamp_babesia_18s, ac.lamp_babesia_cytb, ac.codigo_animal, ac.data_coleta,
                     l.nome AS fazenda
              FROM animais_completos ac
              LEFT JOIN locais l ON l.id = ac.local_id
              WHERE l.ativo = 1 $whereExtra
              ORDER BY ac.codigo_animal, ac.data_coleta";
}
$rowsCo = $pdo->query($sqlCo)->fetchAll();

$coinf = [
    'neg'=>0, 'so_bb'=>0, 'so_am'=>0, 'so_tv'=>0,
    'bb_am'=>0, 'bb_tv'=>0, 'am_tv'=>0, 'todos'=>0,
];
$nBB=0; $nAM=0; $nTV=0; $nTotalCo=0;
$nMultiInfeccao=0; // 2 ou 3 patogenos
foreach ($rowsCo as $r) {
    // B. bovis = qualquer positivo entre os 3 alvos (PCR ou LAMP 18S ou LAMP CytB)
    $bb = ((int)($r['pcr_babesia'] ?? 0)) || ((int)($r['lamp_babesia_18s'] ?? 0)) || ((int)($r['lamp_babesia_cytb'] ?? 0));
    $am = (int)($r['pcr_anaplasma'] ?? 0);
    $tv = (int)($r['pcr_trypanosoma'] ?? 0);
    $bb = $bb ? 1 : 0;
    $am = $am ? 1 : 0;
    $tv = $tv ? 1 : 0;
    $nTotalCo++;
    if ($bb) $nBB++; if ($am) $nAM++; if ($tv) $nTV++;
    $somap = $bb + $am + $tv;
    if ($somap >= 2) $nMultiInfeccao++;
    $key = "$bb$am$tv";
    switch ($key) {
        case '000': $coinf['neg']++;     break;
        case '100': $coinf['so_bb']++;   break;
        case '010': $coinf['so_am']++;   break;
        case '001': $coinf['so_tv']++;   break;
        case '110': $coinf['bb_am']++;   break;
        case '101': $coinf['bb_tv']++;   break;
        case '011': $coinf['am_tv']++;   break;
        case '111': $coinf['todos']++;   break;
    }
}
$wMulti = wilson95($nMultiInfeccao, $nTotalCo);

// =========================================================
// ITEM 2 - ANALISE LONGITUDINAL por codigo_animal
// =========================================================
// Estacao a partir da data (hemisferio sul) — fallback quando a view nao traz 'estacao'
function estacaoDeData($data) {
    $ts = strtotime((string)$data);
    if ($ts === false) return null;
    $m = (int)date('n', $ts); $d = (int)date('j', $ts);
    $md = $m * 100 + $d;
    if ($md >= 1221 || $md <= 320)  return 'Verão';     // 21/12 - 20/03
    if ($md >= 321 && $md <= 620)   return 'Outono';    // 21/03 - 20/06
    if ($md >= 621 && $md <= 922)   return 'Inverno';   // 21/06 - 22/09
    return 'Primavera';                                 // 23/09 - 20/12
}
$animais = []; // codigo_animal => [['data'=>, 'estacao'=>, 'ano'=>, 'fazenda'=>, 'bb'=>0/1, 'am'=>, 'tv'=>], ...]
foreach ($rowsCo as $r) {
    $cod = $r['codigo_animal'] ?? null;
    if (!$cod) continue;
    $bb = (((int)($r['pcr_babesia'] ?? 0)) || ((int)($r['lamp_babesia_18s'] ?? 0)) || ((int)($r['lamp_babesia_cytb'] ?? 0))) ? 1 : 0;
    $am = (int)($r['pcr_anaplasma'] ?? 0);
    $tv = (int)($r['pcr_trypanosoma'] ?? 0);
    $est = $r['estacao'] ?? null;
    if (!$est) $est = estacaoDeData($r['data_coleta']); // path animais_completos nao tem 'estacao'
    $ts  = strtotime((string)($r['data_coleta'] ?? ''));
    $ano = $ts !== false ? (int)date('Y', $ts) : null;
    if (!isset($animais[$cod])) $animais[$cod] = [];
    $animais[$cod][] = [
        'data' => $r['data_coleta'],
        'estacao' => $est, 'ano' => $ano, 'fazenda' => $r['fazenda'] ?? null,
        'bb'   => $bb, 'am' => $am, 'tv' => $tv,
    ];
}
// Ordenar coletas de cada animal por data
foreach ($animais as $cod => $cs) {
    usort($animais[$cod], fn($a,$b) => strcmp($a['data'], $b['data']));
}

// Distribuicao do numero de coletas por animal
$distNColetas = [];
foreach ($animais as $cs) {
    $n = count($cs);
    $distNColetas[$n] = ($distNColetas[$n] ?? 0) + 1;
}
ksort($distNColetas);

// Estados longitudinais por parasita
function classificarSerie($coletas, $key) {
    if (count($coletas) < 2) return 'unica_coleta';
    $vals = array_column($coletas, $key);
    $primeiro = $vals[0];
    $ultimo   = end($vals);
    $todosPos = array_sum($vals) === count($vals);
    $todosNeg = array_sum($vals) === 0;
    if ($todosNeg) return 'sempre_neg';
    if ($todosPos) return 'sempre_pos';
    if ($primeiro === 0 && $ultimo === 1)  return 'soroconv';   // negativo -> positivo
    if ($primeiro === 1 && $ultimo === 0)  return 'aclarado';   // positivo -> negativo
    return 'oscilante';
}
$estadoBB = ['unica_coleta'=>0,'sempre_neg'=>0,'sempre_pos'=>0,'soroconv'=>0,'aclarado'=>0,'oscilante'=>0];
$estadoAM = $estadoBB; $estadoTV = $estadoBB;
foreach ($animais as $cs) {
    $estadoBB[classificarSerie($cs,'bb')]++;
    $estadoAM[classificarSerie($cs,'am')]++;
    $estadoTV[classificarSerie($cs,'tv')]++;
}

// Matriz de transicao N -> N+1 (so animais com >=2 coletas) por parasita
function matrizTransicao($animais, $key) {
    $m = ['00'=>0, '01'=>0, '10'=>0, '11'=>0]; // [estado_t][estado_t+1]
    foreach ($animais as $cs) {
        if (count($cs) < 2) continue;
        for ($i = 0; $i < count($cs) - 1; $i++) {
            $a = $cs[$i][$key]; $b = $cs[$i+1][$key];
            $m["$a$b"]++;
        }
    }
    return $m;
}
$transBB = matrizTransicao($animais, 'bb');
$transAM = matrizTransicao($animais, 'am');
$transTV = matrizTransicao($animais, 'tv');
$nTransicoes = array_sum($transBB);
$nAnimaisLongi = count($animais);
$nAnimaisCom2plus = 0;
foreach ($animais as $cs) if (count($cs) >= 2) $nAnimaisCom2plus++;

// =========================================================
// LASAGNA PLOT (Grafico 9C) - matriz animal x onda (ano-estacao)
// status por celula: negativo / B.bovis / A.marginale / T.vivax / coinfeccao / ausente
// (AUDITORIA_MELHORIAS_TPB.md sec.5.7 — preliminar; adensa a cada coleta)
// =========================================================
$ordemEstacao = ['Verão' => 1, 'Outono' => 2, 'Inverno' => 3, 'Primavera' => 4];
// Status de uma coleta: prioridade coinfeccao > patogeno unico > negativo
function statusColeta($c) {
    $soma = $c['bb'] + $c['am'] + $c['tv'];
    if ($soma >= 2) return 'coinf';
    if ($c['bb']) return 'bb';
    if ($c['am']) return 'am';
    if ($c['tv']) return 'tv';
    return 'neg';
}
// 1) Universo de ondas (ano-estacao) observadas, ordenadas cronologicamente
$ondasSet = [];
foreach ($animais as $cs) {
    foreach ($cs as $c) {
        if ($c['ano'] === null || $c['estacao'] === null) continue;
        $key = $c['ano'] . '|' . $c['estacao'];
        $ondasSet[$key] = true;
    }
}
$ondasKeys = array_keys($ondasSet);
usort($ondasKeys, function($a, $b) use ($ordemEstacao) {
    [$ya, $ea] = explode('|', $a); [$yb, $eb] = explode('|', $b);
    if ((int)$ya !== (int)$yb) return (int)$ya <=> (int)$yb;
    return ($ordemEstacao[$ea] ?? 9) <=> ($ordemEstacao[$eb] ?? 9);
});
// Rotulos curtos das colunas (ex.: "Out/25")
$abrevEstacao = ['Verão'=>'Ver','Outono'=>'Out','Inverno'=>'Inv','Primavera'=>'Pri'];
$lasanhaCols = [];
foreach ($ondasKeys as $k) {
    [$y, $e] = explode('|', $k);
    $lasanhaCols[] = ($abrevEstacao[$e] ?? $e) . '/' . substr($y, 2);
}
// 2) Matriz: 1 linha por animal (>=1 coleta com onda valida); celula = status ou 'ausente'
$lasanhaRows = []; // [ ['animal'=>, 'fazenda'=>, 'nPos'=>, 'cells'=>[status por coluna]] ]
foreach ($animais as $cod => $cs) {
    // status por onda deste animal
    $porOnda = [];
    $faz = null; $nPos = 0;
    foreach ($cs as $c) {
        if ($c['ano'] === null || $c['estacao'] === null) continue;
        $faz = $faz ?? $c['fazenda'];
        $st = statusColeta($c);
        if ($st !== 'neg') $nPos++;
        $porOnda[$c['ano'] . '|' . $c['estacao']] = $st;
    }
    if (empty($porOnda)) continue;
    $cells = [];
    foreach ($ondasKeys as $k) $cells[] = $porOnda[$k] ?? 'ausente';
    $lasanhaRows[] = ['animal' => $cod, 'fazenda' => $faz, 'nPos' => $nPos, 'cells' => $cells];
}
// Ordenar: fazenda, depois mais positivos no topo, depois codigo
usort($lasanhaRows, function($a, $b) {
    $f = strcmp((string)$a['fazenda'], (string)$b['fazenda']);
    if ($f !== 0) return $f;
    if ($a['nPos'] !== $b['nPos']) return $b['nPos'] <=> $a['nPos'];
    return strcmp((string)$a['animal'], (string)$b['animal']);
});
$nLasanhaAnimais = count($lasanhaRows);
$nLasanhaOndas   = count($lasanhaCols);

// =========================================================
// ITEM 4 - TEMPO DE DETECCAO LAMP (proxy de carga parasitaria)
// =========================================================
$temTabelaLamp = false;
try {
    $pdo->query("SELECT 1 FROM resultados_lamp LIMIT 1");
    $temTabelaLamp = true;
} catch (Throwable $e) {}

$tempos18s = []; $temposCytb = [];
$intens18s = ['Forte'=>0,'Médio'=>0,'Fraco'=>0];
$intensCytb = ['Forte'=>0,'Médio'=>0,'Fraco'=>0];
$nLAMPanalises = 0;

if ($temTabelaLamp) {
    try {
        $rowsLamp = $pdo->query("
            SELECT babesia_18s_lamp, tempo_deteccao_18s_min, intensidade_sinal_18s,
                   babesia_cytb_lamp, tempo_deteccao_cytb_min, intensidade_sinal_cytb
            FROM resultados_lamp
        ")->fetchAll();
        foreach ($rowsLamp as $r) {
            $nLAMPanalises++;
            if (($r['babesia_18s_lamp'] ?? '') === 'Positivo') {
                if ($r['tempo_deteccao_18s_min'] !== null) $tempos18s[] = (int)$r['tempo_deteccao_18s_min'];
                $isi = $r['intensidade_sinal_18s'] ?? null;
                if ($isi && isset($intens18s[$isi])) $intens18s[$isi]++;
            }
            if (($r['babesia_cytb_lamp'] ?? '') === 'Positivo') {
                if ($r['tempo_deteccao_cytb_min'] !== null) $temposCytb[] = (int)$r['tempo_deteccao_cytb_min'];
                $isi = $r['intensidade_sinal_cytb'] ?? null;
                if ($isi && isset($intensCytb[$isi])) $intensCytb[$isi]++;
            }
        }
    } catch (Throwable $e) {}
}

function descritivasArr($arr) {
    if (empty($arr)) return ['n'=>0,'media'=>null,'mediana'=>null,'q1'=>null,'q3'=>null,'min'=>null,'max'=>null,'sd'=>null];
    sort($arr);
    $n = count($arr);
    $media = array_sum($arr) / $n;
    $sd = 0; foreach ($arr as $v) $sd += pow($v - $media, 2);
    $sd = $n > 1 ? sqrt($sd / ($n - 1)) : 0;
    $q = function($p) use ($arr, $n) {
        $idx = $p * ($n - 1);
        $lo = floor($idx); $hi = ceil($idx);
        return $arr[$lo] + ($arr[$hi] - $arr[$lo]) * ($idx - $lo);
    };
    return [
        'n'=>$n, 'media'=>round($media,1), 'mediana'=>round($q(0.5),1),
        'q1'=>round($q(0.25),1), 'q3'=>round($q(0.75),1),
        'min'=>$arr[0], 'max'=>$arr[$n-1], 'sd'=>round($sd,1),
    ];
}
$d18s  = descritivasArr($tempos18s);
$dCytb = descritivasArr($temposCytb);

// =========================================================
// ITEM 9 - CURVA ROC dos LAMPs (PCR como referencia)
// 4 criterios: so 18S, so CytB, OR (18S|CytB), AND (18S&CytB)
// =========================================================
function calcSensSpec($rowsComp, $criterio) {
    $vp=0;$fn=0;$fp=0;$vn=0;
    foreach ($rowsComp as $r) {
        $pcr = (int)($r['pcr_babesia'] ?? 0);
        $l18 = (int)($r['lamp_babesia_18s'] ?? 0);
        $lct = (int)($r['lamp_babesia_cytb'] ?? 0);
        switch ($criterio) {
            case '18s':  $teste = $l18; break;
            case 'cytb': $teste = $lct; break;
            case 'or':   $teste = ($l18 || $lct) ? 1 : 0; break;
            case 'and':  $teste = ($l18 && $lct) ? 1 : 0; break;
            default:     $teste = 0;
        }
        if ($pcr && $teste) $vp++;
        elseif ($pcr)       $fn++;
        elseif ($teste)     $fp++;
        else                $vn++;
    }
    $sens = ($vp+$fn)>0 ? $vp/($vp+$fn) : 0;
    $spec = ($vn+$fp)>0 ? $vn/($vn+$fp) : 0;
    $youden = $sens + $spec - 1;
    return ['sens'=>$sens,'spec'=>$spec,'youden'=>$youden,'vp'=>$vp,'fn'=>$fn,'fp'=>$fp,'vn'=>$vn];
}
$roc = [
    'so_18s'  => calcSensSpec($rowsComp, '18s'),
    'so_cytb' => calcSensSpec($rowsComp, 'cytb'),
    'or'      => calcSensSpec($rowsComp, 'or'),
    'and'     => calcSensSpec($rowsComp, 'and'),
];
// AUC pelo metodo trapezoidal sobre 4 pontos ordenados por (1-spec)
$pontosROC = [];
foreach ($roc as $k=>$v) $pontosROC[] = ['x'=>1-$v['spec'], 'y'=>$v['sens'], 'rotulo'=>$k];
usort($pontosROC, fn($a,$b)=>$a['x']<=>$b['x']);
$auc = 0;
$pPlotROC = array_merge([['x'=>0,'y'=>0,'rotulo'=>'origem']], $pontosROC, [['x'=>1,'y'=>1,'rotulo'=>'topo']]);
for ($i=1;$i<count($pPlotROC);$i++) {
    $auc += ($pPlotROC[$i]['x'] - $pPlotROC[$i-1]['x']) * ($pPlotROC[$i]['y'] + $pPlotROC[$i-1]['y']) / 2;
}
// IC95% de Hanley-McNeil para a AUC (n+ = PCR positivos, n- = PCR negativos)
$n1_roc = $roc['so_18s']['vp'] + $roc['so_18s']['fn'];
$n2_roc = $roc['so_18s']['fp'] + $roc['so_18s']['vn'];
$aucCI = ['lo' => null, 'hi' => null];
if ($n1_roc >= 1 && $n2_roc >= 1) {
    $a = max(1e-9, min(1 - 1e-9, $auc));
    $q1 = $a / (2 - $a); $q2 = 2 * $a * $a / (1 + $a);
    $var = ($a * (1 - $a) + ($n1_roc - 1) * ($q1 - $a * $a) + ($n2_roc - 1) * ($q2 - $a * $a)) / ($n1_roc * $n2_roc);
    if ($var < 0) $var = 0;
    $se = sqrt($var); $zc = 1.959963984540054;
    $aucCI = ['lo' => max(0, $a - $zc * $se), 'hi' => min(1, $a + $zc * $se)];
}

// =========================================================
// ITEM 10 - HEATMAP KAPPA 5x5 (todos pares dos 5 testes)
// =========================================================
$testes5 = [
    'pcr_bb'   => ['col'=>'pcr_babesia',     'rotulo'=>'PCR B. bovis'],
    'pcr_am'   => ['col'=>'pcr_anaplasma',   'rotulo'=>'PCR A. marginale'],
    'pcr_tv'   => ['col'=>'pcr_trypanosoma', 'rotulo'=>'PCR T. vivax'],
    'lamp_18s' => ['col'=>'lamp_babesia_18s','rotulo'=>'LAMP 18S'],
    'lamp_cyt' => ['col'=>'lamp_babesia_cytb','rotulo'=>'LAMP CytB'],
];
$keysT = array_keys($testes5);
$matrizK   = []; // [linha][coluna] = kappa (ponto)
$matrizKci = []; // [linha][coluna] = ['lo'=>, 'hi'=>] (IC95% assintotico; null na diagonal/sem variancia)
foreach ($keysT as $k1) {
    foreach ($keysT as $k2) {
        if ($k1 === $k2) { $matrizK[$k1][$k2] = 1.0; $matrizKci[$k1][$k2] = null; continue; }
        $a=0;$b=0;$c=0;$d=0;
        foreach ($rowsCo as $r) {
            $v1 = (int)($r[$testes5[$k1]['col']] ?? 0);
            $v2 = (int)($r[$testes5[$k2]['col']] ?? 0);
            if     ($v1 && $v2) $a++;
            elseif ($v1)        $b++;
            elseif ($v2)        $c++;
            else                $d++;
        }
        $matrizK[$k1][$k2] = cohenKappa($a,$b,$c,$d);
        // IC95% do kappa pela formula assintotica (kappaCI ja existe e e validada)
        $ci = kappaCI($a,$b,$c,$d);
        $matrizKci[$k1][$k2] = ($ci['kappa'] !== null && $ci['lo'] !== null)
            ? ['lo' => round($ci['lo'], 3), 'hi' => round($ci['hi'], 3)]
            : null;
    }
}

?>
<!doctype html>
<html lang="pt-br"><head>
<meta charset="utf-8">
<title>Relatorio Cientifico - Diagnostico TPB</title>
<script src="static/js/chart.umd.min.js" onerror="var s=document.createElement('script');s.src='https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js';document.head.appendChild(s);"></script>
<style>
  :root{--azul:#1F4E78;--azul-claro:#2E75B6;--cinza:#666;--bg:#f8f9fa}
  body{font-family:'Segoe UI',Arial,sans-serif;margin:0;color:#222;background:var(--bg)}
  header{background:var(--azul);color:white;padding:1.5em 2em}
  header h1{margin:0;font-size:1.5em} header p{margin:.4em 0 0;opacity:.9}
  main{max-width:1200px;margin:2em auto;padding:0 1.5em}
  h2{color:var(--azul);border-bottom:2px solid var(--azul-claro);padding-bottom:.3em;margin-top:2em}
  table{width:100%;border-collapse:collapse;background:white;box-shadow:0 1px 3px rgba(0,0,0,.06);margin:.5em 0 1.5em}
  th{background:var(--azul);color:white;padding:.7em .8em;text-align:left;font-size:.9em}
  td{padding:.55em .8em;border-bottom:1px solid #e6e6e6;font-size:.92em}
  tr:hover td{background:#f4f9ff}
  .num{text-align:right;font-variant-numeric:tabular-nums}
  .pct{font-weight:600} .ci{color:var(--cinza);font-size:.85em}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1em;margin:1em 0}
  .stat-card{background:white;padding:1em;border-radius:6px;border-left:4px solid var(--azul-claro)}
  .stat-card .lbl{color:var(--cinza);font-size:.82em;text-transform:uppercase;letter-spacing:.05em}
  .stat-card .val{font-size:1.6em;font-weight:700;color:var(--azul)}
  .badge{display:inline-block;background:#e7f1fa;color:var(--azul);padding:.15em .6em;border-radius:10px;font-size:.78em}
  .chart-wrap{background:white;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:1em;margin:1em 0 1.5em}
  .chart-wrap h3{margin:0 0 .5em;color:var(--azul);font-size:1em}
  .chart-grid{display:grid;grid-template-columns:2fr 1fr;gap:1em}
  @media(max-width:900px){.chart-grid{grid-template-columns:1fr}}
  footer{text-align:center;padding:2em;color:var(--cinza);font-size:.85em}
  /* Botoes de download (TASK 1) */
  .dl-bar{display:flex;gap:8px;justify-content:flex-end;margin:.4em 0 .6em;flex-wrap:wrap}
  .btn-dl{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--azul-claro);
    color:var(--azul);padding:6px 12px;border-radius:6px;cursor:pointer;font-size:.82em;font-weight:600;
    transition:all .15s ease}
  .btn-dl:hover{background:var(--azul);color:#fff;border-color:var(--azul)}
  .btn-dl:active{transform:translateY(1px)}
  .btn-dl svg{width:14px;height:14px;fill:currentColor}
  .table-section{margin-bottom:1.5em}
  .chart-actions{display:flex;justify-content:flex-end;margin-top:.5em}
  /* Cards das metricas comparativas */
  .metricas-comp{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:1em;margin:1em 0}
  .met-card{background:#fff;padding:1em;border-radius:8px;border-left:4px solid #C0504D;
    box-shadow:0 1px 3px rgba(0,0,0,.06)}
  .met-card.lamp18s{border-left-color:#9BBB59}
  .met-card.lampcytb{border-left-color:#F79646}
  .met-card .titulo{font-weight:700;color:var(--azul);margin-bottom:.4em;font-size:.95em}
  .met-card .linha{display:flex;justify-content:space-between;font-size:.85em;color:#444;
    padding:.18em 0;border-bottom:1px dashed #eee}
  .met-card .linha:last-child{border-bottom:0}
  .met-card .linha b{color:var(--azul);font-variant-numeric:tabular-nums}
  .interpret{display:inline-block;padding:.1em .5em;border-radius:8px;font-size:.78em;font-weight:600;
    background:#e7f1fa;color:var(--azul);margin-left:6px}

  /* ============= EMISSAO DE LAUDOS ============= */
  .laudo-section{background:linear-gradient(135deg,#fff 0%,#f0f4f8 100%);
    border:2px solid var(--azul-claro);border-radius:14px;padding:1.4em 1.6em;
    margin:0 0 2em 0;box-shadow:0 4px 14px rgba(31,78,120,.12)}
  .laudo-section h2{border:none;margin:0 0 .3em;display:flex;align-items:center;gap:.4em}
  .laudo-section .sub{color:#555;font-size:.9em;margin:0 0 1em}
  .laudo-form{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));
    gap:.8em;align-items:end;margin-bottom:1em}
  .laudo-form .field{display:flex;flex-direction:column;gap:.25em}
  .laudo-form label{font-size:.85em;color:#444;font-weight:600}
  .laudo-form input, .laudo-form select{padding:.5em .6em;border:1.5px solid #cbd5e0;
    border-radius:6px;font-size:.92em;background:white;font-family:inherit}
  .laudo-form input:focus, .laudo-form select:focus{outline:none;border-color:var(--azul);
    box-shadow:0 0 0 3px rgba(46,117,182,.15)}
  .btn-laudo{background:var(--azul);color:#fff;border:0;padding:.6em 1.3em;border-radius:7px;
    font-weight:600;font-size:.92em;cursor:pointer;transition:all .15s}
  .btn-laudo:hover{background:#143A5C;transform:translateY(-1px);box-shadow:0 4px 12px rgba(31,78,120,.3)}
  .btn-laudo:disabled{background:#999;cursor:not-allowed;transform:none}
  .btn-laudo-secondary{background:#fff;color:var(--azul);border:1.5px solid var(--azul-claro)}
  .btn-laudo-secondary:hover{background:var(--azul);color:#fff}
  .laudo-animais-box{background:white;border:1px solid #e2e8f0;border-radius:8px;
    padding:.8em;margin:.8em 0;max-height:280px;overflow-y:auto}
  .laudo-animais-box .controles{display:flex;justify-content:space-between;align-items:center;
    margin-bottom:.5em;padding-bottom:.4em;border-bottom:1px solid #e2e8f0}
  .laudo-animais-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.3em}
  .laudo-animais-grid label{display:flex;align-items:center;gap:.4em;font-size:.86em;
    padding:.25em .4em;border-radius:4px;cursor:pointer;border:1px solid transparent}
  .laudo-animais-grid label:hover{background:#f0f4f8;border-color:#cbd5e0}
  .laudo-animais-grid label.tem-pos{background:#fff5f5;border-color:#fed7d7;color:#a00}
  .laudo-animais-grid input{margin:0}

  /* Documento do laudo */
  .laudo-doc{background:white;padding:2.5em 3em;border:1px solid #e2e8f0;border-radius:8px;
    margin-top:1em;font-family:'Georgia',serif;color:#222;line-height:1.5;
    box-shadow:0 2px 8px rgba(0,0,0,.05)}
  .laudo-doc .laudo-cabecalho{text-align:center;border-bottom:3px double #1F4E78;
    padding-bottom:1em;margin-bottom:1.4em}
  .laudo-doc .laudo-cabecalho h1{margin:0 0 .3em;color:#1F4E78;font-size:1.5em;letter-spacing:.05em}
  .laudo-doc .laudo-instituicao{font-size:.95em;color:#444;line-height:1.4}
  .laudo-doc .laudo-instituicao small{color:#777}
  .laudo-doc .laudo-meta{display:grid;grid-template-columns:repeat(2,1fr);gap:.4em 1em;
    background:#f8f9fa;padding:.8em 1.2em;border-radius:6px;margin-bottom:1.4em;font-size:.92em}
  .laudo-doc .laudo-introducao{text-align:justify;margin-bottom:1.4em;font-size:.96em}
  .laudo-doc .laudo-introducao p{margin:.6em 0;text-indent:2em}
  .laudo-doc .laudo-tabela{width:100%;border-collapse:collapse;margin-bottom:1.4em;font-size:.85em;
    font-family:'Segoe UI',Arial,sans-serif;box-shadow:none}
  .laudo-doc .laudo-tabela th{background:#1F4E78;color:#fff;padding:.5em;text-align:center;
    border:1px solid #1F4E78;font-size:.82em}
  .laudo-doc .laudo-tabela td{padding:.4em .6em;border:1px solid #cbd5e0;text-align:center;vertical-align:middle}
  .laudo-doc .laudo-tabela td:first-child{text-align:left}
  .lau-pos{display:inline-block;background:#c0504d;color:#fff;padding:.1em .5em;
    border-radius:3px;font-size:.78em;font-weight:700;letter-spacing:.05em}
  .lau-neg{color:#557;font-size:.82em}
  .lau-conc-pos{color:#a00;font-weight:600;font-size:.85em}
  .lau-conc-neg{color:#2f855a;font-size:.85em}
  .laudo-doc .laudo-conclusao{text-align:justify;margin-bottom:1.4em;font-size:.96em}
  .laudo-doc .laudo-conclusao h3{color:#1F4E78;margin:1em 0 .4em;font-family:'Segoe UI',Arial,sans-serif;
    font-size:1em;border-bottom:1px solid #cbd5e0;padding-bottom:.2em}
  .laudo-doc .laudo-conclusao p{margin:.5em 0;text-indent:1.5em}
  .laudo-doc .laudo-conclusao ul, .laudo-doc .laudo-conclusao ol{padding-left:2.5em}
  .laudo-doc .laudo-conclusao li{margin:.4em 0}
  .laudo-doc .laudo-rodape{margin-top:2em;padding-top:1em;border-top:1px solid #cbd5e0}
  .laudo-doc .laudo-assinatura{text-align:center;margin:2em auto 1em}
  .laudo-doc .laudo-linha-ass{width:300px;border-bottom:1px solid #333;margin:1em auto .4em}
  .laudo-doc .laudo-obs{margin-top:1em;padding:.6em;background:#f8f9fa;border-radius:4px;
    color:#555;font-family:'Segoe UI',Arial,sans-serif;line-height:1.4}

  /* Modal de medicamentos */
  .modal-med{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;
    display:flex;align-items:center;justify-content:center;padding:1.5em}
  .modal-med-content{background:white;border-radius:14px;width:100%;max-width:980px;
    max-height:90vh;overflow:hidden;display:flex;flex-direction:column;
    box-shadow:0 20px 60px rgba(0,0,0,.4)}
  .modal-med-header{display:flex;justify-content:space-between;align-items:center;
    padding:1em 1.4em;border-bottom:1px solid #cbd5e0;background:#f8f9fa}
  .modal-med-body{padding:1.4em 2em;overflow-y:auto;line-height:1.6;font-size:.94em}
  .modal-med-body h1{color:#1F4E78;font-size:1.5em;border-bottom:2px solid #2E75B6;padding-bottom:.3em}
  .modal-med-body h2{color:#1F4E78;font-size:1.2em;margin-top:1.4em;border-bottom:1px solid #cbd5e0;padding-bottom:.2em}
  .modal-med-body h3{color:#2E75B6;font-size:1.05em;margin-top:1em}
  .modal-med-body h4{color:#444;font-size:.98em}
  .modal-med-body table{width:100%;border-collapse:collapse;margin:.8em 0;font-size:.88em}
  .modal-med-body th{background:#1F4E78;color:white;padding:.5em;text-align:left}
  .modal-med-body td{padding:.4em .6em;border:1px solid #cbd5e0;vertical-align:top}
  .modal-med-body code{background:#f8f9fa;padding:.1em .4em;border-radius:3px;font-size:.88em;color:#a00}
  .modal-med-body blockquote{border-left:4px solid #ffc107;background:#fff8e1;padding:.6em 1em;
    margin:.8em 0;color:#664d03;border-radius:0 4px 4px 0}
  .modal-med-body ul, .modal-med-body ol{padding-left:1.8em}
  .modal-med-body li{margin:.25em 0}

  /* Estilos especificos para impressao */
  @media print {
    body * { visibility: hidden; }
    #laudo-doc-print, #laudo-doc-print * { visibility: visible; }
    #laudo-doc-print {
      position: absolute; left: 0; top: 0; width: 100%;
      padding: 1.5em; box-shadow: none; border: none;
    }
    .laudo-doc { font-size: 11pt; padding: 0 !important; }
    .lau-pos { background: #000 !important; color: #fff !important;
      -webkit-print-color-adjust: exact; print-color-adjust: exact; }
  }
  /* Gráfico 7 - layout 50/50 com altura controlada que cabe na pagina */
  .g7-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.2em;align-items:start;width:100%}
  .g7-cell{min-width:0;width:100%}
  .g7-cell canvas{display:block;width:100% !important;height:340px !important;max-width:100%}
  @media(max-width:780px){
    .g7-grid{grid-template-columns:1fr}
    .g7-cell canvas{height:300px !important}
  }
</style>
</head><body>

<header>
  <h1>Relatorio Cientifico - Diagnostico TPB</h1>
  <p>Vigilancia parasitaria bovina - Gerado em <?= date('d/m/Y H:i') ?></p>
</header>

<main>

<!-- ============================================================
     EMISSAO DE LAUDOS - secao no topo
     ============================================================ -->
<section class="laudo-section" id="secao-laudo">
  <h2>📋 Emissão de Laudos</h2>
  <p class="sub">Gere laudos clínicos personalizados por fazenda e período. O laudo inclui tabela de positividade por animal, conclusão analítica e recomendações terapêuticas com sugestões de fármacos (Diminazeno, Imidocarb, antibióticos para anaplasmose).</p>

  <div class="laudo-form">
    <div class="field">
      <label for="laudo_fazenda">Fazenda</label>
      <select id="laudo_fazenda">
        <option value="">Carregando…</option>
      </select>
    </div>
    <div class="field">
      <label for="laudo_inicio">Data inicial</label>
      <input type="date" id="laudo_inicio" value="<?= date('Y-m-d', strtotime('-365 days')) ?>">
    </div>
    <div class="field">
      <label for="laudo_fim">Data final</label>
      <input type="date" id="laudo_fim" value="<?= date('Y-m-d') ?>">
    </div>
    <div class="field">
      <label>&nbsp;</label>
      <button class="btn-laudo" onclick="buscarAnimaisLaudo()">🔍 Buscar animais</button>
    </div>
  </div>

  <div id="laudo_animais_container" style="display:none;">
    <div class="laudo-animais-box">
      <div class="controles">
        <div>
          <label style="display:inline-flex;align-items:center;gap:.4em;cursor:pointer;font-weight:600;">
            <input type="checkbox" id="laudo_select_all" checked onchange="toggleTodosLaudo(this.checked)">
            Selecionar todos
          </label>
          <span style="margin-left:1em;color:#666;font-size:.86em;">
            <span id="laudo_resumo_animais">0</span> selecionados
          </span>
        </div>
        <div style="font-size:.82em;color:#a00;">
          🔴 = animal com ≥1 positividade
        </div>
      </div>
      <div id="laudo_animais_grid" class="laudo-animais-grid"></div>
    </div>
    <div style="display:flex;gap:.6em;flex-wrap:wrap;">
      <button class="btn-laudo" onclick="gerarLaudo()">📄 Emitir Laudo</button>
      <button class="btn-laudo btn-laudo-secondary" onclick="document.getElementById('laudo_animais_container').style.display='none'">Cancelar</button>
    </div>
  </div>

  <div id="laudo_resultado_container" style="display:none;margin-top:1em;">
    <div style="display:flex;gap:.6em;justify-content:flex-end;margin-bottom:.6em;flex-wrap:wrap;">
      <button class="btn-laudo btn-laudo-secondary" onclick="window.print()">🖨️ Imprimir / Salvar PDF</button>
      <button class="btn-laudo btn-laudo-secondary" onclick="document.getElementById('laudo_resultado_container').style.display='none'">✖ Fechar laudo</button>
    </div>
    <div id="laudo_resultado"></div>
  </div>

  <div style="margin-top:1em;padding-top:1em;border-top:1px solid #cbd5e0;">
    <button class="btn-laudo btn-laudo-secondary" onclick="abrirGuiaMedicamentos()">📚 Guia farmacológico completo (medicamentos para TPB)</button>
    <a href="medicamentos_tpb.md" download class="btn-laudo btn-laudo-secondary" style="text-decoration:none;display:inline-block;margin-left:.4em;">⬇️ Baixar guia (.md)</a>
  </div>
</section>

<!-- Modal: Guia de Medicamentos -->
<div id="modal-medicamentos" class="modal-med" style="display:none;">
  <div class="modal-med-content">
    <div class="modal-med-header">
      <h2 style="margin:0;color:#1F4E78;">📚 Farmacoterapia da TPB</h2>
      <button class="btn-laudo btn-laudo-secondary" onclick="fecharGuiaMedicamentos()">✖ Fechar</button>
    </div>
    <div id="modal-med-body" class="modal-med-body">Carregando…</div>
  </div>
</div>

<?= $banner ?>

<div class="stats">
  <div class="stat-card"><div class="lbl">Amostras processadas</div><div class="val"><?= $totalAmostras ?></div>
    <div style="font-size:.72em;color:#777;margin-top:.2em;">1 amostra por animal-coleta</div></div>
  <div class="stat-card"><div class="lbl">Coletas (visitas)</div><div class="val"><?= $totalColetas ?></div>
    <div style="font-size:.72em;color:#777;margin-top:.2em;">datas distintas de campo</div></div>
  <div class="stat-card"><div class="lbl">Animais unicos</div><div class="val"><?= $nAnimais ?></div></div>
  <div class="stat-card"><div class="lbl">Fazendas</div><div class="val"><?= $nFazendas ?></div></div>
  <div class="stat-card"><div class="lbl">Periodo</div>
    <div class="val" style="font-size:1em">
      <?= $rangeData['mn'] ? date('d/m/Y', strtotime($rangeData['mn'])) : '-' ?>
      a <?= $rangeData['mx'] ? date('d/m/Y', strtotime($rangeData['mx'])) : '-' ?>
    </div>
  </div>
</div>

<h2>Tabela 1 - Caracterizacao da amostra por fazenda</h2>
<div class="chart-grid">
  <div class="table-section">
    <div class="dl-bar">
      <button class="btn-dl" onclick="baixarTabela('tabela1','Tabela1_Caracterizacao_Amostra')">
        <svg viewBox="0 0 24 24"><path d="M14 3v5h5v11H5V3h9zM5 1a2 2 0 0 0-2 2v18a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8l-7-7H5z M12 12v6m0 0l-3-3m3 3l3-3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        Baixar Tabela
      </button>
    </div>
    <table id="tabela1">
      <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 1 - Caracterizacao da amostra por fazenda</caption>
      <thead><tr>
        <th>Fazenda</th><th>Municipio</th>
        <th class="num">n animais</th>
        <th class="num">n coletas<br><small style="font-weight:normal">(visitas)</small></th>
        <th class="num">n amostras<br><small style="font-weight:normal">(animal x data)</small></th>
        <th class="num">% femeas</th><th class="num">% lactacao</th>
        <th class="num">Idade media (m)</th>
        <th class="num">Com clima</th>
      </tr></thead>
      <tbody>
      <?php foreach ($tab1 as $row): ?>
        <tr>
          <td><b><?= htmlspecialchars($row['fazenda'] ?? '-') ?></b></td>
          <td><?= htmlspecialchars($row['municipio'] ?? '-') ?></td>
          <td class="num"><?= $row['n_animais'] ?></td>
          <td class="num"><?= $row['n_coletas'] ?? '-' ?></td>
          <td class="num"><?= $row['n_amostras'] ?></td>
          <td class="num"><?= $row['pct_femea'] ?? '-' ?>%</td>
          <td class="num"><?= $row['pct_lactacao'] ?? '-' ?>%</td>
          <td class="num"><?= $row['idade_media_meses'] ?? '-' ?></td>
          <td class="num"><?= $row['n_com_clima'] ?? 0 ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <div class="chart-wrap"><h3>Animais por fazenda</h3>
    <canvas id="g3" height="240"></canvas>
    <div class="chart-actions">
      <button class="btn-dl" onclick="baixarGrafico('g3','Grafico_Animais_por_Fazenda')">
        <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5zm2 2h10v14H7V5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        Baixar Gráfico
      </button>
    </div>
  </div>
</div>

<h2>Tabela 2 - Prevalencia por fazenda x parasita (IC95% Wilson)</h2>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela2','Tabela2_Prevalencia_Fazenda')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z M12 12v6m0 0l-3-3m3 3l3-3" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela2">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 2 - Prevalencia por fazenda x parasita (IC95% Wilson)</caption>
  <thead><tr><th>Fazenda</th><th class="num">n</th>
    <th>PCR B. bovis</th><th>PCR A. marginale</th><th>PCR T. vivax</th>
    <th>LAMP 18S</th><th>LAMP CytB</th></tr></thead>
  <tbody>
  <?php foreach ($prev as $r):
      $n=(int)$r['n'];
      $cells=['pos_pcr_babesia','pos_pcr_anaplasma','pos_pcr_trypanosoma','pos_lamp_18s','pos_lamp_cytb']; ?>
    <tr><td><b><?= htmlspecialchars($r['fazenda']) ?></b></td><td class="num"><?= $n ?></td>
      <?php foreach ($cells as $c):
          $k=(int)$r[$c]; $w=wilson95($k,$n); ?>
        <td><span class="pct"><?= $k ?>/<?= $n ?> (<?= fmt_pct($w['p']) ?>)</span><br>
            <span class="ci"><?= fmt_ci($w) ?></span></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="chart-wrap"><h3>Grafico 1 - Prevalencia por fazenda x parasita (% com IC95%)</h3>
<canvas id="g1" height="120"></canvas>
<div class="chart-actions">
  <button class="btn-dl" onclick="baixarGrafico('g1','Grafico1_Prevalencia_Fazenda_Parasita')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Gráfico
  </button>
</div>
</div>

<h2>Tabela 3 - Prevalencia por estacao do ano</h2>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela3','Tabela3_Prevalencia_Estacao')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela3">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 3 - Prevalencia por estacao do ano</caption>
  <thead><tr><th>Estacao</th><th class="num">n</th>
    <th>PCR B. bovis</th><th>PCR A. marginale</th><th>PCR T. vivax</th>
    <th>LAMP 18S</th><th>LAMP CytB</th></tr></thead>
  <tbody>
  <?php foreach ($prevEst as $r):
      $n=(int)$r['n']; $cells=['pos_pcr_babesia','pos_pcr_anaplasma','pos_pcr_trypanosoma','pos_lamp_18s','pos_lamp_cytb']; ?>
    <tr><td><span class="badge"><?= htmlspecialchars($r['estacao'] ?? '-') ?></span></td>
        <td class="num"><?= $n ?></td>
      <?php foreach ($cells as $c):
          $k=(int)$r[$c]; $w=wilson95($k,$n); ?>
        <td><span class="pct"><?= $k ?>/<?= $n ?> (<?= fmt_pct($w['p']) ?>)</span><br>
            <span class="ci"><?= fmt_ci($w) ?></span></td>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php
// Prevalencia agregada (qualquer patogeno) por estacao, na ordem sazonal
$ordemEst = ['Verão','Outono','Inverno','Primavera'];
$temAnyEst = !empty($estPrev);
?>
<?php if ($temAnyEst): ?>
<p style="font-size:.9em;color:#444;margin:.2em 0 .6em;">
  <b>Positividade global (qualquer patógeno) por estação:</b>
  <?php $parts=[]; foreach ($ordemEst as $est) { if (isset($estPrev[$est])) { $e=$estPrev[$est]; $parts[]=htmlspecialchars($est).' '.number_format($e['prev'],1,',','.').'% ('.$e['pos'].'/'.$e['n'].')'; } } echo implode(' · ', $parts); ?>.
</p>
<?php endif; ?>
<p style="font-size:.85em;color:#444;margin:.2em 0 1em;">
  <b>Teste de independência estação × positividade (χ² de Pearson, qualquer patógeno):</b>
  <?php if ($chiEstacao !== null): ?>
    χ²(<?= $chiEstacao['df'] ?>) = <?= number_format($chiEstacao['chi2'],2,',','.') ?>; <?= fmt_pclause($chiEstacao['p']) ?> <?= $chiEstacao['p'] < 0.05 ? '(⇒ há associação)' : '(⇒ sem evidência de associação)' ?><?php if ($chiEstacao['min_expected'] < 5): ?> <span style="color:#a00;">— menor freq. esperada &lt; 5; interpretar com cautela (preferível teste exato)</span><?php endif; ?>.
  <?php else: ?>
    dados insuficientes (—).
  <?php endif; ?>
</p>

<div class="chart-wrap"><h3>Grafico 2 - Prevalencia por estacao do ano</h3>
<canvas id="g2" height="120"></canvas>
<div class="chart-actions">
  <button class="btn-dl" onclick="baixarGrafico('g2','Grafico2_Prevalencia_Estacao')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Gráfico
  </button>
</div>
</div>


<h2>Comparação metodológica para diagnóstico de <i>Babesia bovis</i></h2>
<p style="color:#444;font-size:.92em;line-height:1.5;">
  Análise comparativa entre <b>PCR convencional</b> (PCR B. bovis) e duas variantes de <b>PCR-LAMP</b>
  (LAMP 18S rRNA e LAMP CytB), aplicadas às mesmas <?= $nTotalComp ?> amostras. A PCR convencional é
  utilizada como referência (<i>gold-standard</i> prático) para o cálculo de sensibilidade, especificidade
  e concordância (κ de Cohen).
</p>

<!-- Card resumo de prevalencia por metodo -->
<div class="metricas-comp">
  <div class="met-card" style="border-left-color:#C0504D;">
    <div class="titulo">PCR B. bovis (convencional)</div>
    <div class="linha"><span>Positivos</span><b><?= $nPCR ?> / <?= $nTotalComp ?></b></div>
    <div class="linha"><span>Prevalência</span><b><?= fmt_pct($wPCR['p']) ?></b></div>
    <div class="linha"><span>IC95% Wilson</span><b style="font-size:.85em"><?= fmt_ci($wPCR) ?></b></div>
  </div>
  <div class="met-card lamp18s">
    <div class="titulo">LAMP 18S</div>
    <div class="linha"><span>Positivos</span><b><?= $nLAMP18S ?> / <?= $nTotalComp ?></b></div>
    <div class="linha"><span>Prevalência</span><b><?= fmt_pct($wLAMP18S['p']) ?></b></div>
    <div class="linha"><span>IC95% Wilson</span><b style="font-size:.85em"><?= fmt_ci($wLAMP18S) ?></b></div>
    <div class="linha"><span>Sensib. (vs PCR)</span><b><?= fmt_pct($sens_18) ?></b></div>
    <div class="linha"><span>Especif. (vs PCR)</span><b><?= fmt_pct($spec_18) ?></b></div>
    <div class="linha"><span>Concord. (κ)</span><b><?= $kap_18 !== null ? number_format($kap_18,3,',','.') : '-' ?>
      <span class="interpret"><?= interpretKappa($kap_18) ?></span></b></div>
  </div>
  <div class="met-card lampcytb">
    <div class="titulo">LAMP CytB</div>
    <div class="linha"><span>Positivos</span><b><?= $nLAMPCytB ?> / <?= $nTotalComp ?></b></div>
    <div class="linha"><span>Prevalência</span><b><?= fmt_pct($wLAMPCyt['p']) ?></b></div>
    <div class="linha"><span>IC95% Wilson</span><b style="font-size:.85em"><?= fmt_ci($wLAMPCyt) ?></b></div>
    <div class="linha"><span>Sensib. (vs PCR)</span><b><?= fmt_pct($sens_ct) ?></b></div>
    <div class="linha"><span>Especif. (vs PCR)</span><b><?= fmt_pct($spec_ct) ?></b></div>
    <div class="linha"><span>Concord. (κ)</span><b><?= $kap_ct !== null ? number_format($kap_ct,3,',','.') : '-' ?>
      <span class="interpret"><?= interpretKappa($kap_ct) ?></span></b></div>
  </div>
</div>

<h3 style="color:#1F4E78;margin-top:1.2em;">Tabela 4 - Métricas de desempenho (PCR convencional como referência)</h3>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela4','Tabela4_Metricas_PCR_vs_LAMP')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela4">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 4 - Comparação metodológica (PCR convencional como referência)</caption>
  <thead><tr>
    <th>Comparação</th><th class="num">VP</th><th class="num">FN</th><th class="num">FP</th><th class="num">VN</th>
    <th class="num">Sensib.</th><th class="num">Especif.</th>
    <th class="num">VPP</th><th class="num">VPN</th>
    <th class="num">Acurácia</th><th class="num">κ Cohen</th><th>Interpretação</th>
  </tr></thead>
  <tbody>
    <tr>
      <td><b>PCR vs LAMP 18S</b></td>
      <td class="num"><?= $pp_18 ?></td><td class="num"><?= $pn_18 ?></td>
      <td class="num"><?= $np_18 ?></td><td class="num"><?= $nn_18 ?></td>
      <td class="num"><?= fmt_pct($sens_18) ?></td>
      <td class="num"><?= fmt_pct($spec_18) ?></td>
      <td class="num"><?= fmt_pct($ppv_18) ?></td>
      <td class="num"><?= fmt_pct($npv_18) ?></td>
      <td class="num"><?= fmt_pct($acc_18) ?></td>
      <td class="num"><?= $kap_18 !== null ? number_format($kap_18,3,',','.') : '-' ?></td>
      <td><?= interpretKappa($kap_18) ?></td>
    </tr>
    <tr>
      <td><b>PCR vs LAMP CytB</b></td>
      <td class="num"><?= $pp_ct ?></td><td class="num"><?= $pn_ct ?></td>
      <td class="num"><?= $np_ct ?></td><td class="num"><?= $nn_ct ?></td>
      <td class="num"><?= fmt_pct($sens_ct) ?></td>
      <td class="num"><?= fmt_pct($spec_ct) ?></td>
      <td class="num"><?= fmt_pct($ppv_ct) ?></td>
      <td class="num"><?= fmt_pct($npv_ct) ?></td>
      <td class="num"><?= fmt_pct($acc_ct) ?></td>
      <td class="num"><?= $kap_ct !== null ? number_format($kap_ct,3,',','.') : '-' ?></td>
      <td><?= interpretKappa($kap_ct) ?></td>
    </tr>
    <tr>
      <td><b>LAMP 18S vs LAMP CytB</b></td>
      <td class="num"><?= $pp_lc ?></td><td class="num"><?= $pn_lc ?></td>
      <td class="num"><?= $np_lc ?></td><td class="num"><?= $nn_lc ?></td>
      <td class="num" colspan="4" style="color:#666">(comparação entre LAMPs)</td>
      <td class="num"><?= fmt_pct($acc_lc) ?></td>
      <td class="num"><?= $kap_lc !== null ? number_format($kap_lc,3,',','.') : '-' ?></td>
      <td><?= interpretKappa($kap_lc) ?></td>
    </tr>
  </tbody>
</table>
<p style="font-size:.82em;color:#666;margin-top:.4em;">
  VP=Verdadeiro Positivo, FN=Falso Negativo, FP=Falso Positivo, VN=Verdadeiro Negativo,
  VPP=Valor Preditivo Positivo, VPN=Valor Preditivo Negativo. κ ≥ 0,80 indica concordância
  quase perfeita; 0,60-0,79 substancial; 0,40-0,59 moderada.
</p>
<p style="font-size:.82em;color:#333;margin-top:.4em;line-height:1.55;">
  <b>Concordância κ de Cohen com IC95% assintótico:</b>
  PCR×LAMP18S κ = <?= $kci_18['kappa'] !== null ? number_format($kci_18['kappa'],3,',','.').' [IC95% '.number_format($kci_18['lo'],3,',','.').'–'.number_format($kci_18['hi'],3,',','.').']' : '—' ?> ·
  PCR×CytB κ = <?= $kci_ct['kappa'] !== null ? number_format($kci_ct['kappa'],3,',','.').' [IC95% '.number_format($kci_ct['lo'],3,',','.').'–'.number_format($kci_ct['hi'],3,',','.').']' : '—' ?> ·
  18S×CytB κ = <?= $kci_lc['kappa'] !== null ? number_format($kci_lc['kappa'],3,',','.').' [IC95% '.number_format($kci_lc['lo'],3,',','.').'–'.number_format($kci_lc['hi'],3,',','.').']' : '—' ?>.
</p>
<p style="font-size:.82em;color:#333;margin-top:.2em;line-height:1.55;">
  <b>Teste de McNemar (homogeneidade marginal — dados pareados):</b>
  PCR×LAMP18S <?= fmt_pclause($mcn_18['p']) ?> (<?= htmlspecialchars($mcn_18['metodo']) ?>; discordantes b=<?= $mcn_18['b'] ?>/c=<?= $mcn_18['c'] ?>) ·
  PCR×CytB <?= fmt_pclause($mcn_ct['p']) ?> (<?= htmlspecialchars($mcn_ct['metodo']) ?>; b=<?= $mcn_ct['b'] ?>/c=<?= $mcn_ct['c'] ?>) ·
  18S×CytB <?= fmt_pclause($mcn_lc['p']) ?> (<?= htmlspecialchars($mcn_lc['metodo']) ?>; b=<?= $mcn_lc['b'] ?>/c=<?= $mcn_lc['c'] ?>).
  <br><span style="color:#666;">p &lt; 0,05 ⇒ os métodos diferem na taxa de detecção (complementa o κ, que mede concordância). b/c = pares discordantes.</span>
</p>
</div>

<div class="chart-wrap">
  <h3>Gráfico 7 - Comparação metodológica: PCR convencional × PCR-LAMP (<i>B. bovis</i>)</h3>
  <p style="font-size:.85em;color:#666;margin:.2em 0 .8em;">
    <b>(A)</b> Total de positivos por método e <b>(B)</b> categorias mutuamente exclusivas
    (cada coleta classificada conforme combinação de resultados positivos entre os 3 métodos).
  </p>
  <div class="g7-grid">
    <div class="g7-cell">
      <canvas id="g7a"></canvas>
      <div class="chart-actions" style="margin-top:.4em;">
        <button class="btn-dl" onclick="baixarGrafico('g7a','Grafico7A_Total_Positivos_por_Metodo')">
          <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          Baixar Gráfico (A)
        </button>
      </div>
    </div>
    <div class="g7-cell">
      <canvas id="g7b"></canvas>
      <div class="chart-actions" style="margin-top:.4em;">
        <button class="btn-dl" onclick="baixarGrafico('g7b','Grafico7B_Concordancia_Metodos')">
          <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          Baixar Gráfico (B)
        </button>
      </div>
    </div>
  </div>
</div>


<!-- ============================================================
     ITEM 1 - COINFECCAO ENTRE OS 3 PATOGENOS
     ============================================================ -->
<h2>Coinfecção entre patógenos</h2>
<p style="color:#444;font-size:.92em;line-height:1.5;">
  Distribuição das <?= $nTotalCo ?> amostras pelas 8 categorias mutuamente exclusivas formadas pelas
  combinações de positividade entre <b>B. bovis</b> (qualquer alvo positivo: PCR, LAMP 18S ou LAMP CytB),
  <b>A. marginale</b> (PCR) e <b>T. vivax</b> (PCR). A frequência de multi-infecção (≥2 patógenos
  simultâneos) é fundamental para a hipótese de estabilidade enzoótica.
</p>

<div class="metricas-comp">
  <div class="met-card" style="border-left-color:#C0504D;">
    <div class="titulo">B. bovis (qualquer alvo)</div>
    <div class="linha"><span>Positivos</span><b><?= $nBB ?> / <?= $nTotalCo ?></b></div>
    <div class="linha"><span>Prevalência</span><b><?= fmt_pct(safeDiv($nBB,$nTotalCo)) ?></b></div>
  </div>
  <div class="met-card" style="border-left-color:#9BBB59;">
    <div class="titulo">A. marginale</div>
    <div class="linha"><span>Positivos</span><b><?= $nAM ?> / <?= $nTotalCo ?></b></div>
    <div class="linha"><span>Prevalência</span><b><?= fmt_pct(safeDiv($nAM,$nTotalCo)) ?></b></div>
  </div>
  <div class="met-card" style="border-left-color:#F79646;">
    <div class="titulo">T. vivax</div>
    <div class="linha"><span>Positivos</span><b><?= $nTV ?> / <?= $nTotalCo ?></b></div>
    <div class="linha"><span>Prevalência</span><b><?= fmt_pct(safeDiv($nTV,$nTotalCo)) ?></b></div>
  </div>
  <div class="met-card" style="border-left-color:#1F4E78;">
    <div class="titulo">Multi-infecção (≥2 patógenos)</div>
    <div class="linha"><span>Amostras</span><b><?= $nMultiInfeccao ?> / <?= $nTotalCo ?></b></div>
    <div class="linha"><span>Frequência</span><b><?= fmt_pct($wMulti['p']) ?></b></div>
    <div class="linha"><span>IC95% Wilson</span><b style="font-size:.85em"><?= fmt_ci($wMulti) ?></b></div>
  </div>
</div>

<h3 style="color:#1F4E78;margin-top:1.2em;">Tabela 5 - Categorias mutuamente exclusivas de coinfecção (IC95%)</h3>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela5','Tabela5_Coinfeccao')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela5">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 5 - Coinfecção entre os 3 patógenos (categorias mutuamente exclusivas)</caption>
  <thead><tr><th>Categoria</th><th class="num">n</th><th class="num">%</th><th>IC95% Wilson</th></tr></thead>
  <tbody>
  <?php
  $rotulosCo = [
      'neg'    => 'Negativos para todos',
      'so_bb'  => 'Apenas B. bovis',
      'so_am'  => 'Apenas A. marginale',
      'so_tv'  => 'Apenas T. vivax',
      'bb_am'  => 'B. bovis + A. marginale',
      'bb_tv'  => 'B. bovis + T. vivax',
      'am_tv'  => 'A. marginale + T. vivax',
      'todos'  => 'Todos os 3 (triplo positivo)',
  ];
  foreach ($rotulosCo as $k=>$lbl):
      $v = $coinf[$k]; $w = wilson95($v, $nTotalCo); ?>
    <tr>
      <td><?= $lbl ?></td>
      <td class="num"><?= $v ?></td>
      <td class="num"><?= fmt_pct($w['p']) ?></td>
      <td><?= fmt_ci($w) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="chart-wrap">
  <h3>Gráfico 8 - Coinfecção (UpSet-style: B. bovis × A. marginale × T. vivax)</h3>
  <div style="height:340px"><canvas id="g8"></canvas></div>
  <div class="chart-actions">
    <button class="btn-dl" onclick="baixarGrafico('g8','Grafico8_Coinfeccao')">
      <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      Baixar Gráfico
    </button>
  </div>
</div>


<!-- ============================================================
     ITEM 2 - ANALISE LONGITUDINAL POR ANIMAL
     ============================================================ -->
<h2>Análise longitudinal por animal (delineamento trimestral)</h2>
<p style="color:#444;font-size:.92em;line-height:1.5;">
  Rastreamento dos <b><?= $nAnimaisLongi ?> animais únicos</b>
  (<?= $nAnimaisCom2plus ?> com ≥2 amostras em datas distintas, totalizando <?= $nTransicoes ?> transições N→N+1).
  Estados longitudinais por patógeno: <b>sempre-positivo</b> (infecção persistente), <b>sempre-negativo</b>,
  <b>soroconversor</b> (negativo→positivo, sugere nova infecção), <b>aclarado</b> (positivo→negativo,
  sugere resolução/tratamento eficaz) e <b>oscilante</b> (≥2 inversões, sugere infecção subclínica
  intermitente ou variação de carga abaixo do limiar).
</p>

<h3 style="color:#1F4E78;">Tabela 6 - Estados longitudinais por patógeno</h3>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela6','Tabela6_Estados_Longitudinais')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela6">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 6 - Distribuição de estados longitudinais por animal (N=<?= $nAnimaisLongi ?>)</caption>
  <thead><tr>
    <th>Patógeno</th>
    <th class="num">Amostra única<br><small>(só 1 visita)</small></th>
    <th class="num">Sempre negativo</th>
    <th class="num">Sempre positivo</th>
    <th class="num">Soroconversor (neg→pos)</th>
    <th class="num">Aclarado (pos→neg)</th>
    <th class="num">Oscilante</th>
  </tr></thead>
  <tbody>
    <?php
    $linhasL = [
        ['B. bovis (qq alvo)', $estadoBB],
        ['A. marginale',       $estadoAM],
        ['T. vivax',           $estadoTV],
    ];
    foreach ($linhasL as [$nome, $est]): ?>
      <tr>
        <td><b><?= $nome ?></b></td>
        <td class="num"><?= $est['unica_coleta'] ?></td>
        <td class="num"><?= $est['sempre_neg'] ?></td>
        <td class="num"><?= $est['sempre_pos'] ?></td>
        <td class="num"><?= $est['soroconv'] ?></td>
        <td class="num"><?= $est['aclarado'] ?></td>
        <td class="num"><?= $est['oscilante'] ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<h3 style="color:#1F4E78;">Tabela 7 - Matriz de transição N → N+1 (entre amostras consecutivas de cada animal)</h3>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela7','Tabela7_Matriz_Transicao')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela7">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 7 - Transições entre amostras consecutivas do mesmo animal em datas distintas (n=<?= $nTransicoes ?> transições)</caption>
  <thead><tr>
    <th>Patógeno</th>
    <th class="num">neg → neg</th>
    <th class="num">neg → pos<br><small>(incidência)</small></th>
    <th class="num">pos → neg<br><small>(aclareamento)</small></th>
    <th class="num">pos → pos<br><small>(persistência)</small></th>
    <th class="num">% incidência</th>
    <th class="num">% persistência</th>
  </tr></thead>
  <tbody>
    <?php
    $transitions = [
      ['B. bovis (qq alvo)', $transBB],
      ['A. marginale',       $transAM],
      ['T. vivax',           $transTV],
    ];
    foreach ($transitions as [$nome, $m]):
      $tot = array_sum($m);
      $negPart = $m['00'] + $m['01'];
      $posPart = $m['10'] + $m['11'];
      $incid = $negPart > 0 ? $m['01'] / $negPart : 0;
      $persist = $posPart > 0 ? $m['11'] / $posPart : 0;
    ?>
      <tr>
        <td><b><?= $nome ?></b></td>
        <td class="num"><?= $m['00'] ?></td>
        <td class="num"><?= $m['01'] ?></td>
        <td class="num"><?= $m['10'] ?></td>
        <td class="num"><?= $m['11'] ?></td>
        <td class="num"><?= fmt_pct($incid) ?></td>
        <td class="num"><?= fmt_pct($persist) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
<p style="font-size:.82em;color:#666;">
  <b>Incidência</b> = P(positivo | era negativo). <b>Persistência</b> = P(positivo | era positivo).
  Em região enzoótica estável, espera-se persistência alta e incidência baixa para B. bovis.
</p>
</div>

<div class="chart-wrap">
  <h3>Gráfico 9 - Estados longitudinais por patógeno e distribuição de amostras por animal</h3>
  <div class="g7-grid">
    <div class="g7-cell">
      <canvas id="g9a"></canvas>
      <div class="chart-actions" style="margin-top:.4em;">
        <button class="btn-dl" onclick="baixarGrafico('g9a','Grafico9A_Estados_Longitudinais')">
          <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          Baixar Gráfico (A)
        </button>
      </div>
    </div>
    <div class="g7-cell">
      <canvas id="g9b"></canvas>
      <div class="chart-actions" style="margin-top:.4em;">
        <button class="btn-dl" onclick="baixarGrafico('g9b','Grafico9B_Amostras_por_Animal')">
          <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          Baixar Gráfico (B)
        </button>
      </div>
    </div>
  </div>
</div>


<!-- ============================================================
     LASAGNA PLOT - Grafico 9C (matriz animal x onda)
     ============================================================ -->
<div class="chart-wrap">
  <h3>Gráfico 9C - Lasagna plot longitudinal por animal</h3>
  <p style="color:#444;font-size:.92em;line-height:1.5;">
    Cada <b>linha</b> é um animal e cada <b>coluna</b> é uma onda de coleta (estação × ano);
    a <b>cor</b> da célula indica o status naquela onda. Animais agrupados por fazenda e
    ordenados por nº de ondas positivas. Células cinzas = animal não amostrado naquela onda.
    <b>Preliminar:</b> com poucas ondas o gráfico fica esparso e <b>adensa a cada coleta</b>,
    revelando portadores crônicos vs. infecções transitórias.
  </p>
  <?php if ($nLasanhaAnimais === 0 || $nLasanhaOndas === 0): ?>
    <div style="background:#fff3cd;color:#664d03;padding:12px;border-radius:4px;margin:1em 0;">
      <b>Sem dados longitudinais suficientes</b> para o lasagna plot (nenhuma onda estação/ano
      identificada). Será populado conforme novas coletas sazonais forem registradas.
    </div>
  <?php else: ?>
  <div style="overflow-x:auto;"><canvas id="g9c"></canvas></div>
  <div class="chart-actions" style="margin-top:.4em;">
    <button class="btn-dl" onclick="baixarGrafico('g9c','Grafico9C_Lasagna_Longitudinal')">
      <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      Baixar Gráfico
    </button>
  </div>
  <?php endif; ?>
</div>


<!-- ============================================================
     ITEM 4 - TEMPO DE DETECCAO LAMP (proxy de carga)
     ============================================================ -->
<h2>Tempo de detecção LAMP (proxy semi-quantitativo de carga parasitária)</h2>
<?php if (!$temTabelaLamp || $nLAMPanalises === 0): ?>
  <div style="background:#fff3cd;color:#664d03;padding:12px;border-radius:4px;margin:1em 0;">
    <b>Sem dados de tempo de detecção LAMP.</b> A tabela <code>resultados_lamp</code>
    <?= $temTabelaLamp ? 'está vazia' : 'não foi encontrada no banco' ?>.
    Esta análise será populada quando o cadastro detalhado dos ensaios LAMP
    (tempo de positividade e intensidade de sinal) começar a ser preenchido.
  </div>
<?php else: ?>
<p style="color:#444;font-size:.92em;line-height:1.5;">
  Análise de <?= $nLAMPanalises ?> ensaios LAMP cadastrados em <code>resultados_lamp</code>.
  O tempo até a positividade da reação isotérmica correlaciona-se inversamente com a carga
  parasitária inicial — tempos curtos sugerem alta carga, tempos longos sugerem baixa carga.
</p>

<h3 style="color:#1F4E78;">Tabela 8 - Estatística descritiva do tempo de detecção</h3>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela8','Tabela8_Tempo_Deteccao_LAMP')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela8">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 8 - Tempo de detecção LAMP (minutos) entre amostras positivas</caption>
  <thead><tr>
    <th>Alvo</th><th class="num">n</th>
    <th class="num">Média ± DP</th>
    <th class="num">Mediana [Q1-Q3]</th>
    <th class="num">Min - Max</th>
    <th class="num">Forte</th><th class="num">Médio</th><th class="num">Fraco</th>
  </tr></thead>
  <tbody>
    <tr>
      <td><b>LAMP 18S</b></td>
      <td class="num"><?= $d18s['n'] ?></td>
      <td class="num"><?= $d18s['n']>0 ? "{$d18s['media']} ± {$d18s['sd']}" : '-' ?></td>
      <td class="num"><?= $d18s['n']>0 ? "{$d18s['mediana']} [{$d18s['q1']}-{$d18s['q3']}]" : '-' ?></td>
      <td class="num"><?= $d18s['n']>0 ? "{$d18s['min']} - {$d18s['max']}" : '-' ?></td>
      <td class="num"><?= $intens18s['Forte'] ?></td>
      <td class="num"><?= $intens18s['Médio'] ?></td>
      <td class="num"><?= $intens18s['Fraco'] ?></td>
    </tr>
    <tr>
      <td><b>LAMP CytB</b></td>
      <td class="num"><?= $dCytb['n'] ?></td>
      <td class="num"><?= $dCytb['n']>0 ? "{$dCytb['media']} ± {$dCytb['sd']}" : '-' ?></td>
      <td class="num"><?= $dCytb['n']>0 ? "{$dCytb['mediana']} [{$dCytb['q1']}-{$dCytb['q3']}]" : '-' ?></td>
      <td class="num"><?= $dCytb['n']>0 ? "{$dCytb['min']} - {$dCytb['max']}" : '-' ?></td>
      <td class="num"><?= $intensCytb['Forte'] ?></td>
      <td class="num"><?= $intensCytb['Médio'] ?></td>
      <td class="num"><?= $intensCytb['Fraco'] ?></td>
    </tr>
  </tbody>
</table>
</div>

<div class="chart-wrap">
  <h3>Gráfico 10 - Tempo de detecção LAMP por alvo (histograma)</h3>
  <div class="g7-grid">
    <div class="g7-cell">
      <canvas id="g10a"></canvas>
      <div class="chart-actions" style="margin-top:.4em;">
        <button class="btn-dl" onclick="baixarGrafico('g10a','Grafico10A_Histograma_Tempo_LAMP')">
          <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          Baixar Gráfico (A)
        </button>
      </div>
    </div>
    <div class="g7-cell">
      <canvas id="g10b"></canvas>
      <div class="chart-actions" style="margin-top:.4em;">
        <button class="btn-dl" onclick="baixarGrafico('g10b','Grafico10B_Intensidade_Sinal_LAMP')">
          <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
          Baixar Gráfico (B)
        </button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>


<!-- ============================================================
     ITEM 9 - CURVA ROC dos LAMPs
     ============================================================ -->
<h2>Curva ROC: critérios de positividade LAMP (PCR como referência)</h2>
<p style="color:#444;font-size:.92em;line-height:1.5;">
  Sensibilidade × (1 − Especificidade) para 4 critérios de positividade do PCR-LAMP, usando o PCR
  convencional como gold-standard. O ponto mais distante da diagonal (maior <b>índice J de Youden</b>
  = sens + esp − 1) representa o critério ótimo. A AUC empírica trapezoidal sumariza o desempenho global.
</p>

<h3 style="color:#1F4E78;">Tabela 9 - Sensibilidade, especificidade e J de Youden por critério</h3>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela9','Tabela9_ROC_LAMP')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela9">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 9 - Critérios de positividade LAMP × PCR convencional (AUC empírica = <?= number_format($auc, 3, ',', '.') ?><?php if ($aucCI['lo'] !== null): ?>; IC95% <?= number_format($aucCI['lo'],3,',','.') ?>–<?= number_format($aucCI['hi'],3,',','.') ?> Hanley–McNeil<?php endif; ?>)</caption>
  <thead><tr>
    <th>Critério</th><th class="num">VP</th><th class="num">FN</th>
    <th class="num">FP</th><th class="num">VN</th>
    <th class="num">Sensib.</th><th class="num">Especif.</th>
    <th class="num">1 − Espec.</th><th class="num">J Youden</th>
  </tr></thead>
  <tbody>
    <?php
    $rotuloROC = [
      'so_18s'  => 'Apenas LAMP 18S positivo',
      'so_cytb' => 'Apenas LAMP CytB positivo',
      'or'      => 'LAMP 18S OU CytB positivo (união)',
      'and'     => 'LAMP 18S E CytB positivos (intersecção)',
    ];
    foreach ($rotuloROC as $k=>$lbl):
        $r = $roc[$k]; ?>
      <tr>
        <td><?= $lbl ?></td>
        <td class="num"><?= $r['vp'] ?></td>
        <td class="num"><?= $r['fn'] ?></td>
        <td class="num"><?= $r['fp'] ?></td>
        <td class="num"><?= $r['vn'] ?></td>
        <td class="num"><?= fmt_pct($r['sens']) ?></td>
        <td class="num"><?= fmt_pct($r['spec']) ?></td>
        <td class="num"><?= fmt_pct(1 - $r['spec']) ?></td>
        <td class="num"><b><?= number_format($r['youden'],3,',','.') ?></b></td>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="chart-wrap">
  <h3>Gráfico 11 - Curva ROC dos critérios LAMP</h3>
  <div style="height:440px"><canvas id="g11"></canvas></div>
  <p class="nota" style="font-size:.85em;color:#444;line-height:1.5;">
    Eixo X = <b>1 − especificidade</b> (taxa de falsos positivos); eixo Y = <b>sensibilidade</b>
    (taxa de verdadeiros positivos). A <b>diagonal tracejada</b> representa o acaso (AUC = 0,5).
    Cada ponto é um critério de positividade do LAMP — quanto <b>mais próximo do canto
    superior-esquerdo</b>, melhor o critério (alta sensibilidade com poucos falsos positivos).
    Os critérios são identificados por cor e forma na legenda (passe o mouse para os valores).
  </p>
  <div class="chart-actions">
    <button class="btn-dl" onclick="baixarGrafico('g11','Grafico11_ROC_LAMP')">
      <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      Baixar Gráfico
    </button>
  </div>
</div>


<!-- ============================================================
     ITEM 10 - HEATMAP KAPPA 5x5
     ============================================================ -->
<h2>Heatmap de concordância (κ Cohen pareado entre os 5 testes)</h2>
<p style="color:#444;font-size:.92em;line-height:1.5;">
  Matriz simétrica 5×5 com o κ de Cohen entre cada par de testes. Diagonal = 1 (auto-concordância).
  Valores altos entre <b>PCR B. bovis × LAMP 18S/CytB</b> indicam que o LAMP reproduz bem o PCR
  para o mesmo alvo. Valores altos entre <b>parasitas distintos</b> (ex.: PCR B. bovis × PCR A. marginale)
  sinalizam coinfecção sistemática (relevante epidemiologicamente).
  Cada célula mostra o κ pontual e, na 2ª linha, o seu <b>IC95%</b> (assintótico) — passe o mouse
  para o intervalo detalhado. Com poucos eventos, ICs largos (podendo cruzar 0) são esperados e
  devem ser lidos como incerteza, não como ausência de concordância.
</p>

<h3 style="color:#1F4E78;">Tabela 10 - Matriz de κ Cohen entre os 5 testes</h3>
<div class="table-section">
<div class="dl-bar">
  <button class="btn-dl" onclick="baixarTabela('tabela10','Tabela10_Matriz_Kappa')">
    <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
    Baixar Tabela
  </button>
</div>
<table id="tabela10">
  <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Tabela 10 - κ Cohen pareado entre os 5 testes (n=<?= $nTotalCo ?>)</caption>
  <thead><tr>
    <th></th>
    <?php foreach ($keysT as $k): ?>
      <th><?= htmlspecialchars($testes5[$k]['rotulo']) ?></th>
    <?php endforeach; ?>
  </tr></thead>
  <tbody>
    <?php foreach ($keysT as $k1): ?>
      <tr>
        <th style="background:#1F4E78;color:#fff;text-align:left;"><?= htmlspecialchars($testes5[$k1]['rotulo']) ?></th>
        <?php foreach ($keysT as $k2):
            $val = $matrizK[$k1][$k2];
            $cor = '#ffffff';
            if ($val !== null) {
                $t = max(-0.2, min(1.0, $val));
                // Verde claro -> verde escuro para 0..1; cinza para <=0
                if ($t <= 0) $cor = '#e6e6e6';
                else {
                    $g = round(255 - $t * 130);
                    $cor = "rgb($g, " . round(255 - $t * 50) . ", $g)";
                }
            }
            $txt = $val !== null ? number_format($val, 2, ',', '.') : '-';
        ?>
          <td style="background:<?= $cor ?>;text-align:center;font-variant-numeric:tabular-nums;font-weight:600;">
            <?= $txt ?>
          </td>
        <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
  </tbody>
</table>
</div>

<div class="chart-wrap">
  <h3>Gráfico 12 - Heatmap κ Cohen 5×5</h3>
  <div style="height:420px"><canvas id="g12"></canvas></div>
  <div class="chart-actions">
    <button class="btn-dl" onclick="baixarGrafico('g12','Grafico12_Heatmap_Kappa_5x5')">
      <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      Baixar Gráfico
    </button>
  </div>
</div>


<h2>Correlação Climática (NASA POWER, média de 90 dias antes da coleta)</h2>
<p style="color:#666;font-size:.9em;">Cada ponto = bin de valor climático com a prevalência observada (% infectados em qualquer parasita) e IC95% Wilson (barras verticais). O <b>tamanho do ponto</b> é proporcional ao nº de amostras do bin e a <b>linha tracejada</b> é a tendência (regressão linear ponderada por n). O ρ de Spearman, com IC95% (Fisher z) e p-valor, é calculado sobre as observações individuais (sem binning); <b>*</b> indica p &lt; 0,05.</p>

<div class="chart-grid" style="grid-template-columns:1fr 1fr 1fr;">
  <div class="chart-wrap">
    <h3>Gráfico 4 - Temperatura média (°C) × Prevalência</h3>
    <canvas id="g4" height="180"></canvas>
    <p class="nota" style="font-size:.85em;"><?= notaCorr($rT, $siT, $trendT) ?></p>
    <div class="chart-actions">
      <button class="btn-dl" onclick="baixarGrafico('g4','Grafico4_Temperatura_Prevalencia')">
        <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        Baixar Gráfico
      </button>
    </div>
  </div>
  <div class="chart-wrap">
    <h3>Gráfico 5 - Pluviometria total (mm/90d) × Prevalência</h3>
    <canvas id="g5" height="180"></canvas>
    <p class="nota" style="font-size:.85em;"><?= notaCorr($rP, $siP, $trendP) ?></p>
    <div class="chart-actions">
      <button class="btn-dl" onclick="baixarGrafico('g5','Grafico5_Pluviometria_Prevalencia')">
        <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        Baixar Gráfico
      </button>
    </div>
  </div>
  <div class="chart-wrap">
    <h3>Gráfico 6 - Umidade média (%) × Prevalência</h3>
    <canvas id="g6" height="180"></canvas>
    <p class="nota" style="font-size:.85em;"><?= notaCorr($rU, $siU, $trendU) ?></p>
    <div class="chart-actions">
      <button class="btn-dl" onclick="baixarGrafico('g6','Grafico6_Umidade_Prevalencia')">
        <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        Baixar Gráfico
      </button>
    </div>
  </div>
</div>

<?php if ($rT['n'] == 0): ?>
<div style="background:#fff3cd;color:#664d03;padding:12px;border-radius:4px;">
<b>Sem dados climáticos vinculados.</b> Vá em <a href="manutencao.php#backup">Manutenção → Carga clima</a>
e clique em "⬇️ Carregar 90d clima na NASA POWER" para popular dados_climaticos.
</div>
<?php endif; ?>


<!-- ============================================================
     INTEGRACAO CLIMA x EPIDEMIOLOGIA — modelo multivariavel
     ============================================================ -->
<h2>Integração clima → epidemiologia: modelo multivariável</h2>
<p style="color:#444;font-size:.92em;line-height:1.55;">
  Regressão logística (IRLS) de <b>infecção por qualquer patógeno (0/1)</b> em função de
  <b>temperatura média + pluviometria total + umidade média</b> (NASA POWER, média de 90&nbsp;dias pré-coleta),
  ajustadas simultaneamente. Os preditores são padronizados, de modo que cada <b>razão de chances (OR)</b>
  corresponde ao efeito de <b>+1 desvio-padrão</b> da variável climática, mantendo as demais constantes.
  Diferente dos gráficos 4–6 (univariados), este modelo separa o efeito de cada variável do efeito das outras.
</p>
<?php if ($logit === null): ?>
  <div style="background:#fff3cd;color:#664d03;padding:12px;border-radius:4px;">
    <b>Modelo não estimado.</b> Requer ≥ 30 amostras com clima vinculado, desfecho com variabilidade e
    preditores não colineares/constantes. Amostras com clima completo disponíveis: <b><?= (int)$nClimaDS ?></b>.
    À medida que as coletas sazonais avançarem (4 estações × 3 localidades), o n crescerá e o modelo ganhará poder.
  </div>
<?php else: ?>
  <?php $epv = $logit['eventos'] > 0 ? round($logit['eventos'] / 4, 1) : 0; ?>
  <p style="font-size:.88em;color:#444;margin:.2em 0;">
    n = <?= (int)$logit['n'] ?> amostras · eventos (infectados) = <?= (int)$logit['eventos'] ?> ·
    EPV ≈ <?= number_format($epv,1,',','.') ?><?php if ($epv < 10): ?>
    <span style="color:#a00;">(EPV &lt; 10 ⇒ estimativas exploratórias, risco de sobreajuste)</span><?php endif; ?>
  </p>
  <div class="table-section">
  <div class="dl-bar">
    <button class="btn-dl" onclick="baixarTabela('tabela_logit','Tabela_Modelo_Logistico_Clima')">
      <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      Baixar Tabela
    </button>
  </div>
  <table id="tabela_logit">
    <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Modelo logístico multivariável — OR por +1 desvio-padrão (IC95% Wald)</caption>
    <thead><tr><th>Preditor (por +1 DP)</th><th class="num">OR</th><th class="num">IC95%</th><th class="num">z (Wald)</th><th class="num">p</th></tr></thead>
    <tbody>
    <?php foreach ($logit['coef'] as $c):
        $sig = ($c['p'] !== null && $c['p'] < 0.05); ?>
      <tr<?= $sig ? ' style="background:#eef7ee;"' : '' ?>>
        <td><?= htmlspecialchars($c['rotulo']) ?></td>
        <td class="num"><b><?= number_format($c['or'],3,',','.') ?></b></td>
        <td class="num">[<?= number_format($c['or_lo'],3,',','.') ?> – <?= number_format($c['or_hi'],3,',','.') ?>]</td>
        <td class="num"><?= number_format($c['z'],3,',','.') ?></td>
        <td class="num"><?= fmt_p($c['p']) ?><?= $sig ? ' <b style="color:#157347">*</b>' : '' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <div class="chart-wrap">
    <h3>Gráfico 13 - Forest plot dos OR climáticos (escala log)</h3>
    <canvas id="g13" style="display:block;"></canvas>
    <div class="chart-actions">
      <button class="btn-dl" onclick="baixarGrafico('g13','Grafico13_ForestPlot_OR_Clima')">
        <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        Baixar Gráfico
      </button>
    </div>
  </div>
  <p style="font-size:.82em;color:#666;">
    OR &gt; 1 ⇒ maior chance de infecção a cada +1 DP do preditor; OR &lt; 1 ⇒ menor chance.
    IC95% que cruza a linha de OR = 1 ⇒ não significativo a 5%. <b>*</b> marca p &lt; 0,05.
    Associações <b>descritivas/ecológicas</b>, não causais: poucas fazendas, clima agregado por fazenda e
    possível confundimento por manejo e estação.
  </p>
<?php endif; ?>

<h3 style="color:#1F4E78;margin-top:1.2em;">Associação univariada clima × patógeno (Spearman)</h3>
<?php if (empty($climPat) || $nClimaDS == 0): ?>
  <div style="background:#fff3cd;color:#664d03;padding:12px;border-radius:4px;"><b>Sem dados climáticos vinculados.</b></div>
<?php else: ?>
  <div class="table-section">
  <div class="dl-bar">
    <button class="btn-dl" onclick="baixarTabela('tabela_climpat','Tabela_Clima_Patogeno_Spearman')">
      <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
      Baixar Tabela
    </button>
  </div>
  <table id="tabela_climpat">
    <caption style="caption-side:top;text-align:left;font-weight:bold;padding:.3em 0;color:#1F4E78;">Spearman ρ (p-valor; n) — patógeno × variável climática</caption>
    <thead><tr><th>Patógeno</th><?php foreach ($climVars as $crot) echo '<th class="num">'.htmlspecialchars($crot).'</th>'; ?></tr></thead>
    <tbody>
    <?php foreach ($patAlvos as $pk => $pnome): ?>
      <tr><td><i><?= htmlspecialchars($pnome) ?></i></td>
      <?php foreach ($climVars as $ck => $crot): $cell = $climPat[$pk][$ck];
        $sig = ($cell['p'] !== null && $cell['p'] < 0.05) ? 'font-weight:700;color:#1F4E78;background:#eef4fb;' : ''; ?>
        <td class="num" style="<?= $sig ?>"><?= $cell['rho'] !== null ? number_format($cell['rho'],3,',','.') : '—' ?>
          <span style="font-weight:400;color:#888;font-size:.85em;">(<?= fmt_pclause($cell['p']) ?>; n=<?= $cell['n'] ?>)</span></td>
      <?php endforeach; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <p style="font-size:.82em;color:#666;">
    ρ de Spearman (empates por rank médio). Como o desfecho é 0/1, há muitos empates e o ρ tende a ser atenuado
    (o modelo logístico acima é a leitura principal). p sem correção para múltiplas comparações (exploratório).
    Células destacadas: p &lt; 0,05. <i>Rhipicephalus (Boophilus) microplus</i> — vetor de <i>B. bovis</i> e
    <i>A. marginale</i> — tem desenvolvimento favorecido por calor e umidade, o que motiva o acompanhamento
    conjunto clima × positividade ao longo das estações.
  </p>
<?php endif; ?>


<h2>Heatmap temporal — clima semanal por fazenda</h2>
<p style="color:#666;font-size:.9em;">
    9 linhas (3 fazendas x 3 variaveis): cada celula eh a media semanal da variavel climatica,
    da semana iniciada em 90 dias antes da primeira coleta ate hoje. Auto-atualizavel.
    Valores buscados na <b>NASA POWER</b> (T2M, PRECTOTCORR, RH2M) e cacheados em <code>clima_semanal_cache</code>.
</p>

<div style="background:white;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.06);padding:14px;margin:1em 0;">
    <div style="display:flex;gap:10px;align-items:center;margin-bottom:10px;flex-wrap:wrap;">
        <button id="btn_carregar_clima" class="btn-secondary" onclick="iniciarCargaSemanal()" style="padding:8px 14px;border-radius:6px;border:0;color:white;cursor:pointer;font-weight:600;">
            Iniciar carga semanal NASA
        </button>
        <span id="info_semanal" style="color:#666;font-size:13px;">aguardando...</span>
        <div style="flex:1;background:#e9ecef;border-radius:4px;height:16px;overflow:hidden;border:1px solid #ced4da;min-width:200px;">
            <div id="bar_semanal" style="background:linear-gradient(90deg,#0d6efd,#198754);height:100%;width:0%;color:white;text-align:center;font-size:10px;line-height:16px;font-weight:600;transition:width .3s;">0%</div>
        </div>
    </div>
    <canvas id="heatmap_temporal" height="280"></canvas>
    <div class="chart-actions">
      <button class="btn-dl" onclick="baixarGrafico('heatmap_temporal','Heatmap_Clima_Semanal')">
        <svg viewBox="0 0 24 24"><path d="M5 3v18h14V3H5z" fill="none" stroke="currentColor" stroke-width="2"/></svg>
        Baixar Gráfico
      </button>
    </div>
    <p class="nota" style="font-size:.82em;color:#666;margin-top:10px;">
        Linhas agrupadas em <b>3 triades</b>, uma por variavel climatica (Temperatura, Pluviometria, Umidade).
        Em cada triade, as 3 fazendas aparecem lado a lado para comparacao direta.
        Paleta unica <b>verde → amarelo → vermelho</b>, normalizada por variavel:
        verde = abaixo da mediana, amarelo = mediana, vermelho = acima da mediana. Cinza = sem dado.
    </p>
</div>

</main>
<footer>Diagnostico TPB - IFRJ - LGM - Pipeline cientifico v1
</footer>

<script>
const errorBarPlugin = {
    id:'errorBars',
    afterDatasetsDraw(chart){
        const {ctx, scales:{x,y}} = chart;
        chart.data.datasets.forEach((ds,i)=>{
            if(!ds.errs) return;
            const meta = chart.getDatasetMeta(i);
            meta.data.forEach((bar,j)=>{
                const e=ds.errs[j]; if(!e) return;
                const cx=bar.x, top=y.getPixelForValue(e.hi), bot=y.getPixelForValue(e.lo);
                ctx.save(); ctx.strokeStyle='#333'; ctx.lineWidth=1.5;
                ctx.beginPath();
                ctx.moveTo(cx,top); ctx.lineTo(cx,bot);
                ctx.moveTo(cx-5,top); ctx.lineTo(cx+5,top);
                ctx.moveTo(cx-5,bot); ctx.lineTo(cx+5,bot);
                ctx.stroke(); ctx.restore();
            });
        });
    }
};
new Chart(document.getElementById('g1'),{
    type:'bar',
    data:{labels:<?= json_encode($g1_labels) ?>, datasets:<?= json_encode($g1_datasets) ?>},
    options:{
        plugins:{
            legend:{position:'bottom'},
            tooltip:{callbacks:{label:c=>{
                const ds=c.dataset; const e=ds.errs&&ds.errs[c.dataIndex];
                let s=`${ds.label}: ${c.parsed.y.toFixed(1)}%`;
                if(e) s+=` (IC95% ${e.lo.toFixed(1)}–${e.hi.toFixed(1)}%)`;
                return s;
            }}}
        },
        scales:{y:{beginAtZero:true,suggestedMax:<?= $g1_ymax ?>,
            title:{display:true,text:'Prevalência (%)'},
            ticks:{callback:v=>v+'%'}}}
    },
    plugins:[errorBarPlugin]
});
new Chart(document.getElementById('g2'),{
    type:'line',
    data:{labels:<?= json_encode($g2_labels) ?>, datasets:<?= json_encode($g2_datasets) ?>},
    options:{
        plugins:{
            legend:{position:'bottom'},
            tooltip:{callbacks:{label:c=>`${c.dataset.label}: ${c.parsed.y.toFixed(1)}%`}}
        },
        interaction:{mode:'index',intersect:false},
        scales:{y:{beginAtZero:true,suggestedMax:<?= $g2_ymax ?>,
            title:{display:true,text:'Prevalência (%)'},
            ticks:{callback:v=>v+'%'}}}
    }
});
new Chart(document.getElementById('g3'),{
    type:'doughnut',
    data:{labels:<?= json_encode($g3_labels) ?>, datasets:[{
        data:<?= json_encode($g3_data) ?>,
        backgroundColor:['#1F4E78','#2E75B6','#9BBB59','#F79646','#8064A2'],
        borderColor:'#fff', borderWidth:2}]},
    options:{plugins:{legend:{position:'bottom',labels:{boxWidth:12}}}}
});

// ========== Gráficos 4, 5, 6 — Correlação clima x prevalência ==========
function corrChart(canvasId, eixoX, dataObj, cor) {
    const el = document.getElementById(canvasId);
    if (!el) return;
    const pts = dataObj.data || [];
    if (pts.length === 0) {
        const ctx = el.getContext('2d');
        ctx.fillStyle = '#888'; ctx.font = '13px Arial'; ctx.textAlign = 'center';
        ctx.fillText('Sem dados climáticos vinculados.', el.width/2, 60);
        return;
    }
    // Limites de eixo dinamicos (evita Y fixo em 100 e X mal escalado)
    const xs   = pts.map(p => p.x);
    const hiAr = (dataObj.errs || []).map(e => e.hi);
    // yPeak ja inclui o IC superior (Wilson) de cada bin
    const yPeak = Math.max(...pts.map(p => p.y), ...(hiAr.length ? hiAr : [0]));
    const xmin = Math.min(...xs), xmax = Math.max(...xs);
    const xpad = (xmax - xmin) * 0.08 || 1;
    const maxN = Math.max(...pts.map(p => p.n || 1), 1);
    const niceY = m => { if (m <= 0) return 10; const t = m * 1.1;
        for (const c of [5,10,15,20,25,30,40,50,60,70,80,90,100,105,110,115]) if (c >= t) return c; return 115; };
    // Teto dinamico: prevalencia baixa -> teto justo (<100); quando o pico (incluindo o
    // IC95% superior) encosta em 100, abre folga ate ~115 para o whisker superior aparecer
    // inteiro. A reta de tendencia continua RECORTADA (nao estica o eixo, pois yPeak ignora a trend).
    const yMax = Math.min(115, niceY(yPeak));

    const datasets = [{
        label: eixoX,
        data: pts,
        errs: dataObj.errs,
        backgroundColor: cor + 'cc',
        borderColor: cor,
        // Raio do ponto proporcional ao n do bin (mais dados = ponto maior)
        pointRadius: c => 4 + 9 * Math.sqrt((c.raw.n || 1) / maxN),
        pointHoverRadius: c => 6 + 9 * Math.sqrt((c.raw.n || 1) / maxN),
        showLine: false
    }];
    // Linha de tendencia (OLS ponderado pelo n), se calculada no servidor
    if (dataObj.trend) {
        const s = dataObj.trend.slope, b = dataObj.trend.intercept;
        const x0 = xmin - xpad, x1 = xmax + xpad;
        datasets.push({
            label: 'Tendência (OLS)',
            data: [{x:x0, y:s*x0+b}, {x:x1, y:s*x1+b}],
            borderColor: '#333', borderDash: [6,4], borderWidth: 1.5,
            pointRadius: 0, showLine: true, fill: false
        });
    }
    new Chart(el, {
        type: 'scatter',
        data: { datasets },
        options: {
            plugins: {
                legend: { display: false },
                tooltip: { filter: c => c.datasetIndex === 0, callbacks: {
                    label: c => `${eixoX} = ${c.parsed.x}; prev = ${c.parsed.y.toFixed(1)}% (n=${c.raw.n||'?'})`
                }}
            },
            scales: {
                x: { type: 'linear', title: { display: true, text: eixoX },
                     min: xmin - xpad, max: xmax + xpad },
                y: { title: { display: true, text: 'Prevalência (%)' },
                     beginAtZero: true, max: yMax, ticks: { callback: v => v + '%' } }
            }
        },
        plugins: [errorBarPlugin]
    });
}
corrChart('g4', 'Temp. média (°C)',
    {data: <?= json_encode($binT['data']) ?>, errs: <?= json_encode($binT['errs']) ?>, trend: <?= json_encode($trendT) ?>}, '#dc3545');
corrChart('g5', 'Pluviometria (mm/90d)',
    {data: <?= json_encode($binP['data']) ?>, errs: <?= json_encode($binP['errs']) ?>, trend: <?= json_encode($trendP) ?>}, '#0d6efd');
corrChart('g6', 'Umidade média (%)',
    {data: <?= json_encode($binU['data']) ?>, errs: <?= json_encode($binU['errs']) ?>, trend: <?= json_encode($trendU) ?>}, '#198754');

// ========== Gráfico 13 — Forest plot dos OR do modelo logístico (canvas custom, escala log) ==========
<?php if ($logit !== null): ?>
const forestData = <?= json_encode(array_map(fn($c) => [
    'rotulo' => $c['rotulo'],
    'or'  => round($c['or'], 3),
    'lo'  => round($c['or_lo'], 3),
    'hi'  => round($c['or_hi'], 3),
    'p'   => $c['p'],
], $logit['coef'])) ?>;
function renderForestPlot() {
    const canvas = document.getElementById('g13');
    if (!canvas || !forestData.length) return;
    const parent = canvas.parentElement;
    const padLeft = 196, padRight = 150, padTop = 40, padBottom = 50, rowH = 48;
    const W = canvas.width = Math.max(560, parent.clientWidth - 4);
    const H = canvas.height = padTop + forestData.length * rowH + padBottom;
    const ctx = canvas.getContext('2d');
    ctx.clearRect(0, 0, W, H); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, W, H);
    const plotW = W - padLeft - padRight;
    // Faixa do eixo X em escala log (sempre inclui OR = 1)
    let lo = Math.min(1, ...forestData.map(d => d.lo)) * 0.85;
    let hi = Math.max(1, ...forestData.map(d => d.hi)) * 1.15;
    if (!(lo > 0)) lo = 0.1; if (!(hi > lo)) hi = lo * 10;
    const L = Math.log10(lo), R = Math.log10(hi);
    const xpix = v => padLeft + (Math.log10(Math.max(v, 1e-6)) - L) / (R - L) * plotW;
    // Titulo
    ctx.fillStyle = '#1F4E78'; ctx.font = 'bold 13px sans-serif'; ctx.textAlign = 'center';
    ctx.fillText('OR por +1 desvio-padrão (escala log, IC95% Wald)', W / 2, 20);
    // Grade / ticks log
    const cand = [0.1, 0.2, 0.25, 0.5, 1, 2, 3, 5, 10, 20, 50, 100];
    ctx.font = '11px sans-serif'; ctx.textAlign = 'center';
    cand.forEach(t => {
        if (t < lo || t > hi) return;
        const x = xpix(t), ref = (t === 1);
        ctx.strokeStyle = ref ? '#c0504d' : '#e6e6e6';
        ctx.lineWidth = ref ? 1.6 : 1;
        ctx.setLineDash(ref ? [5, 4] : []);
        ctx.beginPath(); ctx.moveTo(x, padTop - 6); ctx.lineTo(x, H - padBottom + 6); ctx.stroke();
        ctx.setLineDash([]);
        ctx.fillStyle = ref ? '#c0504d' : '#888';
        ctx.fillText(ref ? '1 (sem efeito)' : String(t), x, H - padBottom + 20);
    });
    ctx.fillStyle = '#555'; ctx.font = '11px sans-serif';
    ctx.fillText('Odds Ratio (escala log)', padLeft + plotW / 2, H - 10);
    // Linhas (uma por preditor)
    forestData.forEach((d, i) => {
        const y = padTop + i * rowH + rowH / 2;
        ctx.fillStyle = '#222'; ctx.font = '12px sans-serif'; ctx.textAlign = 'right';
        ctx.fillText(d.rotulo, padLeft - 12, y + 4);
        const xl = xpix(d.lo), xh = xpix(d.hi), xo = xpix(d.or);
        ctx.strokeStyle = '#1F4E78'; ctx.lineWidth = 2;
        ctx.beginPath();
        ctx.moveTo(xl, y); ctx.lineTo(xh, y);
        ctx.moveTo(xl, y - 6); ctx.lineTo(xl, y + 6);
        ctx.moveTo(xh, y - 6); ctx.lineTo(xh, y + 6);
        ctx.stroke();
        const sig = (d.p !== null && d.p < 0.05);
        ctx.fillStyle = sig ? '#157347' : '#1F4E78';
        ctx.beginPath(); ctx.arc(xo, y, 7, 0, 2 * Math.PI); ctx.fill();
        // Valor numerico na margem direita (alinhado)
        ctx.fillStyle = '#333'; ctx.font = (sig ? 'bold ' : '') + '11px sans-serif'; ctx.textAlign = 'left';
        ctx.fillText(`${d.or.toFixed(2)} [${d.lo.toFixed(2)}–${d.hi.toFixed(2)}]${sig ? ' *' : ''}`, W - padRight + 8, y + 4);
    });
}
document.addEventListener('DOMContentLoaded', renderForestPlot);
window.addEventListener('resize', () => setTimeout(renderForestPlot, 100));
<?php endif; ?>


// =================================================================
// Heatmap temporal — clima semanal por fazenda
// =================================================================
let _heatmapChart = null;
let _heatmapAtivo = false;

// Paleta unica verde -> amarelo -> vermelho (HSL hue 120 -> 60 -> 0).
// Recebe valor normalizado v em [0,1], com 0.5 = mediana.
function corVAR(v) {
    if (v == null || isNaN(v)) return '#e6e6e6';
    const t = Math.max(0, Math.min(1, v));
    // 0 -> 120 (verde), 0.5 -> 60 (amarelo), 1 -> 0 (vermelho)
    const hue = 120 * (1 - t);
    return `hsl(${hue}, 75%, 55%)`;
}

async function carregarHeatmapTemporal() {
    try {
        const r = await fetch('api_clima_semanal.php?action=heatmap');
        const d = await r.json();
        if (d.erro) { document.getElementById('info_semanal').textContent='Erro: '+d.erro; return; }
        renderHeatmapTemporal(d);
        const pct = d.total > 0 ? (100*d.cacheadas/d.total).toFixed(1) : 0;
        const bar = document.getElementById('bar_semanal');
        bar.style.width = pct + '%'; bar.textContent = `${d.cacheadas}/${d.total} (${pct}%)`;
        document.getElementById('info_semanal').textContent =
            `Periodo ${d.periodo.inicio} a ${d.periodo.fim} - ${d.semanas.length} semanas, ${d.fazendas.length} fazendas`;
    } catch (e) {
        document.getElementById('info_semanal').textContent='Erro de rede: '+e.message;
    }
}

function renderHeatmapTemporal(d) {
    const el = document.getElementById('heatmap_temporal');
    if (!el) return;
    const ctx = el.getContext('2d');

    const padLeft = 200, padTop = 38, padRight = 30, padBottom = 110;
    const W = el.width = el.parentElement.offsetWidth - 30;
    const nCols = d.semanas.length;
    const nRows = 3 * (1 + d.fazendas.length);  // 3 grupos x (1 header + N fazendas)
    const colW = (W - padLeft - padRight) / nCols;
    const rowH = 22;
    const H = padTop + nRows * rowH + padBottom + 20;
    el.height = H;
    ctx.clearRect(0, 0, W, H);
    // Fundo branco para o JPEG exportado nao ficar transparente
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, W, H);

    // Determinar min/max por variavel
    const stats = { t:{min:1e9,max:-1e9}, p:{min:1e9,max:-1e9}, r:{min:1e9,max:-1e9} };
    d.fazendas.forEach(f => {
        ['t','p','r'].forEach(k => {
            f[k].forEach(v => {
                if (v != null) {
                    stats[k].min = Math.min(stats[k].min, v);
                    stats[k].max = Math.max(stats[k].max, v);
                }
            });
        });
    });

    // Normalizar usando MEDIANA como referencia (assim a mediana fica = amarelo)
    function normalizar(v, vmin, vmed, vmax) {
        if (v == null || isNaN(v)) return null;
        if (v <= vmed) {
            const r = (vmed - vmin); return r > 0 ? 0.5 * (v - vmin) / r : 0.5;
        } else {
            const r = (vmax - vmed); return r > 0 ? 0.5 + 0.5 * (v - vmed) / r : 0.5;
        }
    }
    function medianaArr(arr) {
        const s = arr.filter(v => v != null).sort((a,b)=>a-b);
        if (!s.length) return 0;
        const m = Math.floor(s.length/2);
        return s.length % 2 ? s[m] : (s[m-1]+s[m])/2;
    }
    const meds = {
        t: medianaArr(d.fazendas.flatMap(f => f.t)),
        p: medianaArr(d.fazendas.flatMap(f => f.p)),
        r: medianaArr(d.fazendas.flatMap(f => f.r)),
    };
    const labelVar = { t: 'Temperatura (°C)', p: 'Pluviometria (mm)', r: 'Umidade (%)' };

    // Header — mes/ano (mostra label so na primeira semana do mes)
    // Formato: "01/2026" = janeiro de 2026
    const meses = (d.datas || d.semanas).map(s => {
        if (!s || s.length < 7) return '';
        // Aceita yyyy-mm-dd ou aproveita dd/mm/aa do label
        if (s.indexOf('-') === 4) {
            // yyyy-mm-dd
            return s.substr(5,2) + '/' + s.substr(0,4);  // "01/2026"
        } else if (s.length >= 8) {
            // dd/mm/aa do fallback antigo
            return s.substr(3,2) + '/20' + s.substr(6,2);
        }
        return s;
    });
    ctx.fillStyle = '#333'; ctx.font = 'bold 10px sans-serif'; ctx.textAlign = 'left';
    let mesAnterior = '';
    for (let c = 0; c < nCols; c++) {
        const mAtual = meses[c];
        if (mAtual !== mesAnterior) {
            ctx.save();
            ctx.translate(padLeft + c*colW + 1, padTop - 5);
            ctx.rotate(-Math.PI/6);  // inclinacao 30 graus (suave)
            ctx.fillText(mAtual, 0, 0);
            ctx.restore();
            // Linha vertical fina marcando inicio do mes
            ctx.strokeStyle = '#ccc';
            ctx.lineWidth = 0.5;
            ctx.beginPath();
            ctx.moveTo(padLeft + c*colW, padTop);
            ctx.lineTo(padLeft + c*colW, padTop + nRows * rowH);
            ctx.stroke();
            mesAnterior = mAtual;
        }
    }

    // Render: 3 grupos (uma triade por variavel), 3 fazendas em cada
    let row = 0;
    ['t','p','r'].forEach((k, gi) => {
        // Cabeçalho do grupo (variavel)
        const yHead = padTop + row * rowH;
        ctx.fillStyle = '#1F4E78'; ctx.font = 'bold 12px sans-serif'; ctx.textAlign = 'left';
        ctx.fillText(`◼ ${labelVar[k]} (mediana = ${meds[k].toFixed(1)})`, 8, yHead + rowH/2 + 4);
        // separador visual no canvas (linha azul-claro)
        ctx.strokeStyle = '#2E75B6'; ctx.lineWidth = 1.2;
        ctx.beginPath();
        ctx.moveTo(padLeft - 3, yHead - 1);
        ctx.lineTo(W - padRight, yHead - 1);
        ctx.stroke();
        row++;

        d.fazendas.forEach(f => {
            const y = padTop + row * rowH;
            // label da fazenda
            ctx.fillStyle = '#444'; ctx.font = '11px sans-serif'; ctx.textAlign = 'right';
            ctx.fillText(f.fazenda, padLeft - 8, y + rowH/2 + 3);
            // celulas — cor unica via corVAR e normalizacao por mediana
            const valoresVar = d.fazendas.flatMap(ff => ff[k]).filter(v => v != null);
            const vmin = Math.min(...valoresVar);
            const vmax = Math.max(...valoresVar);
            const vmed = meds[k];
            f[k].forEach((v, c) => {
                const x = padLeft + c * colW;
                if (v == null) {
                    ctx.fillStyle = '#e6e6e6';
                } else {
                    const t = normalizar(v, vmin, vmed, vmax);
                    ctx.fillStyle = corVAR(t);
                }
                ctx.fillRect(x, y, colW - 0.5, rowH - 1);
            });
            row++;
        });
        row += 0.4; // pequeno gap visual entre grupos (sera arredondado)
    });

    // ===== Legenda colorbar visual (3 paineis, um por variavel) =====
    const legendY = padTop + nRows * rowH + 28;
    const tituloVar  = { t: 'Temperatura (°C)', p: 'Pluviometria (mm/sem)', r: 'Umidade (%)' };
    const unidadeVar = { t: '°C',                p: ' mm',                  r: '%' };

    // Texto cabecalho geral
    ctx.fillStyle = '#1F4E78'; ctx.font = 'bold 11px sans-serif'; ctx.textAlign = 'left';
    ctx.fillText('Escala de cor (normalizada por variavel - mediana = amarelo)', padLeft, legendY);

    // Tres colorbars lado a lado ocupando a largura util
    const gapX  = 24;
    const dispW = W - padLeft - padRight;
    const cbW   = (dispW - gapX * 2) / 3;
    const cbH   = 14;
    const cbY   = legendY + 18;
    const desenharColorBar = (gi, vKey) => {
        const x0 = padLeft + gi * (cbW + gapX);
        // Titulo
        ctx.fillStyle = '#444'; ctx.font = 'bold 10px sans-serif'; ctx.textAlign = 'left';
        ctx.fillText(tituloVar[vKey], x0, cbY - 4);
        // Gradiente verde -> amarelo -> vermelho com 11 paradas (consistente com corVAR)
        const grad = ctx.createLinearGradient(x0, 0, x0 + cbW, 0);
        for (let i = 0; i <= 10; i++) {
            grad.addColorStop(i / 10, corVAR(i / 10));
        }
        ctx.fillStyle = grad;
        ctx.fillRect(x0, cbY, cbW, cbH);
        ctx.strokeStyle = '#999'; ctx.lineWidth = 1;
        ctx.strokeRect(x0, cbY, cbW, cbH);
        // Tick da mediana
        ctx.strokeStyle = '#1F4E78'; ctx.lineWidth = 1.5;
        ctx.beginPath();
        ctx.moveTo(x0 + cbW / 2, cbY - 2);
        ctx.lineTo(x0 + cbW / 2, cbY + cbH + 2);
        ctx.stroke();
        // Rotulos numericos: min | mediana | max
        ctx.fillStyle = '#222'; ctx.font = '10px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText(stats[vKey].min.toFixed(1) + unidadeVar[vKey], x0, cbY + cbH + 14);
        ctx.textAlign = 'center';
        ctx.fillStyle = '#1F4E78';
        ctx.fillText('mediana ' + meds[vKey].toFixed(1), x0 + cbW / 2, cbY + cbH + 14);
        ctx.textAlign = 'right';
        ctx.fillStyle = '#222';
        ctx.fillText(stats[vKey].max.toFixed(1) + unidadeVar[vKey], x0 + cbW, cbY + cbH + 14);
        // Rotulos qualitativos abaixo
        ctx.fillStyle = '#777'; ctx.font = 'italic 9px sans-serif';
        ctx.textAlign = 'left';
        ctx.fillText('baixo', x0, cbY + cbH + 26);
        ctx.textAlign = 'right';
        ctx.fillText('alto', x0 + cbW, cbY + cbH + 26);
    };
    desenharColorBar(0, 't');
    desenharColorBar(1, 'p');
    desenharColorBar(2, 'r');

    // Indicador "sem dado" (cinza)
    const swX = padLeft;
    const swY = cbY + cbH + 40;
    ctx.fillStyle = '#e6e6e6';
    ctx.fillRect(swX, swY, 14, 12);
    ctx.strokeStyle = '#999';
    ctx.strokeRect(swX, swY, 14, 12);
    ctx.fillStyle = '#666'; ctx.font = '10px sans-serif'; ctx.textAlign = 'left';
    ctx.fillText('= sem dado / cache nao populado', swX + 20, swY + 9);

    // Tooltip on hover (nova ordem: 3 grupos com header, 3 fazendas em cada)
    el.onmousemove = (ev) => {
        const rect = el.getBoundingClientRect();
        const mx = ev.clientX - rect.left;
        const my = ev.clientY - rect.top;
        const c = Math.floor((mx - padLeft) / colW);
        const rRaw = Math.floor((my - padTop) / rowH);
        if (c < 0 || c >= nCols || rRaw < 0) { el.title = ''; return; }
        // Cada grupo tem 1 (header) + 3 (fazendas) = 4 linhas
        const grupo = Math.floor(rRaw / 4);
        const dentro = rRaw % 4;
        if (grupo < 0 || grupo > 2 || dentro === 0) { el.title = ''; return; }
        const vKey = ['t','p','r'][grupo];
        const fIdx = dentro - 1;
        if (fIdx >= d.fazendas.length) { el.title = ''; return; }
        const f = d.fazendas[fIdx];
        const v = f[vKey][c];
        const semanaLabel = (d.datas ? d.datas[c] : d.semanas[c]);
        el.title = `${labelVar[vKey]} | ${f.fazenda} | semana ${semanaLabel} | valor=${v == null ? 'sem dado' : v.toFixed(1)}`;
    };
}

async function iniciarCargaSemanal() {
    if (_heatmapAtivo) {
        _heatmapAtivo = false;
        document.getElementById('btn_carregar_clima').textContent = 'Continuar carga semanal NASA';
        return;
    }
    _heatmapAtivo = true;
    document.getElementById('btn_carregar_clima').textContent = 'Pausar carga';
    while (_heatmapAtivo) {
        try {
            const r = await fetch('api_clima_semanal.php?action=carregar&chunk=6');
            const d = await r.json();
            if (d.erro) { document.getElementById('info_semanal').textContent='Erro: '+d.erro; break; }
            const pct = d.total > 0 ? (100*d.cacheadas/d.total).toFixed(1) : 0;
            const bar = document.getElementById('bar_semanal');
            bar.style.width = pct + '%'; bar.textContent = `${d.cacheadas}/${d.total} (${pct}%)`;
            document.getElementById('info_semanal').textContent =
                `Carga: ${d.sucesso} sucesso, ${d.falha} falha, ${d.restantes} restantes`;
            // Atualizar visual a cada batch
            await carregarHeatmapTemporal();
            if (d.terminou) {
                _heatmapAtivo = false;
                document.getElementById('btn_carregar_clima').textContent = 'Cache completo';
                document.getElementById('btn_carregar_clima').disabled = true;
                break;
            }
        } catch (e) {
            document.getElementById('info_semanal').textContent='Erro de rede: '+e.message;
            await new Promise(r => setTimeout(r, 2000));
        }
    }
}

// Auto-carregar ao abrir
document.addEventListener('DOMContentLoaded', carregarHeatmapTemporal);

// =================================================================
// TASK 2 - Graficos 7A e 7B: Comparacao metodologica B. bovis
// =================================================================
const g7a_data = {
    labels: ['PCR B. bovis (convencional)', 'LAMP 18S', 'LAMP CytB'],
    datasets: [{
        label: 'Amostras positivas',
        data: [<?= $nPCR ?>, <?= $nLAMP18S ?>, <?= $nLAMPCytB ?>],
        backgroundColor: ['#C0504D', '#9BBB59', '#F79646'],
        borderColor: ['#8B0000', '#5C7A2D', '#B85C00'],
        borderWidth: 1.5,
        maxBarThickness: 56, categoryPercentage: 0.6, barPercentage: 0.8
    }]
};
new Chart(document.getElementById('g7a'), {
    type: 'bar',
    data: g7a_data,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            title: { display: true, text: '(A) Positivos por método (n=<?= $nTotalComp ?>)', font: { size: 13 } },
            tooltip: {
                callbacks: {
                    label: c => {
                        const tot = <?= $nTotalComp ?>;
                        const pct = tot > 0 ? (100 * c.parsed.y / tot).toFixed(1) : 0;
                        return `${c.parsed.y} positivos (${pct}%)`;
                    }
                }
            }
        },
        scales: {
            y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'N amostras positivas' } },
            x: { ticks: { font: { size: 10 }, maxRotation: 25, minRotation: 0 } }
        }
    }
});

// Grafico 7B - Concordancia (categorias mutuamente exclusivas)
const g7b_data = {
    labels: [
        'Negativos\n(todos)',
        'Apenas PCR',
        'Apenas LAMP 18S',
        'Apenas LAMP CytB',
        'PCR + LAMP 18S',
        'PCR + LAMP CytB',
        'LAMP 18S + CytB',
        'Todos os 3'
    ],
    datasets: [{
        label: 'N amostras',
        data: [
            <?= $cat['neg_todos'] ?>,
            <?= $cat['so_pcr'] ?>,
            <?= $cat['so_18s'] ?>,
            <?= $cat['so_cytb'] ?>,
            <?= $cat['pcr_18s'] ?>,
            <?= $cat['pcr_cytb'] ?>,
            <?= $cat['18s_cytb'] ?>,
            <?= $cat['todos'] ?>
        ],
        backgroundColor: [
            '#bbb',     // Negativos
            '#C0504D',  // Apenas PCR
            '#9BBB59',  // Apenas 18S
            '#F79646',  // Apenas CytB
            '#8064A2',  // PCR+18S
            '#7B0AAF',  // PCR+CytB
            '#2E75B6',  // 18S+CytB
            '#1F4E78'   // Todos
        ],
        borderColor: '#fff',
        borderWidth: 1
    }]
};
new Chart(document.getElementById('g7b'), {
    type: 'bar',
    data: g7b_data,
    options: {
        responsive: true,
        maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: { display: false },
            title: { display: true, text: '(B) Concordância entre os 3 métodos', font: { size: 13 } },
            tooltip: {
                callbacks: {
                    label: c => {
                        const tot = <?= $nTotalComp ?>;
                        const pct = tot > 0 ? (100 * c.parsed.x / tot).toFixed(1) : 0;
                        return `${c.parsed.x} amostras (${pct}%)`;
                    }
                }
            }
        },
        scales: {
            x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'N amostras' } },
            y: { ticks: { font: { size: 10 } } }
        }
    }
});


// =================================================================
// ITEM 1 - GRAFICO 8 - Coinfeccao (UpSet-style, barras horizontais)
// =================================================================
new Chart(document.getElementById('g8'), {
    type: 'bar',
    data: {
        labels: [
            'Negativos para todos',
            'Apenas B. bovis',
            'Apenas A. marginale',
            'Apenas T. vivax',
            'B. bovis + A. marginale',
            'B. bovis + T. vivax',
            'A. marginale + T. vivax',
            'Triplo positivo'
        ],
        datasets: [{
            label: 'N amostras',
            data: [
                <?= $coinf['neg'] ?>,
                <?= $coinf['so_bb'] ?>,
                <?= $coinf['so_am'] ?>,
                <?= $coinf['so_tv'] ?>,
                <?= $coinf['bb_am'] ?>,
                <?= $coinf['bb_tv'] ?>,
                <?= $coinf['am_tv'] ?>,
                <?= $coinf['todos'] ?>
            ],
            backgroundColor: [
                '#bbb', '#C0504D', '#9BBB59', '#F79646',
                '#8064A2', '#7B0AAF', '#2E75B6', '#1F4E78'
            ],
            borderColor: '#fff', borderWidth: 1
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        indexAxis: 'y',
        plugins: {
            legend: { display: false },
            title: { display: true, text: 'Coinfecção entre B. bovis, A. marginale e T. vivax (n=<?= $nTotalCo ?>)' },
            tooltip: {
                callbacks: {
                    label: c => {
                        const tot = <?= $nTotalCo ?>;
                        const pct = tot > 0 ? (100 * c.parsed.x / tot).toFixed(1) : 0;
                        return `${c.parsed.x} amostras (${pct}%)`;
                    }
                }
            }
        },
        scales: { x: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'N amostras' } } }
    }
});

// =================================================================
// ITEM 2 - GRAFICO 9A: Estados longitudinais (stacked) e 9B: distribuicao N amostras por animal
// =================================================================
const estadosBB = <?= json_encode($estadoBB) ?>;
const estadosAM = <?= json_encode($estadoAM) ?>;
const estadosTV = <?= json_encode($estadoTV) ?>;
const labelsEstado = ['Coleta única','Sempre negativo','Sempre positivo','Soroconversor','Aclarado','Oscilante'];
// Okabe-Ito (CVD-safe). Coleta unica = cinza neutro (proposital).
// Estados em cores fortes; amarelo (#F0E442) evitado como fundo de barra (baixo contraste).
const cmapEstado = ['#bbb','#009E73','#D55E00','#0072B2','#56B4E9','#CC79A7'];
const keysEstado = ['unica_coleta','sempre_neg','sempre_pos','soroconv','aclarado','oscilante'];

new Chart(document.getElementById('g9a'), {
    type: 'bar',
    data: {
        labels: ['B. bovis','A. marginale','T. vivax'],
        datasets: keysEstado.map((k,i) => ({
            label: labelsEstado[i],
            data: [estadosBB[k], estadosAM[k], estadosTV[k]],
            backgroundColor: cmapEstado[i],
            borderColor: '#fff', borderWidth: 1,
            maxBarThickness: 64
        }))
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } },
            title: { display: true, text: '(A) Estados longitudinais por animal (N=<?= $nAnimaisLongi ?>)' }
        },
        scales: {
            x: { stacked: true, categoryPercentage: 0.6 },
            y: { stacked: true, beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'N animais' } }
        }
    }
});

// 9B: distribuicao do numero de amostras por animal (cada amostra = 1 data distinta)
const distNCol = <?= json_encode($distNColetas) ?>;
new Chart(document.getElementById('g9b'), {
    type: 'bar',
    data: {
        labels: Object.keys(distNCol).map(k => k + ' amostra' + (k>1?'s':'')),
        datasets: [{
            label: 'N animais',
            data: Object.values(distNCol),
            backgroundColor: '#2E75B6',
            borderColor: '#1F4E78', borderWidth: 1,
            maxBarThickness: 56, categoryPercentage: 0.6, barPercentage: 0.8
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            title: { display: true, text: '(B) Distribuição de amostras por animal' }
        },
        scales: { y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'N animais' } } }
    }
});

// =================================================================
// GRAFICO 9C: Lasagna plot longitudinal por animal (canvas custom)
// =================================================================
const lasanhaRows = <?= json_encode($lasanhaRows) ?>;
const lasanhaCols = <?= json_encode($lasanhaCols) ?>;
// Cores por status (Okabe-Ito; ausente = cinza neutro)
const corStatus = {
    'neg':     '#e9ecef', // negativo (claro neutro)
    'bb':      '#E69F00', // B. bovis
    'am':      '#56B4E9', // A. marginale
    'tv':      '#009E73', // T. vivax
    'coinf':   '#D55E00', // coinfeccao
    'ausente': '#cfcfcf'  // nao amostrado
};
const rotStatus = {
    'neg':'Negativo','bb':'B. bovis','am':'A. marginale','tv':'T. vivax',
    'coinf':'Coinfecção','ausente':'Não amostrado'
};
let _g9cGeo = null;

function renderLasagna() {
    const canvas = document.getElementById('g9c');
    if (!canvas || !lasanhaRows.length || !lasanhaCols.length) return;
    const parent = canvas.parentElement;
    const nRows = lasanhaRows.length, nCols = lasanhaCols.length;
    const padTop = 64, padLeft = 96, padRight = 12, padBottom = 18;
    const cellH = Math.max(10, Math.min(22, 520 / nRows)); // altura por animal
    const minCellW = 34;
    const Wdisp = Math.max(parent.clientWidth - 4, padLeft + padRight + nCols * minCellW);
    canvas.width  = Wdisp;
    canvas.height = padTop + padBottom + nRows * cellH;
    const ctx = canvas.getContext('2d');
    const cellW = (canvas.width - padLeft - padRight) / nCols;
    _g9cGeo = { padTop, padLeft, cellW, cellH, nRows, nCols };

    ctx.clearRect(0,0,canvas.width,canvas.height);
    ctx.fillStyle = '#fff'; ctx.fillRect(0,0,canvas.width,canvas.height);

    // Cabecalho de colunas (ondas)
    ctx.fillStyle = '#1F4E78'; ctx.font = 'bold 11px sans-serif'; ctx.textAlign = 'center';
    for (let j = 0; j < nCols; j++) {
        ctx.fillText(lasanhaCols[j], padLeft + j*cellW + cellW/2, padTop - 8);
    }
    // Linhas (animais) + rotulo
    const mostrarRotulo = cellH >= 11;
    let fazAnterior = null;
    for (let i = 0; i < nRows; i++) {
        const row = lasanhaRows[i];
        const y = padTop + i*cellH;
        if (mostrarRotulo) {
            ctx.fillStyle = '#444'; ctx.font = (cellH<14?'9px':'10px') + ' sans-serif'; ctx.textAlign = 'right';
            ctx.fillText(row.animal, padLeft - 6, y + cellH/2 + 3);
        }
        // separador de fazenda
        if (row.fazenda !== fazAnterior) {
            ctx.strokeStyle = '#1F4E78'; ctx.lineWidth = 1.5;
            ctx.beginPath(); ctx.moveTo(padLeft, y); ctx.lineTo(canvas.width - padRight, y); ctx.stroke();
            fazAnterior = row.fazenda;
        }
        for (let j = 0; j < nCols; j++) {
            const st = row.cells[j];
            const x = padLeft + j*cellW;
            ctx.fillStyle = corStatus[st] || '#fff';
            ctx.fillRect(x, y, cellW - 1, cellH - 1);
        }
    }
    // Legenda
    const ordemLeg = ['neg','bb','am','tv','coinf','ausente'];
    let lx = padLeft, ly = canvas.height - 4;
    ctx.font = '10px sans-serif'; ctx.textBaseline = 'middle';
    for (const st of ordemLeg) {
        ctx.fillStyle = corStatus[st]; ctx.fillRect(lx, ly - 9, 11, 9);
        ctx.strokeStyle = '#999'; ctx.lineWidth = 0.5; ctx.strokeRect(lx, ly - 9, 11, 9);
        ctx.fillStyle = '#333'; ctx.textAlign = 'left';
        ctx.fillText(rotStatus[st], lx + 15, ly - 4);
        lx += 18 + ctx.measureText(rotStatus[st]).width + 16;
    }
    ctx.textBaseline = 'alphabetic';
}

// Tooltip do lasagna (hit-test pela geometria)
function setupLasagnaTooltip() {
    const canvas = document.getElementById('g9c');
    if (!canvas || !lasanhaRows.length) return;
    let tip = document.getElementById('g9c-tip');
    if (!tip) {
        tip = document.createElement('div');
        tip.id = 'g9c-tip';
        tip.style.cssText = 'position:fixed;z-index:9999;pointer-events:none;display:none;'
            + 'background:#1F4E78;color:#fff;padding:6px 9px;border-radius:5px;font-size:12px;'
            + 'box-shadow:0 2px 8px rgba(0,0,0,.25);line-height:1.35;';
        document.body.appendChild(tip);
    }
    canvas.addEventListener('mousemove', (ev) => {
        if (!_g9cGeo) { tip.style.display='none'; return; }
        const rect = canvas.getBoundingClientRect();
        const sx = canvas.width / rect.width, sy = canvas.height / rect.height;
        const cx = (ev.clientX - rect.left) * sx, cy = (ev.clientY - rect.top) * sy;
        const g = _g9cGeo;
        const j = Math.floor((cx - g.padLeft) / g.cellW);
        const i = Math.floor((cy - g.padTop)  / g.cellH);
        if (i < 0 || j < 0 || i >= g.nRows || j >= g.nCols) { tip.style.display='none'; return; }
        const row = lasanhaRows[i];
        const st = row.cells[j];
        tip.innerHTML = '<b>' + row.animal + '</b>'
            + (row.fazenda ? ' · ' + row.fazenda : '')
            + '<br>' + lasanhaCols[j] + ': ' + rotStatus[st];
        tip.style.display = 'block';
        tip.style.left = (ev.clientX + 14) + 'px';
        tip.style.top  = (ev.clientY + 14) + 'px';
    });
    canvas.addEventListener('mouseleave', () => { tip.style.display = 'none'; });
}

document.addEventListener('DOMContentLoaded', () => { renderLasagna(); setupLasagnaTooltip(); });
window.addEventListener('resize', () => { setTimeout(renderLasagna, 100); });

// =================================================================
// ITEM 4 - GRAFICO 10A: histograma tempo deteccao | 10B: barras intensidade
// =================================================================
<?php if ($temTabelaLamp && $nLAMPanalises > 0): ?>
const tempos18s  = <?= json_encode($tempos18s) ?>;
const temposCytb = <?= json_encode($temposCytb) ?>;

// Histograma com bins COMPARTILHADOS entre as duas series.
// (Antes cada serie usava seu proprio min/max -> bins desalinhados no mesmo eixo.)
function histogramasCompartilhados(arrA, arrB, nBins) {
    const todos = arrA.concat(arrB);
    if (!todos.length) return { labels: [], a: [], b: [] };
    const mn = Math.min(...todos), mx = Math.max(...todos);
    const w = (mx - mn) / nBins || 1;
    const labels = [], a = new Array(nBins).fill(0), b = new Array(nBins).fill(0);
    for (let i = 0; i < nBins; i++) {
        const lo = mn + i * w, hi = mn + (i + 1) * w;
        labels.push(`${lo.toFixed(0)}–${hi.toFixed(0)}`);
    }
    const bin = v => Math.min(nBins - 1, Math.max(0, Math.floor((v - mn) / w)));
    arrA.forEach(v => a[bin(v)]++);
    arrB.forEach(v => b[bin(v)]++);
    return { labels, a, b };
}
const hShared = histogramasCompartilhados(tempos18s, temposCytb, 8);

new Chart(document.getElementById('g10a'), {
    type: 'bar',
    data: {
        labels: hShared.labels,
        datasets: [
            { label: 'LAMP 18S', data: hShared.a, backgroundColor: '#9BBB59cc', borderColor: '#5C7A2D', borderWidth: 1 },
            { label: 'LAMP CytB', data: hShared.b, backgroundColor: '#F79646cc', borderColor: '#B85C00', borderWidth: 1 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            title: { display: true, text: '(A) Tempo até detecção (min) — só positivos' }
        },
        scales: {
            x: { title: { display: true, text: 'Faixa de tempo (min)' } },
            y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'N positivos' } }
        }
    }
});

const intens18s  = <?= json_encode($intens18s) ?>;
const intensCytb = <?= json_encode($intensCytb) ?>;
new Chart(document.getElementById('g10b'), {
    type: 'bar',
    data: {
        labels: ['Forte','Médio','Fraco'],
        datasets: [
            { label: 'LAMP 18S',  data: [intens18s.Forte,  intens18s['Médio'],  intens18s.Fraco],
              backgroundColor: '#9BBB59cc', borderColor: '#5C7A2D', borderWidth: 1 },
            { label: 'LAMP CytB', data: [intensCytb.Forte, intensCytb['Médio'], intensCytb.Fraco],
              backgroundColor: '#F79646cc', borderColor: '#B85C00', borderWidth: 1 }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom' },
            title: { display: true, text: '(B) Intensidade do sinal LAMP (forte = alta carga)' }
        },
        scales: {
            x: { title: { display: true, text: 'Intensidade' } },
            y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'N positivos' } }
        }
    }
});
<?php endif; ?>

// =================================================================
// ITEM 9 - GRAFICO 11: Curva ROC dos LAMPs
// =================================================================
const rocPontos = <?= json_encode([
    'so_18s'  => ['x'=>1-$roc['so_18s']['spec'],  'y'=>$roc['so_18s']['sens'],  'rotulo'=>'só 18S',            'cor'=>'#E69F00'],
    'so_cytb' => ['x'=>1-$roc['so_cytb']['spec'], 'y'=>$roc['so_cytb']['sens'], 'rotulo'=>'só CytB',           'cor'=>'#56B4E9'],
    'or'      => ['x'=>1-$roc['or']['spec'],      'y'=>$roc['or']['sens'],      'rotulo'=>'OR (união)',        'cor'=>'#009E73'],
    'and'     => ['x'=>1-$roc['and']['spec'],     'y'=>$roc['and']['sens'],     'rotulo'=>'AND (intersecção)', 'cor'=>'#CC79A7'],
]) ?>;

// Um dataset por criterio (cor distinta -> identificado pela legenda; sem rotulos
// inline sobrepostos). Marcadores com forma distinta reforcam a leitura CVD-safe.
const rocFormas = { 'so_18s':'circle', 'so_cytb':'triangle', 'or':'rect', 'and':'rectRot' };
const rocDatasets = Object.entries(rocPontos).map(([k, p]) => ({
    label: p.rotulo,
    data: [{x: p.x, y: p.y, rotulo: p.rotulo}],
    backgroundColor: p.cor,
    borderColor: '#333', borderWidth: 1,
    pointStyle: rocFormas[k] || 'circle',
    pointRadius: 10, pointHoverRadius: 13,
    showLine: false
}));
rocDatasets.push({
    label: 'Diagonal (acaso, AUC=0,5)',
    data: [{x:0,y:0},{x:1,y:1}],
    backgroundColor: 'transparent', borderColor: '#999',
    borderDash: [6, 4], borderWidth: 1.5,
    pointStyle: false, pointRadius: 0, showLine: true, fill: false
});

new Chart(document.getElementById('g11'), {
    type: 'scatter',
    data: { datasets: rocDatasets },
    options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 10, font: { size: 11 } } },
            title: { display: true, text: 'Curva ROC dos critérios LAMP (AUC empírica = <?= number_format($auc, 3, ".", "") ?>)' },
            tooltip: {
                callbacks: {
                    label: c => {
                        if (c.dataset.label.includes('Diagonal')) return `(${c.parsed.x.toFixed(2)}, ${c.parsed.y.toFixed(2)})`;
                        const r = c.raw;
                        return `${r.rotulo}: sensib=${(r.y*100).toFixed(1)}%, 1−espec=${(r.x*100).toFixed(1)}%`;
                    }
                }
            }
        },
        scales: {
            x: { min: 0, max: 1, title: { display: true, text: '1 − Especificidade (Falsos Positivos)' } },
            y: { min: 0, max: 1, title: { display: true, text: 'Sensibilidade (Verdadeiros Positivos)' } }
        }
    }
});

// =================================================================
// ITEM 10 - GRAFICO 12: Heatmap kappa 5x5 (canvas custom)
// =================================================================
const matrizK   = <?= json_encode($matrizK) ?>;
const matrizKci = <?= json_encode($matrizKci) ?>;
const rotulosK = <?= json_encode(array_map(fn($k)=>$testes5[$k]['rotulo'], $keysT)) ?>;
const keysK    = <?= json_encode($keysT) ?>;
// Geometria do heatmap kappa (preenchida no render; usada pelo hit-test do tooltip)
let _g12geo = null;

// Formata o IC95% do kappa para exibicao com virgula decimal
function fmtKci(ci) {
    if (!ci || ci.lo == null) return null;
    return '[' + ci.lo.toFixed(2).replace('.', ',') + '; ' + ci.hi.toFixed(2).replace('.', ',') + ']';
}

function renderHeatmapKappa() {
    const canvas = document.getElementById('g12');
    if (!canvas) return;
    const parent = canvas.parentElement;
    canvas.width  = parent.clientWidth - 4;
    canvas.height = 420;
    const ctx = canvas.getContext('2d');
    const padTop = 90, padLeft = 160, padRight = 80, padBottom = 30;
    const W = canvas.width, H = canvas.height;
    const n = keysK.length;
    const cellW = (W - padLeft - padRight) / n;
    const cellH = (H - padTop - padBottom) / n;
    _g12geo = { padTop, padLeft, padRight, padBottom, cellW, cellH, n };

    ctx.clearRect(0,0,W,H);
    ctx.fillStyle = '#fff'; ctx.fillRect(0,0,W,H);

    // Cores: kappa 0-1 -> escala verde-claro -> verde-escuro; <0 -> cinza
    function corKappa(k) {
        if (k === null) return '#e6e6e6';
        if (k <= 0) return '#e6e6e6';
        const t = Math.min(1, k);
        const r = Math.round(255 - t * 130);
        const g = Math.round(255 - t * 50);
        return `rgb(${r}, ${g}, ${r})`;
    }

    // Header (colunas) — rotulos rotacionados
    ctx.fillStyle = '#1F4E78'; ctx.font = 'bold 11px sans-serif';
    for (let j = 0; j < n; j++) {
        ctx.save();
        ctx.translate(padLeft + j*cellW + cellW/2, padTop - 8);
        ctx.rotate(-Math.PI/4);
        ctx.textAlign = 'left';
        ctx.fillText(rotulosK[j], 0, 0);
        ctx.restore();
    }
    // Linhas — rotulos a esquerda
    ctx.font = '11px sans-serif'; ctx.textAlign = 'right';
    for (let i = 0; i < n; i++) {
        ctx.fillStyle = '#1F4E78';
        ctx.fillText(rotulosK[i], padLeft - 6, padTop + i*cellH + cellH/2 + 3);
    }
    // Celulas
    for (let i = 0; i < n; i++) {
        for (let j = 0; j < n; j++) {
            const v = matrizK[keysK[i]][keysK[j]];
            const ci = matrizKci[keysK[i]][keysK[j]];
            const x = padLeft + j*cellW, y = padTop + i*cellH;
            ctx.fillStyle = corKappa(v);
            ctx.fillRect(x, y, cellW - 1, cellH - 1);
            ctx.strokeStyle = '#fff'; ctx.lineWidth = 1;
            ctx.strokeRect(x, y, cellW - 1, cellH - 1);
            const corTxt = (v != null && v > 0.5) ? '#fff' : '#222';
            const ciTxt = fmtKci(ci);
            // Valor pontual do kappa
            ctx.fillStyle = corTxt;
            ctx.font = 'bold 13px sans-serif'; ctx.textAlign = 'center';
            const txt = v != null ? v.toFixed(2).replace('.',',') : '-';
            // Se ha IC para desenhar, sobe o ponto para abrir espaco para a 2a linha
            const dyVal = ciTxt ? -4 : 4;
            ctx.fillText(txt, x + cellW/2, y + cellH/2 + dyVal);
            // 2a linha: IC95% abreviado (fora da diagonal)
            if (ciTxt) {
                ctx.font = '9px sans-serif';
                ctx.fillStyle = (v != null && v > 0.5) ? 'rgba(255,255,255,0.85)' : '#555';
                ctx.fillText(ciTxt, x + cellW/2, y + cellH/2 + 12);
            }
        }
    }
    // Legenda colorbar
    const legY = H - 18, legX = padLeft, legW = W - padLeft - padRight - 6;
    const grad = ctx.createLinearGradient(legX, 0, legX + legW, 0);
    grad.addColorStop(0,    '#fff');
    grad.addColorStop(0.5,  corKappa(0.5));
    grad.addColorStop(1,    corKappa(1));
    ctx.fillStyle = grad;
    ctx.fillRect(legX, legY, legW, 8);
    ctx.strokeStyle = '#999'; ctx.strokeRect(legX, legY, legW, 8);
    ctx.fillStyle = '#444'; ctx.font = '10px sans-serif'; ctx.textAlign = 'left';
    ctx.fillText('κ = 0', legX, legY - 2);
    ctx.textAlign = 'center';
    ctx.fillText('κ = 0,5', legX + legW/2, legY - 2);
    ctx.textAlign = 'right';
    ctx.fillText('κ = 1', legX + legW, legY - 2);
}

// Tooltip por hover no heatmap kappa (hit-test pela geometria _g12geo)
function setupHeatmapKappaTooltip() {
    const canvas = document.getElementById('g12');
    if (!canvas) return;
    let tip = document.getElementById('g12-tip');
    if (!tip) {
        tip = document.createElement('div');
        tip.id = 'g12-tip';
        tip.style.cssText = 'position:fixed;z-index:9999;pointer-events:none;display:none;'
            + 'background:#1F4E78;color:#fff;padding:6px 9px;border-radius:5px;font-size:12px;'
            + 'box-shadow:0 2px 8px rgba(0,0,0,.25);max-width:240px;line-height:1.35;';
        document.body.appendChild(tip);
    }
    function celulaEm(ev) {
        if (!_g12geo) return null;
        const rect = canvas.getBoundingClientRect();
        // canvas.width pode diferir do tamanho CSS (devicePixelRatio); escalar
        const sx = canvas.width  / rect.width;
        const sy = canvas.height / rect.height;
        const cx = (ev.clientX - rect.left) * sx;
        const cy = (ev.clientY - rect.top)  * sy;
        const g = _g12geo;
        const j = Math.floor((cx - g.padLeft) / g.cellW);
        const i = Math.floor((cy - g.padTop)  / g.cellH);
        if (i < 0 || j < 0 || i >= g.n || j >= g.n) return null;
        return { i, j };
    }
    canvas.addEventListener('mousemove', (ev) => {
        const cel = celulaEm(ev);
        if (!cel) { tip.style.display = 'none'; return; }
        const { i, j } = cel;
        const v  = matrizK[keysK[i]][keysK[j]];
        const ci = matrizKci[keysK[i]][keysK[j]];
        const kTxt = (v != null) ? v.toFixed(3).replace('.', ',') : '—';
        const ciTxt = fmtKci(ci);
        let html = '<b>' + rotulosK[i] + ' × ' + rotulosK[j] + '</b><br>κ = ' + kTxt;
        if (i === j) html += ' <span style="opacity:.8">(auto-concordância)</span>';
        else if (ciTxt) html += '<br>IC95% ' + ciTxt;
        tip.innerHTML = html;
        tip.style.display = 'block';
        tip.style.left = (ev.clientX + 14) + 'px';
        tip.style.top  = (ev.clientY + 14) + 'px';
    });
    canvas.addEventListener('mouseleave', () => { tip.style.display = 'none'; });
}

document.addEventListener('DOMContentLoaded', () => { renderHeatmapKappa(); setupHeatmapKappaTooltip(); });
window.addEventListener('resize', () => { setTimeout(renderHeatmapKappa, 100); });


// =================================================================
// EMISSAO DE LAUDOS - logica do formulario e geracao
// =================================================================
let _laudoAnimais = [];

// Carregar lista de fazendas ao abrir a pagina
async function _carregarFazendasLaudo() {
    try {
        const fd = new FormData();
        fd.append('action', 'listar_fazendas_laudo');
        const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
        const d = await r.json();
        const sel = document.getElementById('laudo_fazenda');
        sel.innerHTML = '<option value="">Todas as fazendas</option>';
        (d.fazendas || []).forEach(f => {
            const o = document.createElement('option');
            o.value = f; o.textContent = f;
            sel.appendChild(o);
        });
    } catch (e) {
        console.warn('Erro ao carregar fazendas:', e);
    }
}
document.addEventListener('DOMContentLoaded', _carregarFazendasLaudo);

async function buscarAnimaisLaudo() {
    const fazenda = document.getElementById('laudo_fazenda').value;
    const inicio  = document.getElementById('laudo_inicio').value;
    const fim     = document.getElementById('laudo_fim').value;
    if (!inicio || !fim) { alert('Informe o período (data inicial e final).'); return; }

    const fd = new FormData();
    fd.append('action', 'listar_animais_laudo');
    fd.append('fazenda', fazenda);
    fd.append('inicio', inicio);
    fd.append('fim', fim);

    try {
        const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
        const d = await r.json();
        _laudoAnimais = d.animais || [];
        if (_laudoAnimais.length === 0) {
            alert('Nenhum animal encontrado para o período/fazenda selecionados.');
            return;
        }
        renderListaAnimaisLaudo();
        document.getElementById('laudo_animais_container').style.display = 'block';
        document.getElementById('laudo_animais_container').scrollIntoView({behavior:'smooth', block:'start'});
    } catch (e) {
        alert('Erro ao buscar animais: ' + e.message);
    }
}

function renderListaAnimaisLaudo() {
    const grid = document.getElementById('laudo_animais_grid');
    grid.innerHTML = '';
    _laudoAnimais.forEach(a => {
        const temPos = ((+a.pcr_bb) || (+a.pcr_am) || (+a.pcr_tv) ||
                       (+a.lamp_18s) || (+a.lamp_cytb));
        const lbl = document.createElement('label');
        if (temPos) lbl.classList.add('tem-pos');
        lbl.innerHTML = `
            <input type="checkbox" value="${a.codigo_animal}" checked onchange="atualizarResumoLaudo()">
            <span>${temPos ? '🔴' : '⚪'} <b>${a.codigo_animal}</b>
              <small style="color:#666;display:block;">${a.n_amostras} amostra(s) - ${a.sexo || '?'}</small>
            </span>
        `;
        grid.appendChild(lbl);
    });
    atualizarResumoLaudo();
}

function toggleTodosLaudo(checked) {
    document.querySelectorAll('#laudo_animais_grid input[type=checkbox]').forEach(cb => cb.checked = checked);
    atualizarResumoLaudo();
}

function atualizarResumoLaudo() {
    const n = document.querySelectorAll('#laudo_animais_grid input[type=checkbox]:checked').length;
    document.getElementById('laudo_resumo_animais').textContent = n;
}

// ===== Modal: Guia de Medicamentos (renderiza medicamentos_tpb.md) =====
let _markedLoaded = false;
function _carregarMarked() {
    return new Promise((resolve) => {
        if (typeof marked !== 'undefined') { _markedLoaded = true; resolve(); return; }
        const s = document.createElement('script');
        s.src = 'https://cdn.jsdelivr.net/npm/marked@12.0.0/marked.min.js';
        s.onload = () => { _markedLoaded = true; resolve(); };
        s.onerror = () => resolve(); // segue mesmo se falhar (mostra texto puro)
        document.head.appendChild(s);
    });
}

async function abrirGuiaMedicamentos() {
    const modal = document.getElementById('modal-medicamentos');
    const body  = document.getElementById('modal-med-body');
    modal.style.display = 'flex';
    body.innerHTML = '<p style="color:#666;text-align:center;padding:2em;">Carregando documento…</p>';
    try {
        await _carregarMarked();
        const r = await fetch('medicamentos_tpb.md', { cache: 'no-cache' });
        if (!r.ok) throw new Error('Arquivo medicamentos_tpb.md não encontrado (HTTP ' + r.status + ')');
        const md = await r.text();
        if (typeof marked !== 'undefined') {
            body.innerHTML = marked.parse(md);
        } else {
            // Fallback: mostrar como texto pre-formatado se marked.js nao carregou
            body.innerHTML = '<pre style="white-space:pre-wrap;font-family:inherit;">' +
                md.replace(/[&<>]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;'}[c])) + '</pre>';
        }
    } catch (e) {
        body.innerHTML = '<p style="color:#a00;padding:2em;">Erro ao carregar guia: ' + e.message + '</p>';
    }
}
function fecharGuiaMedicamentos() {
    document.getElementById('modal-medicamentos').style.display = 'none';
}
// Fechar com ESC
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') fecharGuiaMedicamentos();
});
// Fechar clicando fora
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('modal-medicamentos');
    if (modal) modal.addEventListener('click', (e) => {
        if (e.target === modal) fecharGuiaMedicamentos();
    });
});

async function gerarLaudo() {
    const fazenda = document.getElementById('laudo_fazenda').value;
    const inicio  = document.getElementById('laudo_inicio').value;
    const fim     = document.getElementById('laudo_fim').value;
    const codigos = Array.from(document.querySelectorAll('#laudo_animais_grid input[type=checkbox]:checked'))
                         .map(cb => cb.value);
    if (codigos.length === 0) { alert('Selecione ao menos um animal.'); return; }

    const fd = new FormData();
    fd.append('action', 'gerar_laudo_html');
    fd.append('fazenda', fazenda);
    fd.append('inicio', inicio);
    fd.append('fim', fim);
    fd.append('codigos', JSON.stringify(codigos));

    try {
        const r = await fetch(window.location.pathname, { method: 'POST', body: fd });
        if (!r.ok) throw new Error('HTTP ' + r.status);
        const html = await r.text();
        document.getElementById('laudo_resultado').innerHTML = html;
        document.getElementById('laudo_resultado_container').style.display = 'block';
        document.getElementById('laudo_resultado_container').scrollIntoView({behavior:'smooth', block:'start'});
    } catch (e) {
        alert('Erro ao gerar laudo: ' + e.message);
    }
}

// =================================================================
// TASK 1 - Funcoes de download (Grafico em JPEG >=300dpi e Tabela em XLS)
// =================================================================

/**
 * Baixa o canvas (Chart.js OU canvas do heatmap) como JPEG em alta resolucao (~330 DPI).
 * Para Chart.js: re-renderiza com devicePixelRatio elevado.
 * Para canvas comum (heatmap): usa offscreen para upscale com smoothing alto.
 */
function baixarGrafico(canvasId, nomeArquivo) {
    const canvas = document.getElementById(canvasId);
    if (!canvas) { alert('Canvas nao encontrado: ' + canvasId); return; }
    const chart = (typeof Chart !== 'undefined') ? Chart.getChart(canvas) : null;

    // Resolucao alvo: 300 DPI. Tela = 96 DPI -> escala = 3.5x
    const escala = 3.5;

    if (chart) {
        // Chart.js: re-render em alta resolucao
        const ratioOriginal = chart.options.devicePixelRatio || window.devicePixelRatio || 1;
        const animOriginal  = chart.options.animation;
        chart.options.devicePixelRatio = escala;
        chart.options.animation = false;
        chart.resize();
        chart.update('none');
        // Esperar render
        setTimeout(() => {
            try {
                exportarCanvasJPEG(canvas, nomeArquivo);
            } finally {
                chart.options.devicePixelRatio = ratioOriginal;
                chart.options.animation = animOriginal;
                chart.resize();
                chart.update('none');
            }
        }, 250);
    } else {
        // Canvas comum (heatmap): upscale via canvas temporario
        const tmp = document.createElement('canvas');
        tmp.width = canvas.width * escala;
        tmp.height = canvas.height * escala;
        const ctx = tmp.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        // Fundo branco (JPEG nao suporta transparencia)
        ctx.fillStyle = '#fff';
        ctx.fillRect(0, 0, tmp.width, tmp.height);
        ctx.drawImage(canvas, 0, 0, tmp.width, tmp.height);
        exportarCanvasJPEG(tmp, nomeArquivo);
    }
}

function exportarCanvasJPEG(canvas, nomeArquivo) {
    // Garantir fundo branco no JPEG (Chart.js renderiza com fundo transparente)
    const tmp = document.createElement('canvas');
    tmp.width = canvas.width;
    tmp.height = canvas.height;
    const ctx = tmp.getContext('2d');
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, tmp.width, tmp.height);
    ctx.drawImage(canvas, 0, 0);
    const dataUrl = tmp.toDataURL('image/jpeg', 0.95);
    // Tentar gravar metadado de DPI no JPEG (APP0 JFIF)
    const dataUrlComDPI = injetarDPIJpeg(dataUrl, 300);
    const link = document.createElement('a');
    link.download = nomeArquivo + '_300dpi.jpeg';
    link.href = dataUrlComDPI;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

/**
 * Injeta resolucao 300 DPI no header JFIF do JPEG (APP0 marker).
 * Visualizadores que respeitam o cabecalho (Photoshop, ImageJ, GIMP) leem 300 DPI.
 */
function injetarDPIJpeg(dataUrl, dpi) {
    try {
        const base = dataUrl.split(',')[1];
        const bin  = atob(base);
        const len  = bin.length;
        const bytes = new Uint8Array(len);
        for (let i = 0; i < len; i++) bytes[i] = bin.charCodeAt(i);
        // Procurar marker APP0 (0xFFE0) no inicio
        if (bytes[0] === 0xFF && bytes[1] === 0xD8 && bytes[2] === 0xFF && bytes[3] === 0xE0) {
            // bytes[13] = unidade (1=DPI), bytes[14..15]=Xdensity, bytes[16..17]=Ydensity
            bytes[13] = 0x01; // DPI
            bytes[14] = (dpi >> 8) & 0xff;
            bytes[15] = dpi & 0xff;
            bytes[16] = (dpi >> 8) & 0xff;
            bytes[17] = dpi & 0xff;
        }
        let s = '';
        for (let i = 0; i < len; i++) s += String.fromCharCode(bytes[i]);
        return 'data:image/jpeg;base64,' + btoa(s);
    } catch (e) {
        console.warn('Falha ao injetar DPI no JPEG:', e);
        return dataUrl;
    }
}

/**
 * Baixa uma tabela HTML como arquivo .xls (compativel com Excel - formato HTML/XML).
 * O Excel abre normalmente arquivos .xls com conteudo HTML.
 */
function baixarTabela(tabelaId, nomeArquivo) {
    const tabela = document.getElementById(tabelaId);
    if (!tabela) { alert('Tabela nao encontrada: ' + tabelaId); return; }

    // Clonar tabela e limpar elementos visuais que nao fazem sentido em planilha
    const clone = tabela.cloneNode(true);
    // Remover badges e tags <br>, mantendo texto
    clone.querySelectorAll('br').forEach(br => br.replaceWith(' | '));

    const css = `
        <style>
            table { border-collapse: collapse; font-family: Arial; }
            th { background-color: #1F4E78; color: white; padding: 8px; border: 1px solid #ccc; }
            td { padding: 6px; border: 1px solid #ccc; }
            caption { font-weight: bold; font-size: 14pt; padding: 6px; }
        </style>
    `;
    const html = `<!DOCTYPE html>
<html xmlns:o="urn:schemas-microsoft-com:office:office"
      xmlns:x="urn:schemas-microsoft-com:office:excel"
      xmlns="http://www.w3.org/TR/REC-html40">
<head>
<meta charset="UTF-8">
<!--[if gte mso 9]><xml>
<x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet>
<x:Name>${nomeArquivo.substring(0,31)}</x:Name>
<x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions>
</x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook>
</xml><![endif]-->
${css}
</head>
<body>${clone.outerHTML}</body></html>`;

    // BOM UTF-8 para garantir acentuacao no Excel
    const bom = '﻿';
    const blob = new Blob([bom + html], { type: 'application/vnd.ms-excel;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = nomeArquivo + '.xls';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

</script>
</body></html>
