<?php
/**
 * CRIAR (OU REDEFINIR A SENHA DE) UM ADMINISTRADOR — roda só no terminal.
 *
 * Uso:            php criar_admin.php
 * Banco MySQL:    defina DATABASE_URL antes (mysql://usuario:senha@endereco:3306/banco)
 *                 Windows PowerShell:  $env:DATABASE_URL="mysql://..." ; php criar_admin.php
 *
 * A senha é guardada só como hash. Se o usuário já existir, o script redefine a senha dele,
 * reativa a conta e derruba os logins ativos. O 1º administrador criado vira o SUPER administrador.
 */
if (PHP_SAPI !== 'cli') {          // nunca roda pelo navegador
    http_response_code(404);
    exit;
}

require __DIR__ . '/servidor/config.php';
require __DIR__ . '/servidor/http.php';
require __DIR__ . '/servidor/banco.php';
require __DIR__ . '/servidor/seguranca.php';

/** Lê uma linha digitada no terminal. */
function perguntar(string $texto): string
{
    echo $texto;
    return trim((string) fgets(STDIN));
}

/**
 * Lê a senha sem mostrar na tela (Linux/macOS). No Windows o PHP não consegue esconder,
 * então avisa antes.
 */
function perguntar_senha(string $texto): string
{
    if (DIRECTORY_SEPARATOR === '/' && function_exists('shell_exec') && @shell_exec('stty -echo 2>/dev/null') !== null) {
        echo $texto;
        $senha = rtrim((string) fgets(STDIN), "\r\n");
        @shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
        return $senha;
    }
    return rtrim((string) perguntar($texto . '(a senha vai aparecer na tela) '), "\r\n");
}

/** Pede a senha duas vezes e confere se são iguais. */
function pedir_senha(): string
{
    $senha = perguntar_senha('Senha (mínimo 12 caracteres): ');
    if ($senha !== perguntar_senha('Repita a senha: ')) {
        fwrite(STDERR, "As senhas não são iguais. Nada foi alterado.\n");
        exit(1);
    }
    return $senha;
}

try {
    iniciar_banco();
    $usuario = perguntar('Nome do usuário administrador: ');
    $b = banco();
    // Procura sem diferenciar maiúsculas/minúsculas (igual ao cadastro pelo painel)
    $existente = $b->um('SELECT id FROM usuarios WHERE LOWER(usuario) = ?', [mb_strtolower($usuario)]);
    if ($existente) {
        if (strtolower(perguntar("O usuário \"{$usuario}\" já existe. Redefinir a senha? (s/n): ")) !== 's') {
            exit(0);
        }
        $senha = pedir_senha();
        validar_senha_nova($senha, $usuario);
        $b->executar('UPDATE usuarios SET senha_hash = ?, ativo = 1 WHERE id = ?', [gerar_hash($senha), $existente['id']]);
        $b->executar('DELETE FROM sessoes WHERE usuario_id = ?', [$existente['id']]);
        registrar_auditoria('senha_redefinida_script', $usuario);
        echo "Senha redefinida e logins antigos encerrados.\n";
    } else {
        $senha = pedir_senha();
        criar_usuario($usuario, $senha);
        echo "Administrador \"{$usuario}\" criado.\n";
    }
    $b->commit();
} catch (ErroApi $erro) {
    banco()->rollback();
    fwrite(STDERR, 'Não foi possível: ' . $erro->mensagem . "\n");
    exit(1);
} catch (Throwable $erro) {
    fwrite(STDERR, 'Erro: ' . $erro->getMessage() . "\n");
    exit(1);
}
