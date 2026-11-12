/* ============ ESTADO DA PÁGINA ============ */
let categorias = [];            // nomes dos filtros (vêm do servidor)
let livros = [];                // livros cadastrados (vêm do servidor)
let categoriaAtual = "Todos";   // categoria escolhida no menu
let textoBusca = "";            // o que a pessoa digitou na busca
let quantosMostrar = 0;         // quantos livros estão na tela agora (cresce com "Mostrar mais")
// (a função esc() vem do comum.js, carregado antes deste arquivo)

/* ============ ELEMENTOS DA PÁGINA ============ */
const grade = document.getElementById("grade");
const menuCategorias = document.getElementById("categorias");
const contador = document.getElementById("contador");
const campoBusca = document.getElementById("busca");

/* Descrições maiores que isso ganham o botão "Ver mais" */
const LIMITE_DESCRICAO = 110;

/* Quantos livros aparecem por vez (o botão "Mostrar mais" libera os próximos) */
const POR_PAGINA = 24;

/**
 * Deixa um texto "padronizado" para comparar: tudo minúsculo,
 * sem acentos e sem espaços sobrando.
 */
function normalizar(texto) {
  return String(texto || "")
    .normalize("NFD")
    .replace(/[̀-ͯ]/g, "")
    .replace(/\s+/g, " ")
    .trim()
    .toLowerCase();
}

/**
 * Monta a lista final de filtros do menu: primeiro os definidos pelo
 * administrador, depois qualquer categoria usada em livros que não esteja na lista.
 */
function obterListaDeFiltros() {
  const filtros = [];
  const jaTem = (nome) => filtros.some(f => normalizar(f) === normalizar(nome));

  categorias.forEach(nome => { if (nome && !jaTem(nome)) filtros.push(nome); });
  livros.forEach(livro => { if (livro.categoria && !jaTem(livro.categoria)) filtros.push(livro.categoria); });
  return filtros;
}

/**
 * Diz se um livro pertence a uma categoria (sem diferenciar maiúsculas/acentos).
 */
function livroEstaNaCategoria(livro, nomeCategoria) {
  return normalizar(livro.categoria) === normalizar(nomeCategoria);
}

/**
 * Se a imagem img/logo-prefeitura.png não existir ainda,
 * mostra a caixa tracejada "Logo da Prefeitura" no lugar.
 */
function prepararLogoPrefeitura() {
  const caixa = document.getElementById("logoPrefeitura");
  caixa.querySelector("img").addEventListener("error", () => caixa.classList.add("sem-logo"));
}

/**
 * Cria a capa genérica (azul/verde com o título),
 * usada quando o livro ainda não tem imagem.
 */
function criarCapaPadrao(titulo) {
  const div = document.createElement("div");
  div.className = "capa-padrao";
  div.innerHTML = `<div>${esc(titulo)}</div>`;
  return div;
}

/**
 * Monta a área da capa do card: a imagem gerada automaticamente
 * ou, se não houver (ou não abrir), a capa padrão.
 */
function criarAreaCapa(livro) {
  const area = document.createElement("div");
  area.className = "previa";

  if (!livro.capa) {
    area.appendChild(criarCapaPadrao(livro.titulo));
    return area;
  }

  const img = document.createElement("img");
  img.src = livro.capa;
  img.alt = "Capa de " + livro.titulo;
  img.loading = "lazy";
  img.addEventListener("error", () => {
    area.innerHTML = "";
    area.appendChild(criarCapaPadrao(livro.titulo));
  });
  area.appendChild(img);
  return area;
}

/**
 * Abre ou fecha o texto completo da descrição de um livro
 * (chamada quando a pessoa clica em "Ver mais" / "Ver menos").
 */
function alternarDescricao(botao) {
  const texto = botao.previousElementSibling;   // o <p> da descrição
  const aberta = texto.classList.toggle("aberta");
  botao.textContent = aberta ? "Ver menos" : "Ver mais";
}

/**
 * Cria o HTML de UM card de livro.
 * Para mudar o visual/estrutura do card, altere o texto abaixo.
 */
function criarCard(livro) {
  const card = document.createElement("article");
  card.className = "card";

  // Descrição: só aparece se o livro tiver o campo "descricao"
  const descricao = livro.descricao || "";
  const blocoDescricao = descricao
    ? `<p class="card-descricao">${esc(descricao)}</p>` +
      (descricao.length > LIMITE_DESCRICAO
        ? `<button class="btn-mais" type="button">Ver mais</button>`
        : "")
    : "";

  // O botão abre "link" se existir; senão abre o próprio PDF
  const destino = livro.link || livro.arquivo;

  const info = document.createElement("div");
  info.className = "card-info";
  info.innerHTML = `
    <span class="card-categoria">${esc(livro.categoria)}</span>
    <h3 class="card-titulo">${esc(livro.titulo)}</h3>
    <p class="card-autor">${esc(livro.autor)}</p>
    ${blocoDescricao}
    <a class="card-link" href="${esc(destino)}" target="_blank" rel="noopener">
      Ler gratuitamente<span class="sr-only">: ${esc(livro.titulo)} (abre em nova aba)</span>
    </a>
  `;

  // Liga o botão "Ver mais" (se existir neste card)
  const botaoMais = info.querySelector(".btn-mais");
  if (botaoMais) botaoMais.addEventListener("click", () => alternarDescricao(botaoMais));

  card.appendChild(criarAreaCapa(livro));
  card.appendChild(info);
  return card;
}

/**
 * Cria os botões do menu lateral: "Todos" + os filtros,
 * com a quantidade de livros de cada um.
 */
function criarMenuCategorias() {
  const nomes = ["Todos", ...obterListaDeFiltros()];
  menuCategorias.innerHTML = "";

  nomes.forEach(nome => {
    const quantidade = nome === "Todos"
      ? livros.length
      : livros.filter(l => livroEstaNaCategoria(l, nome)).length;

    const botao = document.createElement("button");
    botao.type = "button";
    botao.className = "cat-btn" + (nome === categoriaAtual ? " ativo" : "");
    botao.setAttribute("aria-pressed", nome === categoriaAtual ? "true" : "false");
    botao.innerHTML = `<span>${esc(nome)}</span><span class="qtd">${quantidade}</span>`;

    botao.addEventListener("click", () => {
      categoriaAtual = nome;
      document.querySelectorAll(".cat-btn").forEach(b => {
        b.classList.remove("ativo");
        b.setAttribute("aria-pressed", "false");
      });
      botao.classList.add("ativo");
      botao.setAttribute("aria-pressed", "true");
      mostrarLivros();
    });

    menuCategorias.appendChild(botao);
  });
}

/**
 * Aplica categoria + busca e desenha os livros na tela.
 * A busca procura no título, no autor e na descrição.
 * Mostra POR_PAGINA livros por vez; "recomecar" volta ao início da lista (nova busca ou categoria).
 */
function mostrarLivros(recomecar = true) {
  const termo = normalizar(textoBusca);
  if (recomecar) quantosMostrar = POR_PAGINA;

  const resultado = livros.filter(livro => {
    const naCategoria = categoriaAtual === "Todos" || livroEstaNaCategoria(livro, categoriaAtual);
    const textoLivro = normalizar(`${livro.titulo} ${livro.autor} ${livro.descricao || ""}`);
    return naCategoria && textoLivro.includes(termo);
  });

  grade.innerHTML = "";

  if (resultado.length === 0) {
    grade.innerHTML = `<p class="vazio">Nenhum livro encontrado.</p>`;
  } else {
    resultado.slice(0, quantosMostrar).forEach(livro => grade.appendChild(criarCard(livro)));
  }

  // Botão "Mostrar mais" quando ainda há livros escondidos
  if (resultado.length > quantosMostrar) {
    const restantes = resultado.length - quantosMostrar;
    const botao = document.createElement("button");
    botao.type = "button";
    botao.className = "btn-mostrar-mais";
    botao.textContent = `Mostrar mais (${restantes} restante${restantes > 1 ? "s" : ""})`;
    botao.addEventListener("click", () => {
      quantosMostrar += POR_PAGINA;
      mostrarLivros(false);
    });
    grade.appendChild(botao);
  }

  const exibidos = Math.min(resultado.length, quantosMostrar);
  contador.textContent = resultado.length > exibidos
    ? `${resultado.length} livro(s) encontrado(s) — mostrando ${exibidos}`
    : `${resultado.length} livro(s) encontrado(s)`;
}

/**
 * Busca a lista de livros e filtros no servidor.
 */
async function carregarDoServidor() {
  const resposta = await fetch("/api/livros", { cache: "no-cache" });
  if (!resposta.ok) throw new Error("Falha ao carregar o acervo.");
  const dados = await resposta.json();
  categorias = dados.categorias || [];
  livros = dados.livros || [];
}

/**
 * Função inicial: roda quando a página abre.
 */
async function iniciar() {
  prepararLogoPrefeitura();

  // Espera a pessoa parar de digitar por 200 ms antes de refazer a lista (deixa a busca mais leve)
  let esperaBusca = null;
  campoBusca.addEventListener("input", (e) => {
    textoBusca = e.target.value;
    clearTimeout(esperaBusca);
    esperaBusca = setTimeout(() => mostrarLivros(), 200);
  });

  try {
    await carregarDoServidor();
    criarMenuCategorias();
    mostrarLivros();
  } catch (erro) {
    grade.innerHTML = `<p class="vazio">Não foi possível carregar os livros agora. Tente novamente em instantes.</p>`;
  }
}

iniciar();