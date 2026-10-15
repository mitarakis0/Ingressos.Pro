document.addEventListener('click', async (event) => {
    const copyButton = event.target.closest('[data-copy]');

    if (copyButton) {
        const value = copyButton.getAttribute('data-copy') || '';

        try {
            await navigator.clipboard.writeText(value);

            const previousText = copyButton.textContent;
            copyButton.textContent = 'Copiado!';

            window.setTimeout(() => {
                copyButton.textContent = previousText;
            }, 1400);
        } catch {
            window.prompt('Copie o código Pix:', value);
        }
    }

    const confirmButton = event.target.closest('[data-confirm]');

    if (
        confirmButton &&
        !window.confirm(confirmButton.dataset.confirm || 'Confirmar esta ação?')
    ) {
        event.preventDefault();
    }
});

/** Cria campos para informar o nome de cada participante selecionado. */
function renderParticipantFields(input) {
    const selection = input.closest('.ticket-selection') || input.closest('.ticket-row');
    const fields = selection?.querySelector('.participant-fields');

    if (!fields) {
        return;
    }

    const quantity = Math.max(
        0,
        Math.min(50, Number.parseInt(input.value || '0', 10) || 0),
    );
    const peoplePerItem = Number.parseInt(fields.dataset.people || '1', 10) || 1;
    const participantCount = quantity * peoplePerItem;
    const kind = fields.dataset.kind;
    const id = fields.dataset.id;

    fields.replaceChildren();

    for (let index = 0; index < participantCount; index += 1) {
        const field = document.createElement('div');
        field.className = 'participant-field';

        const label = document.createElement('label');
        label.className = 'small';
        label.textContent = `Participante ${index + 1}`;

        const nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.required = true;
        nameInput.maxLength = 180;
        nameInput.placeholder = 'Nome completo';
        nameInput.name = `participants[${kind}_${id}][${index}]`;

        field.append(label, nameInput);
        fields.append(field);
    }
}

document
    .querySelectorAll('input[name^="tickets["], input[name^="combos["]')
    .forEach((input) => {
        input.addEventListener('input', () => renderParticipantFields(input));
        renderParticipantFields(input);
    });
