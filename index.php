<?php
/**
 * OS Master - Ponto de entrada principal.
 *
 * Centraliza sessao, roteamento, layout base, menu lateral e carregamento das
 * paginas internas do sistema.
 */

ob_start();

define('BASE_PATH', __DIR__);
define('CONFIG_PATH', BASE_PATH . '/config');
define('PAGES_PATH', BASE_PATH . '/pages');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$databaseConfigFile = CONFIG_PATH . '/database.php';

if (!file_exists($databaseConfigFile)) {
    header('Location: install.php');
    exit;
}

require_once $databaseConfigFile;

$isLoggedIn = isset($_SESSION['user_id']);
$userName = $_SESSION['user_name'] ?? '';
$userRole = $_SESSION['user_role'] ?? '';

$pageMeta = [
    'dashboard' => ['title' => 'Painel de Controle'],
    'login' => ['title' => 'Login', 'layout' => false],
    'estados' => ['title' => 'Configuração de Estados'],
    'cidades' => ['title' => 'Configuração de Cidades'],
    'clientes' => ['title' => 'Gestão de Clientes'],
    'funcionarios' => ['title' => 'Gestão de Funcionários'],
    'usuarios' => ['title' => 'Gestão de Usuários'],
    'equipamentos' => ['title' => 'Gestão de Equipamentos'],
    'os' => ['title' => 'Gestão de Ordens de Serviço'],
    'produtos' => ['title' => 'Gestão de Inventário'],
    'folha' => ['title' => 'Folha de Pagamento'],
    'historico_os' => ['title' => 'Histórico e Auditoria'],
    'relatorios' => ['title' => 'Relatório e Demonstrativo de Lucratividade', 'admin_only' => true],
    'config' => ['title' => 'Configurações Gerais', 'admin_only' => true],
    'imprimir_os' => ['title' => 'Imprimir OS', 'layout' => false],
    'imprimir_retirada' => ['title' => 'Imprimir Retirada', 'layout' => false],
];

$menuItems = [
    ['page' => 'dashboard', 'label' => 'Dashboard'],
    ['page' => 'estados', 'label' => 'Estados'],
    ['page' => 'cidades', 'label' => 'Cidades'],
    ['page' => 'clientes', 'label' => 'Clientes'],
    ['page' => 'funcionarios', 'label' => 'Funcionários'],
    ['page' => 'usuarios', 'label' => 'Usuários'],
    ['page' => 'equipamentos', 'label' => 'Equipamentos'],
    ['page' => 'os', 'label' => 'Ordens de Serviço', 'active_pages' => ['os', 'historico_os']],
    ['page' => 'produtos', 'label' => 'Produtos'],
    ['page' => 'folha', 'label' => 'Folha de Pagamento'],
    ['page' => 'relatorios', 'label' => 'Relatórios', 'admin_only' => true],
    ['page' => 'config', 'label' => 'Configurações', 'admin_only' => true],
];

$page = isset($_GET['page']) ? (string) filter_var($_GET['page'], FILTER_DEFAULT) : 'dashboard';
$isAllowedPage = isset($pageMeta[$page]);
$hasPageAccess = $isAllowedPage && (!(($pageMeta[$page]['admin_only'] ?? false)) || $userRole === 'Administrador');

if (!$isLoggedIn && $page !== 'login') {
    header('Location: index.php?page=login');
    exit;
}

if (isset($_GET['ajax_action'])) {
    if ($hasPageAccess) {
        require_once PAGES_PATH . '/' . $page . '.php';
        exit;
    }

    http_response_code($isAllowedPage ? 403 : 404);
    exit;
}

$hasLayout = $hasPageAccess && ($pageMeta[$page]['layout'] ?? true);
$pageFile = $isAllowedPage ? PAGES_PATH . '/' . $page . '.php' : null;
?>
<!DOCTYPE html>
<html lang="pt">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OS Master - Gestão de Ordens de Serviço</title>
    <script>
        (function () {
            var savedTheme = localStorage.getItem('osMasterTheme');
            var prefersLight = window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches;
            document.documentElement.dataset.theme = savedTheme || (prefersLight ? 'light' : 'dark');
        })();
    </script>
    <link rel="stylesheet" href="assets/css/style.css?v=4">
</head>
<body>
    <div id="app">
        <?php if ($hasPageAccess && file_exists($pageFile)): ?>
            <?php if ($hasLayout): ?>
                <div class="layout-wrapper">
                    <aside class="sidebar">
                        <div class="sidebar-brand">
                            <span>OS Master</span>
                        </div>
                        <ul class="sidebar-menu">
                            <?php foreach ($menuItems as $item): ?>
                                <?php
                                if (($item['admin_only'] ?? false) && $userRole !== 'Administrador') {
                                    continue;
                                }

                                $activePages = $item['active_pages'] ?? [$item['page']];
                                $activeClass = in_array($page, $activePages, true) ? 'active' : '';
                                ?>
                                <li class="sidebar-item <?php echo $activeClass; ?>">
                                    <a href="index.php?page=<?php echo htmlspecialchars($item['page']); ?>">
                                        <?php echo htmlspecialchars($item['label']); ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                            <li class="sidebar-item">
                                <a href="auth/logout.php" style="color: #f87171;">Sair do Sistema</a>
                            </li>
                        </ul>
                        <div class="sidebar-footer">v1.0 - Julian Braga</div>
                    </aside>

                    <div class="main-content">
                        <header class="top-header">
                            <div class="page-title">
                                <?php echo htmlspecialchars($pageMeta[$page]['title']); ?>
                            </div>
                            <div class="user-nav">
                                <button type="button" class="theme-toggle" id="themeToggle" aria-label="Alternar tema" title="Alternar tema">
                                    <span class="theme-toggle-icon" aria-hidden="true"></span>
                                    <span class="theme-toggle-text">Tema</span>
                                </button>
                                <div class="user-profile">
                                    <span>Olá, <strong><?php echo htmlspecialchars($userName); ?></strong> (<?php echo htmlspecialchars($userRole); ?>)</span>
                                    <div class="user-avatar"><?php echo htmlspecialchars(strtoupper(substr($userName, 0, 1))); ?></div>
                                </div>
                            </div>
                        </header>

                        <div class="page-container">
                            <?php require_once $pageFile; ?>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <?php require_once $pageFile; ?>
            <?php endif; ?>
        <?php elseif ($hasPageAccess): ?>
            <div class="page-container" style="display:flex; align-items:center; justify-content:center; min-height:calc(100vh - 80px);">
                <div class="card" style="max-width:500px; text-align:center; border-color:var(--border-color);">
                    <h3 style="color:var(--primary); margin-bottom:12px; font-size:20px; font-weight:700;">Módulo em Desenvolvimento</h3>
                    <p style="font-size:14.5px; line-height:1.6; color:var(--text-muted);">A página correspondente ao arquivo físico ainda não foi criada.</p>
                    <p style="margin-top:24px;"><a href="index.php?page=dashboard" class="btn btn-primary">Voltar ao Dashboard</a></p>
                </div>
            </div>
        <?php else: ?>
            <div class="page-container">
                <div class="card" style="border-color:var(--danger); background-color:rgba(239, 68, 68,0.05);">
                    <h3 style="color:var(--danger); font-weight:700;">Acesso Inválido</h3>
                    <p style="color:var(--text-muted);">A página solicitada não existe ou não está autorizada.</p>
                </div>
            </div>
        <?php endif; ?>
    </div>
    <script>
        (function () {
            var toggle = document.getElementById('themeToggle');

            function applyTheme(theme) {
                document.documentElement.dataset.theme = theme;
                localStorage.setItem('osMasterTheme', theme);
                if (toggle) {
                    toggle.setAttribute('aria-pressed', theme === 'light' ? 'true' : 'false');
                    toggle.querySelector('.theme-toggle-text').textContent = theme === 'light' ? 'Claro' : 'Escuro';
                }
            }

            applyTheme(document.documentElement.dataset.theme || 'dark');

            if (toggle) {
                toggle.addEventListener('click', function () {
                    var current = document.documentElement.dataset.theme === 'light' ? 'light' : 'dark';
                    applyTheme(current === 'light' ? 'dark' : 'light');
                });
            }
        })();
    </script>
</body>
</html>
