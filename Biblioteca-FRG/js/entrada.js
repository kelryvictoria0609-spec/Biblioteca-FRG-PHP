/* ============ ELEMENTOS DA PÁGINA ============ */
const blocoEscolhas = document.getElementById("escolhas");
const formLogin = document.getElementById("formLogin");
const msgErro = document.getElementById("erroLogin");

/**
 * Se a logo da prefeitura (img/logo-prefeitura.png) não existir,
 * mostra a caixa tracejada no lugar.
 */
function prepararLogoPrefeitura() {
  const caixa = document.getElementById("logoPrefeitura");
  caixa.querySelector("img").addEventListener("error", () => caixa.classList.add("sem-logo"));
}

/**
 * Troca entre a tela de escolha (Visitante/Administrador)
 * e o formulário de login do administrador.
 */
function mostrarLogin(mostrar) {
  blocoEscolhas.hidden = mostrar;
  formLogin.hidden = !mostrar;
  msgErro.hidden = true;
  if (mostrar) document.getElementById("usuario").focus();
}

/**
 * Envia usuário e senha ao servidor.
 * Se estiverem certos, abre o painel do administrador; se não, mostra o erro.
 */
async function entrarComoAdmin(evento) {
  evento.preventDefault();
  msgErro.hidden = true;

  try {
    const resposta = await fetch("/api/login", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        usuario: document.getElementById("usuario").value,
        senha: document.getElementById("senha").value
      })
    });
    document.getElementById("senha").value = "";   // não deixa a senha na tela
    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) throw new Error(dados.erro || "Não foi possível entrar.");
    window.location.href = "/admin";
  } catch (erro) {
    msgErro.textContent = erro.message;
    msgErro.hidden = false;
  }
}

/**
 * Função inicial: liga os botões da tela de entrada.
 */
function iniciar() {
  prepararLogoPrefeitura();
  document.getElementById("btnAdmin").addEventListener("click", () => mostrarLogin(true));
  document.getElementById("btnVoltar").addEventListener("click", () => mostrarLogin(false));
  formLogin.addEventListener("submit", entrarComoAdmin);
}

iniciar();