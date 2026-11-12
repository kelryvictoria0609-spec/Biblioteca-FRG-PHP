<?php
/**
 * SEGURANÇA: senhas, sessões (tokens), proteção contra pedidos forjados (CSRF)
 * e bloqueio de tentativas de login erradas.
 *
 * Resumo de como funciona:
 *  1. SENHA: nunca é guardada. Guardamos um "hash" (Argon2id, ou bcrypt se o PHP não tiver Argon2).
 *     Se o banco vazar, não dá para recuperar a senha.
 *  2. LOGIN: se a senha confere, criamos um TOKEN aleatório (32 bytes). O navegador recebe o token
 *     num cookie protegido (HttpOnly = o JavaScript não lê; Secure = só HTTPS; SameSite=Strict =
 *     não é enviado a partir de outros sites). No banco guardamos só o SHA-256 do token.
 *  3. CSRF: cada sessão tem um segundo código. Todo pedido que altera algo precisa enviá-lo no
 *     cabeçalho X-CSRF-Token, e o endereço de origem do pedido (Origin) precisa ser o nosso site.
 *  4. BLOQUEIO: 5 erros do mesmo usuário no mesmo IP, 50 do mesmo usuário em qualquer IP ou
 *     15 do mesmo IP bloqueiam o login por 15 minutos.
 *  5. SUPER ADMINISTRADOR: existe UM só (tabela super_admin). Só ele cria, desativa e redefine a
 *     senha dos outros administradores. Os demais cuidam apenas dos livros e dos filtros.
 */

/** A sessão (login) do pedido atual, preenchida pela proteção das rotas do administrador. */
final class Contexto
{
    public static ?array $sessao = null;
}

// ============================== SENHAS ==============================

/** Diz qual método de hash este servidor usa (Argon2id é o recomendado pela OWASP). */
function metodo_hash(): array
{
    if (defined('PASSWORD_ARGON2ID')) {
        // ~19 MB de memória por conferência e 2 passadas (valores mínimos recomendados pela OWASP)
        return [PASSWORD_ARGON2ID, ['memory_cost' => 19456, 'time_cost' => 2, 'threads' => 1]];
    }
    return [PASSWORD_BCRYPT, ['cost' => 12]];
}

/**
 * O bcrypt só considera os primeiros 72 bytes da senha. Para senhas longas não perderem
 * força, a senha passa antes por um SHA-384 (técnica comum e segura). O Argon2 não precisa disso.
 */
function preparar_para_bcrypt(string $senha): string
{
    return base64_encode(hash('sha384', $senha, true));
}

/** Transforma a senha em hash (com sal aleatório embutido). */
function gerar_hash(string $senha): string
{
    [$algoritmo, $opcoes] = metodo_hash();
    $entrada = $algoritmo === PASSWORD_BCRYPT ? preparar_para_bcrypt($senha) : $senha;
    return password_hash($entrada, $algoritmo, $opcoes);
}

/**
 * Confere uma senha digitada contra o hash guardado.
 * Também aceita as senhas da versão antiga do sistema (Python, formato "scrypt:N:r:p$sal$hash"),
 * se a biblioteca libsodium do PHP tiver o scrypt. Essas senhas são convertidas no próximo login.
 */
function conferir_hash(string $hash_guardado, string $senha): bool
{
    if (str_starts_with($hash_guardado, 'scrypt:')) {
        return conferir_hash_antigo($hash_guardado, $senha);
    }
    if (str_starts_with($hash_guardado, '$2y$')) {
        return password_verify(preparar_para_bcrypt($senha), $hash_guardado);
    }
    return password_verify($senha, $hash_guardado);
}

/** Confere uma senha no formato da versão Python ("scrypt:16384:8:5$sal$hash_em_hex"). */
function conferir_hash_antigo(string $hash_guardado, string $senha): bool
{
    if (!function_exists('sodium_crypto_pwhash_scryptsalsa208sha256_ll')) {
        registrar_log('AVISO', 'Senha no formato antigo (Python) e este PHP não tem scrypt: '
            . 'redefina a senha com "php criar_admin.php".');
        return false;
    }
    $partes = explode('$', $hash_guardado);
    if (count($partes) !== 3 || !preg_match('/^scrypt:(\d+):(\d+):(\d+)$/', $partes[0], $p)) {
        return false;
    }
    $calculado = sodium_crypto_pwhash_scryptsalsa208sha256_ll($senha, $partes[1], (int) $p[1], (int) $p[2], (int) $p[3], 64);
    return hash_equals($partes[2], bin2hex($calculado));
}

/** Diz se o hash precisa ser refeito no formato atual (formato antigo ou configuração mudou). */
function hash_desatualizado(string $hash_guardado): bool
{
    [$algoritmo, $opcoes] = metodo_hash();
    return str_starts_with($hash_guardado, 'scrypt:') || password_needs_rehash($hash_guardado, $algoritmo, $opcoes);
}

const SENHAS_FRACAS = ['123456789012', 'senhasenhasenha', 'administrador', 'administrador1',
    'bibliotecafrg', 'bibliotecafrg1', 'qwertyuiop12', 'password1234'];

/** Regras da senha do administrador. Lança ErroApi se for fraca. */
function validar_senha_nova(string $senha, string $usuario = ''): void
{
    if (tamanho($senha) < 12) {
        throw new ErroApi('A senha precisa ter pelo menos 12 caracteres.');
    }
    if (tamanho($senha) > 200) {
        throw new ErroApi('Senha grande demais.');
    }
    if (count(array_unique(mb_str_split($senha))) < 5) {
        throw new ErroApi('A senha é repetitiva demais. Misture letras, números e símbolos.');
    }
    $minuscula = mb_strtolower($senha);
    if (in_array($minuscula, SENHAS_FRACAS, true)
        || ($usuario !== '' && str_contains($minuscula, mb_strtolower($usuario)))) {
        throw new ErroApi('Escolha uma senha menos previsível (não use o nome do usuário).');
    }
}

// ============================== TOKENS ==============================

/** Texto aleatório seguro para URL/cookie (mesmo formato do "token_urlsafe" do Python). */
function token_aleatorio(int $bytes): string
{
    return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
}

/**
 * Cria um login novo. Devolve [token, csrf].
 * O token vai para o cookie; no banco fica só o hash dele.
 */
function criar_sessao(string $usuario_id): array
{
    $token = token_aleatorio(32);
    $csrf = token_aleatorio(24);
    $t = agora();
    banco()->executar(
        'INSERT INTO sessoes (token_hash, usuario_id, csrf, criado_em, expira_em, ultimo_uso, ip, agente)'
        . ' VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [hash('sha256', $token), $usuario_id, $csrf, $t, $t + SESSAO_DURACAO_MAXIMA, $t,
            ip_do_visitante(), mb_substr(cabecalho('User-Agent'), 0, 200)]);
    return [$token, $csrf];
}

/**
 * Procura o login do pedido atual (pelo cookie). Devolve os dados da sessão
 * (usuário, id e csrf) — ou null se não estiver logado / expirou.
 */
function sessao_atual(): ?array
{
    $token = cookie(NOME_COOKIE);
    if ($token === '' || strlen($token) > 100) {
        return null;
    }
    $b = banco();
    $linha = $b->um(
        'SELECT s.token_hash, s.usuario_id, s.csrf, s.expira_em, s.ultimo_uso, u.usuario, u.ativo'
        . ' FROM sessoes s JOIN usuarios u ON u.id = s.usuario_id WHERE s.token_hash = ?',
        [hash('sha256', $token)]);
    if (!$linha) {
        return null;
    }
    $t = agora();
    if (!(int) $linha['ativo'] || (int) $linha['expira_em'] <= $t
        || $t - (int) $linha['ultimo_uso'] > SESSAO_INATIVIDADE) {
        $b->executar('DELETE FROM sessoes WHERE token_hash = ?', [$linha['token_hash']]);
        return null;
    }
    if ($t - (int) $linha['ultimo_uso'] > 60) {     // atualiza o "último uso" no máximo 1x por minuto
        $b->executar('UPDATE sessoes SET ultimo_uso = ? WHERE token_hash = ?', [$t, $linha['token_hash']]);
    }
    return $linha;
}

/** Apaga do banco o login do cookie atual (logout). */
function encerrar_sessao_do_cookie(): void
{
    $token = cookie(NOME_COOKIE);
    if ($token !== '') {
        banco()->executar('DELETE FROM sessoes WHERE token_hash = ?', [hash('sha256', $token)]);
    }
}

/** Derruba todos os logins do usuário, menos o atual (usado ao trocar a senha). */
function encerrar_outras_sessoes(string $usuario_id, string $manter_token_hash): void
{
    banco()->executar('DELETE FROM sessoes WHERE usuario_id = ? AND token_hash <> ?', [$usuario_id, $manter_token_hash]);
}

/** Remove do banco logins que já expiraram. */
function limpar_sessoes_vencidas(): void
{
    banco()->executar('DELETE FROM sessoes WHERE expira_em <= ?', [agora()]);
}

/** Coloca o cookie de login na resposta, com todas as proteções. */
function definir_cookie(string $token): void
{
    setcookie(NOME_COOKIE, $token, [
        'expires' => time() + SESSAO_DURACAO_MAXIMA, 'path' => '/', 'secure' => COOKIE_SEGURO,
        'httponly' => true, 'samesite' => 'Strict',
    ]);
}

/** Manda o navegador apagar o cookie de login (logout). */
function apagar_cookie(): void
{
    setcookie(NOME_COOKIE, '', [
        'expires' => 1, 'path' => '/', 'secure' => COOKIE_SEGURO, 'httponly' => true, 'samesite' => 'Strict',
    ]);
}

// ============================== CSRF / ORIGEM ==============================

/**
 * Em pedidos que alteram dados, o navegador envia o cabeçalho Origin (ou Referer).
 * Ele precisa ser o endereço do nosso próprio site; senão é um site de fora tentando
 * usar o login da pessoa.
 */
function conferir_origem(): void
{
    $origem = cabecalho('Origin') ?: cabecalho('Referer');
    if ($origem === '' || $origem === 'null') {
        throw new ErroApi('Pedido não permitido.', 403);
    }
    $partes = parse_url($origem);
    $endereco = strtolower(($partes['host'] ?? '') . (isset($partes['port']) ? ':' . $partes['port'] : ''));
    $permitidos = [host_do_pedido()];
    if (DOMINIO_PUBLICO !== '') {          // endereço público do site, caso o proxy da TI altere o Host
        $permitidos[] = DOMINIO_PUBLICO;
    }
    if (!in_array($endereco, $permitidos, true)) {
        throw new ErroApi('Pedido não permitido.', 403);
    }
}

/**
 * Proteção das rotas do administrador. Exige: login válido e, nos pedidos que
 * alteram dados, a origem correta + o código CSRF da sessão.
 */
function exigir_admin(): void
{
    $sessao = sessao_atual();
    if (!$sessao) {
        throw new ErroApi('Faça login como administrador.', 401);
    }
    if (metodo() !== 'GET') {
        conferir_origem();
        if (!hash_equals((string) $sessao['csrf'], cabecalho('X-CSRF-Token'))) {
            throw new ErroApi('Sessão inválida. Recarregue a página.', 403);
        }
    }
    Contexto::$sessao = $sessao;
}

/**
 * Devolve o id do SUPER administrador (o único que gerencia os outros administradores).
 * Se ainda não houver um definido, o administrador ativo MAIS ANTIGO passa a ser o super.
 * Isso é gravado na tabela super_admin, que só aceita uma linha: nunca há dois.
 */
function super_admin_id(): ?string
{
    $b = banco();
    $linha = $b->um('SELECT usuario_id FROM super_admin WHERE id = 1');
    if ($linha) {
        return $linha['usuario_id'];
    }
    $primeiro = $b->um('SELECT id FROM usuarios WHERE ativo = 1 ORDER BY criado_em, id LIMIT 1');
    if (!$primeiro) {
        return null;
    }
    try {
        $b->executar('INSERT INTO super_admin (id, usuario_id) VALUES (1, ?)', [$primeiro['id']]);
    } catch (PDOException $e) {        // outro pedido definiu no mesmo instante: usa o que ficou gravado
        $linha = $b->um('SELECT usuario_id FROM super_admin WHERE id = 1');
        return $linha ? $linha['usuario_id'] : null;
    }
    return $primeiro['id'];
}

/**
 * Como o exigir_admin, mas só o SUPER administrador passa (login, origem e código CSRF
 * continuam sendo conferidos). Os outros administradores recebem 403.
 */
function exigir_super_admin(): void
{
    exigir_admin();
    if (Contexto::$sessao['usuario_id'] !== super_admin_id()) {
        throw new ErroApi('Apenas o super administrador pode fazer isso.', 403);
    }
}

// ============================== BLOQUEIO DE LOGIN ==============================

/**
 * Três contadores de erro de login:
 *   ip:...      -> tudo que vem do mesmo IP
 *   par:...     -> o mesmo usuário vindo do mesmo IP (limite baixo: 5)
 *   usuario:... -> o mesmo usuário vindo de QUALQUER IP (limite alto: 50)
 * Assim um atacante de fora não consegue travar a conta do administrador com 5 tentativas.
 */
function chaves_login(string $usuario): array
{
    $ip = ip_do_visitante();
    $nome = mb_substr(mb_strtolower($usuario), 0, 100);
    $par = substr(hash('sha256', $nome . '|' . $ip), 0, 40);   // hash: a chave cabe sempre em 128 caracteres
    return ['ip:' . $ip, 'par:' . $par, 'usuario:' . $nome];
}

/** Quantos erros cada tipo de contador aceita antes de bloquear. */
function limite_login(string $chave): int
{
    if (str_starts_with($chave, 'ip:')) {
        return LOGIN_MAX_ERROS_IP;
    }
    return str_starts_with($chave, 'par:') ? LOGIN_MAX_ERROS_USUARIO : LOGIN_MAX_ERROS_USUARIO_GERAL;
}

/** Lança ErroApi (429) se este IP, este usuário (neste IP) ou este usuário (geral) estiver bloqueado agora. */
function verificar_bloqueio(string $usuario): void
{
    $t = agora();
    foreach (chaves_login($usuario) as $chave) {
        $linha = banco()->um('SELECT bloqueado_ate FROM tentativas_login WHERE chave = ?', [$chave]);
        if ($linha && (int) $linha['bloqueado_ate'] > $t) {
            $minutos = intdiv((int) $linha['bloqueado_ate'] - $t, 60) + 1;
            throw new ErroApi("Muitas tentativas. Tente de novo em {$minutos} minuto(s).", 429);
        }
    }
}

/** Soma um erro de login para o IP e para o usuário, bloqueando se passar do limite. */
function registrar_erro_login(string $usuario): void
{
    $t = agora();
    $b = banco();
    if (random_int(0, 19) === 0) {     // de vez em quando apaga contadores velhos (sem precisar de agendador)
        $b->executar('DELETE FROM tentativas_login WHERE bloqueado_ate <= ? AND inicio < ?', [$t, $t - LOGIN_JANELA]);
    }
    foreach (chaves_login($usuario) as $chave) {
        $linha = $b->um('SELECT erros, inicio FROM tentativas_login WHERE chave = ?', [$chave]);
        if (!$linha || $t - (int) $linha['inicio'] > LOGIN_JANELA) {
            [$erros, $inicio] = [1, $t];
        } else {
            [$erros, $inicio] = [(int) $linha['erros'] + 1, (int) $linha['inicio']];
        }
        $bloqueado = $erros >= limite_login($chave) ? $t + LOGIN_BLOQUEIO : 0;
        $atualizar = 'UPDATE tentativas_login SET erros = ?, inicio = ?, bloqueado_ate = ? WHERE chave = ?';
        if ($linha) {
            $b->executar($atualizar, [$erros, $inicio, $bloqueado, $chave]);
        } else {
            try {
                $b->executar('INSERT INTO tentativas_login (chave, erros, inicio, bloqueado_ate) VALUES (?, ?, ?, ?)',
                    [$chave, $erros, $inicio, $bloqueado]);
            } catch (PDOException $e) {     // outro pedido criou a mesma chave no mesmo instante: só atualiza
                $b->executar($atualizar, [$erros, $inicio, $bloqueado, $chave]);
            }
        }
    }
}

/** Login correto: zera os contadores deste usuário (o do IP continua, evita abuso). */
function zerar_erros_login(string $usuario): void
{
    [, $par, $nome] = chaves_login($usuario);
    banco()->executar('DELETE FROM tentativas_login WHERE chave IN (?, ?)', [$par, $nome]);
}

/**
 * Confere usuário e senha. Devolve a linha do usuário ou null.
 * Se o usuário NÃO existe, conferimos mesmo assim contra um hash falso — assim o
 * tempo de resposta é igual e ninguém descobre quais usuários existem.
 */
function conferir_login(string $usuario, string $senha): ?array
{
    static $hash_falso = null;
    $linha = banco()->um('SELECT id, usuario, senha_hash, ativo FROM usuarios WHERE usuario = ?', [$usuario]);
    if (!$linha) {
        $hash_falso ??= gerar_hash(token_aleatorio(16));
        conferir_hash($hash_falso, $senha);
        return null;
    }
    if (conferir_hash($linha['senha_hash'], $senha) && (int) $linha['ativo']) {
        atualizar_hash_antigo($linha['id'], $linha['senha_hash'], $senha);
        return $linha;
    }
    return null;
}

/**
 * Se a senha foi guardada num formato antigo, guarda de novo no formato atual.
 * Só dá para fazer isso no login, que é o único momento em que temos a senha digitada.
 */
function atualizar_hash_antigo(string $usuario_id, string $hash_guardado, string $senha): void
{
    if (hash_desatualizado($hash_guardado)) {
        banco()->executar('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [gerar_hash($senha), $usuario_id]);
    }
}

// ============================== CRIAR USUÁRIO ==============================

/**
 * Cria um administrador novo (valida usuário e senha). Devolve o id.
 * $autor é quem criou (aparece no histórico); fica vazio quando o usuário é criado
 * pelo terminal ou automaticamente no primeiro acesso.
 */
function criar_usuario(string $usuario, string $senha, ?string $autor = null, ?string $ip = null): string
{
    $usuario = trim($usuario);
    if (!preg_match('/^[\p{L}\p{N}._-]{3,50}$/u', $usuario) || !preg_match('/[\p{L}\p{N}]/u', $usuario)) {
        throw new ErroApi('Usuário: de 3 a 50 caracteres (letras, números, ponto, hífen ou sublinhado).');
    }
    validar_senha_nova($senha, $usuario);
    // Sem diferenciar maiúsculas: não podem existir "Maria" e "maria" ao mesmo tempo
    if (banco()->um('SELECT id FROM usuarios WHERE LOWER(usuario) = ?', [mb_strtolower($usuario)])) {
        throw new ErroApi('Já existe um administrador com esse nome de usuário.', 409);
    }
    $id = novo_id();
    banco()->executar('INSERT INTO usuarios (id, usuario, senha_hash, ativo, criado_em) VALUES (?, ?, ?, 1, ?)',
        [$id, $usuario, gerar_hash($senha), agora()]);
    registrar_auditoria('usuario_criado', $usuario, $autor, $ip);
    super_admin_id();      // se ainda não existe super administrador, o mais antigo (este, no 1º cadastro) vira o super
    return $id;
}

/**
 * Se ainda não existe nenhum administrador e as variáveis ADMIN_USER e ADMIN_SENHA_INICIAL
 * estiverem definidas, cria o primeiro automaticamente (ele vira o super administrador).
 */
function criar_primeiro_admin(): void
{
    if (ADMIN_USER === '' || ADMIN_SENHA_INICIAL === '') {
        return;
    }
    if ((int) banco()->um('SELECT COUNT(*) AS n FROM usuarios')['n'] > 0) {
        return;
    }
    try {
        criar_usuario(ADMIN_USER, ADMIN_SENHA_INICIAL);
        banco()->commit();
        registrar_log('AVISO', 'Administrador "' . ADMIN_USER . '" criado. Troque a senha no painel e apague ADMIN_SENHA_INICIAL.');
    } catch (ErroApi $erro) {
        banco()->rollback();
        registrar_log('ERRO', 'Não criei o administrador inicial: ' . $erro->mensagem);
    }
}
