/* ============ ESTADO ============ */
let categorias = [];       // filtros: [{id, nome, total}]
let livros = [];           // livros cadastrados
let editandoId = null;     // id do livro em edição (null = cadastrando novo)
let maxFiltros = 12;       // limite de filtros (o servidor informa; vem do config.py)
let linkConferido = "";    // último link do PDF que passou no "Conferir link" (já padronizado pelo servidor)
let linkOriginal = "";     // na edição: o link que o livro já tinha (se não mudar, não precisa conferir)
let quebrados = new Set(); // ids dos livros cujo link não abriu no "Verificar links"
// (esc(), api() e csrf vêm do comum.js, que é carregado antes deste arquivo)

/* ============ ELEMENTOS ============ */
const formLivro = document.getElementById("formLivro");
const listaLivros = document.getElementById("listaLivros");
const caixaAviso = document.getElementById("aviso");
const camposFiltros = document.getElementById("camposFiltros");
const campoLinkPdf = document.getElementById("arquivo_url");
const botaoSalvar = document.getElementById("btnSalvar");
const caixaResultado = document.getElementById("resultadoLink");

/**
 * Mostra uma mensagem no topo do painel.
 * tipo: "ok" (verde), "erro" (vermelho) ou "alerta" (amarelo).
 */
function mostrarAviso(texto, tipo = "ok") {
  caixaAviso.textContent = texto;
  caixaAviso.className = "aviso aviso-" + tipo;
  caixaAviso.hidden = false;
  window.scrollTo({ top: 0, behavior: "smooth" });
  clearTimeout(mostrarAviso.relogio);
  if (tipo === "ok") mostrarAviso.relogio = setTimeout(() => (caixaAviso.hidden = true), 6000);
}

/**
 * Descobre o código CSRF da sessão. Se não há sessão válida, volta para a entrada.
 */
async function iniciarSessao() {
  const dados = await api("/api/sessao");
  if (!dados.admin) {
    window.location.href = "/";
    throw new Error("Sem sessão.");
  }
  csrf = dados.csrf;
}

/**
 * Busca livros e filtros no servidor e redesenha o painel.
 */
async function carregar() {
  const [filtros, acervo, ajustes] = await Promise.all(
    [api("/api/admin/categorias"), api("/api/livros"), api("/api/admin/configuracoes")]);
  mostrarPasta(ajustes.pasta_drive);
  categorias = filtros.categorias;
  maxFiltros = filtros.max_filtros || maxFiltros;
  livros = acervo.livros;
  desenharSeletorCategoria();
  desenharLista();
  desenharCamposFiltros();
}

/**
 * Mostra o botão "Abrir pasta da biblioteca" com o link da pasta do Drive.
 * Se a pasta ainda não foi informada, mostra o aviso pedindo ao super administrador.
 * (Também é chamada pelo admin-usuarios.js quando o super administrador salva a pasta.)
 */
function mostrarPasta(url) {
  const botao = document.getElementById("btnAbrirPasta");
  botao.hidden = !url;
  if (url) botao.href = url;
  document.getElementById("semPasta").hidden = Boolean(url);
}

/**
 * Mostra o resultado do "Conferir link" embaixo do campo:
 * tipo "ok" (verde, com a prévia da capa), "erro" (vermelho) ou "espera" (conferindo...).
 */
function mostrarResultadoLink(tipo, texto, capa) {
  const previa = document.getElementById("previaCapa");
  caixaResultado.hidden = false;
  caixaResultado.className = "resultado-link resultado-" + tipo;
  document.getElementById("textoLink").textContent = texto;
  previa.hidden = !capa;
  if (capa) previa.src = capa;
}

/**
 * Libera o botão Salvar só quando o link do PDF está garantido:
 * passou no "Conferir link", ou (na edição) continua igual ao que o livro já tinha.
 */
function atualizarBotaoSalvar() {
  const atual = campoLinkPdf.value.trim();
  const valido = (atual !== "" && atual === linkConferido) || (editandoId !== null && atual === linkOriginal);
  botaoSalvar.disabled = !valido;
  botaoSalvar.title = valido ? "" : 'Primeiro cole o link do PDF e clique em "Conferir link".';
}

/**
 * Chamada sempre que o link é digitado/alterado: se mudou, precisa conferir de novo.
 */
function linkAlterado() {
  if (campoLinkPdf.value.trim() !== linkConferido) caixaResultado.hidden = true;
  atualizarBotaoSalvar();
}

/**
 * Botão "Conferir link": pede ao servidor para abrir o PDF do Drive e criar a prévia da capa.
 * Se der certo, mostra a capa e libera o botão Salvar; se não, explica o que fazer.
 */
async function conferirLink() {
  const link = campoLinkPdf.value.trim();
  if (!link) {
    mostrarResultadoLink("erro", "Cole o link do PDF primeiro.");
    campoLinkPdf.focus();
    return;
  }
  const botao = document.getElementById("btnConferir");
  botao.disabled = true;
  botao.textContent = "Conferindo...";
  mostrarResultadoLink("espera", "Abrindo o PDF no Drive e criando a capa. Livros grandes podem levar alguns segundos...");
  try {
    const resposta = await api("/api/admin/livros/conferir-link", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ link })
    });
    campoLinkPdf.value = resposta.link;          // link padronizado pelo servidor
    linkConferido = resposta.link;
    mostrarResultadoLink("ok", resposta.aviso ? "✅ PDF encontrado. " + resposta.aviso : "✅ Livro encontrado! Confira a capa e preencha os dados abaixo.", resposta.capa);
  } catch (erro) {
    linkConferido = "";
    mostrarResultadoLink("erro", "❌ " + erro.message);
  } finally {
    botao.disabled = false;
    botao.textContent = "Conferir link";
    atualizarBotaoSalvar();
  }
}

/**
 * Preenche a lista de filtros (campo "Filtro" do formulário do livro).
 * O valor de cada opção é o id do filtro.
 */
function desenharSeletorCategoria(selecionadaId) {
  const select = document.getElementById("categoria");
  const atual = selecionadaId || select.value;
  select.innerHTML = categorias.map(c => `<option value="${esc(c.id)}">${esc(c.nome)}</option>`).join("");
  if (categorias.some(c => c.id === atual)) select.value = atual;
}

/**
 * Desenha a lista de livros cadastrados, com os botões Editar / Refazer capa / Excluir.
 */
function desenharLista() {
  document.getElementById("totalLivros").textContent = livros.length;

  if (livros.length === 0) {
    listaLivros.innerHTML = `<p class="vazio-lista">Nenhum livro cadastrado ainda. Use o formulário acima.</p>`;
    return;
  }

  listaLivros.innerHTML = livros.map(l => `
    <article class="linha-livro" data-id="${esc(l.id)}">
      <div class="mini-capa">
        ${l.capa ? `<img src="${esc(l.capa)}" alt="">` : `<span>📘</span>`}
      </div>
      <div class="linha-info">
        <strong>${esc(l.titulo)}</strong>
        <span>${esc(l.autor)} · <em>${esc(l.categoria)}</em></span>
        ${l.capa ? "" : `<span class="sem-capa">Sem capa — use "Refazer capa"</span>`}
        ${quebrados.has(l.id) ? `<span class="link-quebrado">⚠ O PDF não abre. Confira se o arquivo ainda está na pasta do Drive e use "Editar" para colar o link certo.</span>` : ""}
      </div>
      <div class="linha-acoes">
        <button class="btn-mini" data-acao="editar" type="button">Editar</button>
        <button class="btn-mini" data-acao="capa" type="button">Refazer capa</button>
        <button class="btn-mini btn-perigo" data-acao="excluir" type="button">Excluir</button>
      </div>
    </article>
  `).join("");
}

/**
 * Coloca os dados de um livro no formulário para editar.
 */
function iniciarEdicao(id) {
  const livro = livros.find(l => l.id === id);
  if (!livro) return;
  editandoId = id;

  formLivro.titulo.value = livro.titulo;
  formLivro.autor.value = livro.autor;
  formLivro.descricao.value = livro.descricao || "";
  formLivro.link.value = livro.link || "";
  // Livros antigos (PDF guardado no banco) não têm link: o campo fica vazio e o PDF antigo é mantido
  linkOriginal = /^https?:\/\//i.test(livro.arquivo || "") ? livro.arquivo : "";
  campoLinkPdf.value = linkOriginal;
  linkConferido = "";
  caixaResultado.hidden = true;
  desenharSeletorCategoria(livro.categoria_id);

  document.getElementById("tituloForm").textContent = "Editar livro";
  document.getElementById("btnSalvar").textContent = "Salvar alterações";
  document.getElementById("btnCancelar").hidden = false;
  atualizarBotaoSalvar();
  formLivro.scrollIntoView({ behavior: "smooth", block: "start" });
}

/**
 * Sai do modo de edição e limpa o formulário.
 */
function cancelarEdicao() {
  editandoId = null;
  linkConferido = "";
  linkOriginal = "";
  formLivro.reset();
  caixaResultado.hidden = true;
  desenharSeletorCategoria();
  document.getElementById("tituloForm").textContent = "Adicionar livro";
  document.getElementById("btnSalvar").textContent = "Adicionar livro";
  document.getElementById("btnCancelar").hidden = true;
  atualizarBotaoSalvar();
}

/**
 * Envia o formulário: cadastra um livro novo ou salva a edição.
 * O servidor guarda só o link do Drive e a capa (o PDF continua no Drive).
 */
async function enviarLivro(evento) {
  evento.preventDefault();
  const botao = botaoSalvar;
  const eraEdicao = Boolean(editandoId);   // guarda antes de limpar o formulário
  const idEditado = editandoId;
  botao.disabled = true;
  botao.textContent = "Salvando...";

  try {
    const url = editandoId ? `/api/admin/livros/${editandoId}` : "/api/admin/livros";
    const resposta = await api(url, {
      method: editandoId ? "PUT" : "POST",
      body: new FormData(formLivro)
    });

    if (idEditado) quebrados.delete(idEditado);   // link corrigido: tira a marca de "não abre"
    cancelarEdicao();
    await carregar();
    if (resposta.aviso) mostrarAviso(resposta.aviso, "alerta");
    else mostrarAviso(eraEdicao ? "Alterações salvas!" : "Livro salvo e capa gerada!");
  } catch (erro) {
    mostrarAviso(erro.message, "erro");
  } finally {
    botao.textContent = editandoId ? "Salvar alterações" : "Adicionar livro";
    atualizarBotaoSalvar();
  }
}

/**
 * Botão "Verificar links": confere, um livro de cada vez, se o PDF ainda abre no Drive
 * (por exemplo, se alguém apagou o arquivo). Mostra o progresso e marca na lista os que falharam.
 */
async function verificarLinks() {
  const botao = document.getElementById("btnVerificar");
  const texto = document.getElementById("resultadoVerificacao");
  if (livros.length === 0) return;
  botao.disabled = true;
  texto.hidden = false;
  quebrados = new Set();
  let feitos = 0;
  for (const livro of livros) {
    texto.textContent = `Verificando ${feitos + 1} de ${livros.length}: ${livro.titulo}...`;
    try {
      const resposta = await api(`/api/admin/livros/${livro.id}/verificar`, { method: "POST" });
      if (!resposta.abre) quebrados.add(livro.id);
    } catch (erro) {
      quebrados.add(livro.id);
    }
    feitos++;
  }
  desenharLista();
  texto.textContent = quebrados.size === 0
    ? `✅ Todos os ${livros.length} livros abrem normalmente.`
    : `⚠ ${quebrados.size} livro(s) com problema no link. Eles estão marcados em vermelho na lista abaixo.`;
  botao.disabled = false;
}

/**
 * Gera de novo a capa de um livro (usa a 1ª página do PDF).
 */
async function refazerCapa(id, botao) {
  botao.disabled = true;
  botao.textContent = "Gerando...";
  try {
    await api(`/api/admin/livros/${id}/capa`, { method: "POST" });
    await carregar();
    mostrarAviso("Capa gerada!");
  } catch (erro) {
    mostrarAviso(erro.message, "erro");
    botao.disabled = false;
    botao.textContent = "Refazer capa";
  }
}

/**
 * Exclui um livro depois de pedir confirmação.
 */
async function excluirLivro(id) {
  const livro = livros.find(l => l.id === id);
  if (!confirm(`Excluir "${livro ? livro.titulo : "este livro"}"? Isso não pode ser desfeito.`)) return;
  try {
    await api(`/api/admin/livros/${id}`, { method: "DELETE" });
    if (editandoId === id) cancelarEdicao();
    await carregar();
    mostrarAviso("Livro excluído.");
  } catch (erro) {
    mostrarAviso(erro.message, "erro");
  }
}

/**
 * Desenha os campos de nome dos filtros (cada um guarda o id do filtro).
 */
function desenharCamposFiltros() {
  camposFiltros.innerHTML = categorias.map(c => campoFiltro(c.id, c.nome, c.total)).join("");
}

/**
 * HTML de uma linha de filtro: campo do nome + quantidade de livros + botão Excluir.
 * id vazio = filtro novo (ainda não salvo).
 */
function campoFiltro(id, nome, total) {
  const qtd = id ? `<span class="qtd-filtro">${total} livro(s)</span>` : "";
  return `
    <div class="linha-filtro" data-id="${esc(id)}">
      <input type="text" maxlength="80" value="${esc(nome)}" placeholder="Nome do filtro" aria-label="Nome do filtro">
      ${qtd}
      <button class="btn-mini btn-perigo" data-acao="excluir-filtro" type="button">Excluir</button>
    </div>`;
}

/**
 * Adiciona um campo em branco para criar um filtro novo.
 */
function adicionarCampoFiltro() {
  if (camposFiltros.children.length >= maxFiltros) {
    mostrarAviso(`O máximo é ${maxFiltros} filtros.`, "alerta");
    return;
  }
  camposFiltros.insertAdjacentHTML("beforeend", campoFiltro("", "", 0));
  camposFiltros.lastElementChild.querySelector("input").focus();
}

/**
 * Botão "Excluir" de um filtro:
 *  - filtro novo (ainda não salvo): só tira da tela;
 *  - filtro que tem livros: avisa e NÃO exclui (os livros ficariam sem categoria);
 *  - filtro vazio: pede confirmação e exclui no servidor.
 * Sempre sobra pelo menos um filtro.
 */
async function excluirFiltro(linha) {
  const id = linha.dataset.id;
  if (!id) {                                   // ainda não foi salvo: basta remover a linha
    linha.remove();
    return;
  }
  const filtro = categorias.find(c => c.id === id);
  const nome = filtro ? filtro.nome : "este filtro";
  if (filtro && filtro.total > 0) {
    mostrarAviso(`"${nome}" ainda tem ${filtro.total} livro(s). Mude esses livros para outro filtro (botão Editar) ou exclua-os antes de excluir o filtro.`, "alerta");
    return;
  }
  const restantes = [...camposFiltros.querySelectorAll(".linha-filtro")]
    .filter(l => l !== linha && l.querySelector("input").value.trim() !== "");
  if (restantes.length === 0) {
    mostrarAviso("Precisa existir pelo menos um filtro.", "alerta");
    return;
  }
  if (!confirm(`Excluir o filtro "${nome}"? Isso não pode ser desfeito.`)) return;

  try {
    await api("/api/admin/categorias", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ categorias: restantes.map(l => ({ id: l.dataset.id, nome: l.querySelector("input").value })) })
    });
    await carregar();
    mostrarAviso(`Filtro "${nome}" excluído.`);
  } catch (erro) {
    mostrarAviso(erro.message, "erro");
  }
}

/**
 * Salva os nomes dos filtros no servidor.
 */
async function salvarFiltros(evento) {
  evento.preventDefault();
  const itens = [...camposFiltros.querySelectorAll(".linha-filtro")].map(linha => ({
    id: linha.dataset.id,
    nome: linha.querySelector("input").value
  }));

  try {
    await api("/api/admin/categorias", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ categorias: itens })
    });
    await carregar();
    mostrarAviso("Filtros salvos!");
  } catch (erro) {
    mostrarAviso(erro.message, "erro");
  }
}

/**
 * Troca a senha do administrador logado.
 */
async function trocarSenha(evento) {
  evento.preventDefault();
  const formSenha = evento.target;
  try {
    await api("/api/admin/senha", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        atual: document.getElementById("senhaAtual").value,
        nova: document.getElementById("senhaNova").value
      })
    });
    formSenha.reset();
    mostrarAviso("Senha alterada! Os outros acessos desta conta foram encerrados.");
  } catch (erro) {
    mostrarAviso(erro.message, "erro");
  }
}

/**
 * Sai da área do administrador e volta para a tela de entrada.
 */
async function sair() {
  await api("/api/logout", { method: "POST" }).catch(() => {});
  window.location.href = "/";
}

/**
 * Função inicial: liga os botões, pega a sessão e carrega os dados.
 */
async function iniciar() {
  const caixaLogo = document.getElementById("logoPrefeitura");
  caixaLogo.querySelector("img").addEventListener("error", () => caixaLogo.classList.add("sem-logo"));

  formLivro.addEventListener("submit", enviarLivro);
  document.getElementById("btnConferir").addEventListener("click", conferirLink);
  document.getElementById("btnVerificar").addEventListener("click", verificarLinks);
  campoLinkPdf.addEventListener("input", linkAlterado);
  // Ao colar o link, já confere sozinho (a pessoa não precisa lembrar de clicar)
  campoLinkPdf.addEventListener("paste", () => setTimeout(conferirLink, 50));
  // Enter no campo do link = conferir (e não enviar o formulário sem querer)
  campoLinkPdf.addEventListener("keydown", (e) => {
    if (e.key === "Enter") { e.preventDefault(); conferirLink(); }
  });
  document.getElementById("btnCancelar").addEventListener("click", cancelarEdicao);
  document.getElementById("formFiltros").addEventListener("submit", salvarFiltros);
  document.getElementById("formSenha").addEventListener("submit", trocarSenha);
  document.getElementById("btnMaisFiltro").addEventListener("click", adicionarCampoFiltro);
  document.getElementById("btnSair").addEventListener("click", sair);

  // Botão "Excluir" de cada filtro
  camposFiltros.addEventListener("click", (e) => {
    const botao = e.target.closest('button[data-acao="excluir-filtro"]');
    if (botao) excluirFiltro(botao.closest(".linha-filtro"));
  });

  // Cliques nos botões da lista (Editar / Refazer capa / Excluir)
  listaLivros.addEventListener("click", (e) => {
    const botao = e.target.closest("button[data-acao]");
    if (!botao) return;
    const id = botao.closest(".linha-livro").dataset.id;
    if (botao.dataset.acao === "editar") iniciarEdicao(id);
    if (botao.dataset.acao === "capa") refazerCapa(id, botao);
    if (botao.dataset.acao === "excluir") excluirLivro(id);
  });

  try {
    await iniciarSessao();
    await carregar();
  } catch (erro) {
    mostrarAviso(erro.message, "erro");
  }
}

iniciar();