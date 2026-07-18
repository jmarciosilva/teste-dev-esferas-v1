document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!form.matches('form[data-product-update]')) {
        return;
    }

    event.preventDefault();

    const url = form.getAttribute('action');
    const formData = new FormData(form);
    // .update-feedback é irmã do <form> (fica fora dele no HTML), então a
    // busca precisa partir do elemento pai, não do próprio form.
    const feedback = form.parentElement.querySelector('.update-feedback');

    fetch(url, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'fetch' },
    })
        .then((response) => response.json())
        .then((data) => {
            if (feedback) {
                feedback.textContent = data.message || 'Atualizado.';
            }
        })
        .catch(() => {
            if (feedback) {
                feedback.textContent = 'Erro ao atualizar.';
            }
        });
});
