<?php
/**
 * OS Master - Fluxo de Caixa
 */

if (!defined('BASE_PATH')) {
    header("Location: ../index.php");
    exit;
}

if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit;
}

if ($_SESSION['user_role'] !== 'Administrador') {
    echo "<div class='card' style='border-color: var(--danger); background-color: var(--danger-bg);'>";
    echo "<h3 style='color: var(--danger); font-weight: 700; margin-bottom: 10px;'>Acesso Negado</h3>";
    echo "<p style='color: var(--text-muted);'>Apenas administradores podem visualizar o fluxo de caixa.</p>";
    echo "</div>";
    exit;
}

if (!function_exists('parseDecimalCaixa')) {
    function parseDecimalCaixa($value): float {
        if ($value === null || $value === '') return 0.00;
        $clean = str_replace('.', '', (string)$value);
        $clean = str_replace(',', '.', $clean);
        return (float)$clean;
    }
}

if (!function_exists('formatDecimalCaixa')) {
    function formatDecimalCaixa($value): string {
        return number_format((float)$value, 2, ',', '.');
    }
}

$currencySymbol = 'R$';
try {
    $stmtCurrency = $pdo->query("SELECT moeda FROM config_sistema LIMIT 1");
    $moeda = $stmtCurrency->fetchColumn();
    if ($moeda) {
        $currencySymbol = $moeda;
    }
} catch (PDOException $e) {
    // Fallback silencioso
}

$mesRef = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: (int)date('m');
$anoRef = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');
$mesesNomes = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

$message = '';
$messageType = '';
$action = $_GET['action'] ?? 'list';
$editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$movimentos = [];
$categorias = [];
$totalEntradas = 0.00;
$totalSaidas = 0.00;
$formasPagamento = ['Dinheiro', 'Pix', 'Cartão de Débito', 'Cartão de Crédito', 'Boleto', 'Transferência', 'Cheque', 'Outro'];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['form_action'] ?? '') === 'create_manual') {
        $tipo = $_POST['tipo'] ?? '';
        $idCategoria = filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT) ?: null;
        $descricao = trim(filter_input(INPUT_POST, 'descricao', FILTER_DEFAULT));
        $valor = parseDecimalCaixa($_POST['valor'] ?? '0,00');
        $dataMovimento = trim(filter_input(INPUT_POST, 'data_movimento', FILTER_DEFAULT)) ?: date('Y-m-d');
        $formaPagamento = trim(filter_input(INPUT_POST, 'forma_pagamento', FILTER_DEFAULT));
        $observacoes = trim(filter_input(INPUT_POST, 'observacoes', FILTER_DEFAULT));

        if (!in_array($tipo, ['ENTRADA', 'SAIDA'], true) || $descricao === '' || $valor <= 0) {
            $message = 'Tipo, descrição e valor são obrigatórios para lançar no caixa.';
            $messageType = 'danger';
        } else {
            $stmtInsert = $pdo->prepare("
                INSERT INTO caixa_movimento (tipo, id_categoria, descricao, valor, data_movimento, forma_pagamento, origem, id_usuario, observacoes)
                VALUES (:tipo, :id_categoria, :descricao, :valor, :data_movimento, :forma_pagamento, 'MANUAL', :id_usuario, :observacoes)
            ");
            $stmtInsert->execute([
                ':tipo' => $tipo,
                ':id_categoria' => $idCategoria,
                ':descricao' => $descricao,
                ':valor' => $valor,
                ':data_movimento' => $dataMovimento,
                ':forma_pagamento' => $formaPagamento ?: null,
                ':id_usuario' => $_SESSION['user_id'] ?? null,
                ':observacoes' => $observacoes ?: null
            ]);

            $message = 'Movimento manual lançado no fluxo de caixa.';
            $messageType = 'success';
            $mesRef = (int)date('m', strtotime($dataMovimento));
            $anoRef = (int)date('Y', strtotime($dataMovimento));
        }
    }

    if ($action === 'delete_manual' && $editId) {
        $stmtDelete = $pdo->prepare("DELETE FROM caixa_movimento WHERE id_movimento = :id AND origem = 'MANUAL'");
        $stmtDelete->execute([':id' => $editId]);
        $message = 'Movimento manual removido do caixa.';
        $messageType = 'success';
        $action = 'list';
    }
} catch (PDOException $e) {
    $message = 'Erro ao lançar movimento no caixa: ' . $e->getMessage();
    $messageType = 'danger';
}

try {
    $stmtCategorias = $pdo->query("
        SELECT id_categoria, nome, tipo
        FROM financeiro_categoria
        WHERE ativo = 1
        ORDER BY nome ASC
    ");
    $categorias = $stmtCategorias->fetchAll();

    $stmtMov = $pdo->prepare("
        SELECT cm.*, fc.nome AS categoria_nome
        FROM caixa_movimento cm
        LEFT JOIN financeiro_categoria fc ON cm.id_categoria = fc.id_categoria
        WHERE MONTH(cm.data_movimento) = :mes AND YEAR(cm.data_movimento) = :ano
        ORDER BY cm.data_movimento DESC, cm.id_movimento DESC
    ");
    $stmtMov->execute([':mes' => $mesRef, ':ano' => $anoRef]);
    $movimentos = $stmtMov->fetchAll();

    foreach ($movimentos as $mov) {
        if ($mov['tipo'] === 'ENTRADA') {
            $totalEntradas += (float)$mov['valor'];
        } else {
            $totalSaidas += (float)$mov['valor'];
        }
    }
} catch (PDOException $e) {
    $message = 'As tabelas financeiras ainda não existem. Recrie o banco pelo install.php para ativar este módulo.';
    $messageType = 'danger';
}

$saldo = $totalEntradas - $totalSaidas;
?>

<?php if (!empty($message)): ?>
    <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
        <?php echo htmlspecialchars($message); ?>
    </div>
<?php endif; ?>

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

<div class="card" style="margin-bottom: 24px;">
    <div class="module-header">
        <div>
            <h2 class="module-title">Fluxo de Caixa</h2>
            <p class="module-subtitle">Movimento mensal consolidado a partir de pagamentos e recebimentos baixados.</p>
        </div>
        <div class="action-group">
            <a href="index.php?page=contas_pagar" class="btn btn-secondary">Contas a Pagar</a>
            <a href="index.php?page=contas_receber" class="btn btn-secondary">Contas a Receber</a>
        </div>
    </div>

    <form method="GET" action="index.php" style="display: grid; grid-template-columns: 1fr 1fr auto; gap: 16px; align-items: end;">
        <input type="hidden" name="page" value="fluxo_caixa">
        <div class="form-group" style="margin-bottom: 0;">
            <label for="mes" class="form-label">Mês</label>
            <select id="mes" name="mes" class="form-control">
                <?php foreach ($mesesNomes as $num => $nome): ?>
                    <option value="<?php echo $num; ?>" <?php echo ($num === $mesRef) ? 'selected' : ''; ?>><?php echo htmlspecialchars($nome); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="margin-bottom: 0;">
            <label for="ano" class="form-label">Ano</label>
            <select id="ano" name="ano" class="form-control">
                <?php for ($y = (int)date('Y') - 5; $y <= (int)date('Y') + 1; $y++): ?>
                    <option value="<?php echo $y; ?>" <?php echo ($y === $anoRef) ? 'selected' : ''; ?>><?php echo $y; ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 12px 22px;">Filtrar</button>
    </form>
</div>

<div class="card" style="margin-bottom: 24px;">
    <div class="module-header">
        <div>
            <h2 class="module-title">Lançamento Manual</h2>
            <p class="module-subtitle">Use para entradas ou saídas avulsas que não vieram de contas a pagar ou receber.</p>
        </div>
    </div>

    <form method="POST" action="index.php?page=fluxo_caixa&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" autocomplete="off">
        <input type="hidden" name="form_action" value="create_manual">

        <div class="form-grid form-grid-3">
            <div class="form-group">
                <label for="tipo" class="form-label">Tipo</label>
                <select id="tipo" name="tipo" class="form-control" required>
                    <option value="ENTRADA">Entrada</option>
                    <option value="SAIDA">Saída</option>
                </select>
            </div>
            <div class="form-group">
                <label for="descricao" class="form-label">Descrição</label>
                <input type="text" id="descricao" name="descricao" class="form-control" placeholder="Ex: aporte, ajuste, retirada" required>
            </div>
            <div class="form-group">
                <label for="id_categoria" class="form-label">Categoria</label>
                <select id="id_categoria" name="id_categoria" class="form-control">
                    <option value="">Sem categoria</option>
                    <?php foreach ($categorias as $cat): ?>
                        <option value="<?php echo $cat['id_categoria']; ?>" data-tipo="<?php echo htmlspecialchars($cat['tipo']); ?>">
                            <?php echo htmlspecialchars($cat['nome']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-grid form-grid-3">
            <div class="form-group">
                <label for="data_movimento" class="form-label">Data</label>
                <input type="date" id="data_movimento" name="data_movimento" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
            </div>
            <div class="form-group">
                <label for="valor" class="form-label">Valor (<?php echo htmlspecialchars($currencySymbol); ?>)</label>
                <input type="text" id="valor" name="valor" class="form-control money-input" value="0,00" required style="text-align: right;">
            </div>
            <div class="form-group">
                <label for="forma_pagamento" class="form-label">Forma</label>
                <select id="forma_pagamento" name="forma_pagamento" class="form-control">
                    <option value="">Não informada</option>
                    <?php foreach ($formasPagamento as $forma): ?>
                        <option value="<?php echo htmlspecialchars($forma); ?>"><?php echo htmlspecialchars($forma); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label for="observacoes" class="form-label">Observações</label>
                <input type="text" id="observacoes" name="observacoes" class="form-control">
            </div>
            <div style="display: flex; justify-content: flex-end; align-items: flex-end;">
                <button type="submit" class="btn btn-primary" style="padding: 12px 22px;">Lançar no Caixa</button>
            </div>
        </div>
    </form>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="margin-bottom: 0; border-left: 4px solid var(--success);">
        <span class="table-muted-text">Entradas</span>
        <h3 style="font-size: 24px; margin-top: 6px; color: var(--success);"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalCaixa($totalEntradas); ?></h3>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid var(--danger);">
        <span class="table-muted-text">Saídas</span>
        <h3 style="font-size: 24px; margin-top: 6px; color: var(--danger);"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalCaixa($totalSaidas); ?></h3>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid var(--primary);">
        <span class="table-muted-text">Saldo do mês</span>
        <h3 style="font-size: 24px; margin-top: 6px; color: <?php echo ($saldo < 0) ? 'var(--danger)' : 'var(--success)'; ?>;"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalCaixa($saldo); ?></h3>
    </div>
</div>

<div class="card">
    <h2 class="card-title">Movimentos de <?php echo htmlspecialchars($mesesNomes[$mesRef]); ?> de <?php echo $anoRef; ?></h2>
    <?php if (empty($movimentos)): ?>
        <p style="color: var(--text-muted); font-size: 14px;">Nenhum movimento de caixa encontrado para este período.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Data</th>
                        <th>Descrição</th>
                        <th>Categoria</th>
                        <th>Origem</th>
                        <th>Forma</th>
                        <th style="text-align: right;">Valor</th>
                        <th style="width: 110px; text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($movimentos as $mov): ?>
                        <tr>
                            <td><?php echo date('d/m/Y', strtotime($mov['data_movimento'])); ?></td>
                            <td>
                                <div class="table-primary-text"><?php echo htmlspecialchars($mov['descricao']); ?></div>
                                <div class="table-muted-text">#<?php echo (int)$mov['id_movimento']; ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($mov['categoria_nome'] ?: 'Sem categoria'); ?></td>
                            <td><?php echo htmlspecialchars(str_replace('_', ' ', $mov['origem'])); ?></td>
                            <td><?php echo htmlspecialchars($mov['forma_pagamento'] ?: 'Não informada'); ?></td>
                            <td style="text-align: right; font-weight: 800; color: <?php echo ($mov['tipo'] === 'ENTRADA') ? 'var(--success)' : 'var(--danger)'; ?>;">
                                <?php echo ($mov['tipo'] === 'ENTRADA') ? '+' : '-'; ?> <?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalCaixa($mov['valor']); ?>
                            </td>
                            <td style="text-align: right;">
                                <?php if ($mov['origem'] === 'MANUAL'): ?>
                                    <button type="button" class="btn btn-danger btn-confirm-action"
                                            data-url="index.php?page=fluxo_caixa&action=delete_manual&id=<?php echo (int)$mov['id_movimento']; ?>&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>"
                                            data-text="Remover este movimento manual do caixa?"
                                            style="padding: 4px 10px; font-size: 12px;">
                                        Remover
                                    </button>
                                <?php else: ?>
                                    <span class="table-muted-text">Automático</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.money-input').forEach(input => {
        input.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '');
            if (v === '') {
                this.value = '0,00';
                return;
            }
            v = (Number(v) / 100).toFixed(2).replace('.', ',');
            v = v.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
            this.value = v;
        });
    });

    const tipoSelect = document.getElementById('tipo');
    const categoriaSelect = document.getElementById('id_categoria');

    function filtrarCategorias() {
        if (!tipoSelect || !categoriaSelect) return;
        const tipo = tipoSelect.value;
        Array.from(categoriaSelect.options).forEach(option => {
            const optionTipo = option.getAttribute('data-tipo');
            option.hidden = optionTipo && optionTipo !== 'AMBOS' && optionTipo !== tipo;
        });
        const selected = categoriaSelect.options[categoriaSelect.selectedIndex];
        if (selected && selected.hidden) {
            categoriaSelect.value = '';
        }
    }

    if (tipoSelect) {
        tipoSelect.addEventListener('change', filtrarCategorias);
        filtrarCategorias();
    }

    const modal = document.getElementById('custom-confirm-modal');
    const modalText = document.getElementById('confirm-modal-text');
    const modalOk = document.getElementById('confirm-modal-ok');
    const modalCancel = document.getElementById('confirm-modal-cancel');

    document.querySelectorAll('.btn-confirm-action').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            modalText.textContent = this.getAttribute('data-text');
            modalOk.setAttribute('href', this.getAttribute('data-url'));
            modal.style.display = 'flex';
        });
    });

    if (modalCancel) {
        modalCancel.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    }

    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                modal.style.display = 'none';
            }
        });
    }
});
</script>
