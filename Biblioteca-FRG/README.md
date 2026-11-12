<div align="center">

<!-- Logo da prefeitura: troca sozinha no tema escuro do GitHub -->
<picture>
  <source media="(prefers-color-scheme: dark)" srcset="img/logo-prefeitura-escuro.png">
  <img src="img/logo-prefeitura.png" alt="Logo da Prefeitura Municipal de Fazenda Rio Grande" width="320">
</picture>

# 📚 BIBLIOTECA SME-FRG

**Biblioteca digital gratuita da Secretaria Municipal de Educação de Fazenda Rio Grande**

![PHP](https://img.shields.io/badge/PHP-8.1+-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-8.0.16+-4479A1?logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-puro-F7DF1E?logo=javascript&logoColor=black)
![HTML5](https://img.shields.io/badge/HTML5-E34F26?logo=html5&logoColor=white)
![CSS3](https://img.shields.io/badge/CSS3-1572B6?logo=css3&logoColor=white)

*Um espaço de conhecimento, inovação e aprendizagem!*

</div>

---

## 📖 Sobre o projeto

A **Biblioteca SME-FRG** reúne obras, publicações e materiais de referência das divisões da Secretaria Municipal de Educação, com o objetivo de democratizar o acesso ao conhecimento e fortalecer a formação continuada dos profissionais da educação.

O sistema tem três partes:

| Página | Endereço | Quem acessa |
|---|---|---|
| Tela de entrada | `/` | Todos (escolhem Visitante ou Administrador) |
| Biblioteca pública | `/biblioteca` | Qualquer pessoa, sem login |
| Painel do administrador | `/admin` | Somente administradores com login |

## ✨ Funcionalidades

**Para visitantes**
- Busca por título, autor ou descrição.
- Filtros por categoria, com a quantidade de livros de cada uma.
- Leitura gratuita dos PDFs direto no navegador (o livro abre no leitor do Google Drive, numa aba nova).
- Modo claro e escuro (segue a preferência do aparelho na 1ª visita), layout adaptado para celular e botão "Mostrar mais" para acervos grandes.

**Para administradores**
- Cadastro, edição e exclusão de livros pelo **link do PDF no Google Drive**, com passo a passo na tela e botão que abre a pasta da biblioteca.
- **Conferir link**: o sistema testa o link na hora e mostra a capa antes de salvar (livro com link quebrado não entra no site).
- **Capa gerada automaticamente** a partir da 1ª página do PDF.
- **Verificar links**: confere se todos os PDFs ainda abrem (ex.: se alguém apagou um arquivo do Drive).
- Criação, renomeação e **exclusão de filtros** (o filtro só pode ser excluído se não tiver livros).
- Troca da própria senha.
- Botão **Mostrar / Ocultar** em todos os campos de senha.

**Para o super administrador** (um único usuário)
- Lista de todos os administradores.
- Criar, desativar, reativar e redefinir a senha de outros administradores, sem precisar da TI.
- **Histórico de atividades**: últimas 200 ações (logins, livros, filtros, administradores) com data, usuário e IP.
- **Configurações**: link da pasta da biblioteca no Google Drive.

## 🛠️ Tecnologias

| Camada | Tecnologia |
|---|---|
| Servidor | **PHP 8.1 ou superior** (sem frameworks, sem Composer) |
| Banco de dados | **MySQL** 8.0.16 ou superior (único banco aceito) |
| Páginas e visual | **HTML5**, **CSS3** |
| Interação no navegador | **JavaScript puro** (sem frameworks) |
| Onde ficam os PDFs | **Google Drive** da Secretaria (o site guarda só o link e a capa) |
| Capas dos PDFs | `pdftoppm` (Poppler) ou Ghostscript + extensão GD do PHP |
| Servidor web | Apache (`.htaccess`), IIS (`web.config`) ou Nginx |

O sistema não precisa instalar nenhuma biblioteca extra: usa só extensões que já vêm com o PHP. O site **não usa nenhum serviço externo** (sem Google Fonts): as fontes são as do próprio aparelho, o que também ajuda na LGPD.

## 🗂️ Estrutura das pastas

```text
Biblioteca-FRG/
├── index.php              # Porta de entrada do servidor: escolhe a rota, cabeçalhos de segurança
├── criar_admin.php        # Cria o 1º administrador ou redefine uma senha (terminal)
├── .htaccess              # Configuração para servidor Apache
├── web.config             # Configuração para servidor IIS (Windows)
├── entrada.html           # Tela de entrada (Visitante / Administrador)
├── index.html             # Biblioteca pública
├── admin.html             # Painel do administrador
├── 404.html               # Página "não encontrada"
├── css/
│   ├── style.css          # Cores, cabeçalho, cards e modo escuro
│   └── painel.css         # Tela de entrada e painel
├── js/
│   ├── comum.js           # Funções usadas em várias páginas (esc, api)
│   ├── senha.js           # Botão Mostrar/Ocultar em todos os campos de senha
│   ├── tema.js            # Botão de modo claro/escuro
│   ├── entrada.js         # Login na tela de entrada
│   ├── app.js             # Biblioteca pública (busca, filtros, cards)
│   ├── admin.js           # Painel: livros, filtros e troca de senha
│   └── admin-usuarios.js  # Painel: "Administradores" e "Histórico" (só super admin)
├── img/                   # Logos da prefeitura e favicon
└── servidor/              # Código do servidor (bloqueado para o navegador)
    ├── config.php         # Configurações (lidas de variáveis de ambiente)
    ├── http.php           # Leitura dos pedidos e montagem das respostas
    ├── banco.php          # Conexão com o banco e criação das tabelas
    ├── seguranca.php      # Senhas, sessões, CSRF, bloqueio de login, super admin
    ├── rotas_auth.php     # Login, logout, troca de senha e gestão de administradores
    ├── rotas_livros.php   # Livros, filtros, capas e configurações
    └── capas.php          # Geração automática das capas e download seguro dos PDFs
```

---

## 📁 Livros no Google Drive

Os PDFs **não ficam no servidor nem no banco**: ficam numa pasta do Google Drive da Secretaria. O site guarda só o **link** de cada livro e uma **capa pequena** (~30 KB). Assim o servidor da prefeitura fica leve, e quem entrega o PDF ao leitor é o Google.

**Preparação (uma vez só, feita pela Secretaria):**
1. Na conta Google **da Secretaria** (não de uma pessoa), crie a pasta **"Biblioteca SME-FRG"**.
2. Botão direito na pasta → **Compartilhar** → "Acesso geral": **Qualquer pessoa com o link** → **Leitor**. Todo arquivo colocado na pasta fica compartilhado sozinho.
3. Na mesma tela, adicione como **Editor** o e-mail de cada administrador que vai colocar livros.
4. Copie o link da pasta e cole no painel, em **Configurações** (só o super administrador vê essa seção).

**Para cadastrar um livro (qualquer administrador):**
1. No painel, clique em **Abrir pasta da biblioteca no Drive** e arraste o PDF para a pasta.
2. Botão direito no arquivo → **Compartilhar** → **Copiar link**.
3. Cole o link no painel: o sistema confere na hora e mostra a capa. Preencha título, autor e filtro e salve.

**Cuidados:**
- **Excluir o livro no painel não apaga o arquivo do Drive** (apague lá também, se quiser).
- Se alguém apagar o arquivo do Drive ou mudar o compartilhamento, o livro para de abrir. Use **Verificar links** de vez em quando.
- Livros de outros sites (ex.: um PDF público do MEC) também podem ser cadastrados colando o link direto do PDF.

---

## 🚀 Rodando no seu computador

> Exemplos para **Windows (PowerShell)**, no terminal do VS Code, dentro da pasta do projeto.

**1. Ter o PHP instalado**

Baixe o PHP 8.1 ou superior em [windows.php.net/download](https://windows.php.net/download) (versão *Thread Safe*, x64), descompacte em `C:\php` e coloque essa pasta no `PATH` do Windows. Confira com `php -v`.

No arquivo `C:\php\php.ini` (copie o `php.ini-development` com esse nome), tire o `;` da frente destas linhas:

```ini
extension=curl
extension=gd
extension=mbstring
extension=openssl
extension=pdo_mysql
```

**2. Ter um programa para criar as capas** (opcional, mas recomendado)

Baixe o **Poppler for Windows** ([github.com/oschwartz10612/poppler-windows/releases](https://github.com/oschwartz10612/poppler-windows/releases)), descompacte e coloque a pasta `Library\bin` no `PATH`. Sem ele, os livros são salvos normalmente, só que sem capa.

**3. Rodar o sistema**

O sistema usa **somente MySQL**. Antes de rodar, siga a seção **Testando com MySQL no seu computador** (logo abaixo) para criar o banco e definir a variável `DATABASE_URL`.

```powershell
php -S 127.0.0.1:5000 index.php
```

Abra **http://127.0.0.1:5000** no navegador. As tabelas são criadas sozinhas no primeiro acesso.

**4. Criar o primeiro administrador** (ele será o **super administrador**)

```powershell
php criar_admin.php
```

O script pede o usuário e a senha (mínimo de 12 caracteres).

### Testando com MySQL no seu computador

**1.** Entre no MySQL (ajuste o caminho da sua versão) e crie o banco e o usuário:

```powershell
& "C:\Program Files\MySQL\MySQL Server 8.0\bin\mysql.exe" -u root -p
```

```sql
CREATE DATABASE biblioteca_frg CHARACTER SET utf8mb4;
CREATE USER 'biblioteca'@'localhost' IDENTIFIED BY 'TROQUE-POR-UMA-SENHA-FORTE';
GRANT ALL PRIVILEGES ON biblioteca_frg.* TO 'biblioteca'@'localhost';
EXIT;
```

**2.** No terminal do projeto, aponte o sistema para o MySQL e rode:

```powershell
$env:DATABASE_URL="mysql://biblioteca:SUA-SENHA@localhost:3306/biblioteca_frg"
php -S 127.0.0.1:5000 index.php
```

> ⚠️ A variável `$env:DATABASE_URL` vale **só para o terminal em que foi digitada**. Em um terminal novo, defina de novo. Para conferir: `echo $env:DATABASE_URL`.

Não precisa reiniciar o servidor depois de alterar um arquivo `.php`: o PHP lê os arquivos de novo a cada pedido.

---

## 👑 Administradores e super administrador

Existe **um único super administrador**. Ele é o **primeiro administrador ativo criado no banco** e fica gravado na tabela `super_admin`, que só aceita uma linha, então nunca há dois.

| | Administrador comum | Super administrador |
|---|:---:|:---:|
| Cadastrar, editar e excluir livros | ✅ | ✅ |
| Editar filtros | ✅ | ✅ |
| Trocar a própria senha | ✅ | ✅ |
| Ver a lista de administradores | ❌ | ✅ |
| Criar, desativar e reativar administradores | ❌ | ✅ |
| Redefinir a senha de outro administrador | ❌ | ✅ |
| Ver o histórico de atividades | ❌ | ✅ |

**Regras de segurança da gestão de usuários**
- A seção "Administradores" só aparece para o super administrador, e o servidor também recusa os pedidos de outros usuários.
- Toda ação nessa seção exige a **senha de quem está logado**.
- Ninguém pode desativar a própria conta.
- Limite de **20** administradores.
- Ao desativar ou redefinir a senha de alguém, os logins dessa pessoa são encerrados na hora.
- Cada ação fica registrada na tabela `auditoria` (quem fez, em quem e quando).

### Como criar o super administrador (feito pela TI, uma única vez)

**Opção 1: variáveis de ambiente**, antes de ligar o sistema pela primeira vez:

| Variável | Valor |
|---|---|
| `ADMIN_USER` | nome do usuário da Secretaria |
| `ADMIN_SENHA_INICIAL` | senha temporária (mínimo de 12 caracteres) |

O usuário é criado sozinho no primeiro acesso ao site, se o banco ainda não tiver nenhum usuário.

**Opção 2: pelo terminal**, na pasta do sistema:

```bash
php criar_admin.php
```

Depois do primeiro login, a pessoa troca a senha em **"Trocar minha senha"**, e a TI **apaga a variável `ADMIN_SENHA_INICIAL`** do servidor. Daí em diante, a Secretaria cria os outros administradores pelo painel.

### Recuperação e manutenção

- **Super administrador esqueceu a senha:** rodar `php criar_admin.php` com o nome dele e escolher redefinir. O script também reativa a conta, se estiver desativada.
- **Passar o papel de super administrador para outro usuário** (MySQL):

  ```sql
  UPDATE super_admin
     SET usuario_id = (SELECT id FROM usuarios WHERE usuario = 'novo.usuario')
   WHERE id = 1;
  ```

- **Recomendação:** mantenha pelo menos **mais um administrador ativo**, para sempre haver alguém com acesso ao painel.

---

## 🌐 Publicação no servidor (para a TI)

<details>
<summary><b>Clique para ver o passo a passo completo</b></summary>

### 1. Requisitos do servidor

- **PHP 8.1 ou superior** com as extensões `pdo_mysql`, `curl`, `mbstring`, `openssl` e `gd` (todas vêm com o PHP; só precisam estar ativadas no `php.ini`).
- A função `proc_open` do PHP **não pode estar desativada** (`disable_functions`): ela é usada para criar as capas.
- Um destes programas, para criar as capas a partir do PDF: **`pdftoppm`** (pacote `poppler-utils` no Linux; *Poppler for Windows*) ou **Ghostscript** (`gs` no Linux; `gswin64c.exe` no Windows). Sem nenhum deles, os livros são salvos sem capa.
- O site precisa ficar na **raiz** de um endereço (ex.: `https://biblioteca.fazendariogrande.pr.gov.br/`), não numa subpasta.
- O servidor precisa conseguir **acessar a internet (Google Drive)**: ele baixa cada PDF uma única vez, para criar a capa, e descarta.

### 2. Banco de dados MySQL

- Versão **8.0.16 ou superior**.
- Crie um banco vazio (`utf8mb4`) e um usuário com permissão nesse banco. As **tabelas são criadas automaticamente** no primeiro acesso (`CREATE TABLE IF NOT EXISTS`).
- Os PDFs ficam no **Google Drive** da Secretaria; o banco guarda só os links e as capas (cerca de 30 KB por livro), então ele fica **pequeno**.
- Use uma **senha forte e exclusiva** para o usuário do banco.
- O **backup do banco** guarda todos os dados do site. Os PDFs ficam no Drive da Secretaria.

### 3. Configurações (variáveis de ambiente)

| Variável | Para que serve | Obrigatória |
|---|---|:---:|
| `DATABASE_URL` | `mysql://USUARIO:SENHA@ENDERECO:3306/NOME_DO_BANCO` | ✅ |
| `AMBIENTE` | Use `producao`: liga o cookie seguro (**exige HTTPS**) | ✅ |
| `ADMIN_USER` | Nome do super administrador (só no 1º acesso) | 1º acesso |
| `ADMIN_SENHA_INICIAL` | Senha temporária dele (**apagar depois do 1º login**) | 1º acesso |
| `QTD_PROXIES` | `1` se houver outro servidor (proxy) na frente do site; `0` se o acesso for direto | ➖ |
| `MAX_PDF_MB` | Tamanho máximo do PDF que o servidor baixa do Drive para criar a capa, em MB (padrão `30`) | ➖ |
| `PDFTOPPM` / `GHOSTSCRIPT` | Caminho completo do programa das capas, se o sistema não o achar sozinho (ex.: `C:\poppler\Library\bin\pdftoppm.exe`) | ➖ |
| `DOMINIO_PUBLICO` | Endereço do site (ex.: `biblioteca.fazendariogrande.pr.gov.br`). Só é preciso se o proxy alterar o cabeçalho `Host` e o login der "Pedido não permitido" | ➖ |
| `ARQUIVO_LOG` | Caminho de um arquivo de log (ex.: `/var/log/biblioteca/app.log`); gira sozinho, no máximo 5 × 2 MB. Sem ele, os erros vão para o log do PHP | ➖ |
| `AUDITORIA_DIAS` | Dias que o histórico de atividades é mantido (padrão `365`) | ➖ |

**Onde definir:**
- **Apache:** no VirtualHost do site, com `SetEnv DATABASE_URL "mysql://..."`.
- **IIS:** em *Configurações do FastCGI* → variáveis de ambiente do PHP.
- **Nginx + PHP-FPM:** `fastcgi_param DATABASE_URL "mysql://...";` ou `env[DATABASE_URL]` no pool do PHP-FPM.
- **Se não der para usar variáveis de ambiente:** crie o arquivo `servidor/config.local.php` (ele nunca vai para o GitLab e o navegador não consegue abri-lo):

  ```php
  <?php
  return [
      'DATABASE_URL' => 'mysql://USUARIO:SENHA@localhost:3306/biblioteca_frg',
      'AMBIENTE' => 'producao',
  ];
  ```

> Se a senha do banco tiver símbolos, codifique-os na `DATABASE_URL`: `@` = `%40`, `/` = `%2F`, `:` = `%3A`.
> **Nunca use `QTD_PROXIES=1` sem ter um proxy na frente:** o visitante poderia forjar o próprio IP.

### 4. Servidor web

Todo pedido (menos os arquivos de `css/`, `js/` e `img/`) precisa passar pelo `index.php`. Isso também impede que alguém abra direto o `admin.html` ou a pasta `servidor/`.

- **Apache:** o `.htaccess` já faz isso. Precisa do `mod_rewrite` ativo e de `AllowOverride All` na pasta do site.
- **IIS (Windows):** o `web.config` já faz isso. Precisa do módulo **URL Rewrite**. O PHP precisa aceitar os métodos **GET, POST, PUT e DELETE** (em *Mapeamentos de Manipulador* → PHP → *Restrições de solicitação* → *Verbos* → "Todos os verbos"); se o **WebDAV** estiver instalado, remova-o deste site, pois ele bloqueia PUT e DELETE.
- **Nginx:**

  ```nginx
  location ~ ^/(css|js|img)/ { try_files $uri =404; expires 1d; }
  location / {
      include fastcgi_params;
      fastcgi_param SCRIPT_FILENAME $document_root/index.php;
      fastcgi_pass unix:/run/php/php8.3-fpm.sock;   # ajuste para a versão instalada
      client_max_body_size 1m;
  }
  ```

Coloque o site com **HTTPS** e domínio definidos pela prefeitura.

### 5. Primeiro acesso

Crie o super administrador (veja a seção **Administradores e super administrador**), faça o login, troque a senha e remova `ADMIN_SENHA_INICIAL`.

### 6. Checklist final

- [ ] HTTPS funcionando e `AMBIENTE=producao` definido
- [ ] `DATABASE_URL` aponta para o MySQL da prefeitura
- [ ] `https://SEU-ENDERECO/saude` responde `{"ok": true}` (use no monitoramento)
- [ ] Se há proxy (Nginx/IIS): `QTD_PROXIES=1` e o proxy repassa `Host`, `X-Forwarded-For` e `X-Forwarded-Proto`
- [ ] Servidor com acesso à internet (Google Drive), para criar as capas
- [ ] `pdftoppm` ou Ghostscript instalado (cadastre um livro de teste e veja se a capa aparece)
- [ ] Abrir `https://SEU-ENDERECO/admin.html` e `https://SEU-ENDERECO/servidor/config.php` dá **página não encontrada**
- [ ] Super administrador criado e `ADMIN_SENHA_INICIAL` removida
- [ ] Rotina de backup do banco ativa
- [ ] Pasta da biblioteca no Drive informada em **Configurações** (pelo super administrador)
- [ ] Teste: cadastrar um livro pelo link do Drive e conferir a capa na biblioteca pública

> Envie à TI **apenas o código** (pelo GitLab). Não envie o `servidor/config.local.php` nem arquivos de log: os livros ficam no Google Drive e os dados no banco MySQL.

</details>

## 🔒 Segurança

- Senhas guardadas apenas como **hash Argon2id** (ou bcrypt, se o PHP não tiver Argon2) com sal, nunca em texto.
- Sessão por cookie `HttpOnly`, `SameSite=Strict` e `Secure` (em produção). No banco fica só o hash do token. A sessão expira em 8 horas, ou após 2 horas sem uso.
- Proteção contra **CSRF** (código por sessão e conferência da origem do pedido).
- **Bloqueio de tentativas de login:** 5 erros do mesmo usuário no mesmo IP, 50 do mesmo usuário em qualquer IP, ou 15 do mesmo IP bloqueiam o login por 15 minutos.
- Todo acesso ao banco usa **parâmetros** (proteção contra SQL injection).
- Os arquivos do servidor (`servidor/`, `criar_admin.php`, páginas `.html`) **não podem ser abertos direto pelo navegador**: tudo passa pelo `index.php`.
- Cabeçalhos de segurança e **Content-Security-Policy** em todas as respostas.
- Proteção do painel **no servidor**: sem login válido, a página `/admin` nem é enviada.
- Histórico de ações dos administradores na tabela `auditoria` (visível ao super administrador; apagado após `AUDITORIA_DIAS`).
- Nomes de usuário **sem diferenciar maiúsculas** (não existem "Maria" e "maria").
- Limite de 64 KB em todos os pedidos (ninguém consegue enviar arquivos ao servidor), e download de PDFs por link com verificação de segurança **no momento da conexão** (bloqueia endereços internos, mesmo com redirecionamentos) e tempo máximo de 60 s.

---

<div align="center">

<img src="img/logo-prefeitura.png" alt="Logo da Prefeitura Municipal de Fazenda Rio Grande" width="160">

Desenvolvido para a **Secretaria Municipal de Educação**
**Prefeitura Municipal de Fazenda Rio Grande**

</div>