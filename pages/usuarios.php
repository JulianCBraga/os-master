<?php
/**
 * OS Master - Gestão e Cadastro de Usuários
 * * Este arquivo gerencia as credenciais de acesso dos funcionários,
 * permitindo criar logins, definir e alterar senhas com hash seguro
 * e ativar/desativar contas. Apenas administradores têm acesso.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.1
 */

// Impede o acesso direto a este arquivo fora do index.php
if (!defined('BASE_PATH')) {
    header("Location: ../index.php");
    exit;
}

// Verificação de segurança: apenas usuários autenticados podem acessar
if (!isset($_SESSION['user_id'])) {
    header("Location: index.php?page=login");
    exit;
}

// Restrição de Perfil (ACL): Apenas o perfil "Administrador" pode gerenciar usuários
if ($_SESSION['user_role'] !== 'Administrador') {
    echo "<div class='page-container'>";
    echo "<div class='card' style='border-color: var(--danger); background-color: var(--danger-bg);'>";
    echo "<h3 style='color: var(--danger); font-weight: 700; margin-bottom: 10px;'>Acesso Negado</h3>";
    echo "<p style='color: var(--text-muted);'>Você não tem permissão administrativa para acessar a Gestão de Usuários do sistema.</p>";
    echo "<p style='margin-top: 15px;'><a href='index.php?page=dashboard' class='btn btn-secondary'>Voltar ao Dashboard</a></p>";
    echo "</div></div>";
    exit;
}

$message = '';
$messageType = ''; // 'success' ou 'danger'

// Captura parâmetros de ação para edição ou exclusão
$action = $_GET['action'] ?? 'list';
$editId = $_GET['id'] ?? null;

// Instância de dados padrão para o formulário
$editData = [
    'id_pessoa' => '',
    'login'     => '',
    'ativo'     => 1,
    'nome'      => ''
];

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    // 1. AÇÃO: CRIAR CREDENCIAIS PARA UM FUNCIONÁRIO
    if ($formAction === 'create') {
        $id_pessoa = filter_input(INPUT_POST, 'id_pessoa', FILTER_VALIDATE_INT);
        $login     = trim(filter_input(INPUT_POST, 'login', FILTER_DEFAULT));
        $password  = $_POST['password'] ?? '';
        $ativo     = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

        if (!$id_pessoa || empty($login) || empty($password)) {
            $message = 'Por favor, selecione o funcionário e defina o usuário e a senha.';
            $messageType = 'danger';
        } else {
            try {
                // Validação de duplicados (nome de usuário exclusivo no sistema)
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM usuario WHERE login = :login");
                $stmtCheck->execute([':login' => $login]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Este nome de usuário já está em uso. Escolha outro.';
                    $messageType = 'danger';
                } else {
                    // Gera o hash seguro da senha.
                    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                    
                    $sqlInsert = "INSERT INTO usuario (id_pessoa, login, senha, ativo) VALUES (:id_pessoa, :login, :senha, :ativo)";
                    $stmtInsert = $pdo->prepare($sqlInsert);
                    $stmtInsert->execute([
                        ':id_pessoa' => $id_pessoa,
                        ':login'     => $login,
                        ':senha'     => $passwordHash,
                        ':ativo'     => $ativo
                    ]);

                    $message = 'Credenciais de usuário criadas com sucesso!';
                    $messageType = 'success';
                    $action = 'list';
                }
            } catch (PDOException $e) {
                $message = 'Erro ao registrar o usuário no servidor.';
                $messageType = 'danger';
            }
        }
    }

    // 2. AÇÃO: ATUALIZAR CREDENCIAIS EXISTENTES
    if ($formAction === 'update' && $editId) {
        $login    = trim(filter_input(INPUT_POST, 'login', FILTER_DEFAULT));
        $password = $_POST['password'] ?? '';
        $ativo    = isset($_POST['ativo']) ? (int)$_POST['ativo'] : 1;

        if (empty($login)) {
            $message = 'O nome de usuário não pode ficar vazio.';
            $messageType = 'danger';
        } else {
            try {
                // Verifica duplicados excluindo o próprio ID
                $stmtCheck = $pdo->prepare("SELECT COUNT(*) FROM usuario WHERE login = :login AND id_pessoa != :id");
                $stmtCheck->execute([':login' => $login, ':id' => $editId]);
                
                if ($stmtCheck->fetchColumn() > 0) {
                    $message = 'Outro usuário já cadastrado utiliza este nome de login.';
                    $messageType = 'danger';
                } else {
                    // Impede que o Administrador desative a própria conta em uso.
                    if ($editId == $_SESSION['user_id'] && $ativo == 0) {
                        $message = 'Por motivos de segurança, você não pode desativar o usuário com o qual está logado.';
                        $messageType = 'danger';
                    } else {
                        // Se preencheu uma nova senha, atualiza-a.
                        if (!empty($password)) {
                            $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                            $sqlUpdate = "UPDATE usuario SET login = :login, senha = :senha, ativo = :ativo WHERE id_pessoa = :id";
                            $stmtUpdate = $pdo->prepare($sqlUpdate);
                            $stmtUpdate->execute([
                                ':login' => $login,
                                ':senha' => $passwordHash,
                                ':ativo' => $ativo,
                                ':id'    => $editId
                            ]);
                        } else {
                            // Se não preencheu senha, atualiza apenas o login e status.
                            $sqlUpdate = "UPDATE usuario SET login = :login, ativo = :ativo WHERE id_pessoa = :id";
                            $stmtUpdate = $pdo->prepare($sqlUpdate);
                            $stmtUpdate->execute([
                                ':login' => $login,
                                ':ativo' => $ativo,
                                ':id'    => $editId
                            ]);
                        }

                        $message = 'Dados do usuário atualizados com sucesso!';
                        $messageType = 'success';
                        $action = 'list';
                    }
                }
            } catch (PDOException $e) {
                $message = 'Erro ao atualizar os dados do usuário.';
                $messageType = 'danger';
            }
        }
    }
}

// ==========================================================================
// Processamento de Ações de URL (GET)
// ==========================================================================
// 1. CARREGAR DADOS DO USUÁRIO PARA EDIÇÃO
if ($action === 'edit' && $editId) {
    try {
        $stmtEdit = $pdo->prepare("
            SELECT u.id_pessoa, u.login, u.ativo, p.nome 
            FROM usuario u 
            INNER JOIN pessoa p ON u.id_pessoa = p.id_pessoa 
            WHERE u.id_pessoa = :id LIMIT 1
        ");
        $stmtEdit->execute([':id' => $editId]);
        $data = $stmtEdit->fetch();
        
        if ($data) {
            $editData = $data;
        } else {
            $message = 'Usuário não encontrado.';
            $messageType = 'danger';
            $action = 'list';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao carregar dados do usuário.';
        $messageType = 'danger';
    }
}

// 2. EXECUTAR A EXCLUSÃO DE LOGIN (remove apenas o acesso, mantendo o funcionário intacto)
if ($action === 'delete' && $editId) {
    try {
        if ($editId == $_SESSION['user_id']) {
            $message = 'Você não pode remover as credenciais do usuário com o qual está logado!';
            $messageType = 'danger';
        } else {
            $stmtDel = $pdo->prepare("DELETE FROM usuario WHERE id_pessoa = :id");
            $stmtDel->execute([':id' => $editId]);
            $message = 'Credenciais de acesso removidas com sucesso!';
            $messageType = 'success';
        }
    } catch (PDOException $e) {
        $message = 'Erro ao tentar remover as credenciais do usuário.';
        $messageType = 'danger';
    }
    $action = 'list';
}

// ==========================================================================
// Recuperação de Listas de Suporte (Funcionários sem login e Usuários Cadastrados)
// ==========================================================================
$funcionariosSemLogin = [];
$usuariosCadastrados = [];

try {
    // 1. Lista funcionários ativos que não possuem credenciais na tabela usuario
    $sqlSemLogin = "
        SELECT p.id_pessoa, p.nome, f.cargo, f.perfil_acesso
        FROM funcionario f 
        INNER JOIN pessoa p ON f.id_pessoa = p.id_pessoa 
        LEFT JOIN usuario u ON f.id_pessoa = u.id_pessoa 
        WHERE u.id_pessoa IS NULL AND p.status = 1 AND f.status = 1
        ORDER BY p.nome ASC
    ";
    $funcionariosSemLogin = $pdo->query($sqlSemLogin)->fetchAll();

    // 2. Lista os usuários cadastrados e as suas informações de perfil
    $sqlUsuarios = "
        SELECT u.id_pessoa, u.login, u.ativo, p.nome, f.cargo, f.perfil_acesso
        FROM usuario u 
        INNER JOIN pessoa p ON u.id_pessoa = p.id_pessoa 
        INNER JOIN funcionario f ON u.id_pessoa = f.id_pessoa 
        ORDER BY p.nome ASC
    ";
    $usuariosCadastrados = $pdo->query($sqlUsuarios)->fetchAll();
} catch (PDOException $e) {
    $message = 'Erro ao processar as listas de usuários a partir do banco de dados.';
    $messageType = 'danger';
}
?>

<!-- Feedback de Mensagens do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Container para as Notificações de Erro em Tela -->
        <div id="custom-alert-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

        <!-- Modal de Confirmação Customizado (Substituta de confirm()) -->
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

        <div class="users-layout">
            
            <!-- Coluna Esquerda: Usuários Cadastrados no Sistema -->
            <div class="card">
                <div class="module-header">
                    <div>
                        <h2 class="module-title">Usuários de Acesso</h2>
                        <p class="module-subtitle"><?php echo count($usuariosCadastrados); ?> usuário(s) com credenciais cadastradas.</p>
                    </div>
                </div>
                
                <?php if (empty($usuariosCadastrados)): ?>
                    <p style="color: var(--text-muted); font-size: 14px;">Nenhum usuário cadastrado.</p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th>Funcionário</th>
                                    <th>Login</th>
                                    <th>Cargo</th>
                                    <th>Status</th>
                                    <th style="width: 150px; text-align: right;">Ações</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($usuariosCadastrados as $usr): ?>
                                    <tr style="<?php echo ($usr['ativo'] == 0) ? 'opacity: 0.6;' : ''; ?>">
                                        <td>
                                            <div class="table-primary-text"><?php echo htmlspecialchars($usr['nome']); ?></div>
                                            <?php if ($usr['id_pessoa'] == $_SESSION['user_id']): ?>
                                                <div class="table-muted-text">Você</div>
                                            <?php else: ?>
                                                <div class="table-muted-text">Funcionário</div>
                                            <?php endif; ?>
                                        </td>
                                        <td><code><?php echo htmlspecialchars($usr['login']); ?></code></td>
                                        <td>
                                            <span class="badge" style="background-color: var(--info-bg); color: var(--info);">
                                                <?php echo htmlspecialchars($usr['perfil_acesso'] ?: $usr['cargo']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($usr['ativo'] == 1): ?>
                                                <span class="badge badge-finalizada">Ativo</span>
                                            <?php else: ?>
                                                <span class="badge badge-cancelada">Inativo</span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right;">
                                            <div class="action-group">
                                            <a href="index.php?page=usuarios&action=edit&id=<?php echo $usr['id_pessoa']; ?>" class="btn btn-secondary" style="padding: 4px 10px; font-size: 12px; margin-right: 4px;">
                                                Editar
                                            </a>
                                            <button type="button" class="btn btn-danger btn-confirm-action" 
                                                    data-url="index.php?page=usuarios&action=delete&id=<?php echo $usr['id_pessoa']; ?>" 
                                                    data-text="Tem certeza que deseja remover o login de acesso do funcionário '<?php echo htmlspecialchars($usr['nome']); ?>'?" 
                                                    style="padding: 4px 10px; font-size: 12px; <?php echo ($usr['id_pessoa'] == $_SESSION['user_id']) ? 'opacity:0.4; cursor:not-allowed;' : ''; ?>"
                                                    <?php echo ($usr['id_pessoa'] == $_SESSION['user_id']) ? 'disabled' : ''; ?>>
                                                Excluir
                                            </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <!-- Coluna Direita: Painel Dinâmico de Ação -->
            <div class="card">
                <?php if ($action === 'edit' && $editId): ?>
                    <div class="module-header">
                        <div>
                            <h2 class="module-title" style="color: var(--warning);">Editar Credenciais</h2>
                            <p class="module-subtitle">Atualize login, senha e status de acesso.</p>
                        </div>
                    </div>
                    <form id="formUsuario" action="index.php?page=usuarios&action=edit&id=<?php echo $editId; ?>" method="POST" autocomplete="off">
                        <input type="hidden" name="form_action" value="update">
                        <div class="form-section" style="margin-top: 0;">
                            <div class="form-section-title">Dados de acesso</div>
                            <div class="form-group">
                                <label class="form-label">Funcionário Associado</label>
                                <input type="text" class="form-control" value="<?php echo htmlspecialchars($editData['nome']); ?>" disabled style="background-color: var(--bg-card-elevated); color: var(--text-muted);">
                            </div>

                            <div class="form-group">
                                <label for="login" class="form-label">Nome de Usuário (Login)</label>
                                <input type="text" id="login" name="login" class="form-control" value="<?php echo htmlspecialchars($editData['login']); ?>" required>
                            </div>

                            <div class="form-group">
                                <label for="password" class="form-label">Nova Senha</label>
                                <input type="password" id="password" name="password" class="form-control" placeholder="Deixe em branco para manter">
                                <small style="display: block; margin-top: 6px; font-size: 11.5px; color: var(--text-muted);">Preencha este campo apenas se quiser alterar a senha atual.</small>
                            </div>

                            <div class="form-group">
                                <label for="ativo" class="form-label">Acesso Ativo</label>
                                <select id="ativo" name="ativo" class="form-control">
                                    <option value="1" <?php echo ($editData['ativo'] == 1) ? 'selected' : ''; ?>>Sim (Permitir Entrada)</option>
                                    <option value="0" <?php echo ($editData['ativo'] == 0) ? 'selected' : ''; ?>>Não (Bloquear Entrada)</option>
                                </select>
                            </div>
                        </div>

                        <div style="display: flex; gap: 10px; margin-top: 24px;">
                            <button type="submit" class="btn btn-primary" style="flex-grow: 1;">Salvar</button>
                            <a href="index.php?page=usuarios" class="btn btn-secondary">Cancelar</a>
                        </div>
                    </form>
                <?php else: ?>
                    <div class="module-header">
                        <div>
                            <h2 class="module-title">Novas Credenciais</h2>
                            <p class="module-subtitle">Crie acesso para funcionários ativos sem login.</p>
                        </div>
                    </div>
                    
                    <?php if (empty($funcionariosSemLogin)): ?>
                        <p style="color: var(--text-muted); font-size: 13.5px; line-height: 1.5; margin: 0;">
                            Todos os funcionários ativos já possuem credenciais de acesso cadastradas no sistema.
                        </p>
                    <?php else: ?>
                        <form id="formUsuario" action="index.php?page=usuarios" method="POST" autocomplete="off">
                            <input type="hidden" name="form_action" value="create">
                            <div class="form-section" style="margin-top: 0;">
                                <div class="form-section-title">Novo acesso</div>
                                <div class="form-group">
                                    <label for="id_pessoa" class="form-label">Selecionar Funcionário</label>
                                    <select id="id_pessoa" name="id_pessoa" class="form-control" required>
                                        <option value="">Escolha um funcionário...</option>
                                        <?php foreach ($funcionariosSemLogin as $fsl): ?>
                                            <option value="<?php echo $fsl['id_pessoa']; ?>">
                                                <?php echo htmlspecialchars($fsl['nome']) . ' (' . htmlspecialchars($fsl['perfil_acesso'] ?: $fsl['cargo']) . ')'; ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label for="login" class="form-label">Nome de Usuário</label>
                                    <input type="text" id="login" name="login" class="form-control" placeholder="Ex: joao.tecnico" required>
                                </div>

                                <div class="form-group">
                                    <label for="password" class="form-label">Senha Inicial</label>
                                    <input type="password" id="password" name="password" class="form-control" placeholder="Digite a senha inicial" required>
                                </div>

                                <div class="form-group">
                                    <label for="ativo" class="form-label">Status da Conta</label>
                                    <select id="ativo" name="ativo" class="form-control">
                                        <option value="1">Ativo (Entrada Autorizada)</option>
                                        <option value="0">Inativo (Entrada Bloqueada)</option>
                                    </select>
                                </div>
                            </div>

                            <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 24px;">
                                Registrar Usuário
                            </button>
                        </form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>

        </div>
    </div>
</div>


<!-- Lógica JS Avançada de Teclado, Notificação Customizada e Modal -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formUsuario');
    const modal = document.getElementById('custom-confirm-modal');
    const modalText = document.getElementById('confirm-modal-text');
    const modalOk = document.getElementById('confirm-modal-ok');
    const modalCancel = document.getElementById('confirm-modal-cancel');

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

    // 3. VALIDAÇÃO DE TAMANHO DE DADOS DO FORMULÁRIO
    if (form) {
        form.addEventListener('submit', function(e) {
            const loginInput = document.getElementById('login');
            const passInput = document.getElementById('password');

            if (loginInput && loginInput.value.trim().length < 3) {
                e.preventDefault();
                mostrarNotificacaoErro('O nome de usuário deve conter pelo menos 3 caracteres.');
                loginInput.focus();
                return false;
            }

            // Valida apenas para cadastro de nova senha
            if (passInput && passInput.hasAttribute('required') && passInput.value.length < 4) {
                e.preventDefault();
                mostrarNotificacaoErro('A senha deve conter pelo menos 4 caracteres.');
                passInput.focus();
                return false;
            }
        });
    }

    // 4. CONTROLE DO MODAL DE CONFIRMAÇÃO DE EXCLUSÃO (Livre de confirm())
    document.querySelectorAll('.btn-confirm-action').forEach(btn => {
        btn.addEventListener('click', function(e) {
            e.preventDefault();
            if (this.hasAttribute('disabled')) return;

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
