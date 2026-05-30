<?php
/**
 * OS Master - Gestão de Folha de Pagamento e Comissões
 * * Este ficheiro implementa a folha de pagamento dos funcionários ativos no sistema.
 * Calcula de forma dinâmica as comissões por Peças e Mão de Obra baseadas em Ordens de Serviço
 * finalizadas no período selecionado, permitindo fechar o lançamento e efetuar pagamentos.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.2
 */

// Impede o acesso direto a este ficheiro fora do index.php
if (!defined('BASE_PATH')) {
    header("Location: ../index.php");
    exit;
}

// Verificação de segurança: apenas utilizadores autenticados podem aceder
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit;
}

// Restrição de Perfil (ACL): Apenas o cargo "Administrador" pode gerir a folha de pagamentos
if ($_SESSION['user_role'] !== 'Administrador') {
    echo "<div class='page-container'>";
    echo "<div class='card' style='border-color: var(--danger); background-color: rgba(239, 68, 68, 0.05);'>";
    echo "<h3 style='color: var(--danger); font-weight: 700; margin-bottom: 10px;'>Acesso Negado</h3>";
    echo "<p style='color: var(--text-muted);'>Lamentamos, mas não tem permissões administrativas para gerir a Folha de Pagamento.</p>";
    echo "<p style='margin-top: 15px;'><a href='index.php?page=dashboard' class='btn btn-secondary'>Voltar ao Dashboard</a></p>";
    echo "</div></div>";
    exit;
}

// Auxiliares locais de moeda para evitar conflito de redeclarações
if (!function_exists('formatDecimalFolha')) {
    function formatDecimalFolha($value): string {
        return number_format((float)$value, 2, ',', '.');
    }
}

// Recupera símbolo monetário dinâmico configurado no sistema
$currencySymbol = 'R$';
try {
    $stmtCurrency = $pdo->query("SELECT * FROM config_sistema LIMIT 1");
    $sysConfig = $stmtCurrency->fetch();
    if ($sysConfig && isset($sysConfig['moeda']) && !empty($sysConfig['moeda'])) {
        $currencySymbol = $sysConfig['moeda'];
    }
} catch (PDOException $e) {
    // Fallback silencioso
}

$message = '';
$messageType = '';

// Captura mês e ano de referência selecionados (Padrão: mês e ano atual)
$mesRef = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: (int)date('m');
$anoRef = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');

// ==========================================================================
// Processamento de Ações do Formulário (POST / GET de Pagamentos)
// ==========================================================================
$action = $_GET['action'] ?? 'list';
$targetId = $_GET['id'] ?? null;

// 1. AÇÃO: LANÇAR / FECHAR FOLHA DE UM FUNCIONÁRIO
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['form_action']) && $_POST['form_action'] === 'generate_folha') {
    $id_funcionario = filter_input(INPUT_POST, 'id_funcionario', FILTER_VALIDATE_INT);
    $salario_base   = (float)$_POST['salario_base'];
    $total_comissao = (float)$_POST['total_comissao'];
    $valor_total    = (float)$_POST['valor_total'];

    if ($id_funcionario) {
        try {
            // Verifica se já existe um lançamento para este funcionário nesta data para evitar redundâncias
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM folha_pagto WHERE id_funcionario = :id AND mes_referencia = :mes AND ano_referencia = :ano");
            $stmtCheck->execute([':id' => $id_funcionario, ':mes' => $mesRef, ':ano' => $anoRef]);

            if ($stmtCheck->fetchColumn() > 0) {
                $message = 'A folha de pagamento deste funcionário já se encontra fechada para o período selecionado.';
                $messageType = 'danger';
            } else {
                $sqlInsert = "INSERT INTO folha_pagto (id_funcionario, mes_referencia, ano_referencia, valor_salario_base, total_comissao, valor_total_receber, pago) 
                              VALUES (:id_func, :mes, :ano, :salario, :comissao, :total, 0)";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':id_func'  => $id_funcionario,
                    ':mes'      => $mesRef,
                    ':ano'      => $anoRef,
                    ':salario'  => $salario_base,
                    ':comissao' => $total_comissao,
                    ':total'    => $valor_total
                ]);

                $message = 'Folha de pagamento registada com sucesso!';
                $messageType = 'success';
            }
        } catch (PDOException $e) {
            $message = 'Erro ao registar a folha de pagamento.';
            $messageType = 'danger';
        }
    }
}

// 2. AÇÃO: CONFIRMAR PAGAMENTO DE UMA FOLHA LANÇADA
if ($action === 'pay_folha' && $targetId) {
    try {
        $stmtPay = $pdo->prepare("UPDATE folha_pagto SET pago = 1 WHERE id_folha = :id");
        $stmtPay->execute([':id' => $targetId]);
        $message = 'Pagamento confirmado e folha encerrada!';
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = 'Erro ao processar a confirmação de pagamento.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// 3. AÇÃO: REABRIR / ESTORNAR LANÇAMENTO DA FOLHA
if ($action === 'reopen_folha' && $targetId) {
    try {
        $stmtDel = $pdo->prepare("DELETE FROM folha_pagto WHERE id_folha = :id");
        $stmtDel->execute([':id' => $targetId]);
        $message = 'Lançamento estornado! A folha agora encontra-se aberta para recálculos.';
        $messageType = 'success';
    } catch (PDOException $e) {
        $message = 'Não é possível estornar esta folha pois ela já possui referências de fechamento contabilístico.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte e Cálculo Dinâmico de Comissões
// ==========================================================================
$folhaList = [];
try {
    // RESOLUÇÃO DO BUG HY093: Placeholders nomeados exclusivos para cada trecho da query
    // Mapeia os novos status operacionais: 'reparo_concluido', 'pronto_para_retirada', 'entregue'
    $sqlCalcular = "
        SELECT 
            p.id_pessoa, 
            p.nome, 
            f.cargo, 
            f.salario AS salario_base,
            f.comissao_os,
            f.valor_comissao_os,
            f.comissao_mo,
            f.valor_comissao_mo,
            -- Cálculo da comissão sobre Peças de OS finalizadas
            IFNULL(SUM(CASE WHEN f.comissao_os = 1 THEN o.valor_pecas * (f.valor_comissao_os / 100) ELSE 0 END), 0) AS comissao_pecas_calculada,
            -- Cálculo da comissão sobre Mão de Obra de OS finalizadas
            IFNULL(SUM(CASE WHEN f.comissao_mo = 1 THEN o.valor_mao_obra * (f.valor_comissao_mo / 100) ELSE 0 END), 0) AS comissao_mo_calculada,
            -- Busca lançamentos de folhas salvas de forma estática no período
            fp.id_folha,
            fp.valor_salario_base AS folha_salario,
            fp.total_comissao AS folha_comissao,
            fp.valor_total_receber AS folha_total,
            fp.pago AS folha_pago
        FROM funcionario f
        INNER JOIN pessoa p ON f.id_pessoa = p.id_pessoa
        -- Ligação de Ordens de Serviço filtradas por data de fecho real/atualização
        LEFT JOIN os o ON f.id_pessoa = o.id_tecnico 
            AND o.status IN ('finalizada', 'reparo_concluido', 'pronto_para_retirada', 'entregue')
            AND MONTH(IFNULL(o.updated_at, o.data_abertura)) = :mes_os
            AND YEAR(IFNULL(o.updated_at, o.data_abertura)) = :ano_os
        -- Ligação de Folha de pagamento já gerada estaticamente para o mês/ano
        LEFT JOIN folha_pagto fp ON f.id_pessoa = fp.id_funcionario 
            AND fp.mes_referencia = :mes_fp 
            AND fp.ano_referencia = :ano_fp
        WHERE p.status = 1 AND f.status = 1
        GROUP BY f.id_pessoa, fp.id_folha
        ORDER BY p.nome ASC
    ";

    $stmtCalc = $pdo->prepare($sqlCalcular);
    $stmtCalc->execute([
        ':mes_os' => $mesRef,
        ':ano_os' => $anoRef,
        ':mes_fp' => $mesRef,
        ':ano_fp' => $anoRef
    ]);
    $folhaList = $stmtCalc->fetchAll();

} catch (PDOException $e) {
    $message = 'Erro ao consolidar as comissões a partir da base de dados: ' . $e->getMessage();
    $messageType = 'danger';
}

// Nomes dos meses para o seletor da interface
$mesesNomes = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];
?>
<?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <div id="custom-alert-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

        <div id="custom-confirm-modal" style="display: none; position: fixed; inset: 0; background-color: rgba(15, 23, 42, 0.75); align-items: center; justify-content: center; z-index: 9999; padding: 20px;">
            <div style="background-color: var(--bg-card); border: 1px solid var(--border-color); border-radius: var(--radius-lg); max-width: 440px; width: 100%; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);">
                <h3 style="font-size: 18px; font-weight: 700; margin-bottom: 12px; color: var(--text-main);">Confirmar Ação</h3>
                <p id="confirm-modal-text" style="font-size: 14.5px; color: var(--text-muted); margin-bottom: 24px; line-height: 1.5;"></p>
                <div style="display: flex; justify-content: flex-end; gap: 12px;">
                    <button id="confirm-modal-cancel" class="btn btn-secondary" style="padding: 8px 16px;">Cancelar</button>
                    <a id="confirm-modal-ok" class="btn btn-danger" style="padding: 8px 16px;">Confirmar</a>
                </div>
            </div>
        </div>

        <div class="card" style="margin-bottom: 32px;">
            <h2 class="card-title">Selecionar Período Contabilístico</h2>
            <form method="GET" action="index.php" style="display: flex; gap: 16px; align-items: flex-end;">
                <input type="hidden" name="page" value="folha">
                
                <div class="form-group" style="margin-bottom: 0; flex-grow: 1;">
                    <label for="mes" class="form-label">Mês de Referência</label>
                    <select id="mes" name="mes" class="form-control">
                        <?php foreach ($mesesNomes as $num => $nome): ?>
                            <option value="<?php echo $num; ?>" <?php echo ($num === $mesRef) ? 'selected' : ''; ?>>
                                <?php echo $nome; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom: 0; flex-grow: 1;">
                    <label for="ano" class="form-label">Ano de Referência</label>
                    <select id="ano" name="ano" class="form-control">
                        <?php for ($y = (int)date('Y') - 5; $y <= (int)date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php echo ($y === $anoRef) ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                        <?php endfor; ?>
                    </select>
                </div>

                <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">Filtrar Período</button>
            </form>
        </div>

        <div class="card">
            <h2 class="card-title">Cálculos de Comissões e Vencimentos (<?php echo $mesesNomes[$mesRef] . ' de ' . $anoRef; ?>)</h2>
            
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Funcionário</th>
                            <th>Cargo</th>
                            <th style="text-align: right;">Salário Base</th>
                            <th style="text-align: right;">Comissão (Peças)</th>
                            <th style="text-align: right;">Comissão (Mão de Obra)</th>
                            <th style="text-align: right;">Total Comissões</th>
                            <th style="text-align: right;">Total a Receber</th>
                            <th style="text-align: center;">Status</th>
                            <th style="width: 180px; text-align: right;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($folhaList as $folha): 
                            $isSaved = !empty($folha['id_folha']);
                            $baseSalario = $isSaved ? (float)$folha['folha_salario'] : (float)$folha['salario_base'];
                            
                            if ($isSaved) {
                                $comissaoTotal = (float)$folha['folha_comissao'];
                                $receberTotal = (float)$folha['folha_total'];
                                $isPaid = (int)$folha['folha_pago'] === 1;
                            } else {
                                $comissaoTotal = (float)$folha['comissao_pecas_calculada'] + (float)$folha['comissao_mo_calculada'];
                                $receberTotal = $baseSalario + $comissaoTotal;
                                $isPaid = false;
                            }
                        ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($folha['nome']); ?></strong></td>
                                <td>
                                    <span class="badge" style="background-color: rgba(59, 130, 246, 0.1); color: #3b82f6;">
                                        <?php echo htmlspecialchars($folha['cargo']); ?>
                                    </span>
                                </td>
                                <td style="text-align: right; font-weight: 500;">
                                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalFolha($baseSalario); ?>
                                </td>
                                
                                <td style="text-align: right; color: var(--text-muted);">
                                    <?php if ($isSaved): ?>
                                        <span style="font-size: 13px; color: var(--text-muted);">-- (Fechado)</span>
                                    <?php else: ?>
                                        <?php if ($folha['comissao_os'] == 1): ?>
                                            <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalFolha($folha['comissao_pecas_calculada']); ?>
                                            <small style="display: block; font-size: 11px; color: #10b981;">(<?php echo formatDecimalFolha($folha['valor_comissao_os']); ?>%)</small>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #ef4444; font-style: italic;">Não comissiona</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: right; color: var(--text-muted);">
                                    <?php if ($isSaved): ?>
                                        <span style="font-size: 13px; color: var(--text-muted);">-- (Fechado)</span>
                                    <?php else: ?>
                                        <?php if ($folha['comissao_mo'] == 1): ?>
                                            <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalFolha($folha['comissao_mo_calculada']); ?>
                                            <small style="display: block; font-size: 11px; color: #10b981;">(<?php echo formatDecimalFolha($folha['valor_comissao_mo']); ?>%)</small>
                                        <?php else: ?>
                                            <span style="font-size: 12px; color: #ef4444; font-style: italic;">Não comissiona</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: right; font-weight: 600; color: #2563eb;">
                                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalFolha($comissaoTotal); ?>
                                </td>

                                <td style="text-align: right; font-weight: 800; color: #10b981; font-size: 14.5px;">
                                    <?php echo htmlspecialchars($currencySymbol) . ' ' . formatDecimalFolha($receberTotal); ?>
                                </td>

                                <td style="text-align: center;">
                                    <?php if (!$isSaved): ?>
                                        <span class="badge" style="background-color: rgba(245, 158, 11, 0.1); color: #f59e0b;">Aberto</span>
                                    <?php else: ?>
                                        <?php if ($isPaid): ?>
                                            <span class="badge" style="background-color: rgba(16, 185, 129, 0.1); color: #10b981; font-weight: bold;">Pago</span>
                                        <?php else: ?>
                                            <span class="badge" style="background-color: rgba(59, 130, 246, 0.1); color: #3b82f6; font-weight: bold;">Lançado</span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>

                                <td style="text-align: right;">
                                    <?php if (!$isSaved): ?>
                                        <form method="POST" action="index.php?page=folha&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" style="display: inline;">
                                            <input type="hidden" name="form_action" value="generate_folha">
                                            <input type="hidden" name="id_funcionario" value="<?php echo $folha['id_pessoa']; ?>">
                                            <input type="hidden" name="salario_base" value="<?php echo $baseSalario; ?>">
                                            <input type="hidden" name="total_comissao" value="<?php echo $comissaoTotal; ?>">
                                            <input type="hidden" name="valor_total" value="<?php echo $receberTotal; ?>">
                                            <button type="submit" class="btn btn-primary" style="padding: 4px 10px; font-size: 12px; font-weight: 700;">
                                                Fechar Folha
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <?php if (!$isPaid): ?>
                                            <button type="button" class="btn btn-secondary btn-confirm-action" 
                                                    data-url="index.php?page=folha&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>&action=pay_folha&id=<?php echo $folha['id_folha']; ?>" 
                                                    data-text="Confirmar o pagamento de <?php echo $currencySymbol . ' ' . formatDecimalFolha($receberTotal); ?> para <?php echo htmlspecialchars($folha['nome']); ?>?" 
                                                    style="padding: 4px 10px; font-size: 12px; font-weight: bold; border-color: #10b981; color: #10b981; background: transparent;">
                                                Pagar
                                            </button>
                                        <?php endif; ?>
                                        <button type="button" class="btn btn-danger btn-confirm-action" 
                                                data-url="index.php?page=folha&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>&action=reopen_folha&id=<?php echo $folha['id_folha']; ?>" 
                                                data-text="Deseja estornar o lançamento deste período para o funcionário selecionado?" 
                                                style="padding: 4px 10px; font-size: 12px; margin-left: 4px;">
                                            Estornar
                                        </button>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const modal = document.getElementById('custom-confirm-modal');
    const modalText = document.getElementById('confirm-modal-text');
    const modalOk = document.getElementById('confirm-modal-ok');
    const modalCancel = document.getElementById('custom-confirm-modal');

    document.querySelectorAll('.btn-confirm-action').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            const url = this.getAttribute('data-url');
            const text = this.getAttribute('data-text');

            modalText.textContent = text;
            modalOk.setAttribute('href', url);
            modal.style.display = 'flex';
        });
    });

    if (modalCancel) {
        modalCancel.addEventListener('click', function(e) {
            if (e.target === modal || e.target === document.getElementById('confirm-modal-cancel')) {
                modal.style.display = 'none';
            }
        });
    }
});
</script>
