/* ============================================================
   BOTÃO "MOSTRAR / OCULTAR" SENHA
   Coloca automaticamente um botão ao lado de TODO campo de senha do site
   (login, trocar senha, novo administrador, redefinir senha...), inclusive
   nos campos que aparecem depois (criados pelo painel).
   Não precisa mexer no HTML: basta carregar este arquivo.
   ============================================================ */
(function () {
  /**
   * Envolve um campo de senha numa caixa e cria o botão "Mostrar".
   */
  function adicionarBotao(campo) {
    if (campo.dataset.comOlho) return;          // já tem botão
    campo.dataset.comOlho = "1";

    const caixa = document.createElement("span");
    caixa.className = "campo-senha";
    campo.parentNode.insertBefore(caixa, campo);
    caixa.appendChild(campo);

    const botao = document.createElement("button");
    botao.type = "button";                       // não envia o formulário ao clicar
    botao.className = "btn-olho";
    botao.textContent = "Mostrar";
    botao.setAttribute("aria-label", "Mostrar senha");
    botao.setAttribute("aria-pressed", "false");
    botao.addEventListener("click", () => {
      const mostrando = campo.type === "text";
      campo.type = mostrando ? "password" : "text";
      botao.textContent = mostrando ? "Mostrar" : "Ocultar";
      botao.setAttribute("aria-label", mostrando ? "Mostrar senha" : "Ocultar senha");
      botao.setAttribute("aria-pressed", mostrando ? "false" : "true");
      campo.focus();
    });
    caixa.appendChild(botao);
  }

  /**
   * Procura campos de senha ainda sem botão.
   */
  function varrer() {
    document.querySelectorAll('input[type="password"]:not([data-com-olho])').forEach(adicionarBotao);
  }

  varrer();
  // Campos criados depois (ex.: "Redefinir senha" na lista de administradores) também ganham o botão
  new MutationObserver(varrer).observe(document.body, { childList: true, subtree: true });
})();