<?php
// ============================================
// Gerenciamento de Locais
// gerenciar_locais.php
// ============================================

require_once 'config.php';

try {
    $pdo = getDBConnection();
    
    // Buscar todos os locais
    $stmt = $pdo->query("
        SELECT 
            l.*,
            COUNT(DISTINCT a.id) as total_animais,
            COUNT(DISTINCT c.id) as total_coletas
        FROM locais l
        LEFT JOIN animais a ON l.id = a.local_id
        LEFT JOIN coletas c ON l.id = c.local_id
        GROUP BY l.id
        ORDER BY l.ativo DESC, l.nome
    ");
    $locais = $stmt->fetchAll();
    
} catch (Exception $e) {
    $erro = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciar Locais - Sistema de Vigilância</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f5f5; padding: 20px; }
        .container { max-width: 1400px; margin: 0 auto; background: white; padding: 30px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { color: #2d3748; margin-bottom: 10px; }
        .subtitle { color: #718096; margin-bottom: 30px; }
        .btn { padding: 10px 20px; border: none; border-radius: 5px; cursor: pointer; font-weight: 600; text-decoration: none; display: inline-block; margin: 5px; }
        .btn-primary { background: #2f855a; color: white; }
        .btn-danger { background: #e53e3e; color: white; }
        .btn-warning { background: #ed8936; color: white; }
        .btn-secondary { background: #718096; color: white; }
        .btn:hover { opacity: 0.9; transform: translateY(-1px); }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid #e2e8f0; }
        th { background: #2f855a; color: white; font-weight: 600; }
        tr:hover { background: #f7fafc; }
        .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 12px; font-weight: 600; }
        .status-active { background: #c6f6d5; color: #276749; }
        .status-inactive { background: #fed7d7; color: #c53030; }
        .alert { padding: 15px; border-radius: 5px; margin-bottom: 20px; }
        .alert-error { background: #fed7d7; color: #c53030; border-left: 4px solid #e53e3e; }
        .alert-success { background: #c6f6d5; color: #276749; border-left: 4px solid #2f855a; }
        .info-box { background: #e6fffa; border-left: 4px solid #2f855a; padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .actions { display: flex; gap: 5px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>📍 Gerenciar Locais</h1>
        <p class="subtitle">Visualize, edite e exclua locais de coleta</p>
        
        <div style="margin-bottom: 20px;">
            <a href="forms.php" class="btn btn-secondary">← Voltar</a>
            <a href="dashboard.php" class="btn btn-secondary">📈 Dashboard</a>
        </div>
        
        <?php if (isset($erro)): ?>
            <div class="alert alert-error">
                ⚠️ Erro: <?php echo htmlspecialchars($erro); ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <strong>ℹ️ Dica:</strong> 
            Locais com dados vinculados (animais ou coletas) não podem ser excluídos, apenas desativados.
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Município</th>
                    <th>Coordenadas</th>
                    <th>Animais</th>
                    <th>Coletas</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($locais as $local): ?>
                <tr>
                    <td><?php echo $local['id']; ?></td>
                    <td><strong><?php echo htmlspecialchars($local['nome']); ?></strong></td>
                    <td><?php echo htmlspecialchars($local['municipio']); ?></td>
                    <td>
                        <?php if ($local['latitude'] && $local['longitude']): ?>
                            <?php echo number_format($local['latitude'], 6); ?>, 
                            <?php echo number_format($local['longitude'], 6); ?>
                        <?php else: ?>
                            <span style="color: #718096;">Não definidas</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo $local['total_animais']; ?></td>
                    <td><?php echo $local['total_coletas']; ?></td>
                    <td>
                        <?php if ($local['ativo']): ?>
                            <span class="status-badge status-active">Ativo</span>
                        <?php else: ?>
                            <span class="status-badge status-inactive">Inativo</span>
                        <?php endif; ?>
                    </td>
                    <td class="actions">
                        <?php if ($local['ativo']): ?>
                            <button class="btn btn-warning btn-sm" onclick="desativarLocal(<?php echo $local['id']; ?>, '<?php echo htmlspecialchars($local['nome'], ENT_QUOTES); ?>')">
                                 Desativar
                            </button>
                        <?php else: ?>
                            <button class="btn btn-primary btn-sm" onclick="ativarLocal(<?php echo $local['id']; ?>, '<?php echo htmlspecialchars($local['nome'], ENT_QUOTES); ?>')">
                                 Ativar
                            </button>
                        <?php endif; ?>
                        
                        <?php if ($local['total_animais'] == 0 && $local['total_coletas'] == 0): ?>
                            <button class="btn btn-danger btn-sm" onclick="excluirLocal(<?php echo $local['id']; ?>, '<?php echo htmlspecialchars($local['nome'], ENT_QUOTES); ?>')">
                                🗑️ Excluir
                            </button>
                        <?php else: ?>
                            <button class="btn btn-secondary btn-sm" disabled title="Não pode excluir: há dados vinculados">
                                🔒 Bloqueado
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    
    <script>
        function desativarLocal(id, nome) {
            if (!confirm(`Desativar o local "${nome}"?\n\nEle não aparecerá mais nos formulários, mas os dados existentes serão mantidos.`)) {
                return;
            }
            
            fetch('api_locais.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'desativar', id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    alert('✓ Local desativado com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + data.erro);
                }
            })
            .catch(error => {
                alert('Erro ao desativar local: ' + error);
            });
        }
        
        function ativarLocal(id, nome) {
            if (!confirm(`Ativar o local "${nome}"?`)) {
                return;
            }
            
            fetch('api_locais.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'ativar', id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    alert('✓ Local ativado com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + data.erro);
                }
            })
            .catch(error => {
                alert('Erro ao ativar local: ' + error);
            });
        }
        
        function excluirLocal(id, nome) {
            if (!confirm(`ATENÇÃO: Excluir permanentemente o local "${nome}"?\n\nEsta ação NÃO pode ser desfeita!`)) {
                return;
            }
            
            if (!confirm('Tem certeza absoluta? Digite OK para confirmar.')) {
                return;
            }
            
            fetch('api_locais.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ action: 'excluir', id: id })
            })
            .then(response => response.json())
            .then(data => {
                if (data.sucesso) {
                    alert('✓ Local excluído com sucesso!');
                    location.reload();
                } else {
                    alert('Erro: ' + data.erro);
                }
            })
            .catch(error => {
                alert('Erro ao excluir local: ' + error);
            });
        }
    </script>
</body>
</html>
