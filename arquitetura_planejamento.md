OS Master - Planejamento de Arquitetura e Engenharia de Software

Este documento serve como a base de engenharia e planejamento para o desenvolvimento do sistema OS Master, garantindo conformidade com o script SQL fornecido e os requisitos formais de TCC.

1. Padrão Arquitetural (MVC Simplificado & Centralização de Layout)

Adotamos uma arquitetura organizada, modular e baseada no padrão MVC (Model-View-Controller) adaptado para PHP estruturado de forma limpa:

Camada de Visualização (Views / Pages): Arquivos HTML5/CSS3/JS limpos localizados em /pages e no arquivo principal index.php. O layout é totalmente responsivo, utilizando variáveis CSS para temas e elementos flexíveis (Flexbox/Grid).

Camada de Controle/Regras de Negócio (Controllers/Actions): Controladores centralizados e chamadas assíncronas em /ajax para processar formulários sem recarregar a tela, mantendo a experiência do usuário fluida.

Camada de Modelo/Dados (Database/Classes): Utilização de PDO (PHP Data Objects) com prepared statements para blindar o sistema contra SQL Injection. Classes PHP em /classes mapearão o comportamento das tabelas.

Evolução para Template Engine Centralizado (Abordagem B)

Para evitar a duplicação do código do menu lateral (sidebar) e cabeçalho (top-header) em cada arquivo, centralizamos toda a estrutura de layout comum no arquivo principal index.php.

As páginas internas de /pages/ agora contêm apenas o conteúdo específico que vai dentro de <div class="page-container">.

Páginas especiais que necessitam de uma visualização separada (como login.php e imprimir_os.php) são controladas dinamicamente através do array $pagesWithoutLayout no index.php.

2. Guia de Limpeza para as Outras Páginas (Passo a Passo)

Para unificar o sistema sem que você se perca, siga este passo a passo simples em cada um dos seus arquivos restantes da pasta /pages/ (Ex: estados.php, cidades.php, clientes.php, funcionarios.php, usuarios.php, equipamentos.php, os.php, produtos.php, config.php, folha.php, historico_os.php, relatorios.php):

Remover a Tag de Abertura do Layout Wrapper e a Sidebar:
Localize no arquivo e exclua todo o bloco que envolve a sidebar:

<div class="layout-wrapper">
    <aside class="sidebar">
        ...
    </aside>
    <div class="main-content">
        <header class="top-header">
            ...
        </header>
        <div class="page-container"> <!-- Remova esta tag se houver -->


Remover as Tags de Fechamento no Fim do Arquivo:
Vá até o final do arquivo e exclua as tags de fechamento correspondentes aos contêineres removidos (normalmente de 3 a 4 tags </div> finais):

        </div> <!-- Do page-container -->
    </div> <!-- Do main-content -->
</div> <!-- Do layout-wrapper -->


Salvar:
Grave o arquivo. O roteador dinâmico do index.php cuidará de desenhar a barra lateral atualizada e destacar o menu ativo automaticamente!

3. Fluxo das Entidades e Herança (Class Table Inheritance)

O banco de dados do OS Master implementa o conceito de Herança de Tabela através da tabela base pessoa.

                    ┌───────────────┐
                    │    PESSOA     │ (Dados gerais: Nome, CPF/CNPJ, Telefone, CEP, Cidade, etc.)
                    └───────┬───────┘
                            │
            ┌───────────────┼───────────────┐
            ▼               ▼               ▼
     ┌─────────────┐ ┌─────────────┐ ┌─────────────┐
     │   CLIENTE   │ │ FUNCIONARIO │ │   USUARIO   │
     └─────────────┘ └─────────────┘ └─────────────┘


Regras de Ouro do Fluxo de Gravação:

Inserção de Cliente/Funcionário/Usuário:

Passo 1: Validar e salvar os dados gerais na tabela pessoa. Obter o ID gerado (id_pessoa).

Passo 2: Inserir os dados específicos na tabela correspondente (cliente ou funcionario), utilizando o mesmo id_pessoa obtido no Passo 1 como Chave Primária e Estrangeira.

Passo 3 (se aplicável): Se a pessoa for um funcionário autorizado a acessar o sistema, seus dados de autenticação serão salvos na tabela usuario, também vinculados pelo id_pessoa.

Integridade Referencial:

As tabelas dependentes possuem ON DELETE CASCADE apontando para pessoa. Isso garante que se uma pessoa for removida (embora nossa regra de negócio priorize a inativação por status), o registro dependente correspondente seja limpo automaticamente.

4. Ordem de Dependência das Tabelas

Para respeitar estritamente as restrições de Chaves Estrangeiras (Foreign Keys), o fluxo de desenvolvimento e preenchimento de dados seguirá esta exata hierarquia:

Nível 0 (Sem dependências externas): estado, produto, config_sistema.

Nível 1: cidade (depende de estado).

Nível 2: pessoa (depende de cidade).

Nível 3 (Herança): cliente, funcionario, usuario (todos dependem de pessoa).

Nível 4: equipamento (depende de cliente), folha_pagto (depende de funcionario).

Nível 5: os (depende de cliente, equipamento, funcionario/tecnico).

Nível 6: item_os (depende de os e produto), historico_os (depende de os).

5. Estratégia de Controle de Acesso (ACL - Access Control List)

Para atender à especificação de Casos de Uso do documento de requisitos, os usuários serão divididos em perfis baseados no seu cargo cadastrado em funcionario:

Administrador (Supervisor): Possui acesso total e irrestrito a todos os módulos, relatórios, parametrizações financeiras, folha de pagamento e configurações globais.

Atendente: Focado na recepção e entrega. Tem permissão para gerenciar clientes, equipamentos, registrar a abertura de ordens de serviço, imprimir guias de entrada/retirada e controlar os prazos de permanência.

Técnico: Focado na execução física do serviço. Tem permissão para gerenciar os itens utilizados (peças/produtos), atualizar o status da OS, registrar diagnósticos técnicos e acompanhar o histórico das ordens de serviço atribuídas a ele.