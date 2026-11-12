/* ============================================================
   ABA "ADMINISTRADORES" DO PAINEL
   Lista, cria, desativa/reativa administradores e redefine a senha de outro.
   Arquivo independente: precisa ser carregado DEPOIS do comum.js e do admin.js, pois usa
   esc() e api() (comum.js) e mostrarAviso() (admin.js).
   Também cuida do "Histórico de atividades" (últimas ações registradas) e das
   "Configurações" (pasta da biblioteca no Drive), só para o super admin.
   Toda ação pede a senha de quem está logado (campo "Sua senha") para confirmar.
   A seção só aparece para o SUPER administrador (os demais não a veem, e o servidor
   também recusa as ações deles).
   ============================================================ */
(function () {
  let administradores = [];   // [{id, usuario, ativo, criado_em, voce, super}]
  let maximo = 20;            // limite de contas (vem do servidor)

  const caixaLista = document.getElementById("listaAdmins");
  const campoConfirma = document.getElementById("senhaConfirma");
  const formNovo = document.getElementById("formNovoAdmin");

  /**
   * Faz um pedido à API de administradores (JSON) usando o api() do comum.js
   * (ele envia o código CSRF e volta para a entrada se a sessão expirou).
   */
  function chamar(url, metodo, corpo) {
    return api(url, {
      method: metodo,
      headers: corpo ? { "Content-Type": "application/json" } : {},
      body: corpo ? JSON.stringify(corpo) : undefined
    });
  }

  /**
   * Lê a senha de confirmação. Se estiver vazia, avisa e leva o cursor até o campo.
   * Devolve o texto, ou null se faltou.
   */
  function pegarConfirmacao() {
    const senha = campoConfirma.value;
    if (!senha) {
      mostrarAviso('Digite a sua senha no campo "Sua senha" para confirmar.', "alerta");
      campoConfirma.scrollIntoView({ behavior: "smooth", block: "center" });
      campoConfirma.focus();
      return null;
    }
    return senha;
  }

  /**
   * Formata a data de criação (segundos desde 1970) como dd/mm/aaaa.
   */
  function formatarData(segundos) {
    return new Date(segundos * 1000).toLocaleDateString("pt-BR");
  }

  /**
   * Desenha a lista de administradores com os botões de cada um.
   * Na linha da própria pessoa não há botões (ela usa "Trocar minha senha").
   */
  function desenharLista() {
    document.getElementById("totalAdmins").textContent = `${administradores.length}/${maximo}`;
    caixaLista.innerHTML = administradores.map(a => `
      <article class="linha-admin${a.ativo ? "" : " desativado"}" data-id="${esc(a.id)}">
        <div class="linha-info">
          <strong>${esc(a.usuario)}
            ${a.voce ? '<span class="etiqueta-voce">Você</span>' : ""}
            ${a.super ? '<span class="etiqueta-voce etiqueta-super">Super admin</span>' : ""}</strong>
          <span>Criado em ${esc(formatarData(a.criado_em))} ·
            <em class="${a.ativo ? "situacao-ativa" : "situacao-inativa"}">${a.ativo ? "Ativo" : "Desativado"}</em></span>
        </div>
        ${a.voce ? "" : `
        <div class="linha-acoes">
          <button class="btn-mini" data-acao="senha" type="button">Redefinir senha</button>
          <button class="btn-mini ${a.ativo ? "btn-perigo" : ""}" data-acao="ativo" type="button">${a.ativo ? "Desativar" : "Reativar"}</button>
        </div>
        <form class="form-redefinir" data-acao="salvar-senha" hidden>
          <input type="password" autocomplete="new-password" minlength="12" placeholder="Nova senha (mínimo de 12 caracteres)" required>
          <button class="btn-mini" type="submit">Salvar nova senha</button>
          <button class="btn-mini" data-acao="cancelar-senha" type="button">Cancelar</button>
        </form>`}
      </article>
    `).join("");
  }

  /**
   * Busca a lista no servidor e redesenha.
   */
  async function carregarAdministradores() {
    const dados = await chamar("/api/admin/usuarios", "GET");
    administradores = dados.usuarios;
    maximo = dados.maximo;
    desenharLista();
  }

  /**
   * Cria um administrador novo com o usuário e a senha inicial digitados.
   */
  async function criarAdministrador(evento) {
    evento.preventDefault();
    const confirmacao = pegarConfirmacao();
    if (confirmacao === null) return;
    const campoUsuario = document.getElementById("novoUsuario");
    const campoSenha = document.getElementById("novaSenhaAdmin");
    const nome = campoUsuario.value.trim();
    try {
      await chamar("/api/admin/usuarios", "POST", {
        usuario: nome, senha: campoSenha.value, senha_confirmacao: confirmacao
      });
      formNovo.reset();
      campoConfirma.value = "";
      await carregarAdministradores();
      mostrarAviso(`Administrador "${nome}" criado. Combine a senha inicial com a pessoa.`);
    } catch (erro) {
      mostrarAviso(erro.message, "erro");
    }
  }

  /**
   * Desativa ou reativa um administrador (desativar encerra os acessos dele na hora).
   */
  async function alternarSituacao(id) {
    const alvo = administradores.find(a => a.id === id);
    if (!alvo) return;
    const confirmacao = pegarConfirmacao();
    if (confirmacao === null) return;
    const novoEstado = !alvo.ativo;
    if (!novoEstado && !confirm(`Desativar "${alvo.usuario}"? Esta conta será desconectada agora e não poderá mais entrar.`)) return;
    try {
      await chamar(`/api/admin/usuarios/${encodeURIComponent(id)}/ativo`, "POST",
        { ativo: novoEstado, senha_confirmacao: confirmacao });
      campoConfirma.value = "";
      await carregarAdministradores();
      mostrarAviso(novoEstado ? `"${alvo.usuario}" reativado.` : `"${alvo.usuario}" desativado.`);
    } catch (erro) {
      mostrarAviso(erro.message, "erro");
    }
  }

  /**
   * Define uma senha nova para outro administrador (quem esqueceu a senha, por exemplo).
   */
  async function redefinirSenha(id, formulario) {
    const alvo = administradores.find(a => a.id === id);
    if (!alvo) return;
    const confirmacao = pegarConfirmacao();
    if (confirmacao === null) return;
    try {
      await chamar(`/api/admin/usuarios/${encodeURIComponent(id)}/senha`, "POST",
        { nova: formulario.querySelector("input").value, senha_confirmacao: confirmacao });
      campoConfirma.value = "";
      await carregarAdministradores();
      mostrarAviso(`Senha de "${alvo.usuario}" redefinida. Combine a nova senha com a pessoa.`);
    } catch (erro) {
      mostrarAviso(erro.message, "erro");
    }
  }

  /**
   * Seção "Configurações": mostra e salva o link da pasta da biblioteca no Google Drive.
   * Ao salvar, o botão "Abrir pasta da biblioteca" do formulário já passa a usar o novo link.
   */
  async function iniciarConfiguracoes() {
    const formConfig = document.getElementById("formConfig");
    const campoPasta = document.getElementById("pastaDrive");
    if (!formConfig) return;
    document.getElementById("blocoConfig").hidden = false;
    const dados = await chamar("/api/admin/configuracoes", "GET");
    campoPasta.value = dados.pasta_drive || "";

    formConfig.addEventListener("submit", async (evento) => {
      evento.preventDefault();
      try {
        const resposta = await chamar("/api/admin/configuracoes", "PUT", { pasta_drive: campoPasta.value.trim() });
        campoPasta.value = resposta.pasta_drive;
        if (typeof mostrarPasta === "function") mostrarPasta(resposta.pasta_drive);
        mostrarAviso(resposta.pasta_drive ? "Pasta da biblioteca salva!" : "Link da pasta removido.");
      } catch (erro) {
        mostrarAviso(erro.message, "erro");
      }
    });
  }

  /* Nomes das ações do histórico em português claro */
  const NOMES_ACOES = {
    login_ok: "Entrou no painel",
    login_falhou: "Tentativa de login falhou",
    senha_trocada: "Trocou a própria senha",
    senha_redefinida: "Senha redefinida por um administrador",
    senha_redefinida_script: "Senha redefinida pelo servidor",
    usuario_criado: "Administrador criado",
    usuario_desativado: "Administrador desativado",
    usuario_reativado: "Administrador reativado",
    livro_criado: "Livro cadastrado",
    livro_editado: "Livro editado",
    livro_excluido: "Livro excluído",
    filtros_salvos: "Filtros alterados",
    configuracao_salva: "Configuração alterada"
  };

  /**
   * Busca as últimas ações no servidor e desenha a tabela do histórico.
   */
  async function carregarHistorico() {
    const dados = await chamar("/api/admin/auditoria", "GET");
    const corpo = document.getElementById("corpoHistorico");
    if (dados.registros.length === 0) {
      corpo.innerHTML = '<tr><td colspan="5">Nenhuma atividade registrada ainda.</td></tr>';
      return;
    }
    corpo.innerHTML = dados.registros.map(r => `
      <tr>
        <td>${esc(new Date(r.quando * 1000).toLocaleString("pt-BR"))}</td>
        <td>${esc(r.usuario || "—")}</td>
        <td>${esc(NOMES_ACOES[r.acao] || r.acao)}</td>
        <td>${esc(r.detalhe)}</td>
        <td>${esc(r.ip)}</td>
      </tr>`).join("");
  }

  /**
   * Função inicial: pega o código da sessão, mostra a seção (só para o super administrador),
   * liga os botões e carrega a lista.
   */
  async function iniciarAdministradores() {
    if (!caixaLista) return;          // a página não tem a seção
    try {
      const sessao = await (await fetch("/api/sessao", { credentials: "same-origin" })).json();
      if (!sessao.admin) return;      // o admin.js já cuida de voltar para a entrada
      if (!sessao.super) return;      // administrador comum: a seção continua escondida
      document.getElementById("blocoAdmins").hidden = false;
      csrf = sessao.csrf;               // variável do comum.js, usada pelo api()

      formNovo.addEventListener("submit", criarAdministrador);

      // Cliques nos botões da lista
      caixaLista.addEventListener("click", (e) => {
        const botao = e.target.closest("button[data-acao]");
        if (!botao) return;
        const linha = botao.closest(".linha-admin");
        const id = linha.dataset.id;
        const formulario = linha.querySelector(".form-redefinir");
        if (botao.dataset.acao === "senha") {
          formulario.hidden = false;
          formulario.querySelector("input").focus();
        }
        if (botao.dataset.acao === "cancelar-senha") {
          formulario.reset();
          formulario.hidden = true;
        }
        if (botao.dataset.acao === "ativo") alternarSituacao(id);
      });

      // Envio do mini-formulário "Redefinir senha" de uma linha
      caixaLista.addEventListener("submit", (e) => {
        e.preventDefault();
        redefinirSenha(e.target.closest(".linha-admin").dataset.id, e.target);
      });

      await carregarAdministradores();

      // Configurações (pasta da biblioteca no Drive) — também só do super administrador
      await iniciarConfiguracoes();

      // Histórico de atividades (também só do super administrador)
      document.getElementById("blocoHistorico").hidden = false;
      document.getElementById("btnAtualizarHistorico").addEventListener("click", () => {
        carregarHistorico().catch(erro => mostrarAviso(erro.message, "erro"));
      });
      await carregarHistorico();
    } catch (erro) {
      if (typeof mostrarAviso === "function") mostrarAviso(erro.message, "erro");
    }
  }

  iniciarAdministradores();
})();