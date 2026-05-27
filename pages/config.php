<?php
/**
 * OS Master - Configurações Gerais do Sistema
 * * Este ficheiro permite gerir as parametrizações do sistema, dados da empresa,
 * termo de compromisso, logótipo corporativo e a moeda padrão (dinâmica) utilizada nas listagens.
 * * @package OSMaster
 * @author Julian C. Braga
 * @version 1.4
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

// Apenas administradores podem configurar as definições globais do sistema
if ($_SESSION['user_role'] !== 'Administrador') {
    echo "<div class='page-container'>";
    echo "<div class='card' style='border-color: var(--danger); background-color: var(--danger-bg);'>";
    echo "<h3 style='color: var(--danger); font-weight: 700; margin-bottom: 10px;'>Acesso Negado</h3>";
    echo "<p style='color: var(--text-muted);'>Não tem permissões administrativas para aceder às configurações globais do sistema.</p>";
    echo "<p style='margin-top: 15px;'><a href='index.php?page=dashboard' class='btn btn-secondary'>Voltar ao Dashboard</a></p>";
    echo "</div></div>";
    exit;
}

$message = '';
$messageType = '';

// ==========================================================================
// Recuperação das Configurações Atuais (Executada antes para conhecer dados prévios)
// ==========================================================================
$configData = [];
try {
    $stmt = $pdo->query("SELECT * FROM config_sistema WHERE id_config = 1 LIMIT 1");
    $configData = $stmt->fetch();
    
    // Se o banco estiver vazio por algum motivo, inicializa valores vazios de fallback
    if (!$configData) {
        $configData = [
            'nome_fantasia'           => 'OS Master Assistência',
            'razao_social'            => 'OS Master Soluções LTDA',
            'cnpj'                    => '00.000.000/0001-00',
            'telefone'                => '(67) 3411-0000',
            'email'                   => 'contacto@osmaster.com',
            'endereco_completo'       => 'Av. Presidente Vargas, 1200 - Centro',
            'logo_caminho'            => null,
            'termo_compromisso_texto' => 'Termos de teste da ordem de serviço.',
            'prazo_maximo_retirada'   => 90,
            'moeda'                   => 'R$'
        ];
    }
} catch (PDOException $e) {
    // Tratamento de falhas
}

// ==========================================================================
// Processamento de Ações do Formulário (POST)
// ==========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = $_POST['form_action'] ?? '';

    if ($formAction === 'update_config') {
        $nome_fantasia           = trim(filter_input(INPUT_POST, 'nome_fantasia', FILTER_DEFAULT));
        $razao_social            = trim(filter_input(INPUT_POST, 'razao_social', FILTER_DEFAULT));
        $cnpj                    = trim(filter_input(INPUT_POST, 'cnpj', FILTER_DEFAULT));
        $telefone                = trim(filter_input(INPUT_POST, 'telefone', FILTER_DEFAULT));
        $email                   = trim(filter_input(INPUT_POST, 'email', FILTER_VALIDATE_EMAIL));
        $endereco_completo       = trim(filter_input(INPUT_POST, 'endereco_completo', FILTER_DEFAULT));
        $termo_compromisso_texto = trim(filter_input(INPUT_POST, 'termo_compromisso_texto', FILTER_DEFAULT));
        $prazo_maximo_retirada   = filter_input(INPUT_POST, 'prazo_maximo_retirada', FILTER_VALIDATE_INT);
        $moeda                   = trim(filter_input(INPUT_POST, 'moeda', FILTER_DEFAULT)) ?: 'R$';
        $logo_caminho            = $configData['logo_caminho'] ?? null;

        if (empty($nome_fantasia) || !$prazo_maximo_retirada) {
            $message = 'Nome Fantasia da empresa e Prazo Máximo de Retirada são de preenchimento obrigatório.';
            $messageType = 'danger';
        } else {
            try {
                // 1. Processamento e Upload Seguro de Logótipo
                if (isset($_FILES['logo_upload']) && $_FILES['logo_upload']['error'] === UPLOAD_ERR_OK) {
                    $fileTmpPath = $_FILES['logo_upload']['tmp_name'];
                    $fileName    = $_FILES['logo_upload']['name'];
                    $fileSize    = $_FILES['logo_upload']['size'];
                    
                    $fileNameCmps  = explode(".", $fileName);
                    $fileExtension = strtolower(end($fileNameCmps));
                    
                    // Extensões de imagem autorizadas
                    $allowedfileExtensions = array('jpg', 'jpeg', 'png', 'gif', 'svg');
                    
                    if (in_array($fileExtension, $allowedfileExtensions)) {
                        if ($fileSize < 2 * 1024 * 1024) { // Limite estrito de 2 Megabytes
                            $uploadFileDir = BASE_PATH . '/uploads/';
                            
                            // Cria a pasta de uploads de forma automática caso não exista
                            if (!is_dir($uploadFileDir)) {
                                mkdir($uploadFileDir, 0755, true);
                            }
                            
                            // Gera um nome único e limpo para evitar colisões
                            $newFileName = 'logo_' . time() . '.' . $fileExtension;
                            $dest_path   = $uploadFileDir . $newFileName;
                            
                            if (move_uploaded_file($fileTmpPath, $dest_path)) {
                                // Elimina o ficheiro de imagem antigo no servidor se existir
                                if (!empty($logo_caminho) && file_exists(BASE_PATH . '/' . $logo_caminho)) {
                                    @unlink(BASE_PATH . '/' . $logo_caminho);
                                }
                                $logo_caminho = 'uploads/' . $newFileName;
                            } else {
                                throw new Exception("Ocorreu um erro ao mover a imagem para o diretório de uploads.");
                            }
                        } else {
                            throw new Exception("O ficheiro enviado excede o tamanho máximo de 2MB.");
                        }
                    } else {
                        throw new Exception("Formato de ficheiro não suportado. Utilize PNG, JPG, JPEG, GIF ou SVG.");
                    }
                }

                // Atualiza de forma centralizada as configurações globais (ID 1 fixo no sistema)
                $sqlUpdate = "UPDATE config_sistema SET 
                                nome_fantasia = :nome_fantasia, 
                                razao_social = :razao_social, 
                                cnpj = :cnpj, 
                                telefone = :telefone, 
                                email = :email, 
                                endereco_completo = :endereco_completo, 
                                termo_compromisso_texto = :termo_compromisso_texto, 
                                prazo_maximo_retirada = :prazo_maximo_retirada,
                                moeda = :moeda,
                                logo_caminho = :logo_caminho
                              WHERE id_config = 1";
                
                $stmt = $pdo->prepare($sqlUpdate);
                $stmt->execute([
                    ':nome_fantasia'           => $nome_fantasia,
                    ':razao_social'            => $razao_social,
                    ':cnpj'                    => $cnpj,
                    ':telefone'                => $telefone,
                    ':email'                   => $email,
                    ':endereco_completo'       => $endereco_completo,
                    ':termo_compromisso_texto' => $termo_compromisso_texto,
                    ':prazo_maximo_retirada'   => $prazo_maximo_retirada,
                    ':moeda'                   => $moeda,
                    ':logo_caminho'            => $logo_caminho
                ]);

                $message = 'Configurações do sistema atualizadas com sucesso!';
                $messageType = 'success';
                
                // Recarrega as configurações atualizadas para exibição imediata
                $stmtRecarregar = $pdo->query("SELECT * FROM config_sistema WHERE id_config = 1 LIMIT 1");
                $configData = $stmtRecarregar->fetch();
                
            } catch (Exception $e) {
                $message = 'Erro ao processar as alterações: ' . $e->getMessage();
                $messageType = 'danger';
            }
        }
    }
}
?>

<!-- Feedback de Alertas do Sistema -->
        <?php if (!empty($message)): ?>
            <div class="alert alert-<?php echo $messageType; ?>" style="margin-bottom: 24px;">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Container de Alertas Assíncronos -->
        <div id="custom-alert-container" style="position: fixed; top: 20px; right: 20px; z-index: 9999; display: flex; flex-direction: column; gap: 10px;"></div>

        <div class="card">
            <h2 class="card-title">Parâmetros do Estabelecimento e Regras de Negócio</h2>

            <form id="formConfig" action="index.php?page=config" method="POST" autocomplete="off" enctype="multipart/form-data">
                <input type="hidden" name="form_action" value="update_config">

                <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-bottom: 16px;">1. Informações Institucionais e Logótipo</h3>
                
                <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 32px; align-items: start; margin-bottom: 20px;">
                    
                    <!-- Lado Esquerdo: Identificação e Dados Fiscais -->
                    <div style="display: flex; flex-direction: column; gap: 12px; width: 100%;">
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="nome_fantasia" class="form-label">Nome Fantasia (Exibição nas faturas)</label>
                                <input type="text" id="nome_fantasia" name="nome_fantasia" class="form-control" value="<?php echo htmlspecialchars($configData['nome_fantasia'] ?? ''); ?>" required>
                            </div>
                            <div class="form-group">
                                <label for="razao_social" class="form-label">Razão Social</label>
                                <input type="text" id="razao_social" name="razao_social" class="form-control" value="<?php echo htmlspecialchars($configData['razao_social'] ?? ''); ?>">
                            </div>
                        </div>

                        <div style="display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="cnpj" class="form-label">CNPJ da Empresa</label>
                                <input type="text" id="cnpj" name="cnpj" class="form-control" placeholder="00.000.000/0001-00" value="<?php echo htmlspecialchars($configData['cnpj'] ?? ''); ?>" maxlength="18">
                            </div>
                            <div class="form-group">
                                <label for="telefone" class="form-label">Telefone de Contacto</label>
                                <input type="text" id="telefone" name="telefone" class="form-control" placeholder="(67) 3411-0000" value="<?php echo htmlspecialchars($configData['telefone'] ?? ''); ?>" maxlength="15">
                            </div>
                            <div class="form-group">
                                <label for="email" class="form-label">E-mail Corporativo</label>
                                <input type="email" id="email" name="email" class="form-control" placeholder="contacto@empresa.com" value="<?php echo htmlspecialchars($configData['email'] ?? ''); ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Lado Direito: Logótipo com Pré-visualização Instantânea -->
                    <div style="background-color: rgba(255, 255, 255, 0.01); border: 1px dashed var(--border-color); border-radius: var(--radius-md); padding: 20px; text-align: center; display: flex; flex-direction: column; align-items: center; justify-content: center; width: 100%;">
                        <span class="form-label" style="margin-bottom: 12px; font-weight: 700;">Logótipo da Empresa</span>
                        
                        <div style="width: 130px; height: 130px; border-radius: var(--radius-sm); border: 1px solid var(--border-color); background-color: var(--bg-main); display: flex; align-items: center; justify-content: center; overflow: hidden; margin-bottom: 16px;">
                            <?php if (!empty($configData['logo_caminho']) && file_exists(BASE_PATH . '/' . $configData['logo_caminho'])): ?>
                                <img id="logo-preview" src="<?php echo htmlspecialchars($configData['logo_caminho']); ?>?t=<?php echo time(); ?>" style="max-width: 100%; max-height: 100%; object-fit: contain;">
                                <span id="logo-placeholder" style="font-size: 11.5px; color: var(--text-muted); display: none;">Sem Imagem</span>
                            <?php else: ?>
                                <img id="logo-preview" src="" style="max-width: 100%; max-height: 100%; object-fit: contain; display: none;">
                                <span id="logo-placeholder" style="font-size: 11.5px; color: var(--text-muted);">Sem Imagem</span>
                            <?php endif; ?>
                        </div>

                        <input type="file" name="logo_upload" id="logo_upload" accept="image/*" style="display: none;">
                        <button type="button" class="btn btn-secondary" onclick="document.getElementById('logo_upload').click();" style="padding: 8px 14px; font-size: 12.5px;">Alterar Imagem</button>
                        <small style="display: block; margin-top: 8px; font-size: 11px; color: var(--text-muted); line-height: 1.4;">Aceites: PNG, JPG ou SVG.<br>Máx: 2MB.</small>
                    </div>
                </div>

                <div class="form-group" style="margin-top: -15px;">
                    <label for="endereco_completo" class="form-label">Endereço Completo</label>
                    <input type="text" id="endereco_completo" name="endereco_completo" class="form-control" placeholder="Rua, Número, Bairro, Cidade/Estado" value="<?php echo htmlspecialchars($configData['endereco_completo'] ?? ''); ?>">
                </div>

                <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-top: 32px; margin-bottom: 16px;">2. Configurações Financeiras e Moeda</h3>

                <div style="display: grid; grid-template-columns: 1fr 2fr; gap: 20px; background-color: rgba(255,255,255,0.01); padding: 20px; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="moeda" class="form-label">Símbolo Monetário Padrão</label>
                        <input type="text" id="moeda" name="moeda" class="form-control" placeholder="R$" value="<?php echo htmlspecialchars($configData['moeda'] ?? 'R$'); ?>" maxlength="10" required style="font-weight: 700; color: var(--success); font-size: 16px; border-color: var(--success);">
                        <small class="form-label" style="margin-top: 6px; font-weight: normal; font-size: 12px;">Defina a moeda do sistema (ex: <code>R$</code>, <code>€</code>, <code>$</code>).</small>
                    </div>
                    <div class="form-group" style="margin-bottom: 0;">
                        <label for="prazo_maximo_retirada" class="form-label">Prazo Limite para Retirada de Equipamentos (Dias)</label>
                        <input type="number" id="prazo_maximo_retirada" name="prazo_maximo_retirada" class="form-control" value="<?php echo htmlspecialchars($configData['prazo_maximo_retirada'] ?? '90'); ?>" min="1" required style="font-weight: 600;">
                        <small class="form-label" style="margin-top: 6px; font-weight: normal; font-size: 12px;">Após este número de dias sem levantamento do aparelho, ele será considerado legalmente abandonado.</small>
                    </div>
                </div>

                <h3 style="font-size: 14px; text-transform: uppercase; color: var(--primary); margin-top: 32px; margin-bottom: 16px;">3. Termos Legais do Comprovativo de Entrada</h3>

                <div class="form-group">
                    <label for="termo_compromisso_texto" class="form-label">Texto do Termo de Responsabilidade e Consentimento</label>
                    <textarea id="termo_compromisso_texto" name="termo_compromisso_texto" class="form-control" placeholder="Insira o texto legal que será impresso no comprovativo de entrada da Ordem de Serviço..." style="height: 140px; resize: none; line-height: 1.6; font-size: 13.5px;"><?php echo htmlspecialchars($configData['termo_compromisso_texto'] ?? ''); ?></textarea>
                </div>

                <div style="display: flex; gap: 12px; margin-top: 32px; justify-content: flex-end; border-top: 1px solid var(--border-color); padding-top: 24px;">
                    <a href="index.php?page=dashboard" class="btn btn-secondary">Cancelar</a>
                    <button type="submit" class="btn btn-primary" style="padding: 12px 24px;">Gravar Configurações</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Lógica JS Avançada de Teclado, Notificação e Máscaras de Digitação -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('formConfig');
    const cnpjInput = document.getElementById('cnpj');
    const telInput = document.getElementById('telefone');

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

    // 2. SISTEMA DE NOTIFICAÇÃO EM TELA
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

    // 3. MÁSCARA CNPJ (00.000.000/0001-00)
    if (cnpjInput) {
        cnpjInput.addEventListener('input', function() {
            let v = this.value.replace(/\D/g, '');
            if (v.length > 14) v = v.slice(0, 14);
            v = v.replace(/^(\d{2})(\d)/, '$1.$2');
            v = v.replace(/^(\d{2})\.(\d{3})(\d)/, '$1.$2.$3');
            v = v.replace(/\.(\d{3})(\d)/, '.$1/$2');
            v = v.replace(/(\d{4})(\d{1,2})$/, '$1-$2');
            this.value = v;
        });
    }

    // 4. MÁSCARA TELEFONE ((00) 0000-0000)
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

    // 5. VALIDAR ALGORITMO DO CNPJ NO SUBMIT (Se digitado)
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
            if (cnpjInput && cnpjInput.value !== '') {
                if (!validarCNPJ(cnpjInput.value)) {
                    e.preventDefault();
                    mostrarNotificacaoErro('O CNPJ fornecido para a empresa é inválido.');
                    cnpjInput.focus();
                    return false;
                }
            }
        });
    }

    // 6. PRÉ-VISUALIZAÇÃO EM TEMPO REAL DO LOGÓTIPO SELECIONADO E SUBMISSÃO AUTOMÁTICA
    const logoUpload = document.getElementById('logo_upload');
    const logoPreview = document.getElementById('logo-preview');
    const logoPlaceholder = document.getElementById('logo-placeholder');

    if (logoUpload) {
        logoUpload.addEventListener('change', function() {
            const file = this.files; // Corrigido de "this.files" para "this.files" para garantir a leitura do ficheiro
            if (file) {
                // Validação básica de tamanho no front-end (2MB)
                if (file.size > 2 * 1024 * 1024) {
                    mostrarNotificacaoErro('O arquivo selecionado excede o limite máximo de 2MB.');
                    this.value = ''; // Limpa o input
                    return;
                }
                
                const reader = new FileReader();
                reader.onload = function(e) {
                    if (logoPreview) {
                        logoPreview.src = e.target.result;
                        logoPreview.style.display = 'block';
                    }
                    if (logoPlaceholder) {
                        logoPlaceholder.style.display = 'none';
                    }
                    
                    // Submete o formulário de forma autónoma para salvar as configurações e atualizar a página
                    if (form) {
                        form.submit();
                    }
                }
                reader.readAsDataURL(file);
            }
        });
    }
});
</script>
