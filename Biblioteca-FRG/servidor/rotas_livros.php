<?php
/**
 * ROTAS DOS LIVROS E FILTROS.
 * Públicas (qualquer visitante): lista de livros e capa.
 * Do administrador (precisam de login): conferir link, cadastrar, editar, refazer capa,
 * verificar links, excluir, filtros e a pasta da biblioteca no Drive.
 *
 * Os PDFs ficam no Google Drive da Secretaria: o site guarda só o link e a capa.
 */

const SQL_LIVRO = 'SELECT l.id, l.titulo, l.autor, l.categoria_id, c.nome AS categoria, l.descricao, l.link,'
    . ' l.arquivo_externo, l.tem_pdf, COALESCE(a.capa_versao, 0) AS capa_versao'
    . ' FROM livros l JOIN categorias c ON c.id = l.categoria_id'
    . ' LEFT JOIN arquivos a ON a.livro_id = l.id';

// ============================== PÚBLICO ==============================

/** Transforma uma linha do banco no formato que o site (app.js) espera. */
function montar_livro(array $l): array
{
    $versao = (int) $l['capa_versao'];
    return [
        'id' => $l['id'],
        'titulo' => $l['titulo'],
        'autor' => $l['autor'],
        'categoria' => $l['categoria'],
        'categoria_id' => $l['categoria_id'],
        'descricao' => $l['descricao'],
        'link' => $l['link'],
        // Link do PDF no Google Drive (livros antigos com PDF dentro do banco -> /livros/<id>/pdf)
        'arquivo' => (int) $l['tem_pdf'] ? "/livros/{$l['id']}/pdf" : $l['arquivo_externo'],
        'capa' => $versao ? "/livros/{$l['id']}/capa?v={$versao}" : null,
    ];
}

/** GET /api/livros — lista de filtros + livros (qualquer pessoa pode ver). */
function rota_listar_livros(): Resposta
{
    $b = banco();
    $categorias = array_column($b->todos('SELECT nome FROM categorias ORDER BY ordem'), 'nome');
    $linhas = $b->todos(SQL_LIVRO . ' ORDER BY l.criado_em DESC, l.titulo');
    $resposta = json(['categorias' => $categorias, 'livros' => array_map('montar_livro', $linhas)]);
    $resposta->cabecalhos['Cache-Control'] = 'no-cache';
    return $resposta;
}

/**
 * Entrega o PDF ou a capa guardados no banco.
 * - ETag: se o navegador já tem a versão atual, responde 304 SEM nem ler o arquivo do banco (economiza muito).
 * - Range: permite abrir PDFs grandes aos poucos e retomar downloads interrompidos.
 */
function enviar_binario(string $coluna, string $tipo, string $livro_id, string $cache, int $versao): Resposta
{
    $etag = '"' . $livro_id . '-' . $coluna . '-' . $versao . '"';
    $pedidas = array_map('trim', explode(',', cabecalho('If-None-Match')));
    if (in_array($etag, $pedidas, true) || in_array('W/' . $etag, $pedidas, true)) {
        return new Resposta('', 304, ['ETag' => $etag, 'Cache-Control' => $cache]);
    }
    $coluna = $coluna === 'pdf' ? 'pdf' : 'capa';           // só estas duas colunas existem
    $linha = banco()->um("SELECT {$coluna} AS dado FROM arquivos WHERE livro_id = ?", [$livro_id]);
    $dados = $linha ? $linha['dado'] : null;
    if (is_resource($dados)) {
        $dados = stream_get_contents($dados);
    }
    if (!$dados) {
        return json(['erro' => 'Não encontrado.'], 404);
    }
    $cabecalhos = ['Content-Type' => $tipo, 'ETag' => $etag, 'Cache-Control' => $cache, 'Accept-Ranges' => 'bytes'];
    $total = strlen($dados);
    if (preg_match('/^bytes=(\d*)-(\d*)$/', cabecalho('Range'), $m) && ($m[1] !== '' || $m[2] !== '')) {
        $inicio = $m[1] === '' ? max(0, $total - (int) $m[2]) : (int) $m[1];
        $fim = ($m[1] === '' || $m[2] === '') ? $total - 1 : min((int) $m[2], $total - 1);
        if ($inicio > $fim || $inicio >= $total) {
            return new Resposta('', 416, ['Content-Range' => "bytes */{$total}"]);
        }
        $cabecalhos['Content-Range'] = "bytes {$inicio}-{$fim}/{$total}";
        return new Resposta(substr($dados, $inicio, $fim - $inicio + 1), 206, $cabecalhos);
    }
    return new Resposta($dados, 200, $cabecalhos);
}

/** GET /livros/{id}/capa — imagem da capa. O ?v= da URL muda quando a capa muda, então pode ficar em cache. */
function rota_baixar_capa(string $livro_id): Resposta
{
    $linha = banco()->um('SELECT capa_versao FROM arquivos WHERE livro_id = ?', [$livro_id]);
    $versao = $linha ? (int) $linha['capa_versao'] : 0;
    return enviar_binario('capa', 'image/jpeg', $livro_id, 'public, max-age=31536000, immutable', $versao);
}

/** GET /livros/{id}/pdf — PDF de livros ANTIGOS, de quando o PDF era enviado pelo painel e ficava no banco. */
function rota_baixar_pdf(string $livro_id): Resposta
{
    $livro = banco()->um('SELECT titulo, atualizado_em FROM livros WHERE id = ?', [$livro_id]);
    if (!$livro) {
        return json(['erro' => 'Não encontrado.'], 404);
    }
    $resposta = enviar_binario('pdf', 'application/pdf', $livro_id, 'public, max-age=300', (int) $livro['atualizado_em']);
    if (in_array($resposta->status, [200, 206], true)) {
        $ascii = (string) iconv('UTF-8', 'ASCII//IGNORE', $livro['titulo']);
        $nome = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $ascii), '-') ?: 'livro';
        $resposta->cabecalhos['Content-Disposition'] = 'inline; filename="' . substr($nome, 0, 80) . '.pdf"';
    }
    return $resposta;
}

// ============================== VALIDAÇÃO ==============================

/** Lê e valida os campos de texto do formulário do painel. Devolve um array. */
function ler_formulario(): array
{
    $f = formulario();
    $campos = [];
    foreach (['titulo', 'autor', 'categoria_id', 'descricao', 'link', 'arquivo_url'] as $chave) {
        $campos[$chave] = trim(texto($f[$chave] ?? ''));
    }
    if ($campos['titulo'] === '' || $campos['autor'] === '') {
        throw new ErroApi('Preencha o título e o autor.');
    }
    if (tamanho($campos['titulo']) > 200 || tamanho($campos['autor']) > 200) {
        throw new ErroApi('Título ou autor grande demais.');
    }
    if (tamanho($campos['descricao']) > 1200) {
        throw new ErroApi('A descrição passou de 1200 caracteres.');
    }
    if (!banco()->um('SELECT id FROM categorias WHERE id = ?', [$campos['categoria_id']])) {
        throw new ErroApi('Escolha um filtro (categoria) válido.');
    }
    foreach (['link', 'arquivo_url'] as $chave) {
        if (tamanho($campos[$chave]) > 2000) {
            throw new ErroApi('Link grande demais.');
        }
        if ($campos[$chave] !== '' && !preg_match('#^https?://#i', $campos[$chave])) {
            throw new ErroApi('Links precisam começar com http:// ou https://');
        }
    }
    return $campos;
}

/**
 * Confere o link do PDF colado no painel e devolve a versão padronizada.
 *   - Link de ARQUIVO do Drive (qualquer formato) -> https://drive.google.com/file/d/CODIGO/view
 *   - Link de PASTA do Drive -> erro explicando que precisa ser o link do arquivo
 *   - Link de outro site (ex.: PDF do MEC) -> aceito como está
 */
function padronizar_link_pdf(string $url): string
{
    $url = trim($url);
    if (!preg_match('#^https?://#i', $url)) {
        throw new ErroApi('Cole o link completo do PDF (começa com https://).');
    }
    if (tamanho($url) > 2000) {
        throw new ErroApi('Link grande demais.');
    }
    if (eh_pasta_drive($url)) {
        throw new ErroApi('Esse é o link da PASTA. Abra a pasta, clique com o botão direito no livro '
            . '→ Compartilhar → Copiar link, e cole o link do ARQUIVO.');
    }
    if (eh_link_drive($url)) {
        $id = id_arquivo_drive($url);
        if (!$id) {
            throw new ErroApi('Não reconheci esse link do Google Drive. Copie de novo pelo botão '
                . 'Compartilhar → Copiar link.');
        }
        return link_leitura_drive($id);
    }
    return $url;
}

// Capas criadas no botão "Conferir link" ficam guardadas por alguns minutos (na pasta temporária
// do servidor), para o "Salvar" não precisar baixar o PDF de novo. No máximo 20, por até 30 minutos.
const CAPAS_MAXIMO = 20;
const CAPAS_VALIDADE = 30 * 60;

/** Pasta onde as capas recentes ficam guardadas. */
function pasta_capas_recentes(): string
{
    $pasta = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'biblioteca_capas_recentes';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0700, true);
    }
    return $pasta;
}

/** Guarda a capa recém-criada para o link (apaga as mais antigas quando passa de 20). */
function guardar_capa_recente(string $link, string $capa): void
{
    $pasta = pasta_capas_recentes();
    @file_put_contents($pasta . DIRECTORY_SEPARATOR . hash('sha256', $link) . '.jpg', $capa, LOCK_EX);
    $arquivos = glob($pasta . DIRECTORY_SEPARATOR . '*.jpg') ?: [];
    if (count($arquivos) > CAPAS_MAXIMO) {
        usort($arquivos, fn($a, $b) => filemtime($a) <=> filemtime($b));
        foreach (array_slice($arquivos, 0, count($arquivos) - CAPAS_MAXIMO) as $velho) {
            @unlink($velho);
        }
    }
}

/** Devolve a capa guardada para o link, se ainda estiver valendo (ou null). */
function pegar_capa_recente(string $link): ?string
{
    $arquivo = pasta_capas_recentes() . DIRECTORY_SEPARATOR . hash('sha256', $link) . '.jpg';
    if (is_file($arquivo) && time() - filemtime($arquivo) < CAPAS_VALIDADE) {
        $capa = @file_get_contents($arquivo);
        return $capa !== false && $capa !== '' ? $capa : null;
    }
    return null;
}

/**
 * Baixa o PDF do link UMA vez, cria a capa e descarta o PDF (nada fica guardado além da capa).
 * Devolve [capa, aviso]:
 *   - se o link não abre um PDF -> ErroApi (o livro NÃO é salvo, assim nenhum livro quebrado entra no site);
 *   - se o PDF abre mas a capa falha (ex.: PDF com senha) -> [null, aviso] e o livro pode ser salvo sem capa.
 */
function preparar_capa(string $link): array
{
    $capa = pegar_capa_recente($link);
    if ($capa !== null) {
        return [$capa, null];
    }
    try {
        $dados_pdf = obter_pdf_de_link($link);
    } catch (Throwable $erro) {
        throw new ErroApi('Não consegui abrir o PDF: ' . $erro->getMessage() . '.');
    }
    try {
        $capa = gerar_capa($dados_pdf);
    } catch (Throwable $erro) {
        return [null, 'O PDF abre, mas não consegui criar a capa (' . $erro->getMessage() . '). O livro pode ser salvo sem capa.'];
    } finally {
        unset($dados_pdf);                       // descarta o PDF: o site não guarda o arquivo
    }
    guardar_capa_recente($link, $capa);
    return [$capa, null];
}

/**
 * Guarda a capa do livro na tabela arquivos (cria a linha se ainda não existir).
 * $apagar_pdf_antigo = true limpa o PDF de livros antigos que tinham o arquivo dentro do banco.
 */
function gravar_capa(string $livro_id, ?string $capa, bool $apagar_pdf_antigo = false): void
{
    $b = banco();
    $versao = $capa ? agora() : 0;
    if (!$b->um('SELECT livro_id FROM arquivos WHERE livro_id = ?', [$livro_id])) {
        $b->executar('INSERT INTO arquivos (livro_id, pdf, capa, capa_versao) VALUES (?, NULL, ?, ?)', [$livro_id, $capa, $versao]);
    } elseif ($apagar_pdf_antigo) {
        $b->executar('UPDATE arquivos SET pdf = NULL, capa = ?, capa_versao = ? WHERE livro_id = ?', [$capa, $versao, $livro_id]);
    } else {
        $b->executar('UPDATE arquivos SET capa = ?, capa_versao = ? WHERE livro_id = ?', [$capa, $versao, $livro_id]);
    }
}

/** Busca um livro no formato do site (ou null). */
function livro_completo(string $livro_id): ?array
{
    $l = banco()->um(SQL_LIVRO . ' WHERE l.id = ?', [$livro_id]);
    return $l ? montar_livro($l) : null;
}

// ============================== ADMIN: LIVROS ==============================

/**
 * POST /api/admin/livros/conferir-link — botão "Conferir link" do painel: testa se o link abre
 * o PDF e devolve a prévia da capa. Recebe {link}. Responde {ok, link (padronizado), capa, aviso}.
 */
function rota_conferir_link(): Resposta
{
    $corpo = corpo_json();
    $link = padronizar_link_pdf(texto($corpo['link'] ?? ''));
    [$capa, $aviso] = preparar_capa($link);
    $previa = $capa ? 'data:image/jpeg;base64,' . base64_encode($capa) : null;
    return json(['ok' => true, 'link' => $link, 'capa' => $previa, 'aviso' => $aviso]);
}

/** POST /api/admin/livros — cadastra um livro novo: guarda os dados + o link do PDF e cria a capa automaticamente. */
function rota_cadastrar_livro(): Resposta
{
    $campos = ler_formulario();
    if ($campos['arquivo_url'] === '') {
        throw new ErroApi('Cole o link do PDF no Google Drive.');
    }
    $link_pdf = padronizar_link_pdf($campos['arquivo_url']);
    [$capa, $aviso] = preparar_capa($link_pdf);

    $livro_id = novo_id();
    $t = agora();
    banco()->executar(
        'INSERT INTO livros (id, titulo, autor, categoria_id, descricao, link, arquivo_externo,'
        . ' tem_pdf, criado_em, atualizado_em) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)',
        [$livro_id, $campos['titulo'], $campos['autor'], $campos['categoria_id'], $campos['descricao'],
            $campos['link'], $link_pdf, $t, $t]);
    gravar_capa($livro_id, $capa);
    registrar_auditoria('livro_criado', $campos['titulo'], Contexto::$sessao['usuario'], ip_do_visitante());
    return json(['ok' => true, 'livro' => livro_completo($livro_id), 'aviso' => $aviso]);
}

/** PUT /api/admin/livros/{id} — edita um livro. Se o link do PDF mudar, a capa é criada de novo. */
function rota_editar_livro(string $livro_id): Resposta
{
    $b = banco();
    $atual = $b->um('SELECT id, tem_pdf, arquivo_externo FROM livros WHERE id = ?', [$livro_id]);
    if (!$atual) {
        throw new ErroApi('Livro não encontrado.', 404);
    }
    $campos = ler_formulario();
    $aviso = null;
    $link_pdf = $atual['arquivo_externo'];
    $trocou_link = false;
    if ($campos['arquivo_url'] !== '') {
        $novo_link = padronizar_link_pdf($campos['arquivo_url']);
        if ($novo_link !== $atual['arquivo_externo'] || (int) $atual['tem_pdf']) {
            [$link_pdf, $trocou_link] = [$novo_link, true];
        }
    } elseif (!(int) $atual['tem_pdf']) {
        throw new ErroApi('Cole o link do PDF no Google Drive.');
    }

    $capa = null;
    if ($trocou_link) {
        [$capa, $aviso] = preparar_capa($link_pdf);
    }
    $b->executar(
        'UPDATE livros SET titulo = ?, autor = ?, categoria_id = ?, descricao = ?, link = ?,'
        . ' arquivo_externo = ?, tem_pdf = ?, atualizado_em = ? WHERE id = ?',
        [$campos['titulo'], $campos['autor'], $campos['categoria_id'], $campos['descricao'],
            $campos['link'], $link_pdf, $trocou_link ? 0 : (int) $atual['tem_pdf'], agora(), $livro_id]);
    if ($trocou_link) {
        gravar_capa($livro_id, $capa, true);
    }
    registrar_auditoria('livro_editado', $campos['titulo'], Contexto::$sessao['usuario'], ip_do_visitante());
    return json(['ok' => true, 'livro' => livro_completo($livro_id), 'aviso' => $aviso]);
}

/** POST /api/admin/livros/{id}/capa — cria a capa de novo a partir do PDF do livro. */
function rota_refazer_capa(string $livro_id): Resposta
{
    $b = banco();
    $livro = $b->um('SELECT id, tem_pdf, arquivo_externo FROM livros WHERE id = ?', [$livro_id]);
    if (!$livro) {
        throw new ErroApi('Livro não encontrado.', 404);
    }
    try {
        if ((int) $livro['tem_pdf']) {
            $linha = $b->um('SELECT pdf FROM arquivos WHERE livro_id = ?', [$livro_id]);
            $conteudo = is_resource($linha['pdf'] ?? null) ? stream_get_contents($linha['pdf']) : (string) ($linha['pdf'] ?? '');
        } else {
            $conteudo = obter_pdf_de_link($livro['arquivo_externo']);
        }
        $nova = gerar_capa($conteudo);
    } catch (Throwable $erro) {
        throw new ErroApi('Não consegui gerar a capa: ' . $erro->getMessage());
    }
    gravar_capa($livro_id, $nova);
    return json(['ok' => true, 'livro' => livro_completo($livro_id)]);
}

/**
 * POST /api/admin/livros/{id}/verificar — botão "Verificar links": confere se o PDF deste livro
 * ainda abre (baixa só o comecinho do arquivo, então é rápido). Responde {ok, abre}.
 */
function rota_verificar_link(string $livro_id): Resposta
{
    $livro = banco()->um('SELECT tem_pdf, arquivo_externo FROM livros WHERE id = ?', [$livro_id]);
    if (!$livro) {
        throw new ErroApi('Livro não encontrado.', 404);
    }
    $abre = (int) $livro['tem_pdf'] || link_abre_pdf($livro['arquivo_externo']);
    return json(['ok' => true, 'abre' => $abre]);
}

/** DELETE /api/admin/livros/{id} — exclui o livro do site (a capa sai junto). O arquivo no Drive NÃO é apagado. */
function rota_excluir_livro(string $livro_id): Resposta
{
    $b = banco();
    $livro = $b->um('SELECT titulo FROM livros WHERE id = ?', [$livro_id]);
    if (!$livro) {
        throw new ErroApi('Livro não encontrado.', 404);
    }
    $b->executar('DELETE FROM arquivos WHERE livro_id = ?', [$livro_id]);
    $b->executar('DELETE FROM livros WHERE id = ?', [$livro_id]);
    registrar_auditoria('livro_excluido', $livro['titulo'], Contexto::$sessao['usuario'], ip_do_visitante());
    return json(['ok' => true]);
}

// ============================== ADMIN: CONFIGURAÇÕES ==============================

/** GET /api/admin/configuracoes — link da pasta da biblioteca no Drive (usado no botão "Abrir pasta da biblioteca"). */
function rota_ver_configuracoes(): Resposta
{
    return json(['pasta_drive' => ler_configuracao('pasta_drive')]);
}

/**
 * PUT /api/admin/configuracoes — salva o link da pasta da biblioteca no Drive. Só o super administrador.
 * Recebe {pasta_drive}. Vazio = apaga o link (o botão some do painel).
 */
function rota_salvar_configuracoes(): Resposta
{
    $corpo = corpo_json();
    $url = mb_substr(trim(texto($corpo['pasta_drive'] ?? '')), 0, 2000);
    if ($url !== '') {
        $id = id_pasta_drive($url);
        if (!$id) {
            throw new ErroApi('Cole o link de uma PASTA do Google Drive (ele tem /drive/folders/ no meio).');
        }
        $url = link_pasta_drive($id);
    }
    salvar_configuracao('pasta_drive', $url);
    registrar_auditoria('configuracao_salva', 'Pasta da biblioteca no Drive', Contexto::$sessao['usuario'], ip_do_visitante());
    return json(['ok' => true, 'pasta_drive' => $url]);
}

// ============================== ADMIN: FILTROS ==============================

/** GET /api/admin/categorias — filtros com id e quantidade de livros (o painel usa para renomear/remover). */
function rota_listar_categorias(): Resposta
{
    $linhas = banco()->todos(
        'SELECT c.id, c.nome, COUNT(l.id) AS total FROM categorias c'
        . ' LEFT JOIN livros l ON l.categoria_id = c.id GROUP BY c.id, c.nome, c.ordem ORDER BY c.ordem');
    $categorias = array_map(fn($c) => ['id' => $c['id'], 'nome' => $c['nome'], 'total' => (int) $c['total']], $linhas);
    return json(['categorias' => $categorias, 'max_filtros' => MAX_FILTROS]);
}

/**
 * PUT /api/admin/categorias — recebe a lista completa de filtros [{id, nome}] na ordem desejada.
 * id vazio = filtro novo; nome diferente = renomeia (os livros acompanham, pois ligam pelo id);
 * filtro que sumiu da lista = removido (só se não tiver livros).
 */
function rota_salvar_categorias(): Resposta
{
    $itens = corpo_json()['categorias'] ?? null;
    if (!is_array($itens) || !array_is_list($itens)) {
        throw new ErroApi('Pedido inválido.');
    }
    $lista = [];
    $nomes = [];
    foreach ($itens as $item) {
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
            throw new ErroApi('Pedido inválido.');
        }
        $nome = trim(texto($item['nome'] ?? ''));
        if ($nome === '') {
            continue;
        }
        if (tamanho($nome) > 80) {
            throw new ErroApi('Nome de filtro grande demais (máx. 80).');
        }
        if (in_array(mb_strtolower($nome), $nomes, true)) {
            throw new ErroApi("O filtro \"{$nome}\" está repetido.");
        }
        $nomes[] = mb_strtolower($nome);
        $lista[] = [texto($item['id'] ?? ''), $nome];
    }
    if (!$lista || count($lista) > MAX_FILTROS) {
        throw new ErroApi('Use de 1 a ' . MAX_FILTROS . ' filtros.');
    }

    $b = banco();
    $existentes = [];
    foreach ($b->todos('SELECT id, nome FROM categorias') as $c) {
        $existentes[$c['id']] = $c;
    }
    $mantidos = array_values(array_filter(array_column($lista, 0), fn($i) => $i !== ''));
    if (array_diff($mantidos, array_keys($existentes))) {
        throw new ErroApi('Filtro desconhecido. Recarregue a página.');
    }
    $removidos = array_diff(array_keys($existentes), $mantidos);
    foreach ($removidos as $id_removido) {
        if ($b->um('SELECT id FROM livros WHERE categoria_id = ? LIMIT 1', [$id_removido])) {
            throw new ErroApi("Não dá para remover \"{$existentes[$id_removido]['nome']}\": ainda tem livros nele.");
        }
    }
    // Passo 1: nomes provisórios (evita conflito de nome único ao trocar nomes entre filtros)
    foreach ($lista as [$id_cat]) {
        if ($id_cat !== '') {
            $b->executar('UPDATE categorias SET nome = ? WHERE id = ?', ['~' . novo_id(), $id_cat]);
        }
    }
    foreach ($removidos as $id_removido) {
        $b->executar('DELETE FROM categorias WHERE id = ?', [$id_removido]);
    }
    // Passo 2: nomes e ordem finais; cria os novos
    foreach ($lista as $ordem => [$id_cat, $nome]) {
        if ($id_cat !== '') {
            $b->executar('UPDATE categorias SET nome = ?, ordem = ? WHERE id = ?', [$nome, $ordem, $id_cat]);
        } else {
            $b->executar('INSERT INTO categorias (id, nome, ordem) VALUES (?, ?, ?)', [novo_id(), $nome, $ordem]);
        }
    }
    registrar_auditoria('filtros_salvos', count($lista) . ' filtros', Contexto::$sessao['usuario'], ip_do_visitante());
    return rota_listar_categorias();
}
