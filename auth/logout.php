<?php
/**
 * OS Master - Encerramento de Sessão Securo (Logout)
 * * Este ficheiro é responsável por limpar todas as variáveis de sessão,
 * destruir o cookie de sessão no navegador do utilizador e redirecionar
 * o utilizador de forma segura de volta para o ecrã de início de sessão.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.0
 */

// Inicia a sessão se ainda não estiver ativa para poder destruí-la
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Limpa todas as variáveis de sessão em memória
$_SESSION = array();

// 2. Destrói o cookie de sessão diretamente no navegador do utilizador
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}

// 3. Destrói a sessão no servidor de forma definitiva
session_destroy();

// 4. Redireciona o utilizador para o ecrã de início de sessão (login) na raiz do projeto
header("Location: ../index.php?page=login");
exit;