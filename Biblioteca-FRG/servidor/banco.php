<?php
/**
 * BANCO DE DADOS
 * - Usa SOMENTE MySQL, com o endereço em DATABASE_URL (mysql://usuario:senha@endereco:3306/banco).
 * - Sem DATABASE_URL o sistema não liga (mostra uma mensagem explicando), para nunca gravar no lugar errado.
 *
 * O SQL é escrito com "?" no lugar dos valores, e os valores são sempre passados à parte
 * (nunca colados no texto do SQL): isso é o que impede "SQL injection".
 *
 * Tabelas:
 *   usuarios         -> administradores (senha guardada só como HASH, nunca a senha)
 *   super_admin      -> uma única linha: qual usuário é o SUPER administrador
 *   sessoes          -> logins ativos (guarda só o HASH do token, nunca o token)
 *   tentativas_login -> controle de bloqueio por tentativas erradas
 *   categorias       -> os filtros do menu
 *   livros           -> dados de cada livro
 *   arquivos         -> a capa de cada livro (e o PDF de livros antigos, de quando o PDF era enviado pelo painel)
 *   auditoria        -> registro de quem fez o quê
 *   configuracoes    -> ajustes feitos pelo painel (ex.: link da pasta da biblioteca no Google Drive)
 */

// {BIN} = tipo de arquivo grande: LONGBLOB no MySQL (o BLOB comum só guarda 64 KB!)
// {FIM} = no MySQL, usa o motor InnoDB e acentos completos (utf8mb4)
const ESQUEMA = <<<'SQL'
CREATE TABLE IF NOT EXISTS usuarios (
    id          VARCHAR(64) PRIMARY KEY,
    usuario     VARCHAR(100) NOT NULL UNIQUE,
    senha_hash  VARCHAR(255) NOT NULL,
    ativo       INTEGER NOT NULL DEFAULT 1,
    criado_em   BIGINT NOT NULL
){FIM};
CREATE TABLE IF NOT EXISTS super_admin (
    id          INTEGER PRIMARY KEY CHECK (id = 1),
    usuario_id  VARCHAR(64) NOT NULL,
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id)
){FIM};
CREATE TABLE IF NOT EXISTS sessoes (
    token_hash  VARCHAR(64) PRIMARY KEY,
    usuario_id  VARCHAR(64) NOT NULL,
    csrf        VARCHAR(64) NOT NULL,
    criado_em   BIGINT NOT NULL,
    expira_em   BIGINT NOT NULL,
    ultimo_uso  BIGINT NOT NULL,
    ip          VARCHAR(64),
    agente      VARCHAR(255),
    FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
){FIM};
CREATE TABLE IF NOT EXISTS tentativas_login (
    chave          VARCHAR(128) PRIMARY KEY,
    erros          INTEGER NOT NULL,
    inicio         BIGINT NOT NULL,
    bloqueado_ate  BIGINT NOT NULL DEFAULT 0
){FIM};
CREATE TABLE IF NOT EXISTS categorias (
    id      VARCHAR(64) PRIMARY KEY,
    nome    VARCHAR(80) NOT NULL UNIQUE,
    ordem   INTEGER NOT NULL
){FIM};
CREATE TABLE IF NOT EXISTS livros (
    id               VARCHAR(64) PRIMARY KEY,
    titulo           VARCHAR(255) NOT NULL,
    autor            VARCHAR(255) NOT NULL,
    categoria_id     VARCHAR(64) NOT NULL,
    descricao        TEXT NOT NULL,
    link             VARCHAR(2048) NOT NULL DEFAULT '',
    arquivo_externo  VARCHAR(2048) NOT NULL DEFAULT '',
    tem_pdf          INTEGER NOT NULL DEFAULT 0,
    criado_em        BIGINT NOT NULL,
    atualizado_em    BIGINT NOT NULL,
    FOREIGN KEY (categoria_id) REFERENCES categorias(id)
){FIM};
CREATE TABLE IF NOT EXISTS arquivos (
    livro_id      VARCHAR(64) PRIMARY KEY,
    pdf           {BIN},
    capa          {BIN},
    capa_versao   BIGINT NOT NULL DEFAULT 0,
    FOREIGN KEY (livro_id) REFERENCES livros(id) ON DELETE CASCADE
){FIM};
CREATE TABLE IF NOT EXISTS auditoria (
    id        VARCHAR(64) PRIMARY KEY,
    quando    BIGINT NOT NULL,
    usuario   VARCHAR(100),
    acao      VARCHAR(60) NOT NULL,
    detalhe   VARCHAR(500),
    ip        VARCHAR(64)
){FIM};
CREATE TABLE IF NOT EXISTS configuracoes (
    chave   VARCHAR(60) PRIMARY KEY,
    valor   VARCHAR(2048) NOT NULL
){FIM}
SQL;

// Muda quando o formato das tabelas mudar: faz o sistema rodar a criação/ajuste de novo uma vez.
const VERSAO_ESQUEMA = '1';

/** Devolve o SQL de criação das tabelas já ajustado ao banco usado. */
function esquema_sql(bool $mysql): string
{
    $fim = $mysql ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
    return str_replace(['{BIN}', '{FIM}'], [$mysql ? 'LONGBLOB' : 'BLOB', $fim], ESQUEMA);
}

/** Gera um identificador aleatório e imprevisível para uma linha (32 letras/números). */
function novo_id(): string
{
    return bin2hex(random_bytes(16));
}

/** Hora atual em segundos (padrão Unix). */
function agora(): int
{
    return time();
}

/**
 * Envolve a conexão com o MySQL e deixa as consultas mais simples de escrever.
 * Métodos: todos() / um() / executar() / commit() / rollback().
 * Cada pedido ao site roda numa "transação": no fim, tudo é confirmado de uma vez
 * (ou desfeito, se der erro no meio).
 */
final class Banco
{
    public PDO $pdo;
    public bool $mysql;

    public function __construct(PDO $pdo, bool $mysql)
    {
        $this->pdo = $pdo;
        $this->mysql = $mysql;
    }

    /** Começa a transação, se ainda não começou. */
    private function garantirTransacao(): void
    {
        if (!$this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
        }
    }

    /** Prepara e executa um comando com os valores passados à parte. */
    private function rodar(string $sql, array $params): PDOStatement
    {
        $this->garantirTransacao();
        $comando = $this->pdo->prepare($sql);
        foreach (array_values($params) as $i => $valor) {
            if ($valor === null) {
                $comando->bindValue($i + 1, null, PDO::PARAM_NULL);
            } elseif (is_int($valor) || is_bool($valor)) {
                $comando->bindValue($i + 1, (int) $valor, PDO::PARAM_INT);
            } elseif (is_string($valor) && !mb_check_encoding($valor, 'UTF-8')) {
                $comando->bindValue($i + 1, $valor, PDO::PARAM_LOB);     // imagem/PDF (bytes)
            } else {
                $comando->bindValue($i + 1, (string) $valor, PDO::PARAM_STR);
            }
        }
        $comando->execute();
        return $comando;
    }

    /** Executa uma consulta e devolve TODAS as linhas (cada linha é um array com o nome das colunas). */
    public function todos(string $sql, array $params = []): array
    {
        return $this->rodar($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Executa uma consulta e devolve a 1ª linha ou null. */
    public function um(string $sql, array $params = []): ?array
    {
        $linha = $this->rodar($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $linha === false ? null : $linha;
    }

    /** Executa INSERT/UPDATE/DELETE. Devolve quantas linhas foram afetadas. */
    public function executar(string $sql, array $params = []): int
    {
        return $this->rodar($sql, $params)->rowCount();
    }

    /** Confirma tudo o que foi gravado neste pedido. */
    public function commit(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->commit();
        }
    }

    /** Desfaz tudo o que foi gravado neste pedido e ainda não foi confirmado. */
    public function rollback(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }
}

/** Abre uma conexão nova com o MySQL (o sistema usa somente MySQL). */
function conectar(): Banco
{
    $url = URL_BANCO;
    if (str_starts_with($url, 'sqlite:') && !PRODUCAO) {
        // Só para testes rápidos no computador, sem MySQL (nunca vale em produção)
        $pdo = new PDO($url, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return new Banco($pdo, false);
    }
    if (!preg_match('#^mysql(\+pymysql)?://#', $url)) {
        throw new RuntimeException(
            'DATABASE_URL não definida. O sistema usa somente MySQL. '
            . 'Defina DATABASE_URL (mysql://usuario:senha@endereco:3306/banco) e tente de novo.');
    }
    $u = parse_url(preg_replace('#^mysql\+pymysql://#', 'mysql://', $url));
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $u['host'] ?? 'localhost', $u['port'] ?? 3306, ltrim($u['path'] ?? '', '/'));
    $pdo = new PDO($dsn, rawurldecode($u['user'] ?? ''), rawurldecode($u['pass'] ?? ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,       // valores enviados separados do SQL pelo próprio MySQL
        PDO::ATTR_STRINGIFY_FETCHES => false,      // números voltam como números
        PDO::ATTR_TIMEOUT => 10,
    ]);
    return new Banco($pdo, true);
}

/**
 * Devolve a conexão do pedido atual (abre na primeira vez).
 * No fim do pedido, o index.php confirma (commit) ou desfaz (rollback).
 */
function banco(): Banco
{
    static $conexao = null;
    if ($conexao === null) {
        $conexao = conectar();
    }
    return $conexao;
}

/**
 * Cria as tabelas (se não existirem) e os filtros iniciais.
 * Roda só quando o banco ainda não está pronto (1º acesso) ou quando VERSAO_ESQUEMA muda.
 */
function iniciar_banco(): void
{
    $b = banco();
    try {
        $versao = $b->um("SELECT valor FROM configuracoes WHERE chave = 'versao_esquema'");
        if ($versao && $versao['valor'] === VERSAO_ESQUEMA) {
            limpar_auditoria_antiga();
            return;
        }
    } catch (PDOException $e) {
        $b->rollback();            // a tabela ainda não existe: segue para criar tudo
    }

    if ($b->mysql) {
        // Trava para dois acessos simultâneos não criarem as tabelas ao mesmo tempo
        $b->um("SELECT GET_LOCK('biblioteca_frg_tabelas', 30) AS ok");
    }
    try {
        foreach (explode(';', esquema_sql($b->mysql)) as $comando) {
            if (trim($comando) !== '') {
                $b->pdo->exec($comando);       // CREATE TABLE confirma sozinho no MySQL
            }
        }
        if ((int) $b->um('SELECT COUNT(*) AS n FROM categorias')['n'] === 0) {
            foreach (CATEGORIAS_INICIAIS as $ordem => $nome) {
                $b->executar('INSERT INTO categorias (id, nome, ordem) VALUES (?, ?, ?)', [novo_id(), $nome, $ordem]);
            }
        }
        salvar_configuracao('versao_esquema', VERSAO_ESQUEMA);
        $b->commit();
    } finally {
        if ($b->mysql) {
            $b->um("SELECT RELEASE_LOCK('biblioteca_frg_tabelas') AS ok");
        }
    }
    limpar_auditoria_antiga(true);
}

/**
 * Retenção do histórico: apaga o que for mais antigo que AUDITORIA_DIAS.
 * Para não pesar, roda no máximo uma vez por dia (guarda o dia da última limpeza).
 */
function limpar_auditoria_antiga(bool $agora_mesmo = false): void
{
    $hoje = date('Y-m-d');
    if (!$agora_mesmo && ler_configuracao('limpeza_auditoria') === $hoje) {
        return;
    }
    banco()->executar('DELETE FROM auditoria WHERE quando < ?', [agora() - AUDITORIA_DIAS * 86400]);
    salvar_configuracao('limpeza_auditoria', $hoje);
    banco()->commit();
}

/** Usada pelo endereço /saude: confirma que o banco responde. Devolve true ou false. */
function testar_conexao(): bool
{
    try {
        banco()->um('SELECT 1 AS ok');
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

/** Lê um ajuste salvo pelo painel (tabela configuracoes). Devolve $padrao se não existir. */
function ler_configuracao(string $chave, string $padrao = ''): string
{
    $linha = banco()->um('SELECT valor FROM configuracoes WHERE chave = ?', [$chave]);
    return $linha ? (string) $linha['valor'] : $padrao;
}

/** Grava (ou troca) um ajuste do painel na tabela configuracoes. */
function salvar_configuracao(string $chave, string $valor): void
{
    $b = banco();
    if ($b->executar('UPDATE configuracoes SET valor = ? WHERE chave = ?', [$valor, $chave]) === 0) {
        if (!$b->um('SELECT chave FROM configuracoes WHERE chave = ?', [$chave])) {   // valor igual também dá 0
            $b->executar('INSERT INTO configuracoes (chave, valor) VALUES (?, ?)', [$chave, $valor]);
        }
    }
}

/** Grava no histórico quem fez o quê (login, livro criado, senha trocada...). */
function registrar_auditoria(string $acao, string $detalhe = '', ?string $usuario = null, ?string $ip = null): void
{
    banco()->executar(
        'INSERT INTO auditoria (id, quando, usuario, acao, detalhe, ip) VALUES (?, ?, ?, ?, ?, ?)',
        [novo_id(), agora(), $usuario, $acao, mb_substr($detalhe, 0, 500), $ip]);
}
