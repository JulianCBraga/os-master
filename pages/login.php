<?php
/**
 * OS Master - Página de Login e Autenticação Segura
 * * Este ficheiro processa o formulário de login, valida as credenciais contra
 * a base de dados de forma segura e inicia a sessão do utilizador com as suas permissões.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.0
 */

// Impede o acesso direto a este ficheiro fora do index.php (segurança contra LFI)
if (!defined('BASE_PATH')) {
    header("Location: ../index.php");
    exit;
}

// Se o utilizador já estiver autenticado, redireciona imediatamente para o dashboard
if (isset($_SESSION['user_id'])) {
    header("Location: index.php?page=dashboard");
    exit;
}

$loginError = '';

// Processa a submissão do formulário de autenticação
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    // Sanatização básica das entradas de dados
    $username = filter_input(INPUT_POST, 'username', FILTER_DEFAULT);
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $loginError = 'Por favor, preencha todos os campos do formulário.';
    } else {
        try {
            // Consulta para verificar o utilizador na tabela 'usuario'
            // Junta com 'funcionario' e 'pessoa' para obter os dados do perfil e cargo de uma vez só
            $sql = "SELECT u.id_pessoa, u.senha, u.ativo, p.nome, f.cargo, f.perfil_acesso, f.status AS funcionario_status
                    FROM usuario u
                    INNER JOIN pessoa p ON u.id_pessoa = p.id_pessoa
                    LEFT JOIN funcionario f ON u.id_pessoa = f.id_pessoa
                    WHERE u.login = :login LIMIT 1";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([':login' => $username]);
            $user = $stmt->fetch();

            // Verifica se o utilizador existe e se está ativo no sistema
            if ($user) {
                if ($user['ativo'] == 0 || (isset($user['funcionario_status']) && $user['funcionario_status'] == 0)) {
                    $loginError = 'Esta conta de usuário encontra-se desativada no sistema.';
                } else {
                    // Valida a palavra-passe encriptada na base de dados
                    if (password_verify($password, $user['senha'])) {
                        // Autenticação bem-sucedida! Guarda os dados estruturais na sessão segura
                        $_SESSION['user_id'] = $user['id_pessoa'];
                        $_SESSION['user_name'] = $user['nome'];
                        $_SESSION['user_login'] = $username;
                        $_SESSION['user_role'] = $user['perfil_acesso'] ?? $user['cargo'] ?? 'Atendente'; // Padrão caso não tenha perfil definido
                        
                        // Redireciona para o painel principal (Dashboard)
                        header("Location: index.php?page=dashboard");
                        exit;
                    } else {
                        // Mensagem genérica por questões de segurança (evita enumeração de usuários)
                        $loginError = 'Usuário ou senha inválidos.';
                    }
                }
            } else {
                // Mensagem genérica por questões de segurança (evita enumeração de usuários)
                $loginError = 'Usuário ou senha inválidos.';
            }

        } catch (PDOException $e) {
            $loginError = 'Ocorreu um erro no servidor ao processar o seu pedido.';
        }
    }
}
?>

<div class="login-wrapper">
    <div class="login-card">
        <div class="login-header">
            <h2>OS Master</h2>
            <p>Insira as suas credenciais para aceder ao sistema</p>
        </div>

        <?php if (!empty($loginError)): ?>
            <div class="alert alert-danger" style="margin-bottom: 20px;">
                <strong>Erro:</strong> <?php echo htmlspecialchars($loginError); ?>
            </div>
        <?php endif; ?>

        <form action="index.php?page=login" method="POST" autocomplete="off">
            <input type="hidden" name="action" value="login">
            
            <div class="form-group">
                <label for="username" class="form-label">Nome de Utilizador</label>
                <input type="text" id="username" name="username" class="form-control" placeholder="Ex: admin" required autofocus>
            </div>

            <div class="form-group" style="margin-bottom: 24px;">
                <label for="password" class="form-label">Palavra-passe</label>
                <input type="password" id="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <button type="submit" class="btn btn-primary w-full" style="width: 100%; padding: 12px;">
                Entrar no Sistema
            </button>
        </form>
    </div>
</div>

<style>
    /* Estilos específicos para centralizar o ecrã de login de forma elegante */
    .login-wrapper {
        display: flex;
        align-items: center;
        justify-content: center;
        min-height: calc(100vh - 40px);
        width: 100%;
        padding: 20px;
        box-sizing: border-box;
    }

    .login-card {
        background-color: var(--bg-card);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        width: 100%;
        max-width: 420px;
        padding: 40px;
        box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.3), 0 8px 10px -6px rgba(0, 0, 0, 0.3);
    }

    .login-header {
        text-align: center;
        margin-bottom: 30px;
    }

    .login-header h2 {
        font-size: 28px;
        font-weight: 800;
        color: var(--text-main);
        margin-bottom: 8px;
        letter-spacing: -0.5px;
    }

    .login-header p {
        font-size: 13.5px;
        color: var(--text-muted);
        line-height: 1.4;
    }
</style>
