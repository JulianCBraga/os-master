<?php
/**
 * OS Master - Gestão e Registo de Produtos (Inventário)
 * * Este ficheiro implementa o CRUD completo para a tabela 'produto',
 * integrando cálculos automáticos de margem de lucro percentual (%), preço de venda e custo,
 * com validação de integridade referencial nas Ordens de Serviço.
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

// Funções auxiliares de formatação de moeda para blindagem de escopo local
if (!function_exists('parseDecimalInput')) {
    function parseDecimalInput($value): float {
        if (empty($value)) return 0.00;
        $clean = str_replace('.', '', $value); // Remove ponto de milhar
        $clean = str_replace(',', '.', $clean); // Substitui vírgula decimal por ponto
        return (float)$clean;
    }
}

if (!function_exists('formatDecimalOutput')) {
    function formatDecimalOutput($value): string {
        return number_format((float)$value, 2, ',', '.');
    }
}

// Determina dinamicamente o símbolo monetário definido nas configurações gerais do sistema.
$currencySymbol = 'R$';
try {
    $stmtCurrency = $pdo->query("SELECT * FROM config_sistema LIMIT 1");
    $sysConfig = $stmtCurrency->fetch();
    if ($sysConfig && isset($sysConfig['moeda']) && !empty($sysConfig['moeda'])) {
        $currencySymbol = $sysConfig['moeda'];
    }
} catch (PDOException $e) {
    // Mantém fallback caso a tabela ou coluna ainda não existam
}

$message = '';
$messageType = ''; // 'success' ou 'danger'

// Captura parâmetros de ação para edição ou eliminação
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// Instância de dados padrão para o formulário
$editData = [
    'descricao'   => '',
    'custo'       => '0,00',
    'lucro_bruto' => '0,00', // Armazenado como percentagem (ex: 50.00 para 50%)
    'valor'       => '0,00',
    'estoque'     => '0'
];

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // Coleta e sanitização de dados do formulário
    $descricao = trim(filter_input(INPUT_POST, 'descricao', FILTER_DEFAULT));
    $custo     = parseDecimalInput($_POST['custo'] ?? '0,00');
    $lucro     = parseDecimalInput($_POST['lucro_bruto'] ?? '0,00'); // Percentual
    $valor     = parseDecimalInput($_POST['valor'] ?? '0,00');
    $estoque   = filter_input(INPUT_POST, 'estoque', FILTER_VALIDATE_INT);

    // 1. AÇÃO: REGISTAR NOVO PRODUTO
    if ($formAction === 'create') {
        if (empty($descricao) || $estoque === false || $estoque < 0) {
            $message = 'A descrição do produto e uma quantidade de estoque válida são campos obrigatórios.';
            $messageType = 'danger';
        } else {
            try {
                // Validação de duplicados pelo nome de descrição
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM produto WHERE descricao = :desc");
                $stmtCheck->execute([':desc' => $descricao]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Já existe um produto registado com esta mesma descrição.';
                    $messageType = 'danger';
                } else {
                    $sqlInsert = "INSERT INTO produto (descricao, custo, lucro_bruto, valor, estoque) 
                                  VALUES (:descricao, :custo, :lucro_bruto, :valor, :estoque)";
                    $stmtInsert = $pdo->prepare($sqlInsert);
                    $stmtInsert->execute([
                        ':descricao'   => $descricao,
                        ':custo'       => $custo,
                        ':lucro_bruto' => $lucro, // Salva o valor percentual limpo (ex: 50.00)
                        ':valor'       => $valor,
                        ':estoque'     => $estoque
                    ]);

                    $message = 'Produto adicionado com sucesso ao inventário!';
                    $messageType = 'success';
                    $action = 'list';
                }
            } catch (PDOException $e) {
                $message = 'Erro ao registar o produto na base de dados.';
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR PRODUTO EXISTENTE
    if ($formAction === 'update' && $editId) {
        if (empty($descricao) || $estoque === false || $estoque < 0) {
            $message = 'A descrição do produto e uma quantidade de estoque válida são campos obrigatórios.';
            $messageType = 'danger';
        } else {
            try {
                // Validação de duplicados excluindo o ID atual
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM produto WHERE descricao = :desc AND id_produto != :id");
                $stmtCheck->execute([':desc' => $descricao, ':id' => $editId]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Já existe outro produto registado com esta mesma descrição.';
                    $messageType = 'danger';
                } else {
                    $sqlUpdate = "UPDATE produto SET 
                                    descricao = :descricao, 
                                    custo = :custo, 
                                    lucro_bruto = :lucro_bruto, 
                                    valor = :valor, 
                                    estoque = :estoque 
                                  WHERE id_produto = :id";
                    $stmtUpdate = $pdo->prepare($sqlUpdate);
                    $stmtUpdate->execute([
                        ':descricao'   => $descricao,
                        ':custo'       => $custo,
                        ':lucro_bruto' => $lucro, // Atualiza o valor percentual
                        ':valor'       => $valor,
                        ':estoque'     => $estoque,
                        ':id'          => $editId
                    ]);

                    $message = 'Dados do produto atualizados com sucesso!';
                    $messageType = 'success';
                    $action = 'list';
                }
            } catch (PDOException $e) {
                $message = 'Erro ao atualizar o produto na base de dados.';
                $messageType = 'danger';
            }
        }
    }
}

// ==========================================================================
// Processamento de Ações de URL (GET)
// ==========================================================================
// 1. CARREGAR DADOS DO PRODUTO PARA EDIÇÃO
if ($action === 'edit' && $editId) {
    try {
        $stmtEdit = $pdo->prepare("SELECT * FROM produto WHERE id_produto = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        
        if ($data) {
            $editData = $data;
            // Formata valores do banco para máscaras visuais nos inputs
            $editData['custo']       = formatDecimalOutput($data['custo']);
            $editData['lucro_bruto'] = formatDecimalOutput($data['lucro_bruto']); // Formata a taxa (ex: 50,00)
            $editData['valor']       = formatDecimalOutput($data['valor']);
        } else {
            $message = 'Produto não encontrado.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar dados do produto para edição.';
        $messageType = 'danger';
    }
}

// 2. EXECUTAR ELIMINAÇÃO DE PRODUTO (Com validação de integridade referencial)
if ($action === 'delete' && $editId) {
    try {
        // Validação de integridade referencial: impede exclusão se o produto estiver lançado em qualquer Item de OS
        $stmtCheckUsage = $pdo->prepare("SELECT COUNT(*) FROM item_os WHERE id_produto = :id");
        $stmtCheckUsage->execute([':id' => $editId]);
        
        if ($stmtCheckUsage->fetchColumn() > 0) {
            $message = 'Não é possível excluir este produto pois ele já se encontra associado ao histórico de Ordens de Serviço.';
            $messageType = 'danger';
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM produto WHERE id_produto = :id");
            $stmtDel->execute([':id' => $editId]);
            $message = 'Produto removido com sucesso do inventário!';
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao tentar remover o produto selecionado.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte
// ==========================================================================
$produtosList = [];
try {
    $stmtList = $pdo->query("SELECT * FROM produto ORDER BY descricao ASC");
    $produtosList = $stmtList->fetchAll();
} catch (PDOException $e) {
    $message = 'Erro ao obter a lista de produtos a partir da base de dados.';
    $messageType = 'danger';
}
?>

<!-- Feedback de Mensagens do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Container de Alertas Customizados (Sem usar alert() nativo) -->
        <div id="custom-alert-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

        <!-- Modal de Confirmação Customizado (Sem usar confirm() nativo) -->
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

        <div style="display: grid; grid-template-columns: 1fr 340px; gap: 32px; align-items: start;">
            
            <!-- Coluna Esquerda: Listagem de Produtos Registados -->
            <div class="card">
                <h2 class="card-title">Produtos em Estoque</h2>
                
                <?php if (empty($produtosList)): ?>
                    <p style="color: var(--text-muted); font-size: 14px;">Nenhum produto cadastrado no inventário.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">ID</th>
                                    <th>Descrição do Produto</th>
                                    <th>Custo</th>
                                    <th>Margem de Lucro Bruto (%)</th>
                                    <th>Preço Venda</th>
                                    <th>Em Estoque</th>
                                    <th style="width: 150px; text-align: right;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($produtosList as $prod): ?>
                                    <tr style="<?php echo ($prod['estoque'] <= 0) ? 'opacity: 0.7; background-color: rgba(239, 68, 68, 0.02);' : ''; ?>">
                                        <td><?php echo $prod['id_produto']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($prod['descricao']); ?></strong></td>
                                        <td><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalOutput($prod['custo']); ?></td>
                                        <td>
                                            <span style="color: var(--text-muted); font-weight: 500;">
                                                <?php echo formatDecimalOutput($prod['lucro_bruto']); ?>%
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge" style="background-color: var(--success-bg); color: var(--success); font-weight: 700;">
                                                <?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatDecimalOutput($prod['valor']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($prod['estoque'] <= 0): ?>
                                                <span class="badge badge-cancelada">Esgotado (0)</span>
                                            <?php else: ?>
                                                <span class="badge badge-finalizada"><?php echo $prod['estoque']; ?> un.</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="index.php?page=produtos&action=edit&id=<?php echo $prod['id_produto']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Editar
                                            </a>
                                            <button type="button" class="btn btn-danger btn-confirm-action" 
                                                    data-url="index.php?page=produtos&action=delete&id=<?php echo $prod['id_produto']; ?>" 
                                                    data-text="Tem a certeza que deseja excluir o produto '<?php echo htmlspecialchars($prod['descricao']); ?>' do sistema?" 
                                                    style="padding: 4px 10px; font-size: 12px;">
                                                Remover
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Coluna Direita: Painel Dinâmico de Ação (Adicionar / Editar) -->
            <div class="card">
                <?php if ($action === 'edit' && $editId): ?>
                    <h2 class="card-title" style="color: var(--warning);">Editar Produto</h2>
                    <form id="formProduto" action="index.php?page=produtos&action=edit&id=<?php echo $editId; ?>" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="update">
                        
                        <div class="form-group">
                            <label for="descricao" class="form-label">Descrição / Nome do Item</label>
                            <input type="text" id="descricao" name="descricao" class="form-control" value="<?php echo htmlspecialchars($editData['descricao']); ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="custo" class="form-label">Preço de Custo (<?php echo htmlspecialchars($currencySymbol); ?>)</label>
                            <input type="text" id="custo" name="custo" class="form-control text-right calc-field" placeholder="0,00" value="<?php echo htmlspecialchars($editData['custo']); ?>" required style="text-align: right;">
                        </div>

                        <div class="form-group">
                            <label for="lucro_bruto" class="form-label">Margem de Lucro Bruto (%)</label>
                            <input type="text" id="lucro_bruto" name="lucro_bruto" class="form-control text-right calc-field" placeholder="0,00" value="<?php echo htmlspecialchars($editData['lucro_bruto']); ?>" required style="text-align: right;">
                        </div>

                        <div class="form-group">
                            <label for="valor" class="form-label">Preço Final de Venda (<?php echo htmlspecialchars($currencySymbol); ?>)</label>
                            <input type="text" id="valor" name="valor" class="form-control text-right calc-field" placeholder="0,00" value="<?php echo htmlspecialchars($editData['valor']); ?>" required style="text-align: right; border-color: var(--success);">
                        </div>

                        <div class="form-group">
                            <label for="estoque" class="form-label">Quantidade em Estoque</label>
                            <input type="number" id="estoque" name="estoque" class="form-control" placeholder="0" value="<?php echo htmlspecialchars($editData['estoque']); ?>" min="0" required>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary" style="flex-grow: 1;">Guardar</button>
                            <a href="index.php?page=produtos" class="btn btn-secondary">Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <h2 class="card-title">Novo Produto</h2>
                    <form id="formProduto" action="index.php?page=produtos" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="create">
                        
                        <div class="form-group">
                            <label for="descricao" class="form-label">Descrição / Nome do Item</label>
                            <input type="text" id="descricao" name="descricao" class="form-control" placeholder="Ex: Ecrã LCD iPhone 13" required>
                        </div>

                        <div class="form-group">
                            <label for="custo" class="form-label">Preço de Custo (<?php echo htmlspecialchars($currencySymbol); ?>)</label>
                            <input type="text" id="custo" name="custo" class="form-control text-right calc-field" placeholder="0,00" required style="text-align: right;">
                        </div>

                        <div class="form-group">
                            <label for="lucro_bruto" class="form-label">Margem de Lucro Bruto (%)</label>
                            <input type="text" id="lucro_bruto" name="lucro_bruto" class="form-control text-right calc-field" placeholder="0,00" required style="text-align: right;">
                        </div>

                        <div class="form-group">
                            <label for="valor" class="form-label">Preço Final de Venda (<?php echo htmlspecialchars($currencySymbol); ?>)</label>
                            <input type="text" id="valor" name="valor" class="form-control text-right calc-field" placeholder="0,00" required style="text-align: right; border-color: var(--success);">
                        </div>

                        <div class="form-group">
                            <label for="estoque" class="form-label">Quantidade em Estoque</label>
                            <input type="number" id="estoque" name="estoque" class="form-control" placeholder="0" min="0" required>
                        </div>

                        <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 24px;">
                            Registar Produto
                        </button>
                    </form>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Lógica JS Avançada de Teclado, Notificação, Máscaras e Cálculos em Tempo Real -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formProduto');
    const modal = document.getElementById('custom-confirm-modal');
    const modalText = document.getElementById('confirm-modal-text');
    const modalOk = document.getElementById('confirm-modal-ok');
    const modalCancel = document.getElementById('confirm-modal-cancel');

    const inputCusto = document.getElementById('custo');
    const inputLucro = document.getElementById('lucro_bruto');
    const inputValor = document.getElementById('valor');

    // 1. IMPEDE A SUBMISSÃO DO FORMULÁRIO AO TECLAR ENTER NOS INPUTS
    if (form) {
        form.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                
                // Salta para o próximo campo disponível
                const fields = Array.from(form.querySelectorAll('input, select'));
                const index = fields.indexOf(e.target);
                if (index > -1 && index + 1 < fields.length) {
                    fields[index + 1].focus();
                }
                return false;
            }
        });
    }

    // 2. SISTEMA DE NOTIFICAÇÃO EM TELA (Livre de alert())
    function mostrarNotificacaoErro(texto) {
        const container = document.getElementById('custom-alert-container');
        const box = document.createElement('div');
        box.style.backgroundColor = '#ef4444';
        box.style.color = '#ffffff';
        box.style.padding = '12px 20px';
        box.style.borderRadius = '6px';
        box.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.4)';
        box.style.fontSize = '14px';
        box.style.fontWeight = '600';
        box.style.display = 'flex';
        box.style.justifyContent = 'space-between';
        box.style.alignItems = 'center';
        box.style.gap = '15px';
        box.style.transition = 'opacity 0.3s ease';
        
        box.innerHTML = `
            <span>⚠️ ${texto}</span>
            <span style="cursor: pointer; font-size: 16px;" onclick="this.parentElement.remove()">×</span>
        `;
        
        container.appendChild(box);
        setTimeout(() => {
            box.style.opacity = '0';
            setTimeout(() => box.remove(), 300);
        }, 5000);
    }

    // 3. MÁSCARA MONETÁRIA E CÁLCULOS DINÂMICOS EM TEMPO REAL
    function stringToFloat(value) {
        if (!value) return 0.00;
        let clean = value.replace(/\D/g, '');
        return parseFloat(clean / 100);
    }

    function floatToString(value) {
        return value.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function formatarCampoMoeda(input) {
        let v = input.value.replace(/\D/g, '');
        if (v === '') {
            input.value = '0,00';
            return;
        }
        v = (v / 100).toFixed(2) + '';
        v = v.replace('.', ',');
        v = v.replace(/(\d)(?=(\d{3})+(?!\d))/g, '$1.');
        input.value = v;
    }

    const calcFields = [inputCusto, inputLucro, inputValor];

    calcFields.forEach(input => {
        if (input) {
            input.addEventListener('focus', function() {
                if (this.value === '' || this.value === '0,00') this.value = '';
            });

            input.addEventListener('input', function() {
                formatarCampoMoeda(this);
                recalcularValores(this.id);
            });

            input.addEventListener('blur', function() {
                if (this.value === '') this.value = '0,00';
            });
        }
    });

    /**
     * Motor de cálculo dinâmico (Utilizando taxa percentual % para lucro_bruto):
     * - Se o utilizador digita no Custo ou na Margem Lucro (%) -> Calcula o Preço Final de Venda automaticamente.
     * Fórmula: Venda = Custo + (Custo * (Lucro_Bruto / 100))
     * - Se o utilizador digita diretamente no Preço de Venda -> Ajusta a Margem Lucro (%) automaticamente.
     * Fórmula: Lucro_Bruto = ((Venda - Custo) / Custo) * 100
     */
    function recalcularValores(activeFieldId) {
        let custo = stringToFloat(inputCusto.value);
        let lucroPercent = stringToFloat(inputLucro.value); // Interpreta a máscara como valor percentual bruto (ex: 50.00)
        let valor = stringToFloat(inputValor.value);

        if (activeFieldId === 'custo' || activeFieldId === 'lucro_bruto') {
            let novoValor = custo + (custo * (lucroPercent / 100));
            inputValor.value = floatToString(novoValor);
        } else if (activeFieldId === 'valor') {
            let novoLucro = 0;
            if (custo > 0) {
                novoLucro = ((valor - custo) / custo) * 100;
            }
            inputLucro.value = floatToString(novoLucro);
        }
    }

    // 4. CONTROLO DO MODAL DE CONFIRMAÇÃO DE REMOÇÃO (Livre de confirm())
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
        modalCancel.addEventListener('click', function() {
            modal.style.display = 'none';
        });
    }

    // Fecha se clicar fora do modal
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>