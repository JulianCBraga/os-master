<?php
/**
 * OS Master - Gestão e Registo de Equipamentos de Clientes
 * * Este ficheiro implementa o CRUD completo para a tabela 'equipamento',
 * relacionando cada aparelho ao seu respetivo cliente (proprietário), com
 * validações de integridade referencial contra exclusão de itens vinculados a OS.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.0
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

$message = '';
$messageType = ''; // 'success' ou 'danger'

// Captura parâmetros de ação para edição ou eliminação
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// Instância de dados padrão para o formulário
$editData = [
    'aparelho'     => '',
    'marca'        => '',
    'modelo'       => '',
    'numero_serie' => '',
    'id_cliente'   => ''
];

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // Coleta e higienização das entradas
    $aparelho     = trim(filter_input(INPUT_POST, 'aparelho', FILTER_DEFAULT));
    $marca        = trim(filter_input(INPUT_POST, 'marca', FILTER_DEFAULT));
    $modelo       = trim(filter_input(INPUT_POST, 'modelo', FILTER_DEFAULT));
    $numero_serie = trim(filter_input(INPUT_POST, 'numero_serie', FILTER_DEFAULT));
    $id_cliente   = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT);

    // 1. AÇÃO: REGISTAR NOVO EQUIPAMENTO
    if ($formAction === 'create') {
        if (empty($aparelho) || !$id_cliente) {
            $message = 'O nome do aparelho e o cliente proprietário são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                // Inserção segura dos dados do equipamento
                $sqlInsert = "INSERT INTO equipamento (aparelho, marca, modelo, numero_serie, id_cliente) 
                              VALUES (:aparelho, :marca, :modelo, :numero_serie, :id_cliente)";
                $stmtInsert = $pdo->prepare($sqlInsert);
                $stmtInsert->execute([
                    ':aparelho'     => $aparelho,
                    ':marca'        => $marca,
                    ':modelo'       => $modelo,
                    ':numero_serie' => $numero_serie,
                    ':id_cliente'   => $id_cliente
                ]);

                $message = 'Equipamento registado com sucesso e vinculado ao cliente!';
                $messageType = 'success';
                $action = 'list';
            } catch (PDOException $e) {
                $message = 'Erro ao registar o equipamento no servidor.';
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR EQUIPAMENTO EXISTENTE
    if ($formAction === 'update' && $editId) {
        if (empty($aparelho) || !$id_cliente) {
            $message = 'O nome do aparelho e o cliente proprietário são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                $sqlUpdate = "UPDATE equipamento SET 
                                aparelho = :aparelho, 
                                marca = :marca, 
                                modelo = :modelo, 
                                numero_serie = :numero_serie, 
                                id_cliente = :id_cliente 
                              WHERE id_equipamento = :id";
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute([
                    ':aparelho'     => $aparelho,
                    ':marca'        => $marca,
                    ':modelo'       => $modelo,
                    ':numero_serie' => $numero_serie,
                    ':id_cliente'   => $id_cliente,
                    ':id'           => $editId
                ]);

                $message = 'Dados do equipamento atualizados com sucesso!';
                $messageType = 'success';
                $action = 'list';
            } catch (PDOException $e) {
                $message = 'Erro ao atualizar o equipamento no servidor.';
                $messageType = 'danger';
            }
        }
    }
}

// ==========================================================================
// Processamento de Ações de URL (GET)
// ==========================================================================
// 1. CARREGAR DADOS PARA EDIÇÃO
if ($action === 'edit' && $editId) {
    try {
        $stmtEdit = $pdo->prepare("SELECT * FROM equipamento WHERE id_equipamento = :id LIMIT 1");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        
        if ($data) {
            $editData = $data;
        } else {
            $message = 'Equipamento não encontrado.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao obter dados do equipamento para edição.';
        $messageType = 'danger';
    }
}

// 2. EXECUTAR REMOÇÃO DE EQUIPAMENTO (Com verificação de integridade)
if ($action === 'delete' && $editId) {
    try {
        // Validação de integridade referencial: impede exclusão se o aparelho estiver vinculado a alguma OS
        $stmtCheckOS = $pdo->prepare("SELECT COUNT(*) FROM os WHERE id_equipamento = :id");
        $stmtCheckOS->execute([':id' => $editId]);
        
        if ($stmtCheckOS->fetchColumn() > 0) {
            $message = 'Não é possível remover este equipamento pois ele encontra-se associado ao histórico de Ordens de Serviço.';
            $messageType = 'danger';
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM equipamento WHERE id_equipamento = :id");
            $stmtDel->execute([':id' => $editId]);
            $message = 'Equipamento removido com sucesso do sistema!';
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao tentar remover o equipamento selecionado.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte
// ==========================================================================
$clientesList = [];
$equipamentosList = [];

try {
    // 1. Lista apenas Clientes Ativos para alimentar o dropdown de proprietários
    $sqlClientes = "
        SELECT p.id_pessoa, p.nome, p.cpf_cnpj 
        FROM cliente c 
        INNER JOIN pessoa p ON c.id_pessoa = p.id_pessoa 
        WHERE p.status = 1 AND c.status = 1
        ORDER BY p.nome ASC
    ";
    $clientesList = $pdo->query($sqlClientes)->fetchAll();

    // 2. Lista Geral de Equipamentos com junção na tabela Pessoa para exibir o nome do dono
    $sqlEquipamentos = "
        SELECT e.*, p.nome AS cliente_nome, c.status AS cliente_status
        FROM equipamento e 
        INNER JOIN pessoa p ON e.id_cliente = p.id_pessoa
        INNER JOIN cliente c ON e.id_cliente = c.id_pessoa
        ORDER BY e.aparelho ASC
    ";
    $equipamentosList = $pdo->query($sqlEquipamentos)->fetchAll();
} catch (PDOException $e) {
    $message = 'Erro ao processar as listagens de suporte a partir da base de dados.';
    $messageType = 'danger';
}
?>

<!-- Feedback de Mensagens do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Container para Notificações Customizadas (Sem usar alert() nativo) -->
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
            
            <!-- Coluna Esquerda: Listagem de Equipamentos Registados -->
            <div class="card">
                <h2 class="card-title">Equipamentos Cadastrados</h2>
                
                <?php if (empty($equipamentosList)): ?>
                    <p style="color: var(--text-muted); font-size: 14px;">Nenhum equipamento registado no sistema.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th style="width: 70px;">ID</th>
                                    <th>Aparelho / Item</th>
                                    <th>Marca / Modelo</th>
                                    <th>Nº de Série</th>
                                    <th>Proprietário (Cliente)</th>
                                    <th style="width: 150px; text-align: right;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($equipamentosList as $eq): ?>
                                    <tr style="<?php echo ($eq['cliente_status'] == 0) ? 'opacity: 0.6;' : ''; ?>">
                                        <td><?php echo $eq['id_equipamento']; ?></td>
                                        <td><strong><?php echo htmlspecialchars($eq['aparelho']); ?></strong></td>
                                        <td>
                                            <?php echo htmlspecialchars($eq['marca']); ?> 
                                            <?php if (!empty($eq['modelo'])): ?>
                                                <span style="color: var(--text-muted); font-size: 13px;">(<?php echo htmlspecialchars($eq['modelo']); ?>)</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($eq['numero_serie'] !== '' ? $eq['numero_serie'] : 'Não informado'); ?></code></td>
                                        <td>
                                            <span class="badge" style="background-color: var(--info-bg); color: var(--info);">
                                                <?php echo htmlspecialchars($eq['cliente_nome']); ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <a href="index.php?page=equipamentos&action=edit&id=<?php echo $eq['id_equipamento']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Editar
                                            </a>
                                            <button type="button" class="btn btn-danger btn-confirm-action" 
                                                    data-url="index.php?page=equipamentos&action=delete&id=<?php echo $eq['id_equipamento']; ?>" 
                                                    data-text="Tem a certeza que deseja remover o equipamento '<?php echo htmlspecialchars($eq['aparelho']); ?>'?" 
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
                    <h2 class="card-title" style="color: var(--warning);">Editar Equipamento</h2>
                    <form id="formEquipamento" action="index.php?page=equipamentos&action=edit&id=<?php echo $editId; ?>" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="update">
                        
                        <div class="form-group">
                            <label for="id_cliente" class="form-label">Cliente Proprietário</label>
                            <select id="id_cliente" name="id_cliente" class="form-control" required>
                                <option value="">Selecione o proprietário...</option>
                                <?php foreach ($clientesList as $cli): ?>
                                    <option value="<?php echo $cli['id_pessoa']; ?>" <?php echo ($cli['id_pessoa'] == $editData['id_cliente']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cli['nome']) . ' (' . htmlspecialchars($cli['cpf_cnpj']) . ')'; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="aparelho" class="form-label">Nome do Aparelho / Item</label>
                            <input type="text" id="aparelho" name="aparelho" class="form-control" value="<?php echo htmlspecialchars($editData['aparelho']); ?>" placeholder="Ex: Notebook, Smartphone" required>
                        </div>

                        <div class="form-group">
                            <label for="marca" class="form-label">Marca</label>
                            <input type="text" id="marca" name="marca" class="form-control" value="<?php echo htmlspecialchars($editData['marca']); ?>" placeholder="Ex: Dell, Samsung, Apple">
                        </div>

                        <div class="form-group">
                            <label for="modelo" class="form-label">Modelo</label>
                            <input type="text" id="modelo" name="modelo" class="form-control" value="<?php echo htmlspecialchars($editData['modelo']); ?>" placeholder="Ex: Inspiron 15, Galaxy S21">
                        </div>

                        <div class="form-group">
                            <label for="numero_serie" class="form-label">Número de Série (N/S)</label>
                            <input type="text" id="numero_serie" name="numero_serie" class="form-control" value="<?php echo htmlspecialchars($editData['numero_serie']); ?>" placeholder="Ex: BR123456">
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary" style="flex-grow: 1;">Guardar</button>
                            <a href="index.php?page=equipamentos" class="btn btn-secondary">Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <h2 class="card-title">Novo Equipamento</h2>
                    
                    <?php if (empty($clientesList)): ?>
                        <div class="alert alert-danger" style="margin: 0; font-size: 13.5px; line-height: 1.5;">
                            <strong>Aviso Importante:</strong><br>
                            É necessário registar pelo menos um <a href="index.php?page=clientes" style="color: inherit; text-decoration: underline; font-weight: bold;">Cliente</a> ativo antes de registar um equipamento.
                        </div>
                    <?php else: ?>
                        <form id="formEquipamento" action="index.php?page=equipamentos" method="POST" autocomplete="off">
                            <input type="hidden" name="form_action" value="create">
                            
                            <div class="form-group">
                                <label for="id_cliente" class="form-label">Cliente Proprietário</label>
                                <select id="id_cliente" name="id_cliente" class="form-control" required>
                                    <option value="">Selecione o proprietário...</option>
                                    <?php foreach ($clientesList as $cli): ?>
                                        <option value="<?php echo $cli['id_pessoa']; ?>">
                                            <?php echo htmlspecialchars($cli['nome']) . ' (' . htmlspecialchars($cli['cpf_cnpj']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="form-group">
                                <label for="aparelho" class="form-label">Nome do Aparelho / Item</label>
                                <input type="text" id="aparelho" name="aparelho" class="form-control" placeholder="Ex: Notebook, Smartphone" required>
                            </div>

                            <div class="form-group">
                                <label for="marca" class="form-label">Marca</label>
                                <input type="text" id="marca" name="marca" class="form-control" placeholder="Ex: Dell, Samsung, Apple">
                            </div>

                            <div class="form-group">
                                <label for="modelo" class="form-label">Modelo</label>
                                <input type="text" id="modelo" name="modelo" class="form-control" placeholder="Ex: Inspiron 15, Galaxy S21">
                            </div>

                            <div class="form-group">
                                <label for="numero_serie" class="form-label">Número de Série (N/S)</label>
                                <input type="text" id="numero_serie" name="numero_serie" class="form-control" placeholder="Ex: BR123456">
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 24px;">
                                Registar Equipamento
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>

<!-- Lógica JS Avançada de Teclado, Notificações Customizadas e Modal de Confirmação -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formEquipamento');
    const modal = document.getElementById('custom-confirm-modal');
    const modalText = document.getElementById('confirm-modal-text');
    const modalOk = document.getElementById('confirm-modal-ok');
    const modalCancel = document.getElementById('confirm-modal-cancel');

    // 1. IMPEDE A SUBMISSÃO DO FORMULÁRIO AO TECLAR ENTER NOS INPUTS
    if (form) {
        form.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                e.preventDefault();
                
                // Salta para o próximo campo de entrada disponível
                const fields = Array.from(form.querySelectorAll('input, select'));
                const index = fields.indexOf(e.target);
                if (index > -1 && index + 1 < fields.length) {
                    fields[index + 1].focus();
                }
                return false;
            }
        });
    }

    // 2. SISTEMA DE NOTIFICAÇÃO EM TELA (Substituto do alert() nativo)
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

    // 3. VALIDAÇÃO CLIENTE ADICIONAL NO SUBMIT
    if (form) {
        form.addEventListener('submit', function(e) {
            const clienteSelect = document.getElementById('id_cliente');
            const aparelhoInput = document.getElementById('aparelho');

            if (clienteSelect && clienteSelect.value === '') {
                e.preventDefault();
                mostrarNotificacaoErro('Por favor, selecione o cliente proprietário do equipamento.');
                clienteSelect.focus();
                return false;
            }

            if (aparelhoInput && aparelhoInput.value.trim() === '') {
                e.preventDefault();
                mostrarNotificacaoErro('Por favor, informe a descrição ou tipo do aparelho.');
                aparelhoInput.focus();
                return false;
            }
        });
    }

    // 4. CONTROLO DO MODAL DE CONFIRMAÇÃO DE REMOÇÃO (Substituto do confirm() nativo)
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

    // Fecha o modal se clicar fora do conteúdo
    modal.addEventListener('click', function(e) {
        if (e.target === modal) {
            modal.style.display = 'none';
        }
    });
});
</script>
