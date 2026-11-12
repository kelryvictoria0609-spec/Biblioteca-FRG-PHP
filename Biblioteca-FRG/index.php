<?php
/**
 * SERVIDOR DA BIBLIOTECA SME-FRG (PHP)
 * Todo pedido ao site passa por aqui (menos os arquivos das pastas css, js e img,
 * que o servidor web entrega direto). Este arquivo escolhe a rota certa:
 *
 *   /            -> tela de entrada (Visitante ou Administrador)
 *   /biblioteca  -> a biblioteca pública (qualquer pessoa)
 *   /admin       -> painel do administrador (só com login; protegido no SERVIDOR)
 *   /api/...     -> dados em JSON (livros, login, painel)
 *
 * Rodar no seu computador:   php -S 127.0.0.1:5000 index.php      (abre em http://127.0.0.1:5000)
 * No servidor: Apache (usa o .htaccess), IIS (usa o web.config) ou Nginx (veja o README).
 */

// Arquivos das pastas css/js/img no servidor de testes do PHP (php -S): entrega direto
if (PHP_SAPI === 'cli-server' && preg_match('#^/(css|js|img)/#', (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))
    && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__ . '/servidor/config.php';
require __DIR__ . '/servidor/http.php';
require __DIR__ . '/servidor/banco.php';
require __DIR__ . '/servidor/seguranca.php';
require __DIR__ . '/servidor/capas.php';
require __DIR__ . '/servidor/rotas_auth.php';
require __DIR__ . '/servidor/rotas_livros.php';

ini_set('display_errors', '0');          // erros nunca aparecem para o visitante (vão para o log)
set_time_limit(120);                     // baixar um PDF grande do Drive pode levar até 60 s

// ============================== ROTAS ==============================
// [método, caminho (com {id} para partes variáveis), função, proteção]
// Proteção: '' = aberta a todos; 'admin' = precisa de login; 'super' = só o super administrador.
const ROTAS = [
    ['GET', '/', 'pagina_entrada', ''],
    ['GET', '/biblioteca', 'pagina_biblioteca', ''],
    ['GET', '/admin', 'pagina_admin', ''],
    ['GET', '/saude', 'rota_saude', ''],
    ['GET', '/favicon.ico', 'rota_favicon', ''],
    ['GET', '/css/{arquivo}', 'rota_estatico_css', ''],
    ['GET', '/js/{arquivo}', 'rota_estatico_js', ''],
    ['GET', '/img/{arquivo}', 'rota_estatico_img', ''],
    // login e administradores
    ['POST', '/api/login', 'rota_login', ''],
    ['POST', '/api/logout', 'rota_logout', ''],
    ['GET', '/api/sessao', 'rota_sessao', ''],
    ['POST', '/api/admin/senha', 'rota_trocar_senha', 'admin'],
    ['GET', '/api/admin/usuarios', 'rota_listar_administradores', 'super'],
    ['POST', '/api/admin/usuarios', 'rota_criar_administrador', 'super'],
    ['POST', '/api/admin/usuarios/{id}/ativo', 'rota_ativar_ou_desativar', 'super'],
    ['POST', '/api/admin/usuarios/{id}/senha', 'rota_redefinir_senha_de_outro', 'super'],
    ['GET', '/api/admin/auditoria', 'rota_historico', 'super'],
    // livros (público)
    ['GET', '/api/livros', 'rota_listar_livros', ''],
    ['GET', '/livros/{id}/capa', 'rota_baixar_capa', ''],
    ['GET', '/livros/{id}/pdf', 'rota_baixar_pdf', ''],
    // livros, filtros e configurações (administrador)
    ['POST', '/api/admin/livros/conferir-link', 'rota_conferir_link', 'admin'],
    ['POST', '/api/admin/livros', 'rota_cadastrar_livro', 'admin'],
    ['PUT', '/api/admin/livros/{id}', 'rota_editar_livro', 'admin'],
    ['POST', '/api/admin/livros/{id}/capa', 'rota_refazer_capa', 'admin'],
    ['POST', '/api/admin/livros/{id}/verificar', 'rota_verificar_link', 'admin'],
    ['DELETE', '/api/admin/livros/{id}', 'rota_excluir_livro', 'admin'],
    ['GET', '/api/admin/configuracoes', 'rota_ver_configuracoes', 'admin'],
    ['PUT', '/api/admin/configuracoes', 'rota_salvar_configuracoes', 'super'],
    ['GET', '/api/admin/categorias', 'rota_listar_categorias', 'admin'],
    ['PUT', '/api/admin/categorias', 'rota_salvar_categorias', 'admin'],
];

// ============================== PÁGINAS ==============================

/** Tela inicial: escolher Visitante ou Administrador. */
function pagina_entrada(): Resposta
{
    return enviar_arquivo(PASTA_SITE, 'entrada.html');
}

/** A biblioteca (aberta a todos). */
function pagina_biblioteca(): Resposta
{
    return enviar_arquivo(PASTA_SITE, 'index.html');
}

/**
 * Painel. A proteção é AQUI no servidor: sem login válido a página nem é enviada
 * (esconder o botão no navegador não protegeria nada).
 */
function pagina_admin(): Resposta
{
    return sessao_atual() ? enviar_arquivo(PASTA_SITE, 'admin.html') : redirecionar('/');
}

/**
 * Verificação de funcionamento (a TI pode usar em monitoramento): responde {"ok": true} se o
 * servidor e o banco estão no ar, ou 503 se o banco não responde. Não mostra nenhum detalhe interno.
 */
function rota_saude(): Resposta
{
    return testar_conexao() ? json(['ok' => true]) : json(['ok' => false], 503);
}

/** Os navegadores pedem /favicon.ico por conta própria; entregamos o da pasta img. */
function rota_favicon(): Resposta
{
    return enviar_arquivo(PASTA_SITE . '/img', 'favicon.ico');
}

/** Arquivos de css/js/img, caso o servidor web não os entregue direto. */
function rota_estatico_css(string $arquivo): Resposta { return enviar_arquivo(PASTA_SITE . '/css', $arquivo); }
function rota_estatico_js(string $arquivo): Resposta { return enviar_arquivo(PASTA_SITE . '/js', $arquivo); }
function rota_estatico_img(string $arquivo): Resposta { return enviar_arquivo(PASTA_SITE . '/img', $arquivo); }

// ============================== ESCOLHA DA ROTA ==============================

/**
 * Procura a rota do pedido. Devolve [função, proteção, partes variáveis].
 * Se o caminho existe mas com outro método, dá 405; se não existe, dá 404.
 */
function achar_rota(string $metodo, string $caminho): array
{
    $caminho_existe = false;
    foreach (ROTAS as [$m, $modelo, $funcao, $protecao]) {
        $padrao = '#^' . preg_replace('#\\\\\{[a-z]+\\\\\}#', '([^/]+)', preg_quote($modelo, '#')) . '$#';
        if (preg_match($padrao, $caminho, $partes)) {
            $caminho_existe = true;
            if ($m === $metodo) {
                return [$funcao, $protecao, array_slice($partes, 1)];
            }
        }
    }
    throw $caminho_existe ? new ErroApi('Method Not Allowed', 405) : new ErroApi('Not Found', 404);
}

/** Atende o pedido e devolve a resposta (já tratando os erros). */
function atender(): Resposta
{
    $caminho = caminho();
    try {
        if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > TAMANHO_MAXIMO_JSON) {
            throw new ErroApi('Pedido grande demais. Os PDFs não são enviados: cole o link do Google Drive.', 413);
        }
        [$funcao, $protecao, $partes] = achar_rota(metodo(), $caminho);
        if (!str_starts_with($caminho, '/css/') && !str_starts_with($caminho, '/js/')
            && !str_starts_with($caminho, '/img/') && $caminho !== '/favicon.ico') {
            iniciar_banco();                 // cria as tabelas no 1º acesso (depois é só uma consulta rápida)
            criar_primeiro_admin();          // só age se ADMIN_USER e ADMIN_SENHA_INICIAL estiverem definidos
        }
        if ($protecao === 'admin') {
            exigir_admin();
        } elseif ($protecao === 'super') {
            exigir_super_admin();
        }
        $resposta = $funcao(...$partes);
        banco_confirmar();
        return $resposta;
    } catch (ErroApi $erro) {
        banco_desfazer();
        if ($erro->status === 404 && !str_starts_with($caminho, '/api') && !str_starts_with($caminho, '/livros')) {
            return enviar_arquivo(PASTA_SITE, '404.html', 404);       // página amigável para pessoas
        }
        return json(['erro' => $erro->mensagem], $erro->status);
    } catch (Throwable $erro) {
        // Bug ou falha do banco: registra no log do servidor e NÃO mostra detalhes ao visitante
        registrar_log('ERRO', get_class($erro) . ': ' . $erro->getMessage() . ' em ' . $erro->getFile() . ':' . $erro->getLine());
        banco_desfazer();
        return json(['erro' => 'Erro interno. Tente novamente.'], 500);
    }
}

/** Confirma no banco tudo o que o pedido gravou (só se a conexão chegou a ser aberta). */
function banco_confirmar(): void
{
    try {
        banco()->commit();
    } catch (RuntimeException $e) {
        // sem banco configurado e o pedido não precisou dele (ex.: arquivo de css)
    }
}

/** Desfaz o que foi gravado pela metade (se a conexão chegou a ser aberta). */
function banco_desfazer(): void
{
    try {
        banco()->rollback();
    } catch (Throwable $e) {
    }
}

// ============================== CABEÇALHOS DE SEGURANÇA ==============================

/** Cabeçalhos que o navegador usa para se proteger (em todas as respostas). */
function cabecalhos_de_seguranca(Resposta $r): void
{
    $r->cabecalhos += [
        'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; "
            . "font-src 'self'; img-src 'self' data:; connect-src 'self'; "
            . "frame-ancestors 'none'; base-uri 'none'; form-action 'self'; object-src 'self'",
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'same-origin',
        'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];
    if (PRODUCAO) {
        $r->cabecalhos['Strict-Transport-Security'] = 'max-age=31536000';
    }
    $caminho = caminho();
    foreach (['/api/admin', '/api/sessao', '/api/login', '/admin'] as $inicio) {
        if (str_starts_with($caminho, $inicio)) {
            $r->cabecalhos['Cache-Control'] = 'no-store';
            return;
        }
    }
    if (preg_match('#^/(css|js|img)/#', $caminho) && !isset($r->cabecalhos['Cache-Control'])) {
        // Estilos, scripts e imagens: o navegador guarda por 1 dia em produção (as páginas usam ?v=N para forçar a troca)
        $r->cabecalhos['Cache-Control'] = CACHE_ESTATICOS ? 'public, max-age=' . CACHE_ESTATICOS : 'no-cache';
    }
}

// ============================== ENVIO DA RESPOSTA ==============================
$resposta = atender();
cabecalhos_de_seguranca($resposta);
http_response_code($resposta->status);
foreach ($resposta->cabecalhos as $nome => $valor) {
    header($nome . ': ' . $valor);
}
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD' && $resposta->status !== 304) {
    echo $resposta->corpo;
}
