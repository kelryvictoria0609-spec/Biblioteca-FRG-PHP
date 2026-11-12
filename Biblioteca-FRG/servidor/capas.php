<?php
/**
 * CAPAS AUTOMÁTICAS
 * Pega a 1ª página do PDF e a transforma numa imagem JPG (a capa do livro).
 * Também baixa PDFs de links com segurança (só da internet pública).
 *
 * Para ler o PDF, o servidor precisa de UM destes programas (o sistema procura nesta ordem):
 *   1. pdftoppm (pacote "poppler-utils" no Linux; "Poppler for Windows" no Windows)
 *   2. Ghostscript (gs no Linux; gswin64c.exe no Windows)
 *   3. extensão Imagick do PHP (que também usa o Ghostscript)
 * Sem nenhum deles o livro é salvo normalmente, só que sem capa (o painel avisa).
 */

const LARGURA_CAPA = 500;          // largura da capa gerada, em pixels
const ALTURA_MAXIMA_CAPA = 1500;   // altura máxima da capa, em pixels (evita gastar memória com páginas gigantes)
const TEMPO_MAXIMO_CAPA = 30;      // segundos para o programa transformar a página em imagem

/**
 * Recebe o PDF (bytes) e devolve a capa (bytes de uma imagem JPG).
 * Dá erro se o PDF estiver corrompido ou protegido por senha.
 */
function gerar_capa(string $dados_pdf): string
{
    $pasta = pasta_temporaria('capa_');
    $entrada = $pasta . DIRECTORY_SEPARATOR . 'livro.pdf';
    $saida = $pasta . DIRECTORY_SEPARATOR . 'capa';
    file_put_contents($entrada, $dados_pdf);
    try {
        if ($programa = achar_programa(PDFTOPPM, ['pdftoppm'])) {
            rodar_programa([$programa, '-f', '1', '-l', '1', '-singlefile', '-jpeg', '-jpegopt', 'quality=90',
                '-scale-to-x', (string) LARGURA_CAPA, '-scale-to-y', '-1', $entrada, $saida]);
            $imagem = $saida . '.jpg';
        } elseif ($programa = achar_programa(GHOSTSCRIPT, ['gs', 'gswin64c', 'gswin32c'])) {
            $imagem = $saida . '.jpg';
            rodar_programa([$programa, '-q', '-dSAFER', '-dBATCH', '-dNOPAUSE', '-dFirstPage=1', '-dLastPage=1',
                '-sDEVICE=jpeg', '-dJPEGQ=90', '-r110', '-dTextAlphaBits=4', '-dGraphicsAlphaBits=4',
                '-sOutputFile=' . $imagem, $entrada]);
        } elseif (extension_loaded('imagick')) {
            $im = new Imagick();
            $im->setResolution(110, 110);
            $im->readImage($entrada . '[0]');
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('jpeg');
            $imagem = $saida . '.jpg';
            $im->writeImage($imagem);
        } else {
            throw new RuntimeException('o servidor não tem programa para ler PDF (instale o poppler-utils ou o Ghostscript)');
        }
        if (!is_file($imagem) || filesize($imagem) === 0) {
            throw new RuntimeException('o PDF está corrompido ou protegido por senha');
        }
        return ajustar_capa((string) file_get_contents($imagem));
    } finally {
        apagar_pasta($pasta);
    }
}

/**
 * Deixa a capa no tamanho padrão (500 px de largura, no máximo 1500 px de altura) e em JPG de
 * qualidade 85, usando a extensão GD do PHP. Sem a GD, devolve a imagem como veio.
 */
function ajustar_capa(string $jpg): string
{
    if (!function_exists('imagecreatefromstring')) {
        return $jpg;
    }
    $original = @imagecreatefromstring($jpg);
    if ($original === false) {
        throw new RuntimeException('não consegui ler a imagem da página');
    }
    $largura = imagesx($original);
    $altura = imagesy($original);
    $escala = min(LARGURA_CAPA / $largura, 4, ALTURA_MAXIMA_CAPA / $altura);
    $nova_l = max(1, (int) round($largura * $escala));
    $nova_a = max(1, (int) round($altura * $escala));
    $capa = imagecreatetruecolor($nova_l, $nova_a);
    imagefill($capa, 0, 0, imagecolorallocate($capa, 255, 255, 255));
    imagecopyresampled($capa, $original, 0, 0, 0, 0, $nova_l, $nova_a, $largura, $altura);
    ob_start();
    imagejpeg($capa, null, 85);
    $resultado = (string) ob_get_clean();
    imagedestroy($original);
    imagedestroy($capa);
    return $resultado;
}

/**
 * Procura um programa: primeiro o caminho informado na configuração; depois nas pastas do PATH.
 * Devolve o caminho completo ou null se não achar.
 */
function achar_programa(string $configurado, array $nomes): ?string
{
    if ($configurado !== '') {
        return is_file($configurado) ? $configurado : null;
    }
    $windows = DIRECTORY_SEPARATOR === '\\';
    foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $pasta) {
        foreach ($nomes as $nome) {
            $caminho = rtrim($pasta, '\\/') . DIRECTORY_SEPARATOR . $nome . ($windows ? '.exe' : '');
            if ($pasta !== '' && is_file($caminho) && ($windows || is_executable($caminho))) {
                return $caminho;
            }
        }
    }
    return null;
}

/**
 * Roda um programa SEM passar pelo terminal (os argumentos vão separados: nada do nome do
 * arquivo pode virar comando). Para o programa se passar de TEMPO_MAXIMO_CAPA segundos.
 */
function rodar_programa(array $comando): void
{
    $processo = proc_open($comando, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tubos);
    if (!is_resource($processo)) {
        throw new RuntimeException('não consegui rodar o programa que lê o PDF');
    }
    fclose($tubos[0]);
    stream_set_blocking($tubos[1], false);
    stream_set_blocking($tubos[2], false);
    $prazo = microtime(true) + TEMPO_MAXIMO_CAPA;
    while (($estado = proc_get_status($processo))['running']) {
        if (microtime(true) > $prazo) {
            proc_terminate($processo, 9);
            proc_close($processo);
            throw new RuntimeException('o PDF demorou demais para ser lido');
        }
        stream_get_contents($tubos[1]);
        stream_get_contents($tubos[2]);
        usleep(50000);
    }
    fclose($tubos[1]);
    fclose($tubos[2]);
    proc_close($processo);
}

/** Cria uma pasta temporária só para este pedido (apagada no fim). */
function pasta_temporaria(string $prefixo): string
{
    $pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'biblioteca_' . $prefixo . bin2hex(random_bytes(8));
    mkdir($pasta, 0700, true);
    return $pasta;
}

/** Apaga uma pasta temporária e o que estiver dentro. */
function apagar_pasta(string $pasta): void
{
    foreach (glob($pasta . DIRECTORY_SEPARATOR . '*') ?: [] as $arquivo) {
        @unlink($arquivo);
    }
    @rmdir($pasta);
}

// ----------------------- LINKS DO GOOGLE DRIVE -----------------------
const ID_DRIVE = '([A-Za-z0-9_-]{10,200})';      // formato do código que o Drive dá a cada arquivo/pasta

/** Diz se o link é do Google Drive (drive.google.com ou docs.google.com). */
function eh_link_drive(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return in_array($host, ['drive.google.com', 'docs.google.com'], true);
}

/** Diz se o link é de uma PASTA do Drive (e não de um arquivo). */
function eh_pasta_drive(string $url): bool
{
    return eh_link_drive($url) && str_contains($url, '/folders/');
}

/**
 * Tira o código do arquivo de qualquer formato de link do Drive:
 *   .../file/d/CODIGO/view   .../open?id=CODIGO   .../uc?id=CODIGO&export=download
 * Devolve o código ou null se não achar.
 */
function id_arquivo_drive(string $url): ?string
{
    if (!eh_link_drive($url) || eh_pasta_drive($url)) {
        return null;
    }
    if (preg_match('#/d/' . ID_DRIVE . '#', $url, $m) || preg_match('#[?&]id=' . ID_DRIVE . '#', $url, $m)) {
        return $m[1];
    }
    return null;
}

/** Tira o código de um link de PASTA do Drive (.../drive/folders/CODIGO). Devolve null se não for pasta. */
function id_pasta_drive(string $url): ?string
{
    if (!eh_pasta_drive($url)) {
        return null;
    }
    return preg_match('#/folders/' . ID_DRIVE . '#', $url, $m) ? $m[1] : null;
}

/** Link que o VISITANTE abre para ler o livro (leitor de PDF do próprio Google Drive). */
function link_leitura_drive(string $id_arquivo): string
{
    return "https://drive.google.com/file/d/{$id_arquivo}/view";
}

/** Link padronizado da pasta da biblioteca no Drive. */
function link_pasta_drive(string $id_pasta): string
{
    return "https://drive.google.com/drive/folders/{$id_pasta}";
}

/** Link de download direto do arquivo (usado só pelo servidor, para criar a capa). */
function link_download_drive(string $id_arquivo): string
{
    return "https://drive.usercontent.google.com/download?id={$id_arquivo}&export=download&confirm=t";
}

const ERRO_DRIVE_FECHADO = 'o Drive não liberou o arquivo. Confira se ele está dentro da pasta da '
    . 'biblioteca (que é compartilhada) ou se está como "Qualquer pessoa com o link"';

/** Se for link de arquivo do Google Drive, converte para o link de download direto. */
function converter_link_drive(string $url): string
{
    $id = id_arquivo_drive($url);
    return $id ? link_download_drive($id) : $url;
}

// ----------------------- BAIXAR PDF A PARTIR DE UM LINK -----------------------

/** Diz se um IP pertence à internet pública (rede interna, localhost e similares são recusados). */
function ip_publico(string $ip): bool
{
    if (PERMITIR_LINKS_LOCAIS) {
        return true;
    }
    $flags = defined('FILTER_FLAG_GLOBAL_RANGE')
        ? FILTER_FLAG_GLOBAL_RANGE
        : FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
    return filter_var($ip, FILTER_VALIDATE_IP, $flags) !== false;
}

/**
 * Segurança (SSRF): descobre os IPs do endereço e confere que TODOS são públicos.
 * Devolve o IP que será usado na conexão — o download é "preso" a esse IP, então não dá
 * para trocar o endereço no meio do caminho e fazer o servidor acessar a rede interna.
 */
function resolver_ip_publico(string $host): string
{
    $host = trim($host, '[]');
    if (filter_var($host, FILTER_VALIDATE_IP)) {
        $ips = [$host];
    } else {
        $ips = [];
        foreach (@dns_get_record($host, DNS_A | DNS_AAAA) ?: [] as $registro) {
            $ips[] = $registro['ip'] ?? $registro['ipv6'] ?? null;
        }
        $ips = array_values(array_filter($ips));
        if (!$ips && ($ipv4 = gethostbynamel($host))) {
            $ips = $ipv4;
        }
    }
    if (!$ips) {
        throw new RuntimeException('não consegui encontrar o endereço do link');
    }
    foreach ($ips as $ip) {
        if (!ip_publico($ip)) {
            throw new RuntimeException('endereço de link não permitido');
        }
    }
    foreach ($ips as $ip) {                      // prefere IPv4 (funciona em mais redes)
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $ip;
        }
    }
    return $ips[0];
}

/** Junta um endereço relativo (ex.: "/arquivo.pdf") com o endereço da página onde ele apareceu. */
function juntar_url(string $base, string $relativo): string
{
    if (preg_match('#^https?://#i', $relativo)) {
        return $relativo;
    }
    $b = parse_url($base);
    $inicio = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
    if (str_starts_with($relativo, '//')) {
        return $b['scheme'] . ':' . $relativo;
    }
    if (str_starts_with($relativo, '/')) {
        return $inicio . $relativo;
    }
    $pasta = preg_replace('#/[^/]*$#', '/', $b['path'] ?? '/');
    return $inicio . $pasta . $relativo;
}

/**
 * Baixa o conteúdo de um endereço, com limite de tamanho e de tempo total.
 * Não usa proxy: a conexão é direta, para poder conferir o IP de destino.
 * Redirecionamentos são seguidos um a um (no máximo 5), conferindo o endereço de cada um.
 * $so_inicio: se for maior que 0, baixa só esses primeiros bytes e para (usado para
 * conferir rapidamente se um link ainda abre, sem baixar o livro inteiro).
 */
function baixar(string $url, int $so_inicio = 0): string
{
    $prazo = microtime(true) + TEMPO_MAXIMO_DOWNLOAD;
    for ($saltos = 0; $saltos <= 5; $saltos++) {
        $partes = parse_url($url);
        $esquema = strtolower($partes['scheme'] ?? '');
        if (!in_array($esquema, ['http', 'https'], true) || empty($partes['host'])) {
            throw new RuntimeException('o link precisa começar com http:// ou https://');
        }
        $porta = $partes['port'] ?? ($esquema === 'https' ? 443 : 80);
        $ip = resolver_ip_publico($partes['host']);
        $restante = (int) max(1, ceil($prazo - microtime(true)));

        $dados = '';
        $estourou = false;
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RESOLVE => [$partes['host'] . ':' . $porta . ':' . (str_contains($ip, ':') ? "[$ip]" : $ip)],
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $restante,
            CURLOPT_HTTPHEADER => array_merge(['User-Agent: Mozilla/5.0'],
                $so_inicio ? ['Range: bytes=0-' . ($so_inicio - 1)] : []),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HEADER => false,
            CURLOPT_WRITEFUNCTION => function ($c, $pedaco) use (&$dados, &$estourou, $so_inicio) {
                $dados .= $pedaco;
                if ($so_inicio && strlen($dados) >= $so_inicio) {
                    return -1;                               // já temos o comecinho: para de baixar
                }
                if (strlen($dados) > LIMITE_DOWNLOAD_LINK) {
                    $estourou = true;
                    return -1;
                }
                return strlen($pedaco);
            },
        ]);
        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($curl, CURLOPT_PROTOCOLS_STR, 'http,https');
        } else {
            curl_setopt($curl, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }
        $ok = curl_exec($curl);
        $codigo = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $destino = (string) curl_getinfo($curl, CURLINFO_REDIRECT_URL);
        $erro = curl_errno($curl);
        $mensagem = curl_error($curl);
        curl_close($curl);

        if ($estourou) {
            throw new RuntimeException('o arquivo do link é grande demais (limite de ' . intdiv(LIMITE_DOWNLOAD_LINK, 1048576) . ' MB)');
        }
        if ($ok === false && !($erro === CURLE_WRITE_ERROR && $so_inicio)) {
            if ($erro === CURLE_OPERATION_TIMEDOUT) {
                throw new RuntimeException('o link demorou demais para responder');
            }
            throw new RuntimeException('não consegui acessar o link (' . $mensagem . ')');
        }
        if (in_array($codigo, [301, 302, 303, 307, 308], true) && $destino !== '') {
            $url = juntar_url($url, $destino);
            continue;
        }
        if ($codigo >= 400) {
            throw new RuntimeException("o link respondeu com erro {$codigo}");
        }
        return $so_inicio ? substr($dados, 0, $so_inicio) : $dados;
    }
    throw new RuntimeException('o link redireciona vezes demais');
}

/**
 * Confere RÁPIDO se um link ainda leva a um PDF (baixa só o comecinho do arquivo).
 * Usado pelo botão "Verificar links". Devolve true ou false.
 */
function link_abre_pdf(string $url): bool
{
    try {
        return str_starts_with(ltrim(baixar(converter_link_drive($url), 1024)), '%PDF');
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Baixa o PDF de um link. Se o link abrir uma página (HTML) em vez do PDF,
 * procura nela o primeiro link .pdf e usa esse.
 */
function obter_pdf_de_link(string $url): string
{
    $do_drive = id_arquivo_drive($url) !== null;
    $url = converter_link_drive($url);
    $dados = baixar($url);

    // No Drive, receber uma página em vez do PDF quer dizer que o arquivo não está compartilhado
    if ($do_drive && !str_starts_with(ltrim($dados), '%PDF')) {
        throw new RuntimeException(ERRO_DRIVE_FECHADO);
    }
    if (!str_starts_with($dados, '%PDF')) {
        if (!preg_match('/href=["\']([^"\']+\.pdf[^"\']*)["\']/i', $dados, $m)) {
            throw new RuntimeException('o link abre uma página e não achei nenhum PDF nela');
        }
        $dados = baixar(juntar_url($url, html_entity_decode($m[1])));
    }
    if (!str_starts_with($dados, '%PDF')) {
        throw new RuntimeException('o link não leva a um PDF válido');
    }
    return $dados;
}
