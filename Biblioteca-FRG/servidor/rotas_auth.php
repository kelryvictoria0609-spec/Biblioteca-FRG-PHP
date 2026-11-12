<?php
/**
 * ROTAS DE LOGIN E DE ADMINISTRADORES:
 *   - entrar, sair, ver sessão e trocar a própria senha (qualquer administrador);
 *   - listar, criar, desativar/reativar administradores e redefinir a senha de outro
 *     (SOMENTE o super administrador);
 *   - ver o histórico de atividades (SOMENTE o super administrador).
 *
 * As ações que mexem em OUTROS administradores exigem também a senha de quem está logado
 * (confirmação), para que um computador deixado aberto não sirva para criar um acesso novo.
 */

const MAX_ADMINISTRADORES = 20;     // limite de contas, para ninguém encher a tabela

/**
 * POST /api/login — recebe {usuario, senha}. Se estiver certo, cria a sessão e envia o cookie.
 * Erros de usuário/senha dão sempre a MESMA mensagem (não revela qual estava errado).
 */
function rota_login(): Resposta
{
    conferir_origem();
    $corpo = corpo_json();
    $usuario = mb_substr(trim(texto($corpo['usuario'] ?? '')), 0, 100);
    $senha = mb_substr(texto($corpo['senha'] ?? ''), 0, 200);

    verificar_bloqueio($usuario);
    $linha = conferir_login($usuario, $senha);

    if ($linha === null) {
        registrar_erro_login($usuario);
        // Só guarda o nome se o usuário existir: quem digita a SENHA no campo usuário não a deixa gravada no histórico
        $existe = banco()->um('SELECT id FROM usuarios WHERE usuario = ?', [$usuario]);
        registrar_auditoria('login_falhou', $existe ? $usuario : '(usuário inexistente)', null, ip_do_visitante());
        banco()->commit();                       // grava a tentativa mesmo devolvendo erro
        throw new ErroApi('Usuário ou senha incorretos.', 401);
    }

    zerar_erros_login($usuario);
    limpar_sessoes_vencidas();
    [$token, $csrf] = criar_sessao($linha['id']);          // token NOVO a cada login
    registrar_auditoria('login_ok', $usuario, null, ip_do_visitante());
    definir_cookie($token);
    return json(['ok' => true, 'csrf' => $csrf, 'usuario' => $linha['usuario']]);
}

/** POST /api/logout — sai: apaga a sessão do banco e o cookie do navegador. */
function rota_logout(): Resposta
{
    conferir_origem();
    encerrar_sessao_do_cookie();
    apagar_cookie();
    return json(['ok' => true]);
}

/** GET /api/sessao — diz se há administrador logado, devolve o código CSRF e se ele é o super administrador. */
function rota_sessao(): Resposta
{
    $s = sessao_atual();
    if (!$s) {
        return json(['admin' => false]);
    }
    return json(['admin' => true, 'usuario' => $s['usuario'], 'csrf' => $s['csrf'],
        'super' => $s['usuario_id'] === super_admin_id()]);
}

/**
 * POST /api/admin/senha — troca a senha do administrador logado. Exige a senha atual e
 * derruba todos os outros logins dessa conta.
 */
function rota_trocar_senha(): Resposta
{
    $corpo = corpo_json();
    $atual = mb_substr(texto($corpo['atual'] ?? ''), 0, 200);
    $nova = mb_substr(texto($corpo['nova'] ?? ''), 0, 200);

    $usuario = Contexto::$sessao['usuario'];
    verificar_bloqueio($usuario);
    $linha = conferir_login($usuario, $atual);
    if ($linha === null) {
        registrar_erro_login($usuario);
        banco()->commit();
        throw new ErroApi('A senha atual está incorreta.', 400);
    }

    validar_senha_nova($nova, $usuario);
    if ($nova === $atual) {
        throw new ErroApi('A nova senha precisa ser diferente da atual.');
    }

    banco()->executar('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [gerar_hash($nova), $linha['id']]);
    encerrar_outras_sessoes($linha['id'], Contexto::$sessao['token_hash']);
    registrar_auditoria('senha_trocada', $usuario, null, ip_do_visitante());
    return json(['ok' => true]);
}

// ============================== ADMINISTRADORES (só o super administrador) ==============================

/**
 * Exige a senha de QUEM ESTÁ LOGADO (campo "senha_confirmacao") antes de mexer em outras contas.
 * Usa o mesmo bloqueio de tentativas do login: errar muitas vezes bloqueia por 15 minutos.
 */
function confirmar_senha_do_admin(array $corpo): void
{
    $usuario = Contexto::$sessao['usuario'];
    $senha = mb_substr(texto($corpo['senha_confirmacao'] ?? ''), 0, 200);
    verificar_bloqueio($usuario);
    if (conferir_login($usuario, $senha) === null) {
        registrar_erro_login($usuario);
        banco()->commit();                       // grava a tentativa errada mesmo devolvendo erro
        throw new ErroApi('Sua senha está incorreta.', 400);
    }
}

/** Procura um administrador pelo id (404 se não existir). */
function buscar_usuario(string $id_usuario): array
{
    $alvo = banco()->um('SELECT id, usuario, ativo FROM usuarios WHERE id = ?', [substr($id_usuario, 0, 64)]);
    if (!$alvo) {
        throw new ErroApi('Administrador não encontrado.', 404);
    }
    return $alvo;
}

/** GET /api/admin/usuarios — lista todos os administradores (nunca devolve senhas nem hashes). */
function rota_listar_administradores(): Resposta
{
    $linhas = banco()->todos('SELECT id, usuario, ativo, criado_em FROM usuarios ORDER BY usuario');
    $super_id = super_admin_id();
    $usuarios = array_map(fn($l) => [
        'id' => $l['id'], 'usuario' => $l['usuario'], 'ativo' => (bool) (int) $l['ativo'],
        'criado_em' => (int) $l['criado_em'], 'voce' => $l['id'] === Contexto::$sessao['usuario_id'],
        'super' => $l['id'] === $super_id,
    ], $linhas);
    return json(['usuarios' => $usuarios, 'maximo' => MAX_ADMINISTRADORES]);
}

/**
 * POST /api/admin/usuarios — cria um administrador novo. Recebe {usuario, senha, senha_confirmacao}.
 * A senha segue as mesmas regras do resto do sistema (mínimo de 12 caracteres etc.).
 */
function rota_criar_administrador(): Resposta
{
    $corpo = corpo_json();
    confirmar_senha_do_admin($corpo);
    $nome = mb_substr(trim(texto($corpo['usuario'] ?? '')), 0, 100);
    $senha = mb_substr(texto($corpo['senha'] ?? ''), 0, 200);

    if ((int) banco()->um('SELECT COUNT(*) AS n FROM usuarios')['n'] >= MAX_ADMINISTRADORES) {
        throw new ErroApi('O limite é de ' . MAX_ADMINISTRADORES . ' administradores.');
    }
    $novo = criar_usuario($nome, $senha, Contexto::$sessao['usuario'], ip_do_visitante());
    return json(['ok' => true, 'id' => $novo], 201);
}

/**
 * POST /api/admin/usuarios/{id}/ativo — desativa ou reativa um administrador.
 * Recebe {ativo: true/false, senha_confirmacao}. Ao desativar, os logins dessa pessoa são encerrados na hora.
 * Ninguém pode desativar a si mesmo (assim sempre sobra pelo menos um administrador ativo).
 */
function rota_ativar_ou_desativar(string $id_usuario): Resposta
{
    $corpo = corpo_json();
    confirmar_senha_do_admin($corpo);
    $alvo = buscar_usuario($id_usuario);
    $ativo = $corpo['ativo'] ?? null;
    if (!is_bool($ativo)) {
        throw new ErroApi('Pedido inválido.');
    }
    if ($alvo['id'] === Contexto::$sessao['usuario_id']) {
        throw new ErroApi('Você não pode desativar a sua própria conta.');
    }
    banco()->executar('UPDATE usuarios SET ativo = ? WHERE id = ?', [$ativo ? 1 : 0, $alvo['id']]);
    if (!$ativo) {
        banco()->executar('DELETE FROM sessoes WHERE usuario_id = ?', [$alvo['id']]);
    }
    registrar_auditoria($ativo ? 'usuario_reativado' : 'usuario_desativado', $alvo['usuario'],
        Contexto::$sessao['usuario'], ip_do_visitante());
    return json(['ok' => true]);
}

/**
 * POST /api/admin/usuarios/{id}/senha — define uma senha nova para OUTRO administrador
 * (por exemplo, quem esqueceu a senha). Recebe {nova, senha_confirmacao}. Os logins dessa pessoa são encerrados.
 */
function rota_redefinir_senha_de_outro(string $id_usuario): Resposta
{
    $corpo = corpo_json();
    confirmar_senha_do_admin($corpo);
    $alvo = buscar_usuario($id_usuario);
    if ($alvo['id'] === Contexto::$sessao['usuario_id']) {
        throw new ErroApi('Para trocar a sua própria senha, use "Trocar minha senha".');
    }
    $nova = mb_substr(texto($corpo['nova'] ?? ''), 0, 200);
    validar_senha_nova($nova, $alvo['usuario']);
    banco()->executar('UPDATE usuarios SET senha_hash = ? WHERE id = ?', [gerar_hash($nova), $alvo['id']]);
    banco()->executar('DELETE FROM sessoes WHERE usuario_id = ?', [$alvo['id']]);
    registrar_auditoria('senha_redefinida', $alvo['usuario'], Contexto::$sessao['usuario'], ip_do_visitante());
    return json(['ok' => true]);
}

// ============================== HISTÓRICO (só o super administrador) ==============================

/** GET /api/admin/auditoria — últimas 200 ações registradas (quem fez o quê, quando e de qual IP). */
function rota_historico(): Resposta
{
    $linhas = banco()->todos('SELECT quando, usuario, acao, detalhe, ip FROM auditoria ORDER BY quando DESC LIMIT 200');
    $registros = array_map(fn($l) => [
        'quando' => (int) $l['quando'], 'usuario' => $l['usuario'] ?? '', 'acao' => $l['acao'],
        'detalhe' => $l['detalhe'] ?? '', 'ip' => $l['ip'] ?? '',
    ], $linhas);
    return json(['registros' => $registros]);
}
