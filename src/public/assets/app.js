document.addEventListener('submit', function (event) {
    const form = event.target;
    if (!form.matches('form[data-product-update]')) {
        return;
    }

    event.preventDefault();

    const url = form.getAttribute('action');
    const formData = new FormData(form);
    const row = form.closest('tr[data-product-row]');
    const submitButton = form.querySelector('button[type="submit"]');

    if (submitButton) {
        submitButton.disabled = true;
    }

    fetch(url, {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'fetch' },
    })
        .then((response) => response.json().then((data) => ({ ok: response.ok, data })))
        .then(({ ok, data }) => {
            openProductModal({
                success: ok,
                message: data.message || (ok ? 'Produto atualizado.' : 'Não foi possível atualizar o produto.'),
                row: ok ? row : null,
                price: data.price,
                stock: data.stock,
            });
            if (ok) {
                form.reset();
            }
        })
        .catch(() => {
            openProductModal({
                success: false,
                message: 'Erro de conexão ao atualizar o produto. Tente novamente.',
                row: null,
            });
        })
        .finally(() => {
            if (submitButton) {
                submitButton.disabled = false;
            }
        });
});

/**
 * Mostra a confirmação de atualização numa modal em vez de um texto solto
 * ao lado do botão. A linha da tabela só é atualizada com os novos valores
 * (vindos do banco, não do formulário) quando a modal é fechada — assim a
 * mudança "aparece registrada na página" de forma clara e intencional.
 */
function openProductModal({ success, message, row, price, stock }) {
    const overlay = document.getElementById('product-modal');
    const icon = document.getElementById('product-modal-icon');
    const title = document.getElementById('product-modal-title');
    const messageEl = document.getElementById('product-modal-message');
    const closeButton = document.getElementById('product-modal-close');

    if (!overlay || !messageEl || !closeButton) {
        return;
    }

    overlay.classList.toggle('modal-overlay--error', !success);
    if (icon) {
        icon.textContent = success ? '✓' : '✕';
    }
    if (title) {
        title.textContent = success ? 'Produto atualizado' : 'Não foi possível atualizar';
    }
    messageEl.textContent = message;

    overlay.hidden = false;
    closeButton.focus();

    const applyChangesAndClose = () => {
        overlay.hidden = true;

        if (row) {
            if (typeof price !== 'undefined') {
                const priceCell = row.querySelector('[data-field="price"]');
                if (priceCell) {
                    priceCell.textContent = price;
                }
            }
            if (typeof stock !== 'undefined') {
                const stockCell = row.querySelector('[data-field="stock"]');
                if (stockCell) {
                    stockCell.textContent = stock;
                }
            }
            row.classList.add('row-updated');
            window.setTimeout(() => row.classList.remove('row-updated'), 1200);
        }

        cleanup();
    };

    const onOverlayClick = (event) => {
        if (event.target === overlay) {
            applyChangesAndClose();
        }
    };

    const onKeydown = (event) => {
        if (event.key === 'Escape') {
            applyChangesAndClose();
        }
    };

    function cleanup() {
        closeButton.removeEventListener('click', applyChangesAndClose);
        overlay.removeEventListener('click', onOverlayClick);
        document.removeEventListener('keydown', onKeydown);
    }

    closeButton.addEventListener('click', applyChangesAndClose);
    overlay.addEventListener('click', onOverlayClick);
    document.addEventListener('keydown', onKeydown);
}
