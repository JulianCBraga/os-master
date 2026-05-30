<?php
/**
 * OS Master - Contas a Receber
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
    echo "<p style='color: var(--text-muted);'>Apenas administradores podem gerir contas a receber.</p>";
    echo "</div>";
    exit;
}

if (!function_exists('parseDecimalReceber')) {
    function parseDecimalReceber($value): float {
        if ($value === null || $value === '') return 0.00;
        $clean = str_replace('.', '', (string)$value);
        $clean = str_replace(',', '.', $clean);
        return (float)$clean;
    }
}

if (!function_exists('formatDecimalReceber')) {
    function formatDecimalReceber($value): string {
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

$message = '';
$messageType = '';
$action = $_GET['action'] ?? 'list';
$editId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$mesRef = filter_input(INPUT_GET, 'mes', FILTER_VALIDATE_INT) ?: (int)date('m');
$anoRef = filter_input(INPUT_GET, 'ano', FILTER_VALIDATE_INT) ?: (int)date('Y');
$statusFiltro = $_GET['status'] ?? 'TODAS';

$editData = [
    'id_categoria' => '',
    'id_cliente' => '',
    'id_os' => '',
    'descricao' => '',
    'data_emissao' => date('Y-m-d'),
    'data_vencimento' => date('Y-m-d'),
    'valor' => '0,00',
    'forma_recebimento' => '',
    'status' => 'ABERTA',
    'data_recebimento' => '',
    'observacoes' => ''
];

$formasRecebimento = ['Dinheiro', 'Pix', 'Cartão de Débito', 'Cartão de Crédito', 'Boleto', 'Transferência', 'Cheque', 'Outro'];
$mesesNomes = [
    1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
    5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
    9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro'
];

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $formAction = $_POST['form_action'] ?? '';
        $contaFields = [
            'id_categoria' => filter_input(INPUT_POST, 'id_categoria', FILTER_VALIDATE_INT) ?: null,
            'id_cliente' => filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT) ?: null,
            'id_os' => filter_input(INPUT_POST, 'id_os', FILTER_VALIDATE_INT) ?: null,
            'descricao' => trim(filter_input(INPUT_POST, 'descricao', FILTER_DEFAULT)),
            'data_emissao' => trim(filter_input(INPUT_POST, 'data_emissao', FILTER_DEFAULT)) ?: null,
            'data_vencimento' => trim(filter_input(INPUT_POST, 'data_vencimento', FILTER_DEFAULT)),
            'valor' => parseDecimalReceber($_POST['valor'] ?? '0,00'),
            'forma_recebimento' => trim(filter_input(INPUT_POST, 'forma_recebimento', FILTER_DEFAULT)),
            'observacoes' => trim(filter_input(INPUT_POST, 'observacoes', FILTER_DEFAULT)),
            'id_usuario' => $_SESSION['user_id'] ?? null
        ];

        if ($contaFields['descricao'] === '' || $contaFields['data_vencimento'] === '' || $contaFields['valor'] <= 0) {
            $message = 'Descrição, vencimento e valor são obrigatórios.';
            $messageType = 'danger';
            $editData = array_merge($editData, $contaFields, ['valor' => $_POST['valor'] ?? '0,00']);
            $action = ($formAction === 'update') ? 'edit' : 'create';
        } elseif ($formAction === 'create') {
            $stmtInsert = $pdo->prepare("
                INSERT INTO conta_receber (id_categoria, id_cliente, id_os, descricao, data_emissao, data_vencimento, valor, forma_recebimento, observacoes, id_usuario)
                VALUES (:id_categoria, :id_cliente, :id_os, :descricao, :data_emissao, :data_vencimento, :valor, :forma_recebimento, :observacoes, :id_usuario)
            ");
            $stmtInsert->execute($contaFields);
            $message = 'Conta a receber cadastrada com sucesso!';
            $messageType = 'success';
            $action = 'list';
        } elseif ($formAction === 'update' && $editId) {
            $stmtUpdate = $pdo->prepare("
                UPDATE conta_receber SET
                    id_categoria = :id_categoria,
                    id_cliente = :id_cliente,
                    id_os = :id_os,
                    descricao = :descricao,
                    data_emissao = :data_emissao,
                    data_vencimento = :data_vencimento,
                    valor = :valor,
                    forma_recebimento = :forma_recebimento,
                    observacoes = :observacoes,
                    id_usuario = :id_usuario
                WHERE id_conta = :id_conta AND status = 'ABERTA'
            ");
            $stmtUpdate->execute($contaFields + ['id_conta' => $editId]);
            $message = 'Conta a receber atualizada com sucesso!';
            $messageType = 'success';
            $action = 'list';
        }
    }

    if ($action === 'receive' && $editId) {
        $stmtConta = $pdo->prepare("
            SELECT cr.*, p.nome AS cliente_nome
            FROM conta_receber cr
            LEFT JOIN pessoa p ON cr.id_cliente = p.id_pessoa
            WHERE cr.id_conta = :id LIMIT 1
        ");
        $stmtConta->execute([':id' => $editId]);
        $conta = $stmtConta->fetch();

        if ($conta && $conta['status'] === 'ABERTA') {
            $pdo->beginTransaction();
            $dataRecebimento = date('Y-m-d');

            $stmtReceive = $pdo->prepare("UPDATE conta_receber SET status = 'RECEBIDA', data_recebimento = :data_recebimento WHERE id_conta = :id");
            $stmtReceive->execute([':data_recebimento' => $dataRecebimento, ':id' => $editId]);

            $stmtCheckMov = $pdo->prepare("SELECT COUNT(*) FROM caixa_movimento WHERE id_conta_receber = :id");
            $stmtCheckMov->execute([':id' => $editId]);
            if ((int)$stmtCheckMov->fetchColumn() === 0) {
                $stmtCaixa = $pdo->prepare("
                    INSERT INTO caixa_movimento (tipo, id_categoria, descricao, valor, data_movimento, forma_pagamento, origem, id_conta_receber, id_os, id_usuario, observacoes)
                    VALUES ('ENTRADA', :id_categoria, :descricao, :valor, :data_movimento, :forma_pagamento, 'CONTA_RECEBER', :id_conta_receber, :id_os, :id_usuario, :observacoes)
                ");
                $stmtCaixa->execute([
                    ':id_categoria' => $conta['id_categoria'],
                    ':descricao' => 'Recebimento: ' . $conta['descricao'],
                    ':valor' => $conta['valor'],
                    ':data_movimento' => $dataRecebimento,
                    ':forma_pagamento' => $conta['forma_recebimento'],
                    ':id_conta_receber' => $editId,
                    ':id_os' => $conta['id_os'],
                    ':id_usuario' => $_SESSION['user_id'] ?? null,
                    ':observacoes' => $conta['cliente_nome'] ? 'Cliente: ' . $conta['cliente_nome'] : null
                ]);
            }

            $pdo->commit();
            $message = 'Recebimento confirmado e lançado no fluxo de caixa.';
            $messageType = 'success';
        }
        $action = 'list';
    }

    if ($action === 'cancel' && $editId) {
        $stmtCancel = $pdo->prepare("UPDATE conta_receber SET status = 'CANCELADA' WHERE id_conta = :id AND status = 'ABERTA'");
        $stmtCancel->execute([':id' => $editId]);
        $message = 'Conta cancelada com sucesso.';
        $messageType = 'success';
        $action = 'list';
    }

    if ($action === 'reopen' && $editId) {
        $pdo->beginTransaction();
        $stmtReopen = $pdo->prepare("UPDATE conta_receber SET status = 'ABERTA', data_recebimento = NULL WHERE id_conta = :id AND status = 'RECEBIDA'");
        $stmtReopen->execute([':id' => $editId]);
        $stmtDeleteMov = $pdo->prepare("DELETE FROM caixa_movimento WHERE id_conta_receber = :id");
        $stmtDeleteMov->execute([':id' => $editId]);
        $pdo->commit();
        $message = 'Recebimento estornado e movimento removido do caixa.';
        $messageType = 'success';
        $action = 'list';
    }

    if ($action === 'edit' && $editId) {
        $stmtEdit = $pdo->prepare("SELECT * FROM conta_receber WHERE id_conta = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        if ($data) {
            $editData = $data;
            $editData['valor'] = formatDecimalReceber($data['valor']);
        } else {
            $message = 'Conta não encontrada.';
            $messageType = 'danger';
            $action = 'list';
        }
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $message = 'Erro financeiro: ' . $e->getMessage();
    $messageType = 'danger';
    $action = 'list';
}

$categorias = [];
$clientesList = [];
$contasList = [];
$totais = ['aberto' => 0, 'recebido' => 0, 'vencido' => 0, 'cancelado' => 0];

try {
    $stmtCategorias = $pdo->query("
        SELECT id_categoria, nome
        FROM financeiro_categoria
        WHERE ativo = 1 AND tipo IN ('ENTRADA', 'AMBOS')
        ORDER BY nome ASC
    ");
    $categorias = $stmtCategorias->fetchAll();

    $stmtClientes = $pdo->query("
        SELECT p.id_pessoa, p.nome, IFNULL(p.cpf_cnpj, '') AS cpf_cnpj
        FROM pessoa p
        INNER JOIN cliente c ON p.id_pessoa = c.id_pessoa
        WHERE p.status = 1 AND c.status = 1
        ORDER BY p.nome ASC
    ");
    $clientesList = $stmtClientes->fetchAll();

    $where = "WHERE MONTH(cr.data_vencimento) = :mes AND YEAR(cr.data_vencimento) = :ano";
    $params = [':mes' => $mesRef, ':ano' => $anoRef];
    if (in_array($statusFiltro, ['ABERTA', 'RECEBIDA', 'CANCELADA'], true)) {
        $where .= " AND cr.status = :status";
        $params[':status'] = $statusFiltro;
    }

    $stmtList = $pdo->prepare("
        SELECT cr.*, fc.nome AS categoria_nome, p.nome AS cliente_nome
        FROM conta_receber cr
        LEFT JOIN financeiro_categoria fc ON cr.id_categoria = fc.id_categoria
        LEFT JOIN pessoa p ON cr.id_cliente = p.id_pessoa
        {$where}
        ORDER BY cr.data_vencimento ASC, cr.id_conta DESC
    ");
    $stmtList->execute($params);
    $contasList = $stmtList->fetchAll();

    foreach ($contasList as $conta) {
        $valor = (float)$conta['valor'];
        if ($conta['status'] === 'RECEBIDA') {
            $totais['recebido'] += $valor;
        } elseif ($conta['status'] === 'CANCELADA') {
            $totais['cancelado'] += $valor;
        } elseif ($conta['data_vencimento'] < date('Y-m-d')) {
            $totais['vencido'] += $valor;
            $totais['aberto'] += $valor;
        } else {
            $totais['aberto'] += $valor;
        }
    }
} catch (PDOException $e) {
    $message = 'As tabelas financeiras ainda não existem. Recrie o banco pelo install.php para ativar este módulo.';
    $messageType = 'danger';
}
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
            <h2 class="module-title">Contas a Receber</h2>
            <p class="module-subtitle">Controle cobranças de clientes, vendas, serviços e recebimentos manuais.</p>
        </div>
        <div class="action-group">
            <a href="index.php?page=fluxo_caixa" class="btn btn-secondary">Fluxo de Caixa</a>
            <a href="index.php?page=contas_receber&action=create&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" class="btn btn-primary">Nova Conta</a>
        </div>
    </div>

    <form method="GET" action="index.php" style="display: grid; grid-template-columns: 1fr 1fr 1fr auto; gap: 16px; align-items: end;">
        <input type="hidden" name="page" value="contas_receber">
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
        <div class="form-group" style="margin-bottom: 0;">
            <label for="status" class="form-label">Status</label>
            <select id="status" name="status" class="form-control">
                <option value="TODAS" <?php echo ($statusFiltro === 'TODAS') ? 'selected' : ''; ?>>Todas</option>
                <option value="ABERTA" <?php echo ($statusFiltro === 'ABERTA') ? 'selected' : ''; ?>>Abertas</option>
                <option value="RECEBIDA" <?php echo ($statusFiltro === 'RECEBIDA') ? 'selected' : ''; ?>>Recebidas</option>
                <option value="CANCELADA" <?php echo ($statusFiltro === 'CANCELADA') ? 'selected' : ''; ?>>Canceladas</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary" style="padding: 12px 22px;">Filtrar</button>
    </form>
</div>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 16px; margin-bottom: 24px;">
    <div class="card" style="margin-bottom: 0; border-left: 4px solid var(--warning);">
        <span class="table-muted-text">A receber</span>
        <h3 style="font-size: 22px; margin-top: 6px;"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalReceber($totais['aberto']); ?></h3>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid var(--danger);">
        <span class="table-muted-text">Vencido</span>
        <h3 style="font-size: 22px; margin-top: 6px; color: var(--danger);"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalReceber($totais['vencido']); ?></h3>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid var(--success);">
        <span class="table-muted-text">Recebido</span>
        <h3 style="font-size: 22px; margin-top: 6px; color: var(--success);"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalReceber($totais['recebido']); ?></h3>
    </div>
    <div class="card" style="margin-bottom: 0; border-left: 4px solid var(--text-muted);">
        <span class="table-muted-text">Cancelado</span>
        <h3 style="font-size: 22px; margin-top: 6px;"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalReceber($totais['cancelado']); ?></h3>
    </div>
</div>

<?php if ($action === 'create' || ($action === 'edit' && $editId)): ?>
    <div class="card" style="margin-bottom: 24px;">
        <div class="module-header">
            <div>
                <h2 class="module-title"><?php echo ($action === 'edit') ? 'Editar Conta a Receber' : 'Nova Conta a Receber'; ?></h2>
                <p class="module-subtitle">Lance cobranças de OS, vendas ou recebimentos manuais.</p>
            </div>
            <a href="index.php?page=contas_receber&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" class="btn btn-secondary">Voltar</a>
        </div>

        <form method="POST" action="index.php?page=contas_receber<?php echo ($action === 'edit') ? '&action=edit&id=' . $editId : ''; ?>&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" autocomplete="off">
            <input type="hidden" name="form_action" value="<?php echo ($action === 'edit') ? 'update' : 'create'; ?>">

            <div class="form-section" style="margin-top: 0;">
                <h3 class="form-section-title">Dados da Cobrança</h3>
                <div class="form-grid form-grid-3">
                    <div class="form-group">
                        <label for="descricao" class="form-label">Descrição</label>
                        <input type="text" id="descricao" name="descricao" class="form-control" value="<?php echo htmlspecialchars($editData['descricao']); ?>" placeholder="Ex: Serviço, venda de peça, entrada de OS" required>
                    </div>
                    <div class="form-group">
                        <label for="id_cliente" class="form-label">Cliente</label>
                        <select id="id_cliente" name="id_cliente" class="form-control">
                            <option value="">Sem cliente vinculado</option>
                            <?php foreach ($clientesList as $cli): ?>
                                <option value="<?php echo $cli['id_pessoa']; ?>" <?php echo ((int)$cli['id_pessoa'] === (int)$editData['id_cliente']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cli['nome'] . ($cli['cpf_cnpj'] ? ' - ' . $cli['cpf_cnpj'] : '')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="id_categoria" class="form-label">Categoria</label>
                        <select id="id_categoria" name="id_categoria" class="form-control">
                            <option value="">Sem categoria</option>
                            <?php foreach ($categorias as $cat): ?>
                                <option value="<?php echo $cat['id_categoria']; ?>" <?php echo ((int)$cat['id_categoria'] === (int)$editData['id_categoria']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($cat['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="form-grid form-grid-3">
                    <div class="form-group">
                        <label for="data_emissao" class="form-label">Emissão</label>
                        <input type="date" id="data_emissao" name="data_emissao" class="form-control" value="<?php echo htmlspecialchars($editData['data_emissao'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label for="data_vencimento" class="form-label">Vencimento</label>
                        <input type="date" id="data_vencimento" name="data_vencimento" class="form-control" value="<?php echo htmlspecialchars($editData['data_vencimento'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label for="valor" class="form-label">Valor (<?php echo htmlspecialchars($currencySymbol); ?>)</label>
                        <input type="text" id="valor" name="valor" class="form-control money-input" value="<?php echo htmlspecialchars($editData['valor']); ?>" required style="text-align: right;">
                    </div>
                </div>

                <div class="form-grid form-grid-3">
                    <div class="form-group">
                        <label for="forma_recebimento" class="form-label">Forma de Recebimento</label>
                        <select id="forma_recebimento" name="forma_recebimento" class="form-control">
                            <option value="">A definir</option>
                            <?php foreach ($formasRecebimento as $forma): ?>
                                <option value="<?php echo htmlspecialchars($forma); ?>" <?php echo ($editData['forma_recebimento'] === $forma) ? 'selected' : ''; ?>><?php echo htmlspecialchars($forma); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="id_os" class="form-label">OS vinculada</label>
                        <input type="number" id="id_os" name="id_os" class="form-control" value="<?php echo htmlspecialchars($editData['id_os'] ?? ''); ?>" min="1" placeholder="Opcional">
                    </div>
                    <div class="form-group">
                        <label for="observacoes" class="form-label">Observações</label>
                        <input type="text" id="observacoes" name="observacoes" class="form-control" value="<?php echo htmlspecialchars($editData['observacoes'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <a href="index.php?page=contas_receber&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" class="btn btn-secondary">Cancelar</a>
                <button type="submit" class="btn btn-primary">Salvar Conta</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<div class="card">
    <h2 class="card-title">Recebimentos de <?php echo htmlspecialchars($mesesNomes[$mesRef]); ?> de <?php echo $anoRef; ?></h2>
    <?php if (empty($contasList)): ?>
        <p style="color: var(--text-muted); font-size: 14px;">Nenhuma conta a receber encontrada para este período.</p>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Descrição</th>
                        <th>Cliente</th>
                        <th>Categoria</th>
                        <th>Vencimento</th>
                        <th style="text-align: right;">Valor</th>
                        <th>Status</th>
                        <th style="width: 250px; text-align: right;">Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($contasList as $conta): ?>
                        <?php
                            $isVencida = $conta['status'] === 'ABERTA' && $conta['data_vencimento'] < date('Y-m-d');
                            $statusLabel = $conta['status'] === 'RECEBIDA' ? 'Recebida' : ($conta['status'] === 'CANCELADA' ? 'Cancelada' : ($isVencida ? 'Vencida' : 'Aberta'));
                            $badgeClass = $conta['status'] === 'RECEBIDA' ? 'badge-finalizada' : ($conta['status'] === 'CANCELADA' ? 'badge-cancelada' : ($isVencida ? 'badge-cancelada' : ''));
                        ?>
                        <tr style="<?php echo ($conta['status'] === 'CANCELADA') ? 'opacity: 0.6;' : ''; ?>">
                            <td>
                                <div class="table-primary-text"><?php echo htmlspecialchars($conta['descricao']); ?></div>
                                <div class="table-muted-text">#<?php echo (int)$conta['id_conta']; ?><?php echo $conta['forma_recebimento'] ? ' - ' . htmlspecialchars($conta['forma_recebimento']) : ''; ?></div>
                            </td>
                            <td><?php echo htmlspecialchars($conta['cliente_nome'] ?: 'Não informado'); ?></td>
                            <td><?php echo htmlspecialchars($conta['categoria_nome'] ?: 'Sem categoria'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($conta['data_vencimento'])); ?></td>
                            <td style="text-align: right; font-weight: 700;"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalReceber($conta['valor']); ?></td>
                            <td>
                                <?php if ($badgeClass): ?>
                                    <span class="badge <?php echo $badgeClass; ?>"><?php echo $statusLabel; ?></span>
                                <?php else: ?>
                                    <span class="badge" style="background-color: var(--warning-bg); color: var(--warning);"><?php echo $statusLabel; ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="text-align: right;">
                                <div class="action-group">
                                    <?php if ($conta['status'] === 'ABERTA'): ?>
                                        <a href="index.php?page=contas_receber&action=edit&id=<?php echo (int)$conta['id_conta']; ?>&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px;">Editar</a>
                                        <button type="button" class="btn btn-primary btn-confirm-action" data-url="index.php?page=contas_receber&action=receive&id=<?php echo (int)$conta['id_conta']; ?>&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" data-text="Confirmar recebimento de <?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalReceber($conta['valor']); ?>?" style="padding: 4px 10px; font-size: 12px;">Receber</button>
                                        <button type="button" class="btn btn-danger btn-confirm-action" data-url="index.php?page=contas_receber&action=cancel&id=<?php echo (int)$conta['id_conta']; ?>&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" data-text="Cancelar esta conta a receber?" style="padding: 4px 10px; font-size: 12px;">Cancelar</button>
                                    <?php elseif ($conta['status'] === 'RECEBIDA'): ?>
                                        <button type="button" class="btn btn-secondary btn-confirm-action" data-url="index.php?page=contas_receber&action=reopen&id=<?php echo (int)$conta['id_conta']; ?>&mes=<?php echo $mesRef; ?>&ano=<?php echo $anoRef; ?>" data-text="Estornar este recebimento e remover a entrada do caixa?" style="padding: 4px 10px; font-size: 12px;">Estornar</button>
                                    <?php else: ?>
                                        <span class="table-muted-text">Sem ações</span>
                                    <?php endif; ?>
                                </div>
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
