<?php
/**
 * CONFIGURAÇÕES DO SERVIDOR
 * Tudo que muda entre o seu computador e o servidor fica aqui, lido de
 * "variáveis de ambiente" (assim nenhuma senha fica escrita no código).
 *
 * Se o servidor não permitir variáveis de ambiente, a TI pode criar o arquivo
 * servidor/config.local.php (veja o README). Ele NUNCA vai para o GitLab (.gitignore).
 */

/** Valores do arquivo opcional servidor/config.local.php (um "return [ 'DATABASE_URL' => '...', ... ];"). */
$GLOBALS['CONFIG_LOCAL'] = is_file(__DIR__ . '/config.local.php') ? (array) require __DIR__ . '/config.local.php' : [];

/**
 * Lê uma configuração: primeiro do config.local.php, depois das variáveis de ambiente.
 * Devolve $padrao se não estiver definida.
 */
function variavel(string $nome, string $padrao = ''): string
{
    if (array_key_exists($nome, $GLOBALS['CONFIG_LOCAL'])) {
        return (string) $GLOBALS['CONFIG_LOCAL'][$nome];
    }
    $valor = getenv($nome);
    if ($valor === false && isset($_SERVER[$nome])) {     // Apache "SetEnv" às vezes só aparece aqui
        $valor = $_SERVER[$nome];
    }
    return $valor === false ? $padrao : (string) $valor;
}

define('PASTA_SITE', dirname(__DIR__));

// No servidor, defina AMBIENTE=producao (liga o cookie seguro, que exige HTTPS).
define('PRODUCAO', variavel('AMBIENTE') === 'producao');

// ---------- Banco de dados (somente MySQL) ----------
// Formato:  mysql://USUARIO:SENHA@ENDERECO:3306/NOME_DO_BANCO
// (se a senha tiver símbolos como @ ou /, escreva-os codificados: @ = %40, / = %2F)
define('URL_BANCO', trim(variavel('DATABASE_URL')));

// ---------- Sessão (login) ----------
// O prefixo "__Host-" faz o navegador recusar o cookie se ele não for HTTPS / do mesmo site.
define('NOME_COOKIE', PRODUCAO ? '__Host-sessao' : 'sessao');
define('COOKIE_SEGURO', PRODUCAO);          // cookie só trafega por HTTPS em produção
define('SESSAO_DURACAO_MAXIMA', 8 * 3600);  // após 8 horas pede login de novo, aconteça o que acontecer
define('SESSAO_INATIVIDADE', 2 * 3600);     // após 2 horas sem usar o painel, expira

// ---------- Proteção contra tentativas de adivinhar a senha ----------
define('LOGIN_MAX_ERROS_USUARIO', 5);          // mesmo usuário vindo do MESMO IP
define('LOGIN_MAX_ERROS_USUARIO_GERAL', 50);   // mesmo usuário somando TODOS os IPs
define('LOGIN_MAX_ERROS_IP', 15);              // tudo que vem do MESMO IP
define('LOGIN_JANELA', 15 * 60);               // contagem zera após 15 min sem erros
define('LOGIN_BLOQUEIO', 15 * 60);             // tempo de bloqueio

// Quantos servidores "intermediários" (proxy: Nginx, IIS...) existem entre o visitante e o sistema.
// 1 se houver um proxy na frente; 0 se o acesso for direto (padrão).
// Serve para descobrir o IP verdadeiro de quem acessa. NUNCA use 1 sem proxy: o visitante poderia forjar o IP.
define('QTD_PROXIES', (int) variavel('QTD_PROXIES', '0'));

// Endereço público do site (ex.: biblioteca.fazendariogrande.pr.gov.br). Opcional.
// Use SE o proxy trocar o cabeçalho "Host" e o login der "Pedido não permitido".
define('DOMINIO_PUBLICO', strtolower(trim(variavel('DOMINIO_PUBLICO'))));

// ---------- Livros ----------
// Os PDFs NÃO ficam no servidor: ficam no Google Drive da Secretaria e o site guarda só o link
// e uma capa pequena (~30 KB). O servidor baixa o PDF uma única vez, só para criar a capa, e descarta.
define('TAMANHO_MAXIMO_PDF', (int) variavel('MAX_PDF_MB', '30') * 1024 * 1024);
define('LIMITE_DOWNLOAD_LINK', TAMANHO_MAXIMO_PDF);
define('TEMPO_MAXIMO_DOWNLOAD', 60);       // segundos no total para baixar um PDF de um link
define('MAX_FILTROS', 12);
define('TAMANHO_MAXIMO_JSON', 64 * 1024);  // nenhum pedido ao servidor passa disso (não há envio de arquivos)
define('CATEGORIAS_INICIAIS', [            // criadas na 1ª vez; depois renomeie pelo painel
    'Educação Infantil',
    'Ensino Fundamental',
    'Educação Especial e Inclusiva',
    'Educação Digital e Tecnologias Educacionais',
    'Equipe Suplementar',
    'Ações Intersetoriais',
]);

// Programas usados para transformar a 1ª página do PDF em imagem (a capa).
// Normalmente o sistema acha sozinho; informe o caminho completo só se ele não achar.
// Ex. no Windows: PDFTOPPM=C:\poppler\Library\bin\pdftoppm.exe  ou  GHOSTSCRIPT=C:\Program Files\gs\gs10.03.0\bin\gswin64c.exe
define('PDFTOPPM', trim(variavel('PDFTOPPM')));
define('GHOSTSCRIPT', trim(variavel('GHOSTSCRIPT')));

// SÓ PARA TESTES no computador (nunca vale em produção): aceita links para endereços internos.
define('PERMITIR_LINKS_LOCAIS', !PRODUCAO && variavel('PERMITIR_LINKS_LOCAIS') === '1');

// ---------- Histórico (auditoria) ----------
define('AUDITORIA_DIAS', (int) variavel('AUDITORIA_DIAS', '365'));   // registros mais antigos são apagados

// ---------- Arquivos estáticos (css, js, img) ----------
// Em produção o navegador guarda esses arquivos por 1 dia. Troque o "?v=" nas páginas HTML ao alterar um deles.
define('CACHE_ESTATICOS', PRODUCAO ? 86400 : 0);

// ---------- Registro de funcionamento (log) ----------
// Se definido, grava os avisos e erros também neste arquivo (com rodízio: até 5 arquivos de 2 MB).
define('ARQUIVO_LOG', trim(variavel('ARQUIVO_LOG')));

// ---------- 1º administrador (opcional) ----------
// Se a tabela de usuários estiver vazia e estas duas variáveis existirem, o administrador
// (que será o SUPER administrador) é criado sozinho no primeiro acesso ao site.
// Depois TROQUE a senha pelo painel e APAGUE ADMIN_SENHA_INICIAL do servidor.
define('ADMIN_USER', trim(variavel('ADMIN_USER')));
define('ADMIN_SENHA_INICIAL', variavel('ADMIN_SENHA_INICIAL'));
