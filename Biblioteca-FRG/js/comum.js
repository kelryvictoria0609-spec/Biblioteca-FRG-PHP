/* ============================================================
   FUNÇÕES COMUNS (usadas por várias páginas)
   Precisa ser carregado ANTES do app.js, do admin.js e do admin-usuarios.js.
   ============================================================ */

let csrf = "";   // código de proteção da sessão do administrador (enviado em todo pedido que altera algo)

/**
 * Transforma texto em versão segura para colocar dentro de HTML
 * (evita que um título com "<" quebre a página ou rode código).
 */
function esc(texto) {
  const div = document.createElement("div");
  div.textContent = texto == null ? "" : String(texto);
  return div.innerHTML.replace(/"/g, "&quot;");
}

/**
 * Faz um pedido ao servidor e já trata o erro.
 * Envia o código CSRF da sessão; se a sessão expirou (401), volta para a entrada.
 * Se o código CSRF ficou velho (403 "Sessão inválida", por exemplo depois de um login
 * em outra aba), busca o código novo e tenta mais UMA vez, sem a pessoa perceber.
 */
async function api(url, opcoes = {}, jaTentouDeNovo = false) {
  const resposta = await fetch(url, {
    ...opcoes,
    credentials: "same-origin",
    headers: { "X-CSRF-Token": csrf, ...(opcoes.headers || {}) }
  });
  if (resposta.status === 401) {
    window.location.href = "/";
    throw new Error("Sessão expirada.");
  }
  const dados = await resposta.json().catch(() => ({}));
  if (resposta.status === 403 && !jaTentouDeNovo && /Sessão inválida/.test(dados.erro || "")) {
    const sessao = await fetch("/api/sessao", { credentials: "same-origin" }).then(r => r.json());
    if (!sessao.admin) {
      window.location.href = "/";
      throw new Error("Sessão expirada.");
    }
    csrf = sessao.csrf;                       // código novo da sessão atual
    return api(url, opcoes, true);            // tenta de novo uma única vez
  }
  if (!resposta.ok) throw new Error(dados.erro || "Algo deu errado.");
  return dados;
}