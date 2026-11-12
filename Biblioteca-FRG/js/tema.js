const CHAVE_TEMA = "biblioteca-frg-tema";
const CHAVE_TEMA_ANTIGA = "leia-frg-tema";   // nome usado em versões anteriores (para ninguém perder a escolha)

/**
 * Lê o tema guardado no navegador.
 * Se o navegador bloquear o armazenamento, devolve null (sem quebrar o site).
 */
function lerTemaSalvo() {
  try {
    return localStorage.getItem(CHAVE_TEMA) || localStorage.getItem(CHAVE_TEMA_ANTIGA);
  } catch (erro) {
    return null;
  }
}

/**
 * Guarda o tema escolhido no navegador (ignora se estiver bloqueado).
 */
function salvarTema(tema) {
  try {
    localStorage.setItem(CHAVE_TEMA, tema);
  } catch (erro) { /* sem problema */ }
}

/**
 * Aplica o tema ("claro" ou "escuro") na página e atualiza o texto do botão.
 * O CSS troca as cores quando <html> tem data-tema="escuro".
 */
function aplicarTema(tema) {
  document.documentElement.setAttribute("data-tema", tema);
  const botao = document.getElementById("btnTema");
  if (botao) {
    botao.textContent = tema === "escuro" ? "☀️ Modo claro" : "🌙 Modo escuro";
  }
}

/**
 * Troca entre claro e escuro quando o botão é clicado.
 */
function alternarTema() {
  const atual = document.documentElement.getAttribute("data-tema");
  const novo = atual === "escuro" ? "claro" : "escuro";
  aplicarTema(novo);
  salvarTema(novo);
}

/**
 * Define o tema inicial: usa o que a pessoa escolheu antes; se nunca escolheu,
 * segue a preferência do aparelho (modo escuro do Windows/celular) e, na falta dela, o claro.
 */
function iniciarTema() {
  const salvo = lerTemaSalvo();
  const doAparelho = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "escuro" : "claro";
  aplicarTema(salvo === "claro" || salvo === "escuro" ? salvo : doAparelho);
  const botao = document.getElementById("btnTema");
  if (botao) botao.addEventListener("click", alternarTema);
}

iniciarTema();