<?php
/**
 * OS Master - Gestão e Registo de Clientes
 * * Este ficheiro implementa o CRUD completo para clientes com herança na tabela base 'pessoa'
 * e máscaras dinâmicas de digitação para CPF/CNPJ, CEP e Contacto.
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

// Carrega a classe de controlo base Pessoa
require_once BASE_PATH . '/classes/Pessoa.php';

$message = '';
$messageType = ''; // 'success' ou 'danger'

// Captura parâmetros de ação para edição ou alteração de status
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// Instância de dados padrão para o formulário
$editData = [
    'tipo_pessoa' => 'FISICA', 'nome' => '', 'cpf_cnpj' => '', 'rg_ie' => '',
    'telefone' => '', 'cep' => '', 'endereco' => '', 'numero' => '',
    'bairro' => '', 'id_cidade' => '', 'status' => 1
];

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // Coleta e higienização dos dados do formulário
    $pessoaFields = [
        'tipo_pessoa' => trim(filter_input(INPUT_POST, 'tipo_pessoa', FILTER_DEFAULT)),
        'nome'        => trim(filter_input(INPUT_POST, 'nome', FILTER_DEFAULT)),
        'cpf_cnpj'    => trim(filter_input(INPUT_POST, 'cpf_cnpj', FILTER_DEFAULT)),
        'rg_ie'       => trim(filter_input(INPUT_POST, 'rg_ie', FILTER_DEFAULT)),
        'telefone'    => trim(filter_input(INPUT_POST, 'telefone', FILTER_DEFAULT)), 
        'cep'         => trim(filter_input(INPUT_POST, 'cep', FILTER_DEFAULT)),
        'endereco'    => trim(filter_input(INPUT_POST, 'endereco', FILTER_DEFAULT)),
        'numero'      => trim(filter_input(INPUT_POST, 'numero', FILTER_DEFAULT)),
        'bairro'      => trim(filter_input(INPUT_POST, 'bairro', FILTER_DEFAULT)),
        'id_cidade'   => filter_input(INPUT_POST, 'id_cidade', FILTER_VALIDATE_INT),
        'status'      => isset($_POST['status']) ? (int)$_POST['status'] : 1
    ];

    // 1. AÇÃO: CRIAR NOVO CLIENTE
    if ($formAction === 'create') {
        if (empty($pessoaFields['nome']) || !$pessoaFields['id_cidade']) {
            $message = 'Nome completo/Razão Social e Cidade são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                // Cria o registo na tabela base 'pessoa' utilizando a classe base Pessoa do Canvas
                $id_pessoa = Pessoa::create($pdo, $pessoaFields);

                // Insere os dados específicos na tabela herdeira 'cliente'
                $sqlCliente = "INSERT INTO cliente (id_pessoa, data_ultima_interacao) VALUES (:id_pessoa, NULL)";
                $stmtCliente = $pdo->prepare($sqlCliente);
                $stmtCliente->execute([':id_pessoa' => $id_pessoa]);

                $pdo->commit();
                $message = 'Cliente registado com sucesso!';
                $messageType = 'success';
                $action = 'list';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR CLIENTE EXISTENTE
    if ($formAction === 'update' && $editId) {
        if (empty($pessoaFields['nome']) || !$pessoaFields['id_cidade']) {
            $message = 'Nome completo/Razão Social e Cidade são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                // Atualiza a tabela base 'pessoa'
                Pessoa::update($pdo, $editId, $pessoaFields);

                $pdo->commit();
                $message = 'Dados do cliente atualizados com sucesso!';
                $messageType = 'success';
                $action = 'list';
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}

// ==========================================================================
// Processamento de Ações de URL (GET)
// ==========================================================================
// 1. CARREGAR DADOS DO CLIENTE PARA EDIÇÃO
if ($action === 'edit' && $editId) {
    try {
        $stmtEdit = $pdo->prepare("
            SELECT p.*, c.data_ultima_interacao 
            FROM pessoa p 
            INNER JOIN cliente c ON p.id_pessoa = c.id_pessoa 
            WHERE p.id_pessoa = :id LIMIT 1
        ");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        
        if ($data) {
            $editData = $data;
        } else {
            $message = 'Cliente não encontrado.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar dados do cliente.';
        $messageType = 'danger';
    }
}

// 2. ATIVAR / INATIVAR CLIENTE (Inativação Lógica)
if ($action === 'toggle_status' && $editId) {
    try {
        $pessoa = Pessoa::getById($pdo, $editId);
        if ($pessoa) {
            $newStatus = ($pessoa['status'] == 1) ? 0 : 1;
            Pessoa::setStatus($pdo, $editId, $newStatus);
            $message = 'Status do cliente alterado com sucesso!';
            $messageType = 'success';
        } else {
            $message = 'Registo não encontrado.';
            $messageType = 'danger';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao alterar o status do cliente.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação das Listas de Suporte
// ==========================================================================
$cidadesList = [];
$clientesList = [];

try {
    // Lista de Cidades com Estados para o dropdown de moradas
    $stmtCid = $pdo->query("
        SELECT c.id_cidade, c.nome AS cidade, e.sigla AS uf 
        FROM cidade c 
        INNER JOIN estado e ON c.id_estado = e.id_estado 
        ORDER BY c.nome ASC
    ");
    $cidadesList = $stmtCid->fetchAll();

    // Listagem Geral de Clientes (Pessoa + Cliente)
    $stmtClientes = $pdo->query("
        SELECT p.id_pessoa, p.tipo_pessoa, p.nome, IFNULL(p.cpf_cnpj, 'Não informado') AS cpf_cnpj, p.telefone, p.status, c.data_ultima_interacao 
        FROM pessoa p 
        INNER JOIN cliente c ON p.id_pessoa = c.id_pessoa 
        ORDER BY p.nome ASC
    ");
    $clientesList = $stmtClientes->fetchAll();
} catch (PDOException $e) {
    $message = 'Erro ao obter dados de suporte da base de dados.';
    $messageType = 'danger';
}
?>

<!-- Feedback de Mensagens do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Container de Alertas Customizados -->
        <div id="custom-alert-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

        <!-- Modal de Confirmação Customizado -->
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

        <div style="display: grid; grid-template-columns: 1fr; gap: 32px;">
            
            <?php if ($action === 'create' || $action === 'edit'): ?>
                <!-- Formulário de Registo / Edição -->
                <div class="card">
                    <h2 class="card-title" style="<?php echo ($action === 'edit') ? 'color: var(--warning);' : ''; ?>">
                        <?php echo ($action === 'edit') ? 'Editar Cliente' : 'Novo Cliente'; ?>
                    </h2>
                    
                    <form id="formCliente" action="index.php?page=clientes<?php echo ($action === 'edit') ? '&action=edit&id=' . $editId : ''; ?>" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="<?php echo ($action === 'edit') ? 'update' : 'create'; ?>">

                        <div style="display: flex; gap: 24px; margin-bottom: 24px; background-color: rgba(255,255,255,0.02); padding: 16px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                            <span class="form-label" style="margin-bottom: 0; align-self: center;">Tipo de Cliente:</span>
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                                <input type="radio" name="tipo_pessoa" value="FISICA" <?php echo ($editData['tipo_pessoa'] === 'FISICA') ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                Pessoa Física (CPF)
                            </label>
                            <label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                                <input type="radio" name="tipo_pessoa" value="JURIDICA" <?php echo ($editData['tipo_pessoa'] === 'JURIDICA') ? 'checked' : ''; ?> style="width: 18px; height: 18px;">
                                Pessoa Jurídica (CNPJ)
                            </label>
                        </div>

                        <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-bottom: 16px;">1. Dados de Identificação</h3>
                        
                        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label id="lbl_nome" for="nome" class="form-label">Nome Completo</label>
                                <input type="text" id="nome" name="nome" class="form-control" value="<?php echo htmlspecialchars($editData['nome']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label id="lbl_cpf_cnpj" for="cpf_cnpj" class="form-label">CPF (Opcional)</label>
                                <input type="text" id="cpf_cnpj" name="cpf_cnpj" class="form-control" placeholder="000.000.000-00" value="<?php echo htmlspecialchars($editData['cpf_cnpj']); ?>" maxlength="18">
                            </div>
                            <div class="form-group">
                                <label id="lbl_rg_ie" for="rg_ie" class="form-label">RG</label>
                                <input type="text" id="rg_ie" name="rg_ie" class="form-control" value="<?php echo htmlspecialchars($editData['rg_ie']); ?>" maxlength="20">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="telefone" class="form-label">Contacto Telefónico</label>
                                <input type="text" id="telefone" name="telefone" class="form-control" placeholder="(67) 99999-9999" value="<?php echo htmlspecialchars($editData['telefone']); ?>" maxlength="15">
                            </div>
                            <div class="form-group">
                                <label for="status" class="form-label">Estado do Registo</label>
                                <select id="status" name="status" class="form-control">
                                    <option value="1" <?php echo ($editData['status'] == 1) ? 'selected' : ''; ?>>Ativo (Permitir OS)</option>
                                    <option value="0" <?php echo ($editData['status'] == 0) ? 'selected' : ''; ?>>Inativo (Bloquear OS)</option>
                                </select>
                            </div>
                        </div>

                        <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-top: 20px; margin-bottom: 16px;">2. Localização e Morada</h3>

                        <div style="display: grid; grid-template-columns: 1fr 2fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="cep" class="form-label">Código Postal (CEP)</label>
                                <input type="text" id="cep" name="cep" class="form-control" placeholder="79800-000" value="<?php echo htmlspecialchars($editData['cep']); ?>" maxlength="9">
                            </div>
                            <div class="form-group">
                                <label for="endereco" class="form-label">Morada (Rua, Avenida, Praça)</label>
                                <input type="text" id="endereco" name="endereco" class="form-control" value="<?php echo htmlspecialchars($editData['endereco']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="numero" class="form-label">Número</label>
                                <input type="text" id="numero" name="numero" class="form-control" value="<?php echo htmlspecialchars($editData['numero']); ?>">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="bairro" class="form-label">Bairro / Freguesia</label>
                                <input type="text" id="bairro" name="bairro" class="form-control" value="<?php echo htmlspecialchars($editData['bairro']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="id_cidade" class="form-label">Cidade Pertencente</label>
                                <select id="id_cidade" name="id_cidade" class="form-control" required>
                                    <option value="">Selecione uma cidade...</option>
                                    <?php foreach ($cidadesList as $cid): ?>
                                        <option value="<?php echo $cid['id_cidade']; ?>" <?php echo ($cid['id_cidade'] == $editData['id_cidade']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cid['cidade']) . ' (' . htmlspecialchars($cid['uf']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 32px; justify-content: flex-end;">
                            <a href="index.php?page=clientes" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Guardar Dados do Cliente</button>
                        </div>
                    </form>
                </div>

                <!-- Lógica Front-end: Máscaras Dinâmicas, Bloqueio de Enter e API ViaCEP -->
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('formCliente');
                    const cpfCnpjInput = document.getElementById('cpf_cnpj');
                    const rgIeInput = document.getElementById('rg_ie');
                    const telInput = document.getElementById('telefone');
                    const cepInput = document.getElementById('cep');
                    
                    // Seletores de rótulo para alteração dinâmica de Tipo de Pessoa
                    const lblNome = document.getElementById('lbl_nome');
                    const lblCpfCnpj = document.getElementById('lbl_cpf_cnpj');
                    const lblRgIe = document.getElementById('lbl_rg_ie');

                    // 1. GESTÃO DINÂMICA DE TIPO DE CLIENTE (Física vs Jurídica)
                    function ajustarCamposTipoPessoa() {
                        const selectedType = document.querySelector('input[name="tipo_pessoa"]:checked').value;
                        if (selectedType === 'FISICA') {
                            lblNome.textContent = 'Nome Completo';
                            lblCpfCnpj.textContent = 'CPF (Opcional)';
                            cpfCnpjInput.placeholder = '000.000.000-00';
                            lblRgIe.textContent = 'RG';
                        } else {
                            lblNome.textContent = 'Razão Social';
                            lblCpfCnpj.textContent = 'CNPJ (Opcional)';
                            cpfCnpjInput.placeholder = '00.000.000/0001-00';
                            lblRgIe.textContent = 'Inscrição Estadual (IE)';
                        }
                        // Limpa e foca no campo ao trocar
                        cpfCnpjInput.value = '';
                    }

                    document.querySelectorAll('input[name="tipo_pessoa"]').forEach(radio => {
                        radio.addEventListener('change', ajustarCamposTipoPessoa);
                    });
                    ajustarCamposTipoPessoa(); // Executa ao carregar para definir estado inicial

                    // 2. IMPEDIR SUBMISSÃO DE FORMULÁRIO AO TECLAR ENTER NOS INPUTS
                    if (form) {
                        form.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                                e.preventDefault();
                                
                                // Salta para o próximo input disponível
                                const fields = Array.from(form.querySelectorAll('input, select'));
                                const index = fields.indexOf(e.target);
                                if (index > -1 && index + 1 < fields.length) {
                                    fields[index + 1].focus();
                                }
                                return false;
                            }
                        });
                    }

                    // 3. NOTIFICAÇÃO EM TELA PERSONALIZADA
                    function mostrarMensagemErro(texto) {
                        const container = document.getElementById('custom-alert-container');
                        const alertBox = document.createElement('div');
                        alertBox.style.backgroundColor = '#ef4444';
                        alertBox.style.color = '#ffffff';
                        alertBox.style.padding = '12px 20px';
                        alertBox.style.borderRadius = '6px';
                        alertBox.style.boxShadow = '0 10px 15px -3px rgba(0, 0, 0, 0.4)';
                        alertBox.style.fontSize = '14px';
                        alertBox.style.fontWeight = '600';
                        alertBox.style.display = 'flex';
                        alertBox.style.justifyContent = 'space-between';
                        alertBox.style.alignItems = 'center';
                        alertBox.style.gap = '15px';
                        alertBox.style.transition = 'opacity 0.3s ease';
                        
                        alertBox.innerHTML = `
                            <span>⚠️ ${texto}</span>
                            <span style="cursor: pointer; font-size: 16px;" onclick="this.parentElement.remove()">×</span>
                        `;
                        
                        container.appendChild(alertBox);
                        setTimeout(() => {
                            alertBox.style.opacity = '0';
                            setTimeout(() => alertBox.remove(), 300);
                        }, 5000);
                    }

                    // 4. MÁSCARAS INTELIGENTES DE DIGITAÇÃO
                    if (cpfCnpjInput) {
                        cpfCnpjInput.addEventListener('input', function() {
                            const selectedType = document.querySelector('input[name="tipo_pessoa"]:checked').value;
                            let v = this.value.replace(/\D/g, '');
                            
                            if (selectedType === 'FISICA') {
                                if (v.length > 11) v = v.slice(0, 11);
                                v = v.replace(/(\d{3})(\d)/, '$1.$2');
                                v = v.replace(/(\d{3})(\d)/, '$1.$2');
                                v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                            } else {
                                if (v.length > 14) v = v.slice(0, 14);
                                v = v.replace(/^(\d{2})(\d)/, '$1.$2');
                                v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
                                v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
                                v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
                            }
                            this.value = v;
                        });
                    }

                    if (telInput) {
                        telInput.addEventListener('input', function() {
                            let v = this.value.replace(/\D/g, '');
                            if (v.length > 11) v = v.slice(0, 11);
                            if (v.length > 10) {
                                v = v.replace(/^(\d{2})(\d{5})(\d{4})$/, '($1) $2-$3');
                            } else if (v.length > 5) {
                                v = v.replace(/^(\d{2})(\d{4})(\d{4})$/, '($1) $2-$3');
                            } else if (v.length > 2) {
                                v = v.replace(/^(\d{2})(\d*)$/, '($1) $2');
                            } else {
                                v = v.replace(/^(\d*)$/, '($1');
                            }
                            this.value = v;
                        });
                    }

                    if (cepInput) {
                        cepInput.addEventListener('input', function() {
                            let v = this.value.replace(/\D/g, '');
                            if (v.length > 8) v = v.slice(0, 8);
                            v = v.replace(/^(\d{5})(\d{1,3})$/, '$1-$2');
                            this.value = v;

                            const cleanCEP = v.replace(/\D/g, '');
                            if (cleanCEP.length === 8) {
                                buscarCep(cleanCEP);
                            }
                        });
                    }

                    function buscarCep(cep) {
                        cepInput.style.borderColor = 'var(--primary)';
                        fetch(`https://viacep.com.br/ws/${cep}/json/`)
                            .then(response => response.json())
                            .then(data => {
                                if (data.erro) {
                                    mostrarMensagemErro("O código postal (CEP) inserido não foi localizado.");
                                    cepInput.style.borderColor = '#ef4444';
                                    return;
                                }
                                document.getElementById('endereco').value = data.logradouro || '';
                                document.getElementById('bairro').value = data.bairro || '';
                                
                                // Seleciona a cidade correspondente no dropdown
                                const selectCidade = document.getElementById('id_cidade');
                                const localidade = data.localidade.toLowerCase();
                                
                                for (let i = 0; i < selectCidade.options.length; i++) {
                                    const optText = selectCidade.options[i].text.toLowerCase();
                                    if (optText.includes(localidade)) {
                                        selectCidade.selectedIndex = i;
                                        break;
                                    }
                                }
                                cepInput.style.borderColor = 'var(--success)';
                            })
                            .catch(() => {
                                mostrarMensagemErro("Falha ao comunicar com o serviço de Código Postal.");
                                cepInput.style.borderColor = '#ef4444';
                            });
                    }

                    // 5. VALIDAÇÕES MATEMÁTICAS DO FORMULÁRIO (CPF/CNPJ no envio)
                    function validarCPF(cpf) {
                        cpf = cpf.replace(/\D/g, '');
                        if (cpf === '') return true;
                        if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) return false;
                        let soma = 0, resto;
                        for (let i = 1; i <= 9; i++) soma += parseInt(cpf.substring(i-1, i)) * (11 - i);
                        resto = (soma * 10) % 11;
                        if (resto === 10 || resto === 11) resto = 0;
                        if (resto !== parseInt(cpf.substring(9, 10))) return false;
                        soma = 0;
                        for (let i = 1; i <= 10; i++)  soma += parseInt(cpf.substring(i-1, i)) * (12 - i);
                        resto = (soma * 10) % 11;
                        if (resto === 10 || resto === 11) resto = 0;
                        if (resto !== parseInt(cpf.substring(10, 11))) return false;
                        return true;
                    }

                    function validarCNPJ(cnpj) {
                        cnpj = cnpj.replace(/\D/g, '');
                        if (cnpj === '') return true;
                        if (cnpj.length !== 14 || /^(\d)\1{13}$/.test(cnpj)) return false;
                        let tamanho = cnpj.length - 2;
                        let numeros = cnpj.substring(0, tamanho);
                        let digitos = cnpj.substring(tamanho);
                        let soma = 0, pos = tamanho - 7;
                        for (let i = tamanho; i >= 1; i--) {
                            soma += numeros.charAt(tamanho - i) * pos--;
                            if (pos < 2) pos = 9;
                        }
                        let resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
                        if (resultado != digitos.charAt(0)) return false;
                        tamanho = tamanho + 1;
                        numeros = cnpj.substring(0, tamanho);
                        soma = 0;
                        pos = tamanho - 7;
                        for (let i = tamanho; i >= 1; i--) {
                            soma += numeros.charAt(tamanho - i) * pos--;
                            if (pos < 2) pos = 9;
                        }
                        resultado = soma % 11 < 2 ? 0 : 11 - (soma % 11);
                        if (resultado != digitos.charAt(1)) return false;
                        return true;
                    }

                    if (form) {
                        form.addEventListener('submit', function(e) {
                            if (cpfCnpjInput && cpfCnpjInput.value !== '') {
                                const selectedType = document.querySelector('input[name="tipo_pessoa"]:checked').value;
                                const docVal = cpfCnpjInput.value;
                                
                                if (selectedType === 'FISICA') {
                                    if (!validarCPF(docVal)) {
                                        e.preventDefault();
                                        mostrarMensagemErro('O CPF informado é matematicamente inválido.');
                                        cpfCnpjInput.focus();
                                        return false;
                                    }
                                } else {
                                    if (!validarCNPJ(docVal)) {
                                        e.preventDefault();
                                        mostrarMensagemErro('O CNPJ informado é matematicamente inválido.');
                                        cpfCnpjInput.focus();
                                        return false;
                                    }
                                }
                            }
                        });
                    }
                });
                </script>

            <?php else: ?>
                
                <!-- Ecrã Padrão: Listagem Geral de Clientes -->
                <div class="card">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px;">
                        <h2 class="card-title" style="margin-bottom: 0;">Clientes Registados</h2>
                        <a href="index.php?page=clientes&action=create" class="btn btn-primary">
                            Registar Novo Cliente
                        </a>
                    </div>

                    <?php if (empty($clientesList)): ?>
                        <p style="color: var(--text-muted); font-size: 14px;">Nenhum cliente registado no sistema.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 70px;">ID</th>
                                        <th>Nome / Razão Social</th>
                                        <th>Tipo</th>
                                        <th>CPF / CNPJ</th>
                                        <th>Telefone</th>
                                        <th>Status</th>
                                        <th style="width: 200px; text-align: right;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clientesList as $cli): ?>
                                        <tr style="<?php echo ($cli['status'] == 0) ? 'opacity: 0.6;' : ''; ?>">
                                            <td><?php echo $cli['id_pessoa']; ?></td>
                                            <td><strong><?php echo htmlspecialchars($cli['nome']); ?></strong></td>
                                            <td>
                                                <span class="badge" style="background-color: var(--info-bg); color: var(--info);">
                                                    <?php echo ($cli['tipo_pessoa'] === 'FISICA') ? 'Física' : 'Jurídica'; ?>
                                                </span>
                                            </td>
                                            <td><code><?php echo htmlspecialchars($cli['cpf_cnpj']); ?></code></td>
                                            <td><?php echo htmlspecialchars($cli['telefone'] !== '' ? $cli['telefone'] : 'Não informado'); ?></td>
                                            <td>
                                                <?php if ($cli['status'] == 1): ?>
                                                    <span class="badge badge-finalizada">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge badge-cancelada">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <a href="index.php?page=clientes&action=edit&id=<?php echo $cli['id_pessoa']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                    Editar
                                                </a>
                                                
                                                <?php if ($cli['status'] == 1): ?>
                                                    <button type="button" class="btn btn-danger btn-confirm-action" 
                                                            data-url="index.php?page=clientes&action=toggle_status&id=<?php echo $cli['id_pessoa']; ?>" 
                                                            data-text="Tem a certeza de que deseja inativar o registo do cliente '<?php echo htmlspecialchars($cli['nome']); ?>'?" 
                                                            style="padding: 4px 10px; font-size: 12px;">
                                                        Inativar
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-primary btn-confirm-action" 
                                                            data-url="index.php?page=clientes&action=toggle_status&id=<?php echo $cli['id_pessoa']; ?>" 
                                                            data-text="Deseja reativar o registo do cliente '<?php echo htmlspecialchars($cli['nome']); ?>'?" 
                                                            style="padding: 4px 10px; font-size: 12px; background-color: var(--success);">
                                                        Ativar
                                                    </button>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Controle JS do Modal de Confirmação customizado -->
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const modal = document.getElementById('custom-confirm-modal');
                    const modalText = document.getElementById('confirm-modal-text');
                    const modalOk = document.getElementById('confirm-modal-ok');
                    const modalCancel = document.getElementById('confirm-modal-cancel');

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

                    // Fecha o modal ao clicar fora dele
                    modal.addEventListener('click', function(e) {
                        if (e.target === modal) {
                            modal.style.display = 'none';
                        }
                    });
                });
                </script>

            <?php endif; ?>

        </div>
