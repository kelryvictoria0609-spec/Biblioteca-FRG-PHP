<?php
/**
 * PEDIDO E RESPOSTA
 * Funções para ler o que o navegador enviou (caminho, cabeçalhos, JSON, formulário, cookie, IP)
 * e para montar as respostas (JSON, arquivo, redirecionamento).
 */

/** Erro "esperado" (senha errada, campo faltando...) que vira uma resposta JSON com mensagem para a pessoa. */
final class ErroApi extends Exception
{
    public string $mensagem;
    public int $status;

    public function __construct(string $mensagem, int $status = 400)
    {
        parent::__construct($mensagem);
        $this->mensagem = $mensagem;
        $this->status = $status;
    }
}

/** Resposta que o index.php envia ao navegador no fim do pedido. */
final class Resposta
{
    public int $status;
    public string $corpo;
    public array $cabecalhos;

    public function __construct(string $corpo = '', int $status = 200, array $cabecalhos = [])
    {
        $this->corpo = $corpo;
        $this->status = $status;
        $this->cabecalhos = $cabecalhos;
    }
}

/** Monta uma resposta JSON (acentos mantidos, sem barras escapadas). */
function json(array $dados, int $status = 200): Resposta
{
    $texto = json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    return new Resposta($texto, $status, ['Content-Type' => 'application/json; charset=utf-8']);
}

/** Monta um redirecionamento para outro endereço do site. */
function redirecionar(string $endereco): Resposta
{
    return new Resposta('', 302, ['Location' => $endereco]);
}

const TIPOS_ARQUIVO = [
    'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
    'js' => 'text/javascript; charset=utf-8', 'png' => 'image/png', 'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg', 'svg' => 'image/svg+xml', 'ico' => 'image/x-icon', 'webp' => 'image/webp',
    'woff2' => 'font/woff2',
];

/**
 * Entrega um arquivo do site (página HTML ou arquivo de css/js/img).
 * Confere que o arquivo está mesmo dentro da pasta permitida (ninguém consegue pedir "../servidor/config.php").
 */
function enviar_arquivo(string $pasta, string $nome, int $status = 200): Resposta
{
    $base = realpath($pasta);
    $caminho = realpath($pasta . DIRECTORY_SEPARATOR . $nome);
    if ($base === false || $caminho === false || !is_file($caminho)
        || !str_starts_with($caminho, $base . DIRECTORY_SEPARATOR)) {
        throw new ErroApi('Not Found', 404);
    }
    $extensao = strtolower(pathinfo($caminho, PATHINFO_EXTENSION));
    $tipo = TIPOS_ARQUIVO[$extensao] ?? 'application/octet-stream';
    return new Resposta((string) file_get_contents($caminho), $status, ['Content-Type' => $tipo]);
}

// ============================== LEITURA DO PEDIDO ==============================

/** Método do pedido (GET, POST, PUT, DELETE...). HEAD é tratado como GET. */
function metodo(): string
{
    $m = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    return $m === 'HEAD' ? 'GET' : $m;
}

/** Caminho pedido, sem o "?..." do final (ex.: /api/livros). */
function caminho(): string
{
    $caminho = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    return rawurldecode(is_string($caminho) && $caminho !== '' ? $caminho : '/');
}

/** Lê um cabeçalho do pedido (ex.: cabecalho('Origin')). Devolve '' se não veio. */
function cabecalho(string $nome): string
{
    $chave = 'HTTP_' . strtoupper(str_replace('-', '_', $nome));
    if ($nome === 'Content-Type') {
        $chave = 'CONTENT_TYPE';
    }
    return (string) ($_SERVER[$chave] ?? '');
}

/** Lê um cookie do pedido. */
function cookie(string $nome): string
{
    return (string) ($_COOKIE[$nome] ?? '');
}

/** O corpo cru do pedido (lido uma vez só e guardado). */
function corpo_cru(): string
{
    static $corpo = null;
    if ($corpo === null) {
        $corpo = (string) file_get_contents('php://input', false, null, 0, TAMANHO_MAXIMO_JSON + 1);
    }
    return $corpo;
}

/** Lê o JSON do pedido (devolve [] se vier inválido ou se não for um objeto). */
function corpo_json(): array
{
    $dados = json_decode(corpo_cru(), true);
    return is_array($dados) && !array_is_list($dados) ? $dados : [];
}

/**
 * Campos de texto de um formulário (FormData). O PHP só lê sozinho formulários enviados por POST;
 * a edição de livro usa PUT, então aqui o "multipart/form-data" é lido manualmente.
 */
function formulario(): array
{
    if (metodo() === 'POST' && $_POST) {
        return $_POST;
    }
    $tipo = cabecalho('Content-Type');
    if (stripos($tipo, 'application/x-www-form-urlencoded') !== false) {
        parse_str(corpo_cru(), $campos);
        return $campos;
    }
    if (!preg_match('/boundary="?([^";]+)"?/i', $tipo, $m)) {
        return [];
    }
    $campos = [];
    foreach (explode('--' . $m[1], corpo_cru()) as $parte) {
        $parte = ltrim($parte, "\r\n");
        if ($parte === '' || str_starts_with($parte, '--')) {
            continue;
        }
        [$cabecalhos, $valor] = array_pad(explode("\r\n\r\n", $parte, 2), 2, '');
        if (preg_match('/name="([^"]*)"/i', $cabecalhos, $n) && stripos($cabecalhos, 'filename=') === false) {
            $campos[$n[1]] = preg_replace('/\r\n$/', '', $valor);
        }
    }
    return $campos;
}

/** Texto de um campo, já convertido em string (valores que não são texto viram ''). */
function texto($valor): string
{
    return is_scalar($valor) ? (string) $valor : '';
}

/**
 * IP do visitante. Se houver um proxy na frente (QTD_PROXIES=1), usa o IP que o proxy repassa
 * no cabeçalho X-Forwarded-For (o último da lista, que foi o proxy quem escreveu).
 */
function ip_do_visitante(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '?';
    if (QTD_PROXIES > 0) {
        $lista = array_values(array_filter(array_map('trim', explode(',', cabecalho('X-Forwarded-For')))));
        if (count($lista) >= QTD_PROXIES) {
            $ip = $lista[count($lista) - QTD_PROXIES];
        }
    }
    return substr((string) $ip, 0, 64);
}

/** Endereço do site que o navegador usou (ex.: biblioteca.prefeitura.gov.br ou 127.0.0.1:5000). */
function host_do_pedido(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if (QTD_PROXIES > 0 && cabecalho('X-Forwarded-Host') !== '') {
        $lista = array_map('trim', explode(',', cabecalho('X-Forwarded-Host')));
        $host = end($lista);
    }
    return strtolower((string) $host);
}

/** Tamanho de um texto em caracteres (acentos contam como 1). */
function tamanho(string $texto): int
{
    return mb_strlen($texto, 'UTF-8');
}

/**
 * Registra um aviso ou erro no log do PHP e, se ARQUIVO_LOG estiver definido, também nesse
 * arquivo (com rodízio: quando passa de 2 MB vira .1, .2... até .5, para nunca encher o disco).
 */
function registrar_log(string $nivel, string $mensagem): void
{
    $linha = date('Y-m-d H:i:s') . " {$nivel} biblioteca: {$mensagem}";
    if (ARQUIVO_LOG === '') {
        error_log($linha);
        return;
    }
    if (is_file(ARQUIVO_LOG) && filesize(ARQUIVO_LOG) > 2000000) {
        for ($i = 4; $i >= 1; $i--) {
            if (is_file(ARQUIVO_LOG . ".{$i}")) {
                @rename(ARQUIVO_LOG . ".{$i}", ARQUIVO_LOG . '.' . ($i + 1));
            }
        }
        @rename(ARQUIVO_LOG, ARQUIVO_LOG . '.1');
    }
    @file_put_contents(ARQUIVO_LOG, $linha . PHP_EOL, FILE_APPEND | LOCK_EX);
}
