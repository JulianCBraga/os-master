<?php
/**
 * OS Master - Gestão e Cadastro de Funcionários
 * * Este ficheiro implementa o CRUD completo para funcionários com máscaras de digitação
 * interativas para CPF, Moeda e Telefone, mantendo a consistência dos dados salvos na base de dados.
 * Impede também a submissão acidental do formulário ao pressionar a tecla Enter nos inputs.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.3
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

/**
 * Converte valor em formato de moeda brasileira (ex: 1.500,00) para float puro (ex: 1500.00)
 */
if (!function_exists('parseBrazilianDecimal')) {
    function parseBrazilianDecimal($value): float {
        if (empty($value)) return 0.00;
        $clean = str_replace('.', '', $value); // Remove o ponto de milhar
        $clean = str_replace(',', '.', $clean); // Substitui a vírgula decimal por ponto
        return (float)$clean;
    }
}

/**
 * Formata um float puro para exibição em formato monetário brasileiro (ex: 1500.00 -> 1.500,00)
 */
if (!function_exists('formatBrazilianDecimal')) {
    function formatBrazilianDecimal($value): string {
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
$funcionarioFolhas = [];
$funcionarioOsRecentes = [];

// Captura parâmetros de ação para edição ou alteração de status
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// Instância de dados padrão para o formulário
$editData = [
    'nome' => '', 'tipo_pessoa' => 'FISICA', 'cpf_cnpj' => '', 'rg_ie' => '',
    'telefone' => '', 'cep' => '', 'endereco' => '', 'numero' => '',
    'bairro' => '', 'id_cidade' => '', 'status' => 1,
    'cargo' => 'Atendente', 'perfil_acesso' => 'Atendente',
    'data_admissao' => date('Y-m-d'), 'data_desligamento' => '',
    'salario' => '0,00',
    'comissao_os' => 0, 'valor_comissao_os' => '0,00',
    'comissao_mo' => 0, 'valor_comissao_mo' => '0,00'
];

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // Coleta e higienização dos dados do formulário
    $pessoaFields = [
        'tipo_pessoa' => 'FISICA',
        'nome'        => trim(filter_input(INPUT_POST, 'nome', FILTER_DEFAULT)),
        'cpf_cnpj'    => trim(filter_input(INPUT_POST, 'cpf_cnpj', FILTER_DEFAULT)), // Mantém a máscara visual enviada
        'rg_ie'       => trim(filter_input(INPUT_POST, 'rg_ie', FILTER_DEFAULT)),
        'telefone'    => trim(filter_input(INPUT_POST, 'telefone', FILTER_DEFAULT)), 
        'cep'         => trim(filter_input(INPUT_POST, 'cep', FILTER_DEFAULT)),
        'endereco'    => trim(filter_input(INPUT_POST, 'endereco', FILTER_DEFAULT)),
        'numero'      => trim(filter_input(INPUT_POST, 'numero', FILTER_DEFAULT)),
        'bairro'      => trim(filter_input(INPUT_POST, 'bairro', FILTER_DEFAULT)),
        'id_cidade'   => filter_input(INPUT_POST, 'id_cidade', FILTER_VALIDATE_INT),
        'status'      => 1
    ];

    // Trata e converte os campos de moeda mascarados para float puro da base de dados
    $funcionarioFields = [
        'cargo'             => trim(filter_input(INPUT_POST, 'cargo', FILTER_DEFAULT)),
        'perfil_acesso'     => trim(filter_input(INPUT_POST, 'perfil_acesso', FILTER_DEFAULT)) ?: 'Atendente',
        'data_admissao'     => trim(filter_input(INPUT_POST, 'data_admissao', FILTER_DEFAULT)) ?: null,
        'data_desligamento' => trim(filter_input(INPUT_POST, 'data_desligamento', FILTER_DEFAULT)) ?: null,
        'status'            => isset($_POST['status']) ? (int)$_POST['status'] : 1,
        'salario'           => parseBrazilianDecimal($_POST['salario'] ?? '0,00'),
        'comissao_os'       => isset($_POST['comissao_os']) ? 1 : 0,
        'valor_comissao_os' => parseBrazilianDecimal($_POST['valor_comissao_os'] ?? '0,00'),
        'comissao_mo'       => isset($_POST['comissao_mo']) ? 1 : 0,
        'valor_comissao_mo' => parseBrazilianDecimal($_POST['valor_comissao_mo'] ?? '0,00')
    ];

    $postedEditData = array_merge($editData, $pessoaFields, [
        'cargo'             => $funcionarioFields['cargo'],
        'perfil_acesso'     => $funcionarioFields['perfil_acesso'],
        'data_admissao'     => $funcionarioFields['data_admissao'] ?? '',
        'data_desligamento' => $funcionarioFields['data_desligamento'] ?? '',
        'status'            => $funcionarioFields['status'],
        'salario'           => trim($_POST['salario'] ?? '0,00'),
        'comissao_os'       => $funcionarioFields['comissao_os'],
        'valor_comissao_os' => trim($_POST['valor_comissao_os'] ?? '0,00'),
        'comissao_mo'       => $funcionarioFields['comissao_mo'],
        'valor_comissao_mo' => trim($_POST['valor_comissao_mo'] ?? '0,00'),
    ]);

    // 1. AÇÃO: CRIAR NOVO FUNCIONÁRIO
    if ($formAction === 'create') {
        $action = 'create';
        $editData = $postedEditData;

        if (empty($pessoaFields['nome']) || !$pessoaFields['id_cidade'] || empty($funcionarioFields['cargo'])) {
            $message = 'Nome, Cidade e Cargo são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                $pessoaExistente = Pessoa::getByDocumento($pdo, $pessoaFields['cpf_cnpj']);

                if ($pessoaExistente) {
                    $id_pessoa = (int)$pessoaExistente['id_pessoa'];

                    $stmtFuncionarioExistente = $pdo->prepare("SELECT id_pessoa FROM funcionario WHERE id_pessoa = :id LIMIT 1");
                    $stmtFuncionarioExistente->execute([':id' => $id_pessoa]);
                    if ($stmtFuncionarioExistente->fetch()) {
                        throw new Exception('Esta pessoa já está cadastrada como funcionário.');
                    }

                    Pessoa::update($pdo, $id_pessoa, $pessoaFields);
                } else {
                    // Cria o registo na tabela base 'pessoa' utilizando a classe base
                    $id_pessoa = Pessoa::create($pdo, $pessoaFields);
                }

                $stmtClienteExistente = $pdo->prepare("SELECT id_pessoa FROM cliente WHERE id_pessoa = :id LIMIT 1");
                $stmtClienteExistente->execute([':id' => $id_pessoa]);
                if (!$stmtClienteExistente->fetch()) {
                    $stmtCliente = $pdo->prepare("
                        INSERT INTO cliente (id_pessoa, tipo_cliente, origem, whatsapp_autorizado, status, data_ultima_interacao)
                        VALUES (:id_pessoa, 'PARTICULAR', 'Funcionário', 1, 1, NULL)
                    ");
                    $stmtCliente->execute([':id_pessoa' => $id_pessoa]);
                }

                // Insere na tabela 'funcionario' com os valores já convertidos para float
                $sqlFunc = "INSERT INTO funcionario (
                                id_pessoa, cargo, perfil_acesso, data_admissao, data_desligamento, status, salario, comissao_os, 
                                valor_comissao_os, comissao_mo, valor_comissao_mo
                            ) VALUES (
                                :id_pessoa, :cargo, :perfil_acesso, :data_admissao, :data_desligamento, :status, :salario, :comissao_os, 
                                :valor_comissao_os, :comissao_mo, :valor_comissao_mo
                            )";
                
                $stmtFunc = $pdo->prepare($sqlFunc);
                $stmtFunc->execute([
                    ':id_pessoa'          => $id_pessoa,
                    ':cargo'              => $funcionarioFields['cargo'],
                    ':perfil_acesso'      => $funcionarioFields['perfil_acesso'],
                    ':data_admissao'      => $funcionarioFields['data_admissao'],
                    ':data_desligamento'  => $funcionarioFields['data_desligamento'],
                    ':status'             => $funcionarioFields['status'],
                    ':salario'            => $funcionarioFields['salario'],
                    ':comissao_os'        => $funcionarioFields['comissao_os'],
                    ':valor_comissao_os'  => $funcionarioFields['valor_comissao_os'],
                    ':comissao_mo'        => $funcionarioFields['comissao_mo'],
                    ':valor_comissao_mo'  => $funcionarioFields['valor_comissao_mo']
                ]);

                $pdo->commit();
                $message = 'Funcionário registado com sucesso!';
                $messageType = 'success';
                $action = 'list';
                $editData = [
                    'nome' => '', 'tipo_pessoa' => 'FISICA', 'cpf_cnpj' => '', 'rg_ie' => '',
                    'telefone' => '', 'cep' => '', 'endereco' => '', 'numero' => '',
                    'bairro' => '', 'id_cidade' => '', 'status' => 1,
                    'cargo' => 'Atendente', 'perfil_acesso' => 'Atendente',
                    'data_admissao' => date('Y-m-d'), 'data_desligamento' => '',
                    'salario' => '0,00',
                    'comissao_os' => 0, 'valor_comissao_os' => '0,00',
                    'comissao_mo' => 0, 'valor_comissao_mo' => '0,00'
                ];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $message = $e->getMessage();
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR FUNCIONÁRIO EXISTENTE
    if ($formAction === 'update' && $editId) {
        $action = 'edit';
        $editData = $postedEditData;

        if (empty($pessoaFields['nome']) || !$pessoaFields['id_cidade'] || empty($funcionarioFields['cargo'])) {
            $message = 'Nome, Cidade e Cargo são campos de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                $pdo->beginTransaction();

                // Atualiza a tabela base 'pessoa'
                Pessoa::update($pdo, $editId, $pessoaFields);

                // Atualiza os dados na tabela 'funcionario'
                $sqlFuncUpdate = "UPDATE funcionario SET 
                                    cargo = :cargo, 
                                    perfil_acesso = :perfil_acesso,
                                    data_admissao = :data_admissao,
                                    data_desligamento = :data_desligamento,
                                    status = :status,
                                    salario = :salario, 
                                    comissao_os = :comissao_os, 
                                    valor_comissao_os = :valor_comissao_os, 
                                    comissao_mo = :comissao_mo, 
                                    valor_comissao_mo = :valor_comissao_mo 
                                  WHERE id_pessoa = :id_pessoa";
                
                $stmtFuncUpdate = $pdo->prepare($sqlFuncUpdate);
                $stmtFuncUpdate->execute([
                    ':cargo'              => $funcionarioFields['cargo'],
                    ':perfil_acesso'      => $funcionarioFields['perfil_acesso'],
                    ':data_admissao'      => $funcionarioFields['data_admissao'],
                    ':data_desligamento'  => $funcionarioFields['data_desligamento'],
                    ':status'             => $funcionarioFields['status'],
                    ':salario'            => $funcionarioFields['salario'],
                    ':comissao_os'        => $funcionarioFields['comissao_os'],
                    ':valor_comissao_os'  => $funcionarioFields['valor_comissao_os'],
                    ':comissao_mo'        => $funcionarioFields['comissao_mo'],
                    ':valor_comissao_mo'  => $funcionarioFields['valor_comissao_mo'],
                    ':id_pessoa'          => $editId
                ]);

                $pdo->commit();
                $message = 'Funcionário atualizado com sucesso!';
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
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && ($action === 'edit' || $action === 'view') && $editId) {
    try {
        $sqlEdit = "SELECT p.*, f.cargo, f.perfil_acesso, f.data_admissao, f.data_desligamento, f.status AS status, f.salario, f.comissao_os, f.valor_comissao_os, f.comissao_mo, f.valor_comissao_mo,
                           c.nome AS cidade_nome, e.sigla AS uf
                    FROM pessoa p 
                    INNER JOIN funcionario f ON p.id_pessoa = f.id_pessoa 
                    LEFT JOIN cidade c ON p.id_cidade = c.id_cidade
                    LEFT JOIN estado e ON c.id_estado = e.id_estado
                    WHERE p.id_pessoa = :id LIMIT 1";
        
        $stmtEdit = $pdo->prepare($sqlEdit);
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        
        if ($data) {
            $editData = $data;
            // Formata os decimais vindos da base de dados para a máscara brasileira no input
            $editData['salario'] = formatBrazilianDecimal($data['salario']);
            $editData['valor_comissao_os'] = formatBrazilianDecimal($data['valor_comissao_os']);
            $editData['valor_comissao_mo'] = formatBrazilianDecimal($data['valor_comissao_mo']);

            if ($action === 'view') {
                $stmtFolhas = $pdo->prepare("
                    SELECT mes_referencia, ano_referencia, valor_salario_base, total_comissao, valor_total_receber, pago
                    FROM folha_pagto
                    WHERE id_funcionario = :id
                    ORDER BY ano_referencia DESC, mes_referencia DESC
                    LIMIT 4
                ");
                $stmtFolhas->execute([':id' => $editId]);
                $funcionarioFolhas = $stmtFolhas->fetchAll();

                $stmtOs = $pdo->prepare("
                    SELECT os.id_os, os.data_abertura, os.status, p.nome AS cliente_nome, eq.aparelho, eq.marca, eq.modelo
                    FROM os
                    INNER JOIN pessoa p ON os.id_cliente = p.id_pessoa
                    INNER JOIN equipamento eq ON os.id_equipamento = eq.id_equipamento
                    WHERE os.id_tecnico = :id
                    ORDER BY os.data_abertura DESC
                    LIMIT 4
                ");
                $stmtOs->execute([':id' => $editId]);
                $funcionarioOsRecentes = $stmtOs->fetchAll();
            }
        } else {
            $message = 'Funcionário não encontrado.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar os dados do funcionário.';
        $messageType = 'danger';
    }
}

// 2. ATIVAR / INATIVAR FUNCIONÁRIO (Inativação Lógica)
if ($action === 'toggle_status' && $editId) {
    try {
        $stmtFuncionarioStatus = $pdo->prepare("SELECT status FROM funcionario WHERE id_pessoa = :id LIMIT 1");
        $stmtFuncionarioStatus->execute([':id' => $editId]);
        $funcionario = $stmtFuncionarioStatus->fetch();
        if ($funcionario) {
            $newStatus = ($funcionario['status'] == 1) ? 0 : 1;
            
            // Impede que o Administrador desative o seu próprio utilizador em uso
            if ($editId == $_SESSION['user_id'] && $newStatus == 0) {
                $message = 'Por motivos de segurança, não pode desativar a sua própria conta ativa.';
                $messageType = 'danger';
            } else {
                $dataDesligamentoSql = $newStatus == 0 ? 'COALESCE(data_desligamento, CURDATE())' : 'NULL';
                $stmtSetFuncionario = $pdo->prepare("UPDATE funcionario SET status = :status, data_desligamento = {$dataDesligamentoSql} WHERE id_pessoa = :id");
                $stmtSetFuncionario->execute([':status' => $newStatus, ':id' => $editId]);
                $message = 'Status do funcionário alterado com sucesso!';
                $messageType = 'success';
            }
        } else {
            $message = 'Registo não encontrado.';
            $messageType = 'danger';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao alterar o status do funcionário.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// Listagem Geral
$cidadesList = [];
$funcionariosList = [];
try {
    $stmtCid = $pdo->query("
        SELECT c.id_cidade, c.nome AS cidade, e.sigla AS uf 
        FROM cidade c 
        INNER JOIN estado e ON c.id_estado = e.id_estado 
        ORDER BY c.nome ASC
    ");
    $cidadesList = $stmtCid->fetchAll();

    $stmtFuncs = $pdo->query("
        SELECT p.id_pessoa, p.nome, IFNULL(p.cpf_cnpj, '') AS cpf_cnpj, p.telefone,
               f.status, f.cargo, f.perfil_acesso, f.data_admissao, f.salario 
        FROM pessoa p 
        INNER JOIN funcionario f ON p.id_pessoa = f.id_pessoa 
        ORDER BY p.nome ASC
    ");
    $funcionariosList = $stmtFuncs->fetchAll();
} catch (PDOException $e) {
    // Caso ocorra falha ao carregar as tabelas relativas
}
?>

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
            
            <?php if ($action === 'view' && $editId): ?>
                <div class="card printable-card">
                    <div class="module-header no-print">
                        <div>
                            <h2 class="module-title">Ficha do Funcionário #<?php echo htmlspecialchars($editId); ?></h2>
                            <p class="module-subtitle">Visualização cadastral para consulta e impressão.</p>
                        </div>
                        <div class="action-group">
                            <button type="button" class="btn btn-primary" onclick="window.print()">Imprimir Ficha</button>
                            <a href="index.php?page=funcionarios&action=edit&id=<?php echo $editId; ?>" class="btn btn-secondary">Editar</a>
                            <a href="index.php?page=funcionarios" class="btn btn-secondary">Voltar</a>
                        </div>
                    </div>

                    <div class="print-header">
                        <h1>Ficha do Funcionário</h1>
                        <p>Cadastro #<?php echo htmlspecialchars($editId); ?> - <?php echo date('d/m/Y H:i'); ?></p>
                    </div>

                    <div class="form-section" style="margin-top: 0;">
                        <h3 class="form-section-title">Identificação</h3>
                        <div class="form-grid form-grid-3">
                            <div>
                                <div class="table-muted-text">Nome Completo</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['nome']); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">CPF</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['cpf_cnpj'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">RG</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['rg_ie'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Telefone</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['telefone'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Status</div>
                                <div class="table-primary-text"><?php echo ((int)$editData['status'] === 1) ? 'Ativo' : 'Inativo'; ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Tipo</div>
                                <div class="table-primary-text">Funcionário / Cliente</div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">Dados Funcionais</h3>
                        <div class="form-grid form-grid-3">
                            <div>
                                <div class="table-muted-text">Cargo</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['cargo'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Perfil de Acesso</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['perfil_acesso'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Salário Base</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($currencySymbol); ?> <?php echo htmlspecialchars($editData['salario']); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Admissão</div>
                                <div class="table-primary-text"><?php echo !empty($editData['data_admissao']) ? date('d/m/Y', strtotime($editData['data_admissao'])) : 'Não informada'; ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Desligamento</div>
                                <div class="table-primary-text"><?php echo !empty($editData['data_desligamento']) ? date('d/m/Y', strtotime($editData['data_desligamento'])) : 'Não informado'; ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Comissões</div>
                                <div class="table-primary-text">
                                    Peças: <?php echo ((int)$editData['comissao_os'] === 1) ? htmlspecialchars($editData['valor_comissao_os']) . '%' : 'Não'; ?> |
                                    Mão de obra: <?php echo ((int)$editData['comissao_mo'] === 1) ? htmlspecialchars($editData['valor_comissao_mo']) . '%' : 'Não'; ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">Endereço</h3>
                        <div class="form-grid form-grid-3">
                            <div>
                                <div class="table-muted-text">CEP</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['cep'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Endereço</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['endereco'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Número</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['numero'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Bairro</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars($editData['bairro'] ?: 'Não informado'); ?></div>
                            </div>
                            <div>
                                <div class="table-muted-text">Cidade / UF</div>
                                <div class="table-primary-text"><?php echo htmlspecialchars(($editData['cidade_nome'] ?? '') ? $editData['cidade_nome'] . ' / ' . ($editData['uf'] ?? '') : 'Não informada'); ?></div>
                            </div>
                        </div>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">Folha de Pagamento Recente</h3>
                        <?php if (empty($funcionarioFolhas)): ?>
                            <p style="color: var(--text-muted); font-size: 14px; margin: 0;">Nenhuma folha de pagamento registrada para este funcionário.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>Referência</th>
                                            <th>Salário</th>
                                            <th>Comissão</th>
                                            <th>Total</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($funcionarioFolhas as $folha): ?>
                                            <tr>
                                                <td><?php echo str_pad((string)$folha['mes_referencia'], 2, '0', STR_PAD_LEFT); ?>/<?php echo htmlspecialchars($folha['ano_referencia']); ?></td>
                                                <td><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatBrazilianDecimal($folha['valor_salario_base']); ?></td>
                                                <td><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatBrazilianDecimal($folha['total_comissao']); ?></td>
                                                <td><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatBrazilianDecimal($folha['valor_total_receber']); ?></td>
                                                <td><?php echo ((int)$folha['pago'] === 1) ? 'Pago' : 'Pendente'; ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="form-section">
                        <h3 class="form-section-title">OS Recentes como Técnico</h3>
                        <?php if (empty($funcionarioOsRecentes)): ?>
                            <p style="color: var(--text-muted); font-size: 14px; margin: 0;">Nenhuma OS recente vinculada a este técnico.</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table">
                                    <thead>
                                        <tr>
                                            <th>OS</th>
                                            <th>Data</th>
                                            <th>Cliente</th>
                                            <th>Equipamento</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($funcionarioOsRecentes as $osRecente): ?>
                                            <tr>
                                                <td><code>#<?php echo htmlspecialchars($osRecente['id_os']); ?></code></td>
                                                <td><?php echo !empty($osRecente['data_abertura']) ? date('d/m/Y', strtotime($osRecente['data_abertura'])) : 'Não informada'; ?></td>
                                                <td><?php echo htmlspecialchars($osRecente['cliente_nome']); ?></td>
                                                <td><?php echo htmlspecialchars(trim(($osRecente['aparelho'] ?: '') . ' ' . ($osRecente['marca'] ?: '') . ' ' . ($osRecente['modelo'] ?: '')) ?: 'Não informado'); ?></td>
                                                <td><?php echo htmlspecialchars($osRecente['status']); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

            <?php elseif ($action === 'create' || $action === 'edit'): ?>
                <div class="card">
                    <div class="module-header">
                        <div>
                            <h2 class="module-title" style="<?php echo ($action === 'edit') ? 'color: var(--warning);' : ''; ?>">
                        <?php echo ($action === 'edit') ? 'Editar Funcionário' : 'Novo Funcionário'; ?>
                            </h2>
                            <p class="module-subtitle">Dados pessoais, acesso, remuneração e comissões do funcionário.</p>
                        </div>
                        <a href="index.php?page=funcionarios" class="btn btn-secondary">Voltar</a>
                    </div>
                    
                    <form id="formFuncionario" action="index.php?page=funcionarios<?php echo ($action === 'edit') ? '&id=' . $editId : ''; ?>" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="<?php echo ($action === 'edit') ? 'update' : 'create'; ?>">

                        <div class="form-section">
                        <h3 class="form-section-title">1. Informações Pessoais</h3>
                        
                        <div class="form-grid form-grid-wide">
                            <div class="form-group">
                                <label for="nome" class="form-label">Nome Completo</label>
                                <input type="text" id="nome" name="nome" class="form-control" value="<?php echo htmlspecialchars($editData['nome']); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="cpf_cnpj" class="form-label">CPF (Opcional)</label>
                                <input type="text" id="cpf_cnpj" name="cpf_cnpj" class="form-control" placeholder="000.000.000-00" value="<?php echo htmlspecialchars($editData['cpf_cnpj']); ?>" maxlength="14">
                            </div>
                            <div class="form-group">
                                <label for="rg_ie" class="form-label">RG</label>
                                <input type="text" id="rg_ie" name="rg_ie" class="form-control" value="<?php echo htmlspecialchars($editData['rg_ie']); ?>" maxlength="20">
                            </div>
                        </div>

                        <div class="form-grid address-grid">
                            <div class="form-group">
                                <label for="cep" class="form-label">CEP</label>
                                <input type="text" id="cep" name="cep" class="form-control" placeholder="79800-000" value="<?php echo htmlspecialchars($editData['cep']); ?>" maxlength="9">
                            </div>
                            <div class="form-group">
                                <label for="telefone" class="form-label">Telefone de Contacto</label>
                                <input type="text" id="telefone" name="telefone" class="form-control" placeholder="(67) 99999-9999" value="<?php echo htmlspecialchars($editData['telefone']); ?>" maxlength="15">
                            </div>
                            <div class="form-group">
                                <label for="endereco" class="form-label">Endereço</label>
                                <input type="text" id="endereco" name="endereco" class="form-control" value="<?php echo htmlspecialchars($editData['endereco']); ?>">
                            </div>
                        </div>

                        <div class="form-grid" style="grid-template-columns: 1fr 2fr 2fr;">
                            <div class="form-group">
                                <label for="numero" class="form-label">Número</label>
                                <input type="text" id="numero" name="numero" class="form-control" value="<?php echo htmlspecialchars($editData['numero']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="bairro" class="form-label">Bairro</label>
                                <input type="text" id="bairro" name="bairro" class="form-control" value="<?php echo htmlspecialchars($editData['bairro']); ?>">
                            </div>
                            <div class="form-group">
                                <label for="id_cidade" class="form-label">Cidade</label>
                                <select id="id_cidade" name="id_cidade" class="form-control" required>
                                    <option value="">Selecione a cidade...</option>
                                    <?php foreach ($cidadesList as $cid): ?>
                                        <option value="<?php echo $cid['id_cidade']; ?>" <?php echo ($cid['id_cidade'] == $editData['id_cidade']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cid['cidade']) . ' (' . htmlspecialchars($cid['uf']) . ')'; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        </div>

                        <div class="form-section">
                        <h3 class="form-section-title">2. Cargo e Remuneração</h3>

                        <div class="form-grid form-grid-3">
                            <div class="form-group">
                                <label for="cargo" class="form-label">Cargo</label>
                                <select id="cargo" name="cargo" class="form-control" required>
                                    <option value="Administrador" <?php echo ($editData['cargo'] === 'Administrador') ? 'selected' : ''; ?>>Administrador</option>
                                    <option value="Atendente" <?php echo ($editData['cargo'] === 'Atendente') ? 'selected' : ''; ?>>Atendente</option>
                                    <option value="Técnico" <?php echo ($editData['cargo'] === 'Técnico') ? 'selected' : ''; ?>>Técnico</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="perfil_acesso" class="form-label">Perfil de Acesso</label>
                                <select id="perfil_acesso" name="perfil_acesso" class="form-control" required>
                                    <option value="Administrador" <?php echo ($editData['perfil_acesso'] === 'Administrador') ? 'selected' : ''; ?>>Administrador</option>
                                    <option value="Atendente" <?php echo ($editData['perfil_acesso'] === 'Atendente') ? 'selected' : ''; ?>>Atendente</option>
                                    <option value="Técnico" <?php echo ($editData['perfil_acesso'] === 'Técnico') ? 'selected' : ''; ?>>Técnico</option>
                                </select>
                            </div>
                            <div class="form-group">
                                <label for="salario" class="form-label">Salário Base (<?php echo htmlspecialchars($currencySymbol); ?>)</label>
                                <input type="text" id="salario" name="salario" class="form-control text-right" placeholder="0,00" value="<?php echo htmlspecialchars($editData['salario']); ?>" required style="text-align: right;">
                            </div>
                        </div>

                        <div class="form-grid form-grid-2">
                            <div class="form-group">
                                <label for="data_admissao" class="form-label">Data de Admissão</label>
                                <input type="date" id="data_admissao" name="data_admissao" class="form-control" value="<?php echo htmlspecialchars($editData['data_admissao'] ?? ''); ?>">
                            </div>
                            <div class="form-group">
                                <label for="data_desligamento" class="form-label">Data de Desligamento</label>
                                <input type="date" id="data_desligamento" name="data_desligamento" class="form-control" value="<?php echo htmlspecialchars($editData['data_desligamento'] ?? ''); ?>">
                            </div>
                        </div>

                        </div>

                        <div class="form-section">
                        <h3 class="form-section-title">3. Comissões e Status</h3>

                        <div class="form-grid form-grid-2">
                            <div>
                                <label class="choice-option">
                                    <input type="checkbox" name="comissao_os" value="1" <?php echo ($editData['comissao_os'] == 1) ? 'checked' : ''; ?>>
                                    Ativar Comissão sobre Peças (%)
                                </label>
                                <div class="form-group" style="margin-top: 12px;">
                                    <input type="text" id="valor_comissao_os" name="valor_comissao_os" class="form-control" placeholder="0,00" value="<?php echo htmlspecialchars($editData['valor_comissao_os']); ?>" style="text-align: right;">
                                </div>
                            </div>
                            <div>
                                <label class="choice-option">
                                    <input type="checkbox" name="comissao_mo" value="1" <?php echo ($editData['comissao_mo'] == 1) ? 'checked' : ''; ?>>
                                    Ativar Comissão sobre Mão de Obra (%)
                                </label>
                                <div class="form-group" style="margin-top: 12px;">
                                    <input type="text" id="valor_comissao_mo" name="valor_comissao_mo" class="form-control" placeholder="0,00" value="<?php echo htmlspecialchars($editData['valor_comissao_mo']); ?>" style="text-align: right;">
                                </div>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top: 20px;">
                            <label for="status" class="form-label">Status</label>
                            <select id="status" name="status" class="form-control">
                                <option value="1" <?php echo ($editData['status'] == 1) ? 'selected' : ''; ?>>Ativo</option>
                                <option value="0" <?php echo ($editData['status'] == 0) ? 'selected' : ''; ?>>Inativo</option>
                            </select>
                        </div>
                        </div>

                        <div style="display: flex; gap: 12px; margin-top: 32px; justify-content: flex-end;">
                            <a href="index.php?page=funcionarios" class="btn btn-secondary">Cancelar</a>
                            <button type="submit" class="btn btn-primary">Gravar Dados do Funcionário</button>
                        </div>
                    </form>
                </div>

                <!-- Lógica das Máscaras Dinâmicas em JS, ViaCEP e UI Livre de Diálogos Nativos -->
                <script>
                document.addEventListener('DOMContentLoaded', function() {
                    const form = document.getElementById('formFuncionario');
                    const cpfInput = document.getElementById('cpf_cnpj');
                    const telInput = document.getElementById('telefone');
                    const cepInput = document.getElementById('cep');
                    
                    // Seletores de campos monetários
                    const moneyInputs = [
                        document.getElementById('salario'),
                        document.getElementById('valor_comissao_os'),
                        document.getElementById('valor_comissao_mo')
                    ];

                    // 1. IMPEDIR SUBMISSÃO DE FORMULÁRIO AO TECLAR ENTER NOS INPUTS
                    if (form) {
                        form.addEventListener('keydown', function(e) {
                            if (e.key === 'Enter' && e.target.tagName === 'INPUT') {
                                e.preventDefault(); // Impede o comportamento padrão de submissão
                                
                                // Opcional: foca no próximo input do formulário
                                const fields = Array.from(form.querySelectorAll('input, select'));
                                const index = fields.indexOf(e.target);
                                if (index > -1 && index + 1 < fields.length) {
                                    fields[index + 1].focus();
                                }
                                return false;
                            }
                        });
                    }

                    // 2. NOTIFICAÇÃO EM TELA PERSONALIZADA
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

                    // 3. MÁSCARA DE CPF (000.000.000-00)
                    if (cpfInput) {
                        cpfInput.addEventListener('input', function() {
                            let v = this.value.replace(/\D/g, '');
                            if (v.length > 11) v = v.slice(0, 11);
                            v = v.replace(/(\d{3})(\d)/, '$1.$2');
                            v = v.replace(/(\d{3})(\d)/, '$1.$2');
                            v = v.replace(/(\d{3})(\d{1,2})$/, '$1-$2');
                            this.value = v;
                        });
                    }

                    // 4. MÁSCARA DE TELEFONE ((00) 00000-0000)
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

                    // 5. MÁSCARA DE CEP (00000-000) com Busca de Endereço Automatizada (ViaCEP)
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
                                    mostrarMensagemErro("CEP não localizado na base de dados.");
                                    cepInput.style.borderColor = '#ef4444';
                                    return;
                                }
                                document.getElementById('endereco').value = data.logradouro || '';
                                document.getElementById('bairro').value = data.bairro || '';
                                
                                // Busca e seleciona a cidade correspondente no select dropdown
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
                                mostrarMensagemErro("Não foi possível conectar com o serviço de CEP.");
                                cepInput.style.borderColor = '#ef4444';
                            });
                    }

                    // 6. MÁSCARA DE MOEDA / PORCENTAGEM (999.999,99)
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

                    moneyInputs.forEach(input => {
                        if (input) {
                            input.addEventListener('focus', function() {
                                if (this.value === '' || this.value === '0,00') this.value = '';
                            });
                            input.addEventListener('input', function() {
                                formatarCampoMoeda(this);
                            });
                            input.addEventListener('blur', function() {
                                if (this.value === '') this.value = '0,00';
                            });
                        }
                    });

                    // 7. VALIDAÇÃO DE SUBMISSÃO (Valida o algoritmo do CPF caso preenchido)
                    if (form) {
                        form.addEventListener('submit', function(e) {
                            if (cpfInput && cpfInput.value !== '') {
                                const cleanCPF = cpfInput.value.replace(/\D/g, '');

                                function cpfValido(cpf) {
                                    if (cpf.length !== 11 || /^(\d)\1{10}$/.test(cpf)) {
                                        return false;
                                    }

                                    for (let t = 9; t < 11; t++) {
                                        let d = 0;
                                        for (let c = 0; c < t; c++) {
                                            d += Number(cpf[c]) * ((t + 1) - c);
                                        }
                                        d = ((10 * d) % 11) % 10;
                                        if (Number(cpf[t]) !== d) {
                                            return false;
                                        }
                                    }

                                    return true;
                                }

                                if (!cpfValido(cleanCPF)) {
                                    e.preventDefault();
                                    mostrarMensagemErro('O CPF informado é inválido. Corrija o número antes de gravar.');
                                    cpfInput.focus();
                                    return false;
                                }
                            }
                        });
                    }
                });
                </script>

            <?php else: ?>
                
                <!-- Listagem Geral de Funcionários -->
                <div class="card">
                    <div class="module-header">
                        <div>
                            <h2 class="module-title">Funcionários Registados</h2>
                            <p class="module-subtitle"><?php echo count($funcionariosList); ?> funcionário(s) no cadastro.</p>
                        </div>
                        <a href="index.php?page=funcionarios&action=create" class="btn btn-primary">
                            Registar Novo Funcionário
                        </a>
                    </div>

                    <?php if (empty($funcionariosList)): ?>
                        <p style="color: var(--text-muted); font-size: 14px;">Nenhum funcionário registado.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th style="width: 70px;">ID</th>
                                        <th>Nome Completo</th>
                                        <th>CPF</th>
                                        <th>Cargo</th>
                                        <th>Perfil</th>
                                        <th>Salário Base</th>
                                        <th>Status</th>
                                        <th style="width: 270px; text-align: right;">Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($funcionariosList as $func): ?>
                                        <tr style="<?php echo ($func['status'] == 0) ? 'opacity: 0.6;' : ''; ?>">
                                            <td><code>#<?php echo $func['id_pessoa']; ?></code></td>
                                            <td>
                                                <div class="table-primary-text"><?php echo htmlspecialchars($func['nome']); ?></div>
                                                <div class="table-muted-text">Admissão: <?php echo !empty($func['data_admissao']) ? date('d/m/Y', strtotime($func['data_admissao'])) : 'Não informada'; ?></div>
                                            </td>
                                            <td><?php echo htmlspecialchars($func['cpf_cnpj'] !== '' ? $func['cpf_cnpj'] : 'Não informado'); ?></td>
                                            <td>
                                                <span class="badge" style="background-color: var(--info-bg); color: var(--info);">
                                                    <?php echo htmlspecialchars($func['cargo']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge" style="background-color: var(--warning-bg); color: var(--warning);">
                                                    <?php echo htmlspecialchars($func['perfil_acesso']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($currencySymbol); ?> <?php echo formatBrazilianDecimal($func['salario']); ?></td>
                                            <td>
                                                <?php if ($func['status'] == 1): ?>
                                                    <span class="badge badge-finalizada">Ativo</span>
                                                <?php else: ?>
                                                    <span class="badge badge-cancelada">Inativo</span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="text-align: right;">
                                                <div class="action-group">
                                                <a href="index.php?page=funcionarios&action=view&id=<?php echo $func['id_pessoa']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                    Visualizar
                                                </a>
                                                <a href="index.php?page=funcionarios&action=edit&id=<?php echo $func['id_pessoa']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                    Editar
                                                </a>
                                                
                                                <?php if ($func['status'] == 1): ?>
                                                    <button type="button" class="btn btn-danger btn-confirm-action" 
                                                            data-url="index.php?page=funcionarios&action=toggle_status&id=<?php echo $func['id_pessoa']; ?>" 
                                                            data-text="Tem a certeza de que deseja inativar o funcionário '<?php echo htmlspecialchars($func['nome']); ?>'?" 
                                                            style="padding: 4px 10px; font-size: 12px;">
                                                        Inativar
                                                    </button>
                                                <?php else: ?>
                                                    <button type="button" class="btn btn-primary btn-confirm-action" 
                                                            data-url="index.php?page=funcionarios&action=toggle_status&id=<?php echo $func['id_pessoa']; ?>" 
                                                            data-text="Deseja reativar o funcionário '<?php echo htmlspecialchars($func['nome']); ?>'?" 
                                                            style="padding: 4px 10px; font-size: 12px; background-color: var(--success);">
                                                        Ativar
                                                    </button>
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
